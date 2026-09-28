<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_token_carries_role_abilities(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'password' => 'Password123']);
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);
        $response->assertOk();
        $token = $user->tokens()->first();
        $this->assertNotNull($token);
        $this->assertContains('assessment', $token->abilities);
    }
}
