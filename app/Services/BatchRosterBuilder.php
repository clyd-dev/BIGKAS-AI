<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates a whole batch roster, per child, and only then creates the batch and
 * one pending Assessment per child, all in one transaction. A roster is all or
 * nothing: one bad entry means nothing is created and the errors are keyed per
 * entry ("entries.17.material_id") so the setup form can flag that child.
 *
 * The entry keys are the form's own: the setup form posts
 * `entries[<learner id>][...]`, so a child cannot be in the roster twice and a
 * message can only ever land on the child it belongs to. The keys are opaque
 * here - the builder flags whatever key it was given.
 *
 * The per-entry rules deliberately duplicate AssessmentController::store(), with
 * the same thresholds and the same messages. Keep the two in step. The one
 * deliberate divergence is the grade rule: the single-child form offers a
 * cross-grade tick, while here the batch material must match each child's own
 * grade and a cross-grade reading is reached by giving that child their own
 * material. Picking a material by hand IS the deliberate act the tick stood for.
 */
class BatchRosterBuilder
{
    /**
     * @param  int  $minLearners  The floor this caller wants. The setup form asks
     *   for config('assessment.batch_min_learners') - a batch of one is a
     *   single-child assessment, which has its own screen. It is a parameter and
     *   not a constant because the board, cancel and self-record suites build
     *   one-entry batches straight through here to exercise edge cases.
     */
    public function build(User $teacher, array $input, int $minLearners = 1): AssessmentBatch
    {
        $errors = $this->check($teacher, $input, $minLearners);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($teacher, $input) {
            $entries = $this->entriesOf($input);

            $batch = AssessmentBatch::create([
                'teacher_id' => $teacher->id,
                // Derived, never posted: the teacher has no class input to fill in.
                // One class for everyone, or null when the roster crosses sections.
                'school_class_id' => $this->sharedClassOf($entries),
                'title' => $input['title'] ?? null,
                'language' => $input['language'],
                'assessment_type' => $input['assessment_type'],
                'default_material_id' => $input['default_material_id'],
                'status' => AssessmentBatch::STATUS_COLLECTING,
            ]);

            foreach ($entries as $entry) {
                $assessment = Assessment::create([
                    'batch_id' => $batch->id,
                    'learner_id' => $entry['learner_id'],
                    'material_id' => filled($entry['material_id'] ?? null)
                        ? $entry['material_id']
                        : $input['default_material_id'],
                    'assessor_id' => $teacher->id,
                    'language' => $input['language'],
                    'assessment_type' => $input['assessment_type'],
                    'status' => Assessment::STATUS_PENDING,
                ]);

                // A join code for every child, unconditionally: which route a
                // child takes (the teacher's laptop or their own device) is
                // decided in the moment, so the code is always there. It is the
                // live single-child session AssessmentController::startSession()
                // issues, made in bulk, and it shares this transaction - a
                // failure anywhere leaves no batch, no entry and no code.
                AssessmentSession::create([
                    'assessment_id' => $assessment->id,
                    'learner_id' => $assessment->learner_id,
                    'teacher_id' => $teacher->id,
                    'material_id' => $assessment->material_id,
                    'session_code' => AssessmentSession::generateCode(),
                    'status' => 'waiting',
                    'started_at' => now(),
                ]);
            }

            return $batch->load('entries');
        });
    }

    /**
     * The same verdict build() reaches, without creating anything: an empty array
     * when the roster is good. The summary step asks this before it shows the
     * teacher what she is about to create.
     *
     * @return array<string, string[]>
     */
    public function check(User $teacher, array $input, int $minLearners = 1): array
    {
        $errors = [];
        $add = function (string $key, string $message) use (&$errors) {
            $errors[$key][] = $message;
        };

        if (! in_array($input['language'] ?? null, ['en', 'fil'], true)) {
            $add('language', 'The selected language is invalid.');
        }
        if (! in_array($input['assessment_type'] ?? null, ['oral_reading', 'comprehension', 'combined'], true)) {
            $add('assessment_type', 'The selected assessment type is invalid.');
        }

        $entries = $this->entriesOf($input);
        $max = (int) config('assessment.batch_max_learners');
        $min = max(1, $minLearners);

        if (count($entries) < $min) {
            $add('entries', $min > 1
                ? "Pick at least {$min} children for a batch. For one child, use the single-learner assessment."
                : 'Add at least one learner to the batch.');
        } elseif (count($entries) > $max) {
            $add('entries', "A batch can hold at most {$max} learners.");
        }

        if ($errors !== []) {
            return $errors;
        }

        $materials = ReadingMaterial::active()
            ->whereIn('id', array_filter(array_merge(
                [$input['default_material_id'] ?? null],
                array_column($entries, 'material_id')
            )))
            ->get()
            ->keyBy('id');

        $seen = [];

        foreach ($entries as $key => $entry) {
            $learner = $entry['learner'];

            if (! $learner) {
                $add("entries.$key.learner_id", 'The selected learner is invalid.');
                continue;
            }
            // Unreachable from the form, where the entry key IS the learner id.
            // Kept for a hand-crafted post, which is the only way here.
            if (isset($seen[$learner->id])) {
                $add("entries.$key.learner_id", 'This learner is already in the batch.');
                continue;
            }
            $seen[$learner->id] = true;

            if (! $this->canAccess($teacher, $learner)) {
                $add("entries.$key.learner_id", 'Unauthorized. This student is not assigned to your class.');
                continue;
            }

            $override = filled($entry['material_id'] ?? null);
            $material = $materials->get($override ? $entry['material_id'] : ($input['default_material_id'] ?? null));
            if (! $material) {
                $add("entries.$key.material_id", 'The selected material is not available.');
                continue;
            }

            // Language must match the chosen material.
            if ($material->language !== $input['language']) {
                $add("entries.$key.material_id", 'The selected material is not in the chosen language.');
                continue;
            }

            // Grade policy. The batch material has to be the child's own grade,
            // because it is chosen once for everybody and nobody picked it for
            // this child in particular. An override is the opposite: the teacher
            // chose that passage for that child on purpose, which is the whole
            // reason the override exists, so it carries no grade rule.
            if (! $override && $material->grade_level !== $learner->grade_level) {
                $add("entries.$key.material_id", 'The batch material is for Grade ' . $material->grade_level
                    . '. This child is in Grade ' . $learner->grade_level . ' - choose a Grade '
                    . $learner->grade_level . ' material for the batch, or give this child their own material.');
                continue;
            }

            // Assessment type filters by comprehension-question availability.
            if (in_array($input['assessment_type'], ['comprehension', 'combined'], true)
                && ! $material->comprehensionQuestions()->exists()) {
                $add("entries.$key.material_id", 'Comprehension assessments require a material with comprehension questions.');
            }
        }

        return $errors;
    }

    /**
     * The roster as the builder reads it: the posted keys preserved, each entry's
     * learner resolved once so validation and creation cannot disagree about who
     * is in the batch.
     *
     * @return array<array-key, array{learner_id: int|null, material_id: mixed, learner: Learner|null}>
     */
    private function entriesOf(array $input): array
    {
        $entries = [];

        foreach ((array) ($input['entries'] ?? []) as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $learnerId = filled($entry['learner_id'] ?? null) ? (int) $entry['learner_id'] : null;

            $entries[$key] = [
                'learner_id' => $learnerId,
                'material_id' => filled($entry['material_id'] ?? null) ? (int) $entry['material_id'] : null,
                'learner' => $learnerId === null ? null : Learner::find($learnerId),
            ];
        }

        return $entries;
    }

    /** The one class every child in the roster belongs to, or null if they differ. */
    private function sharedClassOf(array $entries): ?int
    {
        $classIds = array_unique(array_map(fn ($entry) => $entry['learner']?->class_id, $entries));

        return count($classIds) === 1 ? (reset($classIds) ?: null) : null;
    }

    /**
     * The same rule as AuthorizesLearnerAccess::authorizeLearnerAccess(), evaluated
     * against the given teacher instead of the authenticated user, and returning a
     * bool instead of aborting. Deliberately duplicated so the single-child trait
     * stays untouched; BatchRosterBuilderTest pins the two together.
     */
    private function canAccess(User $user, Learner $learner): bool
    {
        // Admins can access all learners.
        if ($user->isAdmin()) {
            return true;
        }

        // Attached to the learner via the pivot table.
        if ($user->learners()->where('learners.id', $learner->id)->exists()) {
            return true;
        }

        // Or the teacher of the learner's class.
        return $user->isTeacher()
            && $learner->class_id
            && $user->taughtClasses()->where('id', $learner->class_id)->exists();
    }
}
