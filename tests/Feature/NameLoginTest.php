<?php

namespace Tests\Feature;

use App\Http\Controllers\ParentImportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parents and teachers bulk-imported for the pilot have no email address: their
 * login is their own full name (see ParentImportController). The import tests
 * prove the credentials work through Auth::attempt, which is not the same thing
 * as working through the login form — while the form validated the field as
 * 'email', every one of those accounts was rejected before authentication was
 * ever attempted. These tests go through the real route instead.
 */
class NameLoginTest extends TestCase
{
    use RefreshDatabase;

    private function nameLogin(string $name, string $role = 'parent'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $name,
            'password' => bcrypt(ParentImportController::DEFAULT_PASSWORD),
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return $user;
    }

    public function test_an_imported_parent_logs_in_with_their_full_name(): void
    {
        $parent = $this->nameLogin('Maria Dela Cruz');

        $this->post(route('login'), [
            'email' => 'Maria Dela Cruz',
            'password' => ParentImportController::DEFAULT_PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($parent);
    }

    public function test_an_imported_teacher_logs_in_with_their_full_name(): void
    {
        $teacher = $this->nameLogin('Juanita Reyes', 'teacher');

        $this->post(route('login'), [
            'email' => 'juanita reyes',
            'password' => ParentImportController::DEFAULT_PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($teacher);
    }

    /** A name login must not become a way past the password. */
    public function test_the_wrong_password_still_fails_for_a_name_login(): void
    {
        $this->nameLogin('Maria Dela Cruz');

        $this->post(route('login'), [
            'email' => 'Maria Dela Cruz',
            'password' => 'not-the-password',
        ])->assertSessionHas('error');

        $this->assertGuest();
    }

    /** Relaxing the rule must not break the ordinary email login. */
    public function test_an_email_login_still_works(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('Str0ng-P@ssw0rd'),
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->post(route('login'), [
            'email' => 'admin@example.com',
            'password' => 'Str0ng-P@ssw0rd',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_empty_login_is_still_refused(): void
    {
        $this->post(route('login'), ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    /** The browser blocks a name before the server sees it if the field is type=email. */
    public function test_the_login_field_is_not_an_email_input(): void
    {
        $page = $this->get(route('login'))->assertOk();

        $page->assertSee('Email or Full Name');
        $page->assertDontSee('type="email"', false);
    }
}
