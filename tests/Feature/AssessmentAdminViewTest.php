<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentAdminViewTest extends TestCase
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

    private function assessment(User $teacher, string $status = 'completed'): Assessment
    {
        $learner = Learner::factory()->create();

        return Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => ReadingMaterial::firstOrCreate(['title' => 'Test'], ['content' => 'Test text.'])->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'status' => $status,
        ]);
    }

    public function test_admin_main_page_lists_learners_without_assessor(): void
    {
        $teacher = $this->user('teacher');
        $assessment = $this->assessment($teacher);

        $this->actingAs($this->user('admin'))->get(route('assessments.index'))
            ->assertOk()
            ->assertSee($assessment->learner->getFullName())
            ->assertSee('Summary of Activity')
            ->assertSee('View History')
            ->assertDontSee($teacher->name)
            ->assertDontSee('New Assessment');
    }

    public function test_admin_history_shows_assessor_and_result_link(): void
    {
        $teacher = $this->user('teacher');
        $assessment = $this->assessment($teacher);

        $this->actingAs($this->user('admin'))
            ->get(route('assessments.learner-history', $assessment->learner))
            ->assertOk()
            ->assertSee($teacher->name)
            ->assertSee(route('assessments.results', $assessment));
    }

    public function test_admin_cannot_conduct_assessments(): void
    {
        $admin = $this->user('admin');
        $assessment = $this->assessment($this->user('teacher'));

        $this->actingAs($admin)->get(route('assessments.create'))->assertForbidden();
        $this->actingAs($admin)->get(route('assessments.start', $assessment->learner))->assertForbidden();
        $this->actingAs($admin)->post(route('assessments.store'), [])->assertForbidden();
        $this->actingAs($admin)->get(route('assessments.show', $assessment))->assertForbidden();
        $this->actingAs($admin)->post(route('assessments.analyze', $assessment))->assertForbidden();
    }

    public function test_main_and_history_pages_paginate_ten_per_page(): void
    {
        $teacher = $this->user('teacher');
        $admin = $this->user('admin');

        for ($i = 0; $i < 12; $i++) {
            $this->assessment($teacher); // 12 learners, one assessment each
        }
        $this->actingAs($admin)->get(route('assessments.index'))
            ->assertOk()
            ->assertSee('Showing 1–10 of 12');

        $learner = Learner::factory()->create();
        $material = ReadingMaterial::first();
        for ($i = 0; $i < 11; $i++) {
            Assessment::create([
                'learner_id' => $learner->id,
                'material_id' => $material->id,
                'assessor_id' => $teacher->id,
                'language' => 'en',
                'status' => 'completed',
            ]);
        }
        $this->actingAs($admin)->get(route('assessments.learner-history', $learner))
            ->assertOk()
            ->assertSee('Showing 1–10 of 11');
    }

    public function test_teacher_list_still_renders(): void
    {
        $teacher = $this->user('teacher');
        $school = \App\Models\School::create(['name' => 'Old Sagay ES']);
        \App\Models\SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);

        $this->actingAs($teacher)->get(route('assessments.index'))
            ->assertOk()
            ->assertSee('New Assessment');
    }
}
