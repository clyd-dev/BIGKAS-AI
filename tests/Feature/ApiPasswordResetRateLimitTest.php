<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiPasswordResetRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'test@example.com']);
        }
        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'test@example.com']);
        $response->assertStatus(429);
    }
}
