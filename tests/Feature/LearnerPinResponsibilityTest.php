<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LearnerPinResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function teacherWithSection(): array
    {
        $teacher = $this->user('teacher');
        $school = School::create(['name' => 'Old Sagay ES']);
        $class = SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);

        return [$teacher, $class];
    }

    public function test_adding_a_learner_issues_a_pin_the_teacher_can_see(): void
    {
        [$teacher] = $this->teacherWithSection();

        $response = $this->actingAs($teacher)->post(route('learners.store'), ['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $learner = Learner::all()->firstWhere('last_name', 'Cruz');
        $pin = $learner->pin;

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);       // readable through the model
        $this->assertNotNull($learner->pin_created_at);
        $this->assertTrue($learner->checkPin($pin));
        $this->assertNotSame($pin, $learner->getRawOriginal('pin'));    // encrypted in the database

        $response->assertRedirect(route('learners.show', $learner));
        $this->actingAs($teacher)->get(route('learners.show', $learner))
            ->assertOk()->assertSee('Student Portal PIN')->assertSee($pin);
    }

    public function test_teacher_can_issue_a_new_pin_and_the_old_one_stops_working(): void
    {
        [$teacher, $class] = $this->teacherWithSection();
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $learner->pin = '482915';
        $learner->pin_created_at = now()->subDays(3);
        $learner->save();

        $this->actingAs($teacher)->post(route('learners.generate-pin', $learner))->assertRedirect();

        $learner->refresh();
        $this->assertNotSame('482915', $learner->pin);
        $this->assertTrue($learner->checkPin($learner->pin));
        $this->assertFalse($learner->checkPin('482915'));
        $this->actingAs($teacher)->get(route('learners.show', $learner))->assertSee($learner->pin);
    }

    public function test_only_the_learners_own_teacher_can_issue_a_pin(): void
    {
        [, $class] = $this->teacherWithSection();
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);

        $this->actingAs($this->user('teacher'))->post(route('learners.generate-pin', $learner))->assertForbidden();
        $this->actingAs($this->user('parent'))->post(route('learners.generate-pin', $learner))->assertForbidden();
    }

    public function test_admin_cannot_generate_pins_and_no_longer_sees_pin_controls(): void
    {
        [, $class] = $this->teacherWithSection();
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $admin = $this->user('admin');
        $before = $learner->getRawOriginal('pin');

        $this->actingAs($admin)->post(route('learners.generate-pin', $learner))->assertForbidden();
        $this->assertFalse(Route::has('admin.learner-portal.generate-pin'));
        $this->assertSame($before, $learner->fresh()->getRawOriginal('pin'));   // untouched

        $this->actingAs($admin)->get(route('learners.show', $learner))->assertOk()
            ->assertDontSee('Student Portal PIN')->assertDontSee('Generate PIN');

        $this->assertFalse(Route::has('admin.learner-portal'));
    }
}
