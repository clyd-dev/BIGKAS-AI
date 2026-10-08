<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laravel's built-in default pagination view is Tailwind markup, which this
 * Bootstrap app has no styles for, so a plain ->links() used to render a
 * broken pager. AppServiceProvider registers partials.pagination as the
 * default for every paginator instead.
 */
class PaginationViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_logs_render_the_app_pager_not_tailwind_markup(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

        // The page shows 50 per page, so 60 rows guarantees a second page.
        foreach (range(1, 60) as $i) {
            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'test_action',
                'description' => "Log entry {$i}",
                'ip_address' => '127.0.0.1',
            ]);
        }

        $html = $this->actingAs($admin)->get(route('admin.logs'))->assertOk()->getContent();

        $this->assertStringContainsString('bigkas-pager', $html, 'activity logs should use the app pager');
        $this->assertStringContainsString('page=2', $html, 'the pager should link to the next page');
        // Tailwind's view ships these utility classes; they must not appear.
        $this->assertStringNotContainsString('relative inline-flex items-center', $html);
    }

    public function test_default_paginator_view_is_registered(): void
    {
        $this->assertSame('partials.pagination', \Illuminate\Pagination\Paginator::$defaultView);
        $this->assertSame('partials.pagination', \Illuminate\Pagination\Paginator::$defaultSimpleView);
    }
}
