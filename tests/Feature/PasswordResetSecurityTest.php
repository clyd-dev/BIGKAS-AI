<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_sends_notification_not_log(): void
    {
        Notification::fake();
        $user = \App\Models\User::factory()->create(['email' => 'test@example.com']);
        $this->post('/forgot-password', ['email' => 'test@example.com']);
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->post('/forgot-password', ['email' => 'test@example.com']);
        }
        $response = $this->post('/forgot-password', ['email' => 'test@example.com']);
        $response->assertStatus(429);
    }
}
