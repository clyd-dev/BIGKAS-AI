<?php

namespace Tests\Feature;

use App\Models\ClassReport;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    }

    public function test_one_dashboard_with_only_the_essentials(): void
    {
        School::create(['name' => 'Old Sagay ES']);

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs your attention')
            ->assertSee('Recent Activity')
            ->assertSee('School-Wide Reading Weaknesses')
            ->assertSee('Old Sagay ES')
            ->assertSee(route('admin.users'))
            ->assertDontSee('Reading Level Distribution')
            ->assertDontSee('Assessments This Month')
            ->assertDontSee('Recent Assessments');
    }

    public function test_admin_panel_url_redirects_and_sidebar_has_no_second_dashboard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertDontSee('Admin Panel')
            ->assertDontSee('Learner Portal')   // moved to the teacher's Learners page
            ->assertDontSee('Badges');
    }

    public function test_pending_teacher_reports_are_counted(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);
        $school = School::create(['name' => 'Old Sagay ES']);
        $class = SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);
        ClassReport::create(['class_id' => $class->id, 'teacher_id' => $teacher->id, 'school_year' => '2026-2027',
            'period' => 'pre_test', 'status' => 'submitted', 'snapshot' => [], 'submitted_at' => now()]);

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->assertSee('Teacher reports waiting for review');
    }

    public function test_other_admin_pages_link_back_to_the_dashboard(): void
    {
        $this->actingAs($this->admin())->get(route('admin.users'))->assertOk()->assertSee(route('dashboard'));
    }
}
