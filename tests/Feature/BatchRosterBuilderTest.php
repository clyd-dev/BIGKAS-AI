<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Services\BatchRosterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

class BatchRosterBuilderTest extends TestCase
{
    use RefreshDatabase, MakesBatchAssessments;

    private function learnersOf(User $teacher): array
    {
        $classIds = $teacher->taughtClasses()->pluck('id');

        return Learner::whereIn('class_id', $classIds)->orderBy('id')->get()->all();
    }

    /** Extra learners in the teacher's own (first) class. */
    private function moreLearners(User $teacher, int $count, int $grade = 4): array
    {
        $classId = $teacher->taughtClasses()->first()->id;

        return Learner::factory()->count($count)->create(['class_id' => $classId, 'grade_level' => $grade])->all();
    }

    private function input(array $learners, ?ReadingMaterial $default = null, array $overrides = []): array
    {
        $default ??= ReadingMaterial::factory()->create();

        return array_merge([
            'title' => 'Morning group',
            'language' => 'en',
            'assessment_type' => 'oral_reading',
            'default_material_id' => $default->id,
            'school_class_id' => null,
            'entries' => array_map(fn ($l) => [
                'learner_id' => $l->id, 'material_id' => null, 'allow_fallback' => false,
            ], $learners),
        ], $overrides);
    }

    private function withQuestions(ReadingMaterial $material): ReadingMaterial
    {
        ComprehensionQuestion::create([
            'material_id' => $material->id, 'question' => 'Who is Max?', 'question_type' => 'literal',
            'correct_answer' => 'Right', 'option_a' => 'Right', 'option_b' => 'B', 'option_c' => 'C',
            'option_d' => 'D', 'sort_order' => 1,
        ]);

        return $material;
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a ValidationException.');
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, AssessmentBatch::count());
        $this->assertSame(0, Assessment::count());
    }

    public function test_a_roster_within_the_cap_creates_one_assessment_per_learner(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $material = ReadingMaterial::factory()->create();

        $batch = app(BatchRosterBuilder::class)->build($teacher, $this->input($learners, $material));

        $this->assertSame(AssessmentBatch::STATUS_COLLECTING, $batch->status);
        $this->assertSame($teacher->id, $batch->teacher_id);
        $this->assertSame('Morning group', $batch->title);
        $this->assertSame('en', $batch->language);
        $this->assertSame($material->id, $batch->default_material_id);
        $this->assertCount(3, $batch->entries);
        foreach ($batch->entries as $entry) {
            $this->assertSame(Assessment::STATUS_PENDING, $entry->status);
            $this->assertNull($entry->audio_file);
            $this->assertSame($material->id, $entry->material_id);
            $this->assertSame($teacher->id, $entry->assessor_id);
            $this->assertSame('en', $entry->language);
            $this->assertSame('oral_reading', $entry->assessment_type);
        }
        $this->assertEqualsCanonicalizing(
            array_map(fn ($l) => $l->id, $learners),
            $batch->entries->pluck('learner_id')->all()
        );
    }

    public function test_a_roster_of_sixteen_is_rejected(): void
    {
        $teacher = $this->teacher();
        $learners = array_merge($this->learnersOf($teacher), $this->moreLearners($teacher, 13));
        $this->assertCount(16, $learners);

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $this->input($learners)));

        $this->assertArrayHasKey('entries', $errors);
        $this->assertNothingCreated();
    }

    public function test_a_roster_of_exactly_fifteen_is_accepted(): void
    {
        $teacher = $this->teacher();
        $learners = array_merge($this->learnersOf($teacher), $this->moreLearners($teacher, 12));
        $this->assertCount(15, $learners);

        $batch = app(BatchRosterBuilder::class)->build($teacher, $this->input($learners));

        $this->assertCount(15, $batch->entries);
        $this->assertSame(15, Assessment::count());
    }

    public function test_an_empty_roster_is_rejected(): void
    {
        $teacher = $this->teacher();

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $this->input([])));

        $this->assertArrayHasKey('entries', $errors);
        $this->assertNothingCreated();
    }

    public function test_the_same_learner_cannot_appear_twice(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $input = $this->input([$learners[0], $learners[1], $learners[0]]);

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayHasKey('entries.2.learner_id', $errors);
        $this->assertArrayNotHasKey('entries.0.learner_id', $errors);
        $this->assertNothingCreated();
    }

    public function test_a_learner_the_teacher_cannot_access_is_rejected(): void
    {
        // AuthorizesLearnerAccess scopes per learner (pivot row) or per class
        // taught, NOT per school. The two fixture teachers share a school, so the
        // foreign learner is the one in the other teacher's class: same school,
        // different class, no pivot row. The asserts below pin that.
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $own = $this->learnersOf($teacher)[0];
        $foreign = $this->learnersOf($other)[0];
        $this->assertFalse($teacher->taughtClasses()->where('id', $foreign->class_id)->exists());
        $this->assertFalse($teacher->learners()->where('learners.id', $foreign->id)->exists());

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $this->input([$own, $foreign])));

        $this->assertArrayHasKey('entries.1.learner_id', $errors);
        $this->assertArrayNotHasKey('entries.0.learner_id', $errors);
        $this->assertNothingCreated();

        // Control: the same learner is accepted for the teacher who does own them.
        $batch = app(BatchRosterBuilder::class)->build($other, $this->input([$foreign]));
        $this->assertCount(1, $batch->entries);
    }

    public function test_a_cross_grade_material_needs_that_entry_s_own_fallback_tick(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $grade5 = ReadingMaterial::factory()->create(['grade_level' => 5]);

        $input = $this->input([$a, $b], $grade5);
        $input['entries'][0]['allow_fallback'] = true; // only the first child is ticked

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayNotHasKey('entries.0.material_id', $errors);
        $this->assertSame(
            ['This material is for a different grade level. Pick a Grade 4 material or choose a cross-grade fallback option.'],
            $errors['entries.1.material_id']
        );
        $this->assertNothingCreated();

        $input['entries'][1]['allow_fallback'] = true;
        $batch = app(BatchRosterBuilder::class)->build($teacher, $input);
        $this->assertCount(2, $batch->entries);
    }

    public function test_a_material_two_grades_away_is_rejected_even_with_fallback(): void
    {
        $teacher = $this->teacher();
        [$a] = $this->learnersOf($teacher);
        $grade6 = ReadingMaterial::factory()->create(['grade_level' => 6]);
        $input = $this->input([$a], $grade6);
        $input['entries'][0]['allow_fallback'] = true;

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        // Byte-identical to AssessmentController::store(), which single-quotes the
        // ’ escape and so ships it literally.
        $this->assertSame(
            ['Cross-grade fallback is limited to one grade above or below the learner’s grade.'],
            $errors['entries.0.material_id']
        );
        $this->assertNothingCreated();
    }

    public function test_a_material_in_another_language_is_rejected(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $fil = ReadingMaterial::factory()->create(['language' => 'fil']);
        $input = $this->input([$a, $b], null, ['language' => 'en']);
        $input['entries'][1]['material_id'] = $fil->id;

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayNotHasKey('entries.0.material_id', $errors);
        $this->assertSame(['The selected material is not in the chosen language.'], $errors['entries.1.material_id']);
        $this->assertNothingCreated();
    }

    public function test_a_comprehension_batch_needs_materials_with_questions(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $with = $this->withQuestions(ReadingMaterial::factory()->create());
        $without = ReadingMaterial::factory()->create();

        foreach (['comprehension', 'combined'] as $type) {
            $input = $this->input([$a, $b], $with, ['assessment_type' => $type]);
            $input['entries'][1]['material_id'] = $without->id;

            $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

            $this->assertArrayNotHasKey('entries.0.material_id', $errors);
            $this->assertSame(
                ['Comprehension assessments require a material with comprehension questions.'],
                $errors['entries.1.material_id']
            );
            $this->assertNothingCreated();
        }

        // Oral reading has no such requirement.
        $input = $this->input([$a, $b], $without);
        $this->assertCount(2, app(BatchRosterBuilder::class)->build($teacher, $input)->entries);
    }

    public function test_a_per_child_material_override_is_stored_on_that_entry(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $default = ReadingMaterial::factory()->create();
        $override = ReadingMaterial::factory()->create();
        $input = $this->input([$a, $b], $default);
        $input['entries'][1]['material_id'] = $override->id;

        $batch = app(BatchRosterBuilder::class)->build($teacher, $input);

        $byLearner = $batch->entries->keyBy('learner_id');
        $this->assertSame($default->id, $byLearner[$a->id]->material_id);
        $this->assertSame($override->id, $byLearner[$b->id]->material_id);
        $this->assertSame($default->id, $batch->default_material_id);
    }

    public function test_nothing_is_created_when_one_entry_is_invalid(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $bad = ReadingMaterial::factory()->create(['language' => 'fil']);
        $input = $this->input($learners);
        $input['entries'][2]['material_id'] = $bad->id; // last one is wrong

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayHasKey('entries.2.material_id', $errors);
        $this->assertSame(0, AssessmentBatch::count());
        $this->assertSame(0, Assessment::count());
    }

    public function test_a_learner_with_another_unscored_assessment_can_still_join(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $material = ReadingMaterial::factory()->create();
        $existing = Assessment::factory()->create([
            'learner_id' => $a->id, 'material_id' => $material->id, 'assessor_id' => $teacher->id,
            'status' => Assessment::STATUS_PROCESSING,
        ]);

        $batch = app(BatchRosterBuilder::class)->build($teacher, $this->input([$a, $b], $material));

        $mine = $batch->entries->firstWhere('learner_id', $a->id);
        $this->assertNotNull($mine);
        $this->assertNotSame($existing->id, $mine->id);
        $this->assertSame(2, Assessment::where('learner_id', $a->id)->count());
        $this->assertNull($existing->fresh()->batch_id);
        $this->assertSame(Assessment::STATUS_PROCESSING, $existing->fresh()->status);
        $this->assertSame($batch->id, $mine->fresh()->batch_id);
        $this->assertSame(Assessment::STATUS_PENDING, $mine->fresh()->status);
    }
}
