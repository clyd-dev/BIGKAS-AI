<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_weak_password_rejected_on_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'weak@example.com',
            'password' => 'weak12',
            'password_confirmation' => 'weak12',
            'role' => 'parent',
        ]);
        $response->assertSessionHasErrors('password');
    }

    public function test_strong_password_accepted_on_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'strong@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'role' => 'parent',
        ]);
        $response->assertSessionHasNoErrors();
    }
}
