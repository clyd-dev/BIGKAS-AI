<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    use AuthorizesLearnerAccess;

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
                Assessment::create([
                    'batch_id' => $batch->id,
                    'learner_id' => $entry['learner_id'],
                    'material_id' => $entry['material_id'] ?? $input['default_material_id'],
                    'assessor_id' => $teacher->id,
                    'language' => $input['language'],
                    'assessment_type' => $input['assessment_type'],
                    'status' => Assessment::STATUS_PENDING,
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
            if ($gradeDiff > 0 && empty($entry['allow_fallback'])) {
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
     * The shared trait authorises the authenticated user and aborts with a 403.
     * Run it as the given teacher so access means exactly what it does in the
     * other controllers, and turn the 403 into a per-entry answer.
     */
    private function canAccess(User $teacher, Learner $learner): bool
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($teacher);

        try {
            $this->authorizeLearnerAccess($learner);

            return true;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                return false;
            }
            throw $e;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
