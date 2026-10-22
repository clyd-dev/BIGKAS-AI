<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\BatchRosterBuilder;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function secondClassFor(User $teacher, int $grade = 4, string $section = 'Batch C'): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Batch School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => $grade, 'section' => $section, 'school_year' => '2026-2027',
        ]);
        Learner::factory()->count(2)->create(['class_id' => $class->id, 'grade_level' => $grade]);

        return $class;
    }

    /**
     * The shape the setup form posts: entries keyed by learner id, which is what
     * makes the same child twice structurally impossible on the form.
     */
    private function input(array $learners, ?ReadingMaterial $default = null, array $overrides = []): array
    {
        $default ??= ReadingMaterial::factory()->create();

        $entries = [];
        foreach ($learners as $learner) {
            $entries[$learner->id] = ['learner_id' => $learner->id, 'material_id' => null];
        }

        return array_merge([
            'title' => 'Morning group',
            'language' => 'en',
            'assessment_type' => 'oral_reading',
            'default_material_id' => $default->id,
            'entries' => $entries,
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
        $this->assertSame(0, AssessmentSession::count());
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

    public function test_one_session_is_created_per_entry(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);

        $batch = app(BatchRosterBuilder::class)->build($teacher, $this->input($learners));

        $this->assertSame(3, AssessmentSession::count());
        foreach ($batch->entries as $entry) {
            $session = AssessmentSession::where('assessment_id', $entry->id)->sole();
            $this->assertSame($entry->learner_id, $session->learner_id);
            $this->assertSame($entry->material_id, $session->material_id);
            $this->assertSame($teacher->id, $session->teacher_id);
            $this->assertNotEmpty($session->session_code);
        }
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

    /**
     * The setup form asks for a floor of two - a batch of one is a single-child
     * assessment, which has its own screen. The floor is a parameter rather than
     * a constant because the board, cancel and self-record suites legitimately
     * build one-entry batches straight through the builder.
     */
    public function test_a_roster_below_the_given_minimum_is_rejected(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);

        $errors = $this->errorsOf(
            fn () => app(BatchRosterBuilder::class)->build($teacher, $this->input([$a]), 2)
        );

        $this->assertSame(
            ['Pick at least 2 children for a batch. For one child, use the single-learner assessment.'],
            $errors['entries']
        );
        $this->assertNothingCreated();

        $this->assertCount(2, app(BatchRosterBuilder::class)->build($teacher, $this->input([$a, $b]), 2)->entries);
    }

    public function test_a_single_child_roster_is_allowed_when_no_minimum_is_asked_for(): void
    {
        $teacher = $this->teacher();
        [$a] = $this->learnersOf($teacher);

        $this->assertCount(1, app(BatchRosterBuilder::class)->build($teacher, $this->input([$a]))->entries);
    }

    public function test_the_same_learner_cannot_appear_twice(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        // A hand-crafted post, since the form's own keying makes this unreachable.
        $input = $this->input([$learners[0], $learners[1]]);
        $input['entries']['forged'] = ['learner_id' => $learners[0]->id, 'material_id' => null];

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayHasKey('entries.forged.learner_id', $errors);
        $this->assertArrayNotHasKey('entries.' . $learners[0]->id . '.learner_id', $errors);
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

        $this->assertArrayHasKey('entries.' . $foreign->id . '.learner_id', $errors);
        $this->assertArrayNotHasKey('entries.' . $own->id . '.learner_id', $errors);
        $this->assertNothingCreated();

        // Control: the same learner is accepted for the teacher who does own them.
        $batch = app(BatchRosterBuilder::class)->build($other, $this->input([$foreign]));
        $this->assertCount(1, $batch->entries);
    }

    public function test_the_batch_material_must_match_each_childs_own_grade(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $grade5 = ReadingMaterial::factory()->create(['grade_level' => 5]);

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $this->input([$a, $b], $grade5)));

        $this->assertSame(
            ['The batch material is for Grade 5. This child is in Grade 4 - choose a Grade 4 material for the batch, or give this child their own material.'],
            $errors['entries.' . $a->id . '.material_id']
        );
        $this->assertArrayHasKey('entries.' . $b->id . '.material_id', $errors);
        $this->assertNothingCreated();
    }

    /**
     * There is no cross-grade tick any more. Picking a material for one child IS
     * the deliberate act the tick used to stand for, so an override is free of the
     * grade rule - and free of the old one-grade limit too.
     */
    public function test_a_per_child_override_may_come_from_any_grade(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $grade4 = ReadingMaterial::factory()->create(['grade_level' => 4]);
        $grade1 = ReadingMaterial::factory()->create(['grade_level' => 1]);
        $grade6 = ReadingMaterial::factory()->create(['grade_level' => 6]);

        $input = $this->input([$a, $b], $grade4);
        $input['entries'][$a->id]['material_id'] = $grade1->id;
        $input['entries'][$b->id]['material_id'] = $grade6->id;

        $batch = app(BatchRosterBuilder::class)->build($teacher, $input);

        $byLearner = $batch->entries->keyBy('learner_id');
        $this->assertSame($grade1->id, $byLearner[$a->id]->material_id);
        $this->assertSame($grade6->id, $byLearner[$b->id]->material_id);
    }

    public function test_a_cross_grade_tick_is_no_longer_read_at_all(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $grade5 = ReadingMaterial::factory()->create(['grade_level' => 5]);

        $input = $this->input([$a, $b], $grade5);
        foreach ([$a, $b] as $learner) {
            $input['entries'][$learner->id]['allow_fallback'] = true;
        }

        // The removed checkbox cannot be revived by posting its old name.
        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayHasKey('entries.' . $a->id . '.material_id', $errors);
        $this->assertNothingCreated();
    }

    public function test_a_material_in_another_language_is_rejected(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $fil = ReadingMaterial::factory()->create(['language' => 'fil']);
        $input = $this->input([$a, $b], null, ['language' => 'en']);
        $input['entries'][$b->id]['material_id'] = $fil->id;

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayNotHasKey('entries.' . $a->id . '.material_id', $errors);
        $this->assertSame(['The selected material is not in the chosen language.'], $errors['entries.' . $b->id . '.material_id']);
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
            $input['entries'][$b->id]['material_id'] = $without->id;

            $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

            $this->assertArrayNotHasKey('entries.' . $a->id . '.material_id', $errors);
            $this->assertSame(
                ['Comprehension assessments require a material with comprehension questions.'],
                $errors['entries.' . $b->id . '.material_id']
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
        $input['entries'][$b->id]['material_id'] = $override->id;

        $batch = app(BatchRosterBuilder::class)->build($teacher, $input);

        $byLearner = $batch->entries->keyBy('learner_id');
        $this->assertSame($default->id, $byLearner[$a->id]->material_id);
        $this->assertSame($override->id, $byLearner[$b->id]->material_id);
        $this->assertSame($default->id, $batch->default_material_id);
    }

    public function test_the_class_is_derived_from_the_children_not_taken_from_the_input(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $other = $this->otherTeacher();

        // Even a forged class id is ignored: there is no class input any more.
        $input = $this->input($learners, null, ['school_class_id' => $other->taughtClasses()->first()->id]);

        $batch = app(BatchRosterBuilder::class)->build($teacher, $input);

        $this->assertSame($learners[0]->class_id, $batch->school_class_id);
    }

    public function test_the_class_is_left_blank_when_the_children_span_two_classes(): void
    {
        $teacher = $this->teacher();
        $second = $this->secondClassFor($teacher);
        $mine = $this->learnersOf($teacher)[0];
        $theirs = Learner::where('class_id', $second->id)->first();

        $batch = app(BatchRosterBuilder::class)->build($teacher, $this->input([$mine, $theirs]));

        $this->assertNull($batch->school_class_id);
        $this->assertCount(2, $batch->entries);
    }

    public function test_nothing_is_created_when_one_entry_is_invalid(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $bad = ReadingMaterial::factory()->create(['language' => 'fil']);
        $input = $this->input($learners);
        $input['entries'][$learners[2]->id]['material_id'] = $bad->id; // last one is wrong

        $errors = $this->errorsOf(fn () => app(BatchRosterBuilder::class)->build($teacher, $input));

        $this->assertArrayHasKey('entries.' . $learners[2]->id . '.material_id', $errors);
        $this->assertNothingCreated();
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

    public function test_the_builders_access_rule_matches_the_shared_trait(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true, 'email_verified_at' => now()]);
        $pivot = $this->learnersOf($other)[0];
        $teacher->learners()->attach($pivot->id, ['relationship' => 'teacher']);

        $cases = [
            'pivot-linked learner' => [$teacher, $pivot, true],
            'learner in a class the teacher teaches' => [$teacher, $this->learnersOf($teacher)[0], true],
            'unrelated learner' => [$teacher, $this->learnersOf($other)[1], false],
            'admin acting' => [$admin, $this->learnersOf($other)[1], true],
        ];

        $gate = new class {
            use AuthorizesLearnerAccess;

            public function allows($learner): bool
            {
                try {
                    $this->authorizeLearnerAccess($learner);

                    return true;
                } catch (HttpException $e) {
                    return false;
                }
            }
        };

        foreach ($cases as $name => [$user, $learner, $expected]) {
            $this->actingAs($user);
            $viaTrait = $gate->allows($learner);

            $errors = [];
            try {
                app(BatchRosterBuilder::class)->build($user, $this->input([$learner]));
            } catch (ValidationException $e) {
                $errors = $e->errors();
            }
            $viaBuilder = ! array_key_exists('entries.' . $learner->id . '.learner_id', $errors);

            $this->assertSame($expected, $viaTrait, "trait: $name");
            $this->assertSame($viaTrait, $viaBuilder, "builder disagrees with trait: $name");
        }
    }

    public function test_check_reports_the_same_errors_without_creating_anything(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $grade5 = ReadingMaterial::factory()->create(['grade_level' => 5]);
        $good = $this->input([$a, $b]);
        $bad = $this->input([$a, $b], $grade5);

        $builder = app(BatchRosterBuilder::class);

        $this->assertSame([], $builder->check($teacher, $good, 2));
        $this->assertSame(
            $this->errorsOf(fn () => $builder->build($teacher, $bad, 2)),
            $builder->check($teacher, $bad, 2)
        );
        // Checking is read-only: even the valid roster left nothing behind.
        $this->assertNothingCreated();
    }

    public function test_a_failure_during_creation_rolls_back_the_batch_and_earlier_entries(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $count = 0;
        Assessment::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('boom on the second entry');
            }
        });

        try {
            app(BatchRosterBuilder::class)->build($teacher, $this->input($learners));
            $this->fail('Expected the creation failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom on the second entry', $e->getMessage());
        }

        $this->assertSame(2, $count);
        $this->assertSame(0, AssessmentBatch::count());
        $this->assertSame(0, Assessment::count());
        $this->assertSame(0, AssessmentSession::count());
    }
}
