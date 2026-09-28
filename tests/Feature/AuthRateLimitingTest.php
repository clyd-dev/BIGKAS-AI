<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrongpassword']);
        }
        $response = $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrongpassword']);
        $response->assertStatus(429);
    }

    public function test_student_pin_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->post('/student/login', ['pin' => str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
        }
        $response = $this->post('/student/login', ['pin' => '000000']);
        $response->assertStatus(429);
    }
}
