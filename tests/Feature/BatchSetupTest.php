<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * The setup form as the teacher asked for it after using the first one for real:
 * no class input (her class is derived), a checkbox roster instead of "Add child"
 * plus a name dropdown, a material grade that follows the children, and a summary
 * she confirms before anything exists.
 */
class BatchSetupTest extends TestCase
{
    use RefreshDatabase, MakesBatchAssessments;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    private function learnersOf(User $teacher): array
    {
        $classIds = $teacher->taughtClasses()->pluck('id');

        return Learner::whereIn('class_id', $classIds)->orderBy('id')->get()->all();
    }

    /** A second (or first) class for this teacher, with learners of the given grade. */
    private function classFor(User $teacher, int $grade, string $section, int $learners = 2): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Batch School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => $grade, 'section' => $section, 'school_year' => '2026-2027',
        ]);
        Learner::factory()->count($learners)->create(['class_id' => $class->id, 'grade_level' => $grade]);

        return $class;
    }

    /** A teacher with no class of the shared fixture's making, so the grade is ours to choose. */
    private function bareTeacher(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TEACHER, 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    /**
     * What the redesigned form posts: entries keyed by learner id, so the same
     * child cannot be in the roster twice and an error can only ever land on the
     * child it belongs to.
     */
    private function payload(array $learners, ReadingMaterial $default, array $overrides = []): array
    {
        $entries = [];
        foreach ($learners as $learner) {
            $entries[$learner->id] = [
                'learner_id' => $learner->id,
                'material_id' => $overrides[$learner->id] ?? '',
            ];
        }

        return [
            'title' => 'Morning group',
            'language' => 'en',
            'assessment_type' => 'oral_reading',
            'default_material_id' => $default->id,
            'entries' => $entries,
        ];
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, AssessmentBatch::count(), 'a batch exists');
        $this->assertSame(0, Assessment::count(), 'an assessment exists');
        $this->assertSame(0, AssessmentSession::count(), 'a session exists');
    }

    // ---------------------------------------------------------------- the roster

    public function test_the_roster_lists_only_learners_the_teacher_can_reach(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $mine = $this->learnersOf($teacher)[0];
        $mine->update(['first_name' => 'Reachable', 'last_name' => 'Zebedee']);
        $theirs = $this->learnersOf($other)[0];
        $theirs->update(['first_name' => 'Unreachable', 'last_name' => 'Quimby']);

        // Same school proves nothing: access is per class taught or per pivot row.
        $this->assertFalse($teacher->taughtClasses()->where('id', $theirs->class_id)->exists());
        $this->assertFalse($teacher->learners()->where('learners.id', $theirs->id)->exists());

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))
            ->assertOk()
            ->assertSee('Reachable')
            ->assertDontSee('Unreachable')
            ->assertSee('Assessments')
            ->getContent();

        // A checkbox per child, not a dropdown of names and not an "Add child" button.
        $this->assertStringContainsString('name="entries[' . $mine->id . '][learner_id]"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString('Add child', $html);
        $this->assertStringNotContainsString('id="addEntry"', $html);
        $this->assertStringNotContainsString('entries[__INDEX__]', $html);
    }

    public function test_the_form_has_no_class_input(): void
    {
        $teacher = $this->teacher();

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="school_class_id"', $html);
        $this->assertStringNotContainsString('id="school_class_id"', $html);
    }

    public function test_a_two_section_teacher_sees_both_classes_grouped_by_section(): void
    {
        $teacher = $this->bareTeacher();
        $this->classFor($teacher, 4, 'Mabini');
        $this->classFor($teacher, 4, 'Rizal');
        $mabini = Learner::whereRelation('schoolClass', 'section', 'Mabini')->first();
        $rizal = Learner::whereRelation('schoolClass', 'section', 'Rizal')->first();

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Grade 4 - Mabini', $html);
        $this->assertStringContainsString('Grade 4 - Rizal', $html);
        $this->assertStringContainsString('name="entries[' . $mabini->id . '][learner_id]"', $html);
        $this->assertStringContainsString('name="entries[' . $rizal->id . '][learner_id]"', $html);
        // Still no picker: both sections are hers, so she may assess across them.
        $this->assertStringNotContainsString('name="school_class_id"', $html);
    }

    public function test_a_one_class_teacher_sees_a_plain_list_with_no_section_heading(): void
    {
        $teacher = $this->teacher();

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Grade 4 - Batch A', $html);
    }

    // --------------------------------------------------------- the material grade

    public function test_the_material_grade_defaults_to_the_childrens_grade(): void
    {
        $teacher = $this->bareTeacher();
        $this->classFor($teacher, 3, 'Sampaguita');

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="3" selected>Grade 3</option>', $html);
        $this->assertStringNotContainsString('<option value="4" selected>', $html);
    }

    public function test_children_spanning_two_grades_are_named_and_nothing_is_preselected(): void
    {
        $teacher = $this->bareTeacher();
        $this->classFor($teacher, 3, 'Sampaguita');
        $this->classFor($teacher, 5, 'Ilang-ilang');

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();

        // Honest about the ambiguity: it says which grades, and makes her choose.
        $this->assertStringContainsString('Grade 3 and Grade 5', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+" selected>Grade/', $html);
    }

    public function test_a_batch_material_from_the_wrong_grade_is_flagged_on_each_affected_child(): void
    {
        $teacher = $this->bareTeacher();
        $this->classFor($teacher, 3, 'Sampaguita');
        $this->classFor($teacher, 5, 'Ilang-ilang');
        $grade3 = $this->learnersOf($teacher);
        $grade3 = array_values(array_filter($grade3, fn ($l) => $l->grade_level === 3));
        $grade5 = array_values(array_filter($this->learnersOf($teacher), fn ($l) => $l->grade_level === 5));
        $material = ReadingMaterial::factory()->create(['grade_level' => 3]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload([$grade3[0], $grade5[0]], $material))
            ->assertRedirect(route('assessments.batch.create'))
            ->assertSessionHasErrors(['entries.' . $grade5[0]->id . '.material_id'])
            ->assertSessionDoesntHaveErrors(['entries.' . $grade3[0]->id . '.material_id']);

        $this->assertNothingCreated();

        // Giving that child their own material is the deliberate act that clears it.
        $grade5Material = ReadingMaterial::factory()->create(['grade_level' => 5]);
        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload(
                [$grade3[0], $grade5[0]], $material, [$grade5[0]->id => $grade5Material->id]
            ))
            ->assertOk();
    }

    // ------------------------------------------------------------- the roster size

    public function test_one_checked_child_is_refused_and_two_are_accepted(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);
        $material = ReadingMaterial::factory()->create();

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload([$learners[0]], $material))
            ->assertRedirect(route('assessments.batch.create'))
            ->assertSessionHasErrors(['entries']);
        $this->assertNothingCreated();

        // And the confirm action refuses it too, so the summary cannot be bypassed.
        $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload([$learners[0]], $material))
            ->assertSessionHasErrors(['entries']);
        $this->assertNothingCreated();

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload([$learners[0], $learners[1]], $material))
            ->assertOk()
            ->assertSessionHasNoErrors();
        $this->assertNothingCreated();
    }

    public function test_no_checked_child_is_refused(): void
    {
        $teacher = $this->teacher();
        $payload = $this->payload($this->learnersOf($teacher), ReadingMaterial::factory()->create());
        // Every row posted with its material but nothing ticked, exactly as an
        // untouched form would send it.
        $payload['entries'] = array_map(fn ($e) => ['material_id' => ''], $payload['entries']);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $payload)
            ->assertSessionHasErrors(['entries']);
        $this->assertNothingCreated();
    }

    public function test_sixteen_checked_children_are_refused(): void
    {
        $teacher = $this->teacher();
        $classId = $teacher->taughtClasses()->first()->id;
        Learner::factory()->count(13)->create(['class_id' => $classId, 'grade_level' => 4]);
        $learners = $this->learnersOf($teacher);
        $this->assertCount(16, $learners);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload($learners, ReadingMaterial::factory()->create()))
            ->assertSessionHasErrors(['entries']);
        $this->assertNothingCreated();
    }

    public function test_fifteen_checked_children_are_accepted(): void
    {
        $teacher = $this->teacher();
        $classId = $teacher->taughtClasses()->first()->id;
        Learner::factory()->count(12)->create(['class_id' => $classId, 'grade_level' => 4]);
        $learners = $this->learnersOf($teacher);
        $this->assertCount(15, $learners);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload($learners, ReadingMaterial::factory()->create()))
            ->assertSessionHasNoErrors();

        $this->assertSame(15, Assessment::count());
    }

    public function test_the_form_tells_the_teacher_the_floor_and_the_cap(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get(route('assessments.batch.create'))
            ->assertOk()
            ->assertSee('at least 2')
            ->assertSee('at most ' . config('assessment.batch_max_learners'));
    }

    // ---------------------------------------------------------- authorisation

    public function test_another_teachers_learner_cannot_be_forced_through_by_posting_its_id(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $mine = $this->learnersOf($teacher)[0];
        $theirs = $this->learnersOf($other)[0];

        $this->assertFalse($teacher->taughtClasses()->where('id', $theirs->class_id)->exists());
        $this->assertFalse($teacher->learners()->where('learners.id', $theirs->id)->exists());

        foreach (['assessments.batch.review', 'assessments.batch.store'] as $route) {
            $this->actingAs($teacher)
                ->post(route($route), $this->payload([$mine, $theirs], ReadingMaterial::factory()->create()))
                ->assertSessionHasErrors(['entries.' . $theirs->id . '.learner_id']);
            $this->assertNothingCreated();
        }
    }

    public function test_an_admin_cannot_create_a_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        $teacher = $this->teacher();
        $payload = $this->payload($this->learnersOf($teacher), ReadingMaterial::factory()->create());

        $this->actingAs($admin)->get(route('assessments.batch.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('assessments.batch.review'), $payload)->assertForbidden();
        $this->actingAs($admin)->post(route('assessments.batch.store'), $payload)->assertForbidden();
        $this->assertNothingCreated();
    }

    // ------------------------------------------------------------- the summary

    public function test_the_summary_shows_what_will_be_created_and_creates_nothing(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $a->update(['first_name' => 'Aurelio', 'last_name' => 'Bautista']);
        $b->update(['first_name' => 'Benilda', 'last_name' => 'Cruz']);
        $default = ReadingMaterial::factory()->create(['title' => 'The Brown Dog', 'grade_level' => 4]);
        $override = ReadingMaterial::factory()->create(['title' => 'A Harder Passage', 'grade_level' => 5]);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload([$a, $b], $default, [$b->id => $override->id]))
            ->assertOk()
            ->assertSee('2 children')
            ->assertSee('Aurelio')
            ->assertSee('Benilda')
            ->assertSee('The Brown Dog')
            ->assertSee('A Harder Passage')
            ->assertSee('Grade 4')
            ->assertSee('Grade 5');

        $this->assertNothingCreated();
    }

    public function test_the_summary_carries_the_selection_forward_for_confirmation(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $default = ReadingMaterial::factory()->create();

        $html = $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload([$a, $b], $default))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="' . route('assessments.batch.store') . '"', $html);
        $this->assertStringContainsString('name="entries[' . $a->id . '][learner_id]"', $html);
        $this->assertStringContainsString('name="entries[' . $b->id . '][learner_id]"', $html);
        $this->assertStringContainsString('formaction="' . route('assessments.batch.review') . '"', $html);
    }

    public function test_backing_out_of_the_summary_leaves_nothing_and_keeps_the_selection(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $default = ReadingMaterial::factory()->create();
        $override = ReadingMaterial::factory()->create();
        $payload = array_merge(
            $this->payload([$a, $b], $default, [$b->id => $override->id]),
            ['edit' => '1']
        );

        $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $payload)
            ->assertRedirect(route('assessments.batch.create'))
            ->assertSessionHasNoErrors();

        $this->assertNothingCreated();

        $old = session()->getOldInput();
        $this->assertSame('Morning group', $old['title']);
        $this->assertSame($default->id, (int) $old['default_material_id']);
        $this->assertSame([$a->id, $b->id], array_keys($old['entries']));
        $this->assertSame((string) $override->id, (string) $old['entries'][$b->id]['material_id']);

        // The form comes back with those children ticked and that override set.
        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/name="entries\[' . $a->id . '\]\[learner_id\]"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('value="' . $override->id . '" selected', $html);
    }

    public function test_confirming_the_summary_creates_the_batch_and_one_session_per_entry(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $default = ReadingMaterial::factory()->create();

        $response = $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload([$a, $b], $default));

        $batch = AssessmentBatch::firstOrFail();
        $response->assertRedirect(route('assessments.batch.board', $batch));
        $this->assertSame($teacher->id, $batch->teacher_id);
        $this->assertSame(AssessmentBatch::STATUS_COLLECTING, $batch->status);
        $this->assertSame($default->id, $batch->default_material_id);
        $this->assertSame(2, Assessment::where('batch_id', $batch->id)->count());

        foreach (Assessment::where('batch_id', $batch->id)->get() as $entry) {
            $this->assertSame(1, AssessmentSession::where('assessment_id', $entry->id)->count());
            $this->assertSame(Assessment::STATUS_PENDING, $entry->status);
        }
        $this->assertSame(2, AssessmentSession::count());
    }

    public function test_the_class_is_derived_when_every_child_shares_one(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload([$a, $b], ReadingMaterial::factory()->create()));

        $this->assertSame($a->class_id, AssessmentBatch::firstOrFail()->school_class_id);
    }

    public function test_the_class_is_left_blank_when_the_children_come_from_two_sections(): void
    {
        $teacher = $this->bareTeacher();
        $this->classFor($teacher, 4, 'Mabini');
        $this->classFor($teacher, 4, 'Rizal');
        $mabini = Learner::whereRelation('schoolClass', 'section', 'Mabini')->first();
        $rizal = Learner::whereRelation('schoolClass', 'section', 'Rizal')->first();

        $this->actingAs($teacher)->post(
            route('assessments.batch.store'),
            $this->payload([$mabini, $rizal], ReadingMaterial::factory()->create())
        );

        $this->assertNull(AssessmentBatch::firstOrFail()->school_class_id);
        $this->assertSame(2, Assessment::count());
    }

    public function test_a_per_child_override_is_honoured_through_the_form(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $default = ReadingMaterial::factory()->create();
        $override = ReadingMaterial::factory()->create();

        $this->actingAs($teacher)->post(
            route('assessments.batch.store'),
            $this->payload([$a, $b], $default, [$b->id => $override->id])
        );

        $entries = Assessment::whereNotNull('batch_id')->get()->keyBy('learner_id');
        $this->assertSame($default->id, $entries[$a->id]->material_id);
        $this->assertSame($override->id, $entries[$b->id]->material_id);
    }

    public function test_the_board_renders_a_batch_created_through_the_new_form(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $a->update(['first_name' => 'Aurelio', 'last_name' => 'Bautista']);
        $default = ReadingMaterial::factory()->create(['title' => 'The Brown Dog']);

        $this->actingAs($teacher)
            ->post(route('assessments.batch.store'), $this->payload([$a, $b], $default));

        $batch = AssessmentBatch::firstOrFail();
        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('Aurelio')
            ->assertSee('The Brown Dog');
    }

    public function test_a_name_with_an_apostrophe_is_safe_on_the_roster_and_the_summary(): void
    {
        $teacher = $this->teacher();
        [$a, $b] = $this->learnersOf($teacher);
        $a->update(['first_name' => "Shaun", 'last_name' => "O'Brien"]);

        $roster = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();
        $summary = $this->actingAs($teacher)
            ->post(route('assessments.batch.review'), $this->payload([$a, $b], ReadingMaterial::factory()->create()))
            ->assertOk()
            ->getContent();

        foreach (['roster' => $roster, 'summary' => $summary] as $where => $html) {
            $this->assertStringContainsString('O&#039;Brien', $html, $where);
            // No inline handler can be broken by the name because there are none.
            $this->assertStringNotContainsString('onclick', $html, $where);
            $this->assertStringNotContainsString("O'Brien", $html, $where);
        }
    }

    // --------------------------------------------------- the removed checkbox

    public function test_the_cross_grade_checkbox_is_gone(): void
    {
        $teacher = $this->teacher();

        $html = $this->actingAs($teacher)->get(route('assessments.batch.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('allow_fallback', $html);
        $this->assertStringNotContainsString('another grade', $html);
    }

    public function test_the_group_link_is_hidden_on_the_single_learner_page_when_the_flag_is_off(): void
    {
        $teacher = $this->teacher();
        $link = route('assessments.batch.create');

        $this->actingAs($teacher)->get(route('assessments.create'))->assertOk()->assertSee($link, false);

        config(['assessment.batch_enabled' => false]);
        $this->actingAs($teacher)->get(route('assessments.create'))->assertOk()->assertDontSee($link, false);
    }
}
