<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudentPortalContainerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function teacherWithLearners(int $n = 3): array
    {
        $teacher = $this->user('teacher');
        $school = School::create(['name' => 'Old Sagay ES']);
        $class = SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);
        $learners = Learner::factory()->count($n)->create(['class_id' => $class->id, 'grade_level' => 4]);

        return [$teacher, $learners];
    }

    private function badge(array $overrides = []): Badge
    {
        return Badge::create($overrides + ['slug' => 'b-' . uniqid(), 'name' => 'Starter', 'description' => 'First step',
            'icon' => '🏅', 'color' => '#6C63FF', 'category' => 'milestone', 'xp_reward' => 10,
            'criteria' => ['type' => 'assessment_count', 'value' => 1], 'sort_order' => 1, 'is_active' => true]);
    }

    public function test_old_admin_pages_are_gone(): void
    {
        foreach (['admin.learner-portal', 'admin.learner-portal.reset-xp', 'admin.badges', 'admin.badges.store', 'admin.badges.toggle'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} should be removed");
        }
    }

    public function test_teacher_sees_portal_container_on_the_learners_page_without_repeating_list_data(): void
    {
        [$teacher, $learners] = $this->teacherWithLearners();
        $learners[0]->total_xp = 250;
        $learners[0]->current_streak = 4;
        $learners[0]->save();

        $html = $this->actingAs($teacher)->get(route('learners.index'))->assertOk()
            ->assertSee('id="student-portal"', false)->assertSee('Student Portal')->assertSee('250')
            ->assertSee(route('learners.reset-xp', $learners[0]))->getContent();

        // The container header carries only portal data: no section/LRN/reading-level columns of its own.
        $container = substr($html, strpos($html, 'id="student-portal"'));
        $this->assertStringNotContainsString('Reading Level', $container);
        $this->assertStringNotContainsString('Grade &amp; Section', $container);
        $this->assertStringNotContainsString('LRN', $container);
    }

    public function test_activity_tab_shows_plain_pins_to_the_teacher_only(): void
    {
        [$teacher, $learners] = $this->teacherWithLearners(2);
        $learners[0]->pin = '482915';
        $learners[0]->save();

        $this->actingAs($teacher)->get(route('learners.index'))->assertOk()
            ->assertSee('<th>PIN</th>', false)->assertSee('482915');

        $this->actingAs($this->user('admin'))->get(route('learners.index'))->assertOk()->assertDontSee('482915');
        $this->actingAs($this->user('admin'))->get(route('learners.show', $learners[0]))->assertOk()->assertDontSee('482915');
    }

    public function test_admin_does_not_get_the_portal_container(): void
    {
        $this->teacherWithLearners();
        $this->actingAs($this->user('admin'))->get(route('learners.index'))->assertOk()
            ->assertDontSee('id="student-portal"', false)->assertDontSee('Reset XP');
    }

    public function test_teacher_resets_xp_only_for_their_own_learner(): void
    {
        [$teacher, $learners] = $this->teacherWithLearners(1);
        $l = $learners[0];
        $l->total_xp = 400;
        $l->current_streak = 5;
        $l->longest_streak = 9;
        $l->save();

        $this->actingAs($this->user('teacher'))->post(route('learners.reset-xp', $l))->assertForbidden();
        $this->actingAs($this->user('admin'))->post(route('learners.reset-xp', $l))->assertForbidden();
        $this->assertSame(400, $l->fresh()->total_xp);

        $this->actingAs($teacher)->post(route('learners.reset-xp', $l))->assertRedirect();
        $fresh = $l->fresh();
        $this->assertSame([0, 0, 0], [$fresh->total_xp, $fresh->current_streak, $fresh->longest_streak]);
        $this->assertNull($fresh->last_activity_date);
    }

    public function test_badges_tab_lists_and_paginates_badges_with_plain_language_rules(): void
    {
        [$teacher] = $this->teacherWithLearners(1);
        foreach (range(1, 12) as $i) {
            $this->badge(['name' => "Badge {$i}", 'sort_order' => $i, 'criteria' => ['type' => 'streak_days', 'value' => 3]]);
        }

        $this->actingAs($teacher)->get(route('learners.index', ['portal_tab' => 'badges']))->assertOk()
            ->assertSee('Keeps a daily streak: 3 days in a row')
            ->assertSee('Showing 1–10 of 12')
            ->assertSee('Add Badge');
    }

    public function test_teacher_adds_edits_and_toggles_badges(): void
    {
        [$teacher] = $this->teacherWithLearners(1);

        $this->actingAs($teacher)->post(route('badges.store'), [
            'name' => 'Streak Star', 'description' => 'Reads 3 days in a row', 'icon' => '🔥', 'category' => 'streak',
            'xp_reward' => 30, 'criteria_type' => 'streak_days', 'criteria_value' => 3,
        ])->assertRedirect();
        $this->actingAs($teacher)->post(route('badges.store'), [   // same name -> unique slug
            'name' => 'Streak Star', 'description' => 'Again', 'icon' => '⭐', 'category' => 'streak',
            'xp_reward' => 5, 'criteria_type' => 'perfect_score',
        ])->assertRedirect();

        $badges = Badge::orderBy('id')->get();
        $this->assertSame(['streak-star', 'streak-star-2'], $badges->pluck('slug')->all());
        $this->assertSame(['type' => 'streak_days', 'value' => 3], $badges[0]->criteria);
        $this->assertSame(['type' => 'perfect_score'], $badges[1]->criteria);   // no number for this rule

        $this->actingAs($teacher)->put(route('badges.update', $badges[0]), [
            'name' => 'Streak Hero', 'description' => 'Updated', 'icon' => '🔥', 'xp_reward' => 40,
        ])->assertRedirect();
        $this->assertSame('Streak Hero', $badges[0]->fresh()->name);
        $this->assertSame(40, $badges[0]->fresh()->xp_reward);

        $this->actingAs($teacher)->post(route('badges.toggle', $badges[0]))->assertRedirect();
        $this->assertFalse($badges[0]->fresh()->is_active);
    }

    public function test_badge_rules_are_validated_and_admin_cannot_manage_badges(): void
    {
        [$teacher] = $this->teacherWithLearners(1);
        $base = ['name' => 'X', 'description' => 'Y', 'icon' => '🏅', 'category' => 'streak', 'xp_reward' => 5];

        $this->actingAs($teacher)->post(route('badges.store'), $base + ['criteria_type' => 'streak_days'])      // needs a number
            ->assertSessionHasErrors('criteria_value');
        $this->actingAs($teacher)->post(route('badges.store'), $base + ['criteria_type' => 'hack'])
            ->assertSessionHasErrors('criteria_type');
        $this->assertSame(0, Badge::count());

        $badge = $this->badge();
        $admin = $this->user('admin');
        $this->actingAs($admin)->post(route('badges.store'), $base + ['criteria_type' => 'perfect_score'])->assertForbidden();
        $this->actingAs($admin)->put(route('badges.update', $badge), $base + ['xp_reward' => 1])->assertForbidden();
        $this->actingAs($admin)->post(route('badges.toggle', $badge))->assertForbidden();
    }
}
