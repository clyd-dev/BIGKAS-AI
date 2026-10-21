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
 * entry ("entries.3.material_id") so the setup form can flag that child.
 *
 * The per-entry rules deliberately duplicate AssessmentController::store(), with
 * the same thresholds and the same messages. Keep the two in step.
 */
class BatchRosterBuilder
{
    public function build(User $teacher, array $input): AssessmentBatch
    {
        $errors = $this->validate($teacher, $input);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($teacher, $input) {
            $batch = AssessmentBatch::create([
                'teacher_id' => $teacher->id,
                'school_class_id' => $input['school_class_id'] ?? null,
                'title' => $input['title'] ?? null,
                'language' => $input['language'],
                'assessment_type' => $input['assessment_type'],
                'default_material_id' => $input['default_material_id'],
                'status' => AssessmentBatch::STATUS_COLLECTING,
            ]);

            foreach ($input['entries'] as $entry) {
                $assessment = Assessment::create([
                    'batch_id' => $batch->id,
                    'learner_id' => $entry['learner_id'],
                    'material_id' => $entry['material_id'] ?? $input['default_material_id'],
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

    /** @return array<string, string[]> */
    private function validate(User $teacher, array $input): array
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

        $classId = $input['school_class_id'] ?? null;
        if ($classId !== null && ! $teacher->isAdmin() && ! $teacher->taughtClasses()->where('id', $classId)->exists()) {
            $add('school_class_id', 'The selected class is not one of yours.');
        }

        $entries = $input['entries'] ?? [];
        $max = (int) config('assessment.batch_max_learners');

        if (count($entries) === 0) {
            $add('entries', 'Add at least one learner to the batch.');
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

        foreach (array_values($entries) as $i => $entry) {
            $learner = Learner::find($entry['learner_id'] ?? null);

            if (! $learner) {
                $add("entries.$i.learner_id", 'The selected learner is invalid.');
                continue;
            }
            if (isset($seen[$learner->id])) {
                $add("entries.$i.learner_id", 'This learner is already in the batch.');
                continue;
            }
            $seen[$learner->id] = true;

            if (! $this->canAccess($teacher, $learner)) {
                $add("entries.$i.learner_id", 'Unauthorized. This student is not assigned to your class.');
                continue;
            }

            $material = $materials->get($entry['material_id'] ?? $input['default_material_id'] ?? null);
            if (! $material) {
                $add("entries.$i.material_id", 'The selected material is not available.');
                continue;
            }

            // Language must match the chosen material.
            if ($material->language !== $input['language']) {
                $add("entries.$i.material_id", 'The selected material is not in the chosen language.');
                continue;
            }

            // Grade policy: exact grade first; adjacent grades (±1) only when this
            // entry's own cross-grade fallback is ticked.
            $gradeDiff = abs($material->grade_level - $learner->grade_level);
            if ($gradeDiff > 0 && ! filter_var($entry['allow_fallback'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $add("entries.$i.material_id", 'This material is for a different grade level. Pick a Grade ' . $learner->grade_level . ' material or choose a cross-grade fallback option.');
                continue;
            }
            if ($gradeDiff > 1) {
                $add("entries.$i.material_id", 'Cross-grade fallback is limited to one grade above or below the learner’s grade.');
                continue;
            }

            // Assessment type filters by comprehension-question availability.
            if (in_array($input['assessment_type'], ['comprehension', 'combined'], true)
                && ! $material->comprehensionQuestions()->exists()) {
                $add("entries.$i.material_id", 'Comprehension assessments require a material with comprehension questions.');
            }
        }

        return $errors;
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
