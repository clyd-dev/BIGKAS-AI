<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffShellTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    public function test_admin_gets_tab_bar_and_admin_links(): void
    {
        $this->actingAs($this->as('admin'))->get(route('dashboard'))->assertOk()
            ->assertSee('pp-tabbar', false)
            ->assertSee('ppDrawer', false)
            ->assertSee('Activity Logs')
            ->assertSee('Phil-IRI Forms')
            ->assertSee('width=device-width, initial-scale=1, viewport-fit=cover', false);
    }

    public function test_teacher_has_no_admin_links(): void
    {
        $this->actingAs($this->as('teacher'))->get(route('dashboard'))->assertOk()
            ->assertSee('Practice Center')
            ->assertDontSee('Activity Logs')
            ->assertDontSee('Phil-IRI Forms');
    }

    public function test_every_nav_link_resolves_for_each_role(): void
    {
        foreach (['admin', 'teacher'] as $role) {
            foreach (\App\Support\StaffNav::sections($role) as $section) {
                foreach ($section['items'] as $item) {
                    $this->assertNotEmpty(route($item['route']), "$role: {$item['route']}");
                }
            }
            $this->assertLessThanOrEqual(4, count(\App\Support\StaffNav::tabs($role)), "$role has too many bottom tabs");
        }
    }

    public function test_admin_pages_render_in_new_shell(): void
    {
        $admin = $this->as('admin');
        foreach (['admin.schools', 'admin.users', 'admin.classes', 'admin.logs', 'admin.settings', 'learners.index', 'assessments.index', 'reports.index', 'messages.index', 'notifications.index', 'profile.show'] as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk()->assertSee('pp-tabbar', false);
        }
    }
}
