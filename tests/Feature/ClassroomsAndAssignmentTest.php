<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassroomsAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function section(?User $teacher = null, string $name = 'Rizal', int $grade = 4): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);

        return SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher?->id,
            'grade_level' => $grade, 'section' => $name, 'school_year' => '2026-2027', 'is_active' => true]);
    }

    // ── School page: school information only ──

    public function test_school_page_is_only_school_information(): void
    {
        School::create(['name' => 'Old Sagay ES']);
        $this->section(null, 'Rizal');

        $this->actingAs($this->user('admin'))->get(route('admin.schools'))->assertOk()
            ->assertSee('School Information')
            ->assertSee(route('admin.classes'))
            ->assertDontSee('Add Grade &amp; Section', false)
            ->assertDontSee('Grades &amp; Sections (', false)
            ->assertDontSee('Adviser / Teacher');
    }

    // ── Classrooms page: add, edit, danger zone ──

    public function test_classrooms_page_has_add_edit_and_a_danger_zone_for_every_section(): void
    {
        $this->section(null, 'Rizal');
        $this->section(null, 'Bonifacio', 5);

        $html = $this->actingAs($this->user('admin'))->get(route('admin.classes'))->assertOk()->getContent();

        $this->assertStringContainsString('Add Grade &amp; Section', $html);
        $this->assertSame(2, substr_count($html, 'data-bs-target="#danger'));
        $this->assertSame(2, substr_count($html, 'id="danger'));
        $this->assertSame(2, substr_count($html, 'data-bs-target="#classEdit'));
        $this->assertSame(2, substr_count($html, 'Type <strong>'));
    }

    public function test_admin_adds_a_section_with_an_adviser_and_a_default_school_year(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $this->section(null, 'Existing');   // gives the page a school year to default to

        $this->actingAs($admin)->post(route('admin.classes.store'), [
            'grade_level' => 6, 'section' => 'Diamond', 'teacher_id' => $teacher->id, 'school_year' => '',
        ])->assertRedirect(route('admin.classes'))->assertSessionHas('success');

        $class = SchoolClass::where('section', 'Diamond')->firstOrFail();
        $this->assertSame($teacher->id, $class->teacher_id);
        $this->assertSame('2026-2027', $class->school_year);
    }

    public function test_duplicate_sections_are_refused_whatever_the_case(): void
    {
        $admin = $this->user('admin');
        $this->section(null, 'Rizal');

        $this->actingAs($admin)->post(route('admin.classes.store'), ['grade_level' => 4, 'section' => 'RIZAL', 'school_year' => '2026-2027'])
            ->assertSessionHas('error');
        $this->assertSame(1, SchoolClass::count());

        // same name in another grade is fine
        $this->actingAs($admin)->post(route('admin.classes.store'), ['grade_level' => 5, 'section' => 'Rizal', 'school_year' => '2026-2027'])
            ->assertSessionHas('success');
        $this->assertSame(2, SchoolClass::count());
    }

    public function test_a_teacher_cannot_be_adviser_of_two_sections(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $first = $this->section($teacher, 'Rizal');

        $this->actingAs($admin)->post(route('admin.classes.store'), ['grade_level' => 5, 'section' => 'Mabini', 'teacher_id' => $teacher->id, 'school_year' => '2026-2027'])
            ->assertSessionHas('error');
        $this->assertSame(1, SchoolClass::count());

        $second = $this->section(null, 'Mabini', 5);
        $this->actingAs($admin)->put(route('admin.classes.update', $second), ['grade_level' => 5, 'section' => 'Mabini', 'teacher_id' => $teacher->id, 'school_year' => '2026-2027'])
            ->assertSessionHas('error');
        $this->assertNull($second->fresh()->teacher_id);

        // keeping the adviser a section already has is fine
        $this->actingAs($admin)->put(route('admin.classes.update', $first), ['grade_level' => 4, 'section' => 'Rizal 2', 'teacher_id' => $teacher->id, 'school_year' => '2026-2027'])
            ->assertSessionHas('success');
        $this->assertSame('Rizal 2', $first->fresh()->section);
    }

    public function test_only_a_teacher_can_be_an_adviser(): void
    {
        $parent = $this->user('parent');

        $this->actingAs($this->user('admin'))->post(route('admin.classes.store'), ['grade_level' => 4, 'section' => 'Rizal', 'teacher_id' => $parent->id, 'school_year' => '2026-2027'])
            ->assertSessionHas('error');
        $this->assertSame(0, SchoolClass::count());
    }

    public function test_adviser_dropdown_disables_teachers_who_already_have_a_section(): void
    {
        $busy = $this->user('teacher', ['name' => 'Busy Teacher']);
        $free = $this->user('teacher', ['name' => 'Free Teacher']);
        $this->section($busy, 'Rizal');

        $html = $this->actingAs($this->user('admin'))->get(route('admin.classes'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="' . $busy->id . '"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $free->id . '"[^>]*disabled/', $html);
    }

    public function test_danger_zone_needs_the_section_name_and_keeps_the_learners(): void
    {
        $admin = $this->user('admin');
        $class = $this->section($this->user('teacher'), 'Rizal');
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);

        $this->actingAs($admin)->delete(route('admin.classes.delete', $class), ['confirm' => 'wrong name'])->assertSessionHas('error');
        $this->assertNotNull($class->fresh());

        $this->actingAs($admin)->delete(route('admin.classes.delete', $class), ['confirm' => ' rizal '])
            ->assertRedirect(route('admin.classes'))->assertSessionHas('success');
        $this->assertNull(SchoolClass::find($class->id));
        $this->assertNotNull($learner->fresh());                  // the learner is kept...
        $this->assertNull($learner->fresh()->class_id);           // ...without a section
    }

    public function test_only_admin_can_manage_classrooms(): void
    {
        $class = $this->section(null, 'Rizal');
        $teacher = $this->user('teacher');

        $this->actingAs($teacher)->get(route('admin.classes'))->assertForbidden();
        $this->actingAs($teacher)->post(route('admin.classes.store'), ['grade_level' => 4, 'section' => 'X'])->assertForbidden();
        $this->actingAs($teacher)->delete(route('admin.classes.delete', $class), ['confirm' => 'Rizal'])->assertForbidden();
    }

    // ── One teacher per section when creating, editing and assigning users ──

    public function test_creating_a_teacher_in_a_section_that_has_one_is_refused(): void
    {
        $admin = $this->user('admin');
        $class = $this->section($this->user('teacher'), 'Rizal');

        $this->actingAs($admin)->post(route('admin.users.create'), [
            'name' => 'Second Teacher', 'email' => 'second@example.com', 'role' => 'teacher', 'class_id' => $class->id,
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ])->assertSessionHasErrors('class_id');

        $this->assertNull(User::byEmail('second@example.com')->first());
    }

    public function test_assigning_a_taken_section_to_another_teacher_is_refused_but_a_free_one_works(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('teacher');
        $taken = $this->section($owner, 'Rizal');
        $free = $this->section(null, 'Bonifacio');
        $other = $this->user('teacher');

        $this->actingAs($admin)->put(route('admin.users.update', $other), ['role' => 'teacher', 'class_id' => $taken->id])->assertSessionHas('error');
        $this->assertSame($owner->id, $taken->fresh()->teacher_id);

        $this->actingAs($admin)->put(route('admin.users.update', $other), ['role' => 'teacher', 'class_id' => $free->id])->assertSessionHas('success');
        $this->assertSame($other->id, $free->fresh()->teacher_id);

        // a teacher can keep (re-save) their own section
        $this->actingAs($admin)->put(route('admin.users.update', $owner), ['role' => 'teacher', 'class_id' => $taken->id])->assertSessionHas('success');
        $this->assertSame($owner->id, $taken->fresh()->teacher_id);
    }

    public function test_user_forms_disable_sections_that_already_have_a_teacher(): void
    {
        $this->section($this->user('teacher'), 'Rizal');
        $free = $this->section(null, 'Bonifacio');

        $html = $this->actingAs($this->user('admin'))->get(route('admin.users'))->assertOk()->getContent();

        $this->assertStringContainsString('has a teacher', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $free->id . '"\s*>/', $html);
    }

    // ── A teacher without a section cannot add learners or conduct assessments ──

    public function test_unassigned_teacher_cannot_reach_add_learner_or_assessment_routes(): void
    {
        $teacher = $this->user('teacher');
        $learner = Learner::factory()->create();

        $blocked = [
            ['get', route('learners.create')],
            ['post', route('learners.store')],
            ['post', route('learners.import.preview')],
            ['post', route('learners.import.confirm')],
            ['get', route('assessments.create')],
            ['get', route('assessments.start', $learner)],
            ['post', route('assessments.store')],
        ];
        foreach ($blocked as [$method, $url]) {
            $this->actingAs($teacher)->{$method}($url)
                ->assertRedirect(route('dashboard'))
                ->assertSessionHas('error', \App\Http\Middleware\EnsureTeacherAssigned::MESSAGE);
        }
        $this->assertSame(1, Learner::count());   // nothing was created
    }

    public function test_unassigned_teacher_is_blocked_on_the_api_too(): void
    {
        Sanctum::actingAs($this->user('teacher'), ['*']);

        $this->postJson('/api/learners', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'grade_level' => 4])->assertForbidden()
            ->assertJson(['success' => false]);
        $this->postJson('/api/assessments', [])->assertForbidden();
    }

    public function test_unassigned_teacher_sees_a_notice_and_no_add_buttons(): void
    {
        $teacher = $this->user('teacher');

        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertSee('Waiting for your grade &amp; section', false)
            ->assertDontSee(route('learners.create'))
            ->assertDontSee(route('assessments.create'));
        $this->actingAs($teacher)->get(route('learners.index'))->assertOk()
            ->assertSee('Waiting for your grade &amp; section', false)->assertDontSee('Add Learner');
        $this->actingAs($teacher)->get(route('assessments.index'))->assertOk()
            ->assertSee('Waiting for your grade &amp; section', false)->assertDontSee('New Assessment');
    }

    public function test_assignment_turns_everything_on(): void
    {
        $teacher = $this->user('teacher');
        $this->section($teacher, 'Rizal');

        $this->actingAs($teacher)->get(route('learners.create'))->assertOk();
        $this->actingAs($teacher)->get(route('assessments.create'))->assertOk();
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Waiting for your grade')->assertSee(route('learners.create'));
        $this->actingAs($teacher)->get(route('learners.index'))->assertSee('Add Learner');
    }

    public function test_admin_and_parent_are_not_affected_by_the_gate(): void
    {
        $this->actingAs($this->user('parent'))->get(route('dashboard'))->assertRedirect();          // parent portal, not blocked with the notice
        $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk()->assertDontSee('Waiting for your grade');
    }
}
