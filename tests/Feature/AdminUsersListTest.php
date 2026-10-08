<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUsersListTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    public function test_users_list_is_paginated_ten_per_page_with_the_large_pager(): void
    {
        $admin = $this->user('admin');
        User::factory()->count(14)->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);   // 15 users in all

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()
            ->assertSee('Showing 1–10 of 15')
            ->assertSee('bigkas-pager', false);
        $this->actingAs($admin)->get(route('admin.users', ['page' => 2]))->assertOk()
            ->assertSee('Showing 11–15 of 15');
    }

    public function test_pagination_keeps_the_filters(): void
    {
        $admin = $this->user('admin');
        User::factory()->count(12)->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->get(route('admin.users', ['role' => 'parent']))->assertOk()
            ->assertSee('Showing 1–10 of 12')
            ->assertSee('role=parent', false);
    }

    public function test_every_row_has_edit_and_one_danger_zone_button_and_a_modal(): void
    {
        $admin = $this->user('admin');
        $this->user('parent');
        $this->user('teacher');

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-bs-target="#userDanger'));   // one button per user
        $this->assertSame(3, substr_count($html, 'id="userDanger'));                // and its modal
        // one Edit button per user, plus a quick "Assign" button for the teacher who has no section yet
        $this->assertSame(4, substr_count($html, 'data-bs-target="#editUser'));

        // the risky actions only live inside the danger zone modals now
        $this->assertSame(3, substr_count($html, 'Reset password</button>'));
        $this->assertStringNotContainsString('Reset Password to', $html);
        $this->assertStringNotContainsString('Bigkas@123', $html);                  // the shared default is no longer printed on the page
    }

    public function test_danger_zone_offers_activate_for_inactive_users_and_blocks_self_deactivation(): void
    {
        $admin = $this->user('admin', ['name' => 'Principal Admin']);
        $off = $this->user('teacher', ['name' => 'Gone Teacher', 'is_active' => false]);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()
            ->assertSee('Activate account')
            ->assertSee("You can't deactivate your own account.", false);

        $this->actingAs($admin)->post(route('admin.users.deactivate', $admin))->assertSessionHas('error');
        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_deactivate_activate_and_reset_still_work_from_the_danger_zone(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher', ['password' => 'Old-Pass-123']);

        $this->actingAs($admin)->post(route('admin.users.deactivate', $teacher))->assertSessionHas('success');
        $this->assertFalse((bool) $teacher->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.users.activate', $teacher))->assertSessionHas('success');
        $this->assertTrue((bool) $teacher->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $teacher))->assertSessionHas('success');
        $this->assertFalse(Hash::check('Old-Pass-123', $teacher->fresh()->password));
    }
}
