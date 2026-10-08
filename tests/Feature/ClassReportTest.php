<?php

namespace Tests\Feature;

use App\Models\ClassReport;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassReportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function section(User $teacher): SchoolClass
    {
        $school = School::create(['name' => 'Test ES']);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'grade_level' => 4,
            'section' => 'Rizal',
            'school_year' => '2026-2027',
            'is_active' => true,
        ]);
        Learner::factory()->count(3)->create(['class_id' => $class->id, 'grade_level' => 4]);

        return $class;
    }

    public function test_admin_cannot_use_practice_center(): void
    {
        $this->actingAs($this->user('admin'))->get(route('practice.index'))->assertForbidden();
        $this->actingAs($this->user('teacher'))->get(route('practice.index'))->assertOk();
    }

    public function test_admin_overview_lists_sections_not_learners(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);

        $this->actingAs($this->user('admin'))->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Section Reports')
            ->assertSee('Grade 4 – Rizal')
            ->assertSee($teacher->name);
    }

    public function test_teacher_submits_report_and_cannot_duplicate(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);

        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test', 'teacher_note' => 'Hello'])
            ->assertRedirect();

        $report = ClassReport::firstOrFail();
        $this->assertSame('submitted', $report->status);
        $this->assertSame(3, $report->snapshot['enrolment']);
        $this->assertArrayHasKey('fil', $report->snapshot['languages']);

        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test'])
            ->assertSessionHas('error');
        $this->assertSame(1, ClassReport::count());
    }

    public function test_admin_inbox_shows_not_submitted_and_can_review(): void
    {
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('reports.submissions.index'))
            ->assertOk()->assertSee('Not submitted')->assertSee('Waiting for teacher');

        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test']);
        $report = ClassReport::firstOrFail();

        $this->actingAs($admin)->post(route('reports.submissions.review', $report), ['action' => 'returned'])
            ->assertSessionHasErrors('principal_comment');

        $this->actingAs($admin)->post(route('reports.submissions.review', $report), ['action' => 'returned', 'principal_comment' => 'Please reassess'])
            ->assertRedirect();
        $this->assertSame('returned', $report->fresh()->status);

        // Returned reports can be resubmitted.
        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test'])->assertRedirect();
        $this->assertSame(2, ClassReport::count());
    }

    public function test_access_rules(): void
    {
        $teacher = $this->user('teacher');
        $other = $this->user('teacher');
        $class = $this->section($teacher);
        $this->actingAs($teacher)->post(route('reports.submissions.store'), ['period' => 'pre_test']);
        $report = ClassReport::firstOrFail();

        $this->actingAs($other)->get(route('reports.submissions.show', $report))->assertForbidden();
        $this->actingAs($teacher)->get(route('reports.submissions.show', $report))->assertOk();
        $this->actingAs($teacher)->post(route('reports.submissions.review', $report), ['action' => 'reviewed'])->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('reports.submissions.create'))->assertForbidden();
    }
}
