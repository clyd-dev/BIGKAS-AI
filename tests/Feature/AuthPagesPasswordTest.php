<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\StrongPassword;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AuthPagesPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $password, string $confirm = null)
    {
        return $this->post('/register', [
            'name' => 'Test User', 'email' => 'new@example.com', 'role' => 'parent',
            'password' => $password, 'password_confirmation' => $confirm ?? $password,
        ]);
    }

    public function test_login_page_has_a_working_eye_toggle(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('data-password-toggle="password"', false)
            ->assertSee('Show password')
            ->assertSee('type="password"', false)
            ->assertSee('autocomplete="current-password"', false);
    }

    public function test_register_page_has_eye_toggles_on_both_password_boxes(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('data-password-toggle="password"', false)
            ->assertSee('data-password-toggle="password_confirmation"', false)
            ->assertSee('autocomplete="new-password"', false);
    }

    public function test_register_page_lists_every_password_rule_up_front(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('Your password must have', $html);
        foreach (PasswordPolicy::requirements() as $r) {
            $this->assertStringContainsString($r['label'], $html, "legend is missing: {$r['label']}");
        }
        $this->assertStringContainsString('Both password boxes match', $html);
        $this->assertStringContainsString('Symbols are optional', $html);

        // The old, wrong hint must be gone.
        $this->assertStringNotContainsString('Minimum 6 characters', $html);
    }

    public function test_legend_and_server_rule_share_one_definition(): void
    {
        $labels = collect(PasswordPolicy::requirements())->pluck('id')->all();
        $this->assertSame(['length', 'lower', 'upper', 'digit'], $labels);

        // every pattern shown to the user accepts a password the server accepts, and rejects what the server rejects
        foreach (['Abcdefg1' => true, 'abcdefg1' => false, 'ABCDEFG1' => false, 'Abcdefgh' => false, 'Abc1' => false] as $pw => $valid) {
            $matchesAll = collect(PasswordPolicy::requirements())
                ->every(fn ($r) => preg_match('/' . $r['pattern'] . '/', $pw) === 1);
            $this->assertSame($valid, $matchesAll, $pw);
            $this->assertSame($valid, PasswordPolicy::missing($pw) === [], $pw);
        }
    }

    public function test_error_names_exactly_what_is_missing(): void
    {
        $cases = [
            'abcdefgh' => 'an uppercase letter and a number',
            'ABCDEFG1' => 'a lowercase letter',
            'Abcdefgh' => 'a number',
            'Ab1'      => 'at least 8 characters',
            'abc'      => 'at least 8 characters, an uppercase letter and a number',
        ];
        // Same rules and messages the register form uses.
        foreach ($cases as $pw => $expected) {
            $v = Validator::make(['password' => $pw, 'password_confirmation' => $pw],
                ['password' => PasswordPolicy::rules()], PasswordPolicy::messages());
            $this->assertSame(["Your password needs {$expected}."], $v->errors()->get('password'), $pw);
        }

        // ...and one real round trip through the form.
        $this->register('abcdefgh')->assertSessionHasErrors(['password' => 'Your password needs an uppercase letter and a number.']);
    }

    public function test_mismatch_gets_a_clear_message_and_symbols_and_spaces_are_allowed(): void
    {
        $this->register('Abcdefg1', 'Abcdefg2')->assertSessionHasErrors(['password' => 'The two password boxes do not match.']);

        $this->mock(\App\Services\OtpMailer::class, fn ($m) => $m->shouldIgnoreMissing());
        $this->register('My pass! word 9')->assertSessionHasNoErrors();
        $this->assertNotNull(User::byEmail('new@example.com')->first());
    }

    public function test_rule_object_passes_a_good_password(): void
    {
        $this->assertTrue(Validator::make(['password' => 'Abcdefg1'], ['password' => [new StrongPassword()]])->passes());
        $this->assertFalse(Validator::make(['password' => 'abcdefg1'], ['password' => [new StrongPassword()]])->passes());
    }

    public function test_login_gives_the_same_answer_for_any_wrong_password(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'Right-Pass-1', 'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);

        // A short wrong password must not be rejected with a length rule that reveals the policy.
        $this->post('/login', ['email' => 'a@example.com', 'password' => 'x'])->assertSessionHasNoErrors('password');
        $this->assertGuest();
    }

    // ── The other password forms ──

    public function test_reset_password_page_has_eye_and_checklist(): void
    {
        $this->get('/reset-password/sometoken?email=a@example.com')->assertOk()
            ->assertSee('data-password-toggle="password"', false)
            ->assertSee('data-password-toggle="password_confirmation"', false)
            ->assertSee('Your password must have')
            ->assertSee('One uppercase letter (A–Z)')
            ->assertDontSee('Minimum 6 characters');
    }

    public function test_profile_change_password_has_eyes_and_checklist_and_clear_errors(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(), 'password' => 'Old-Pass-123']);

        $this->actingAs($user)->get(route('profile.show'))->assertOk()
            ->assertSee('data-password-toggle="current_password"', false)
            ->assertSee('data-password-toggle="password"', false)
            ->assertSee('data-password-toggle="password_confirmation"', false)
            ->assertSee('Your password must have');

        $this->actingAs($user)->put(route('profile.password'), [
            'current_password' => 'Old-Pass-123', 'password' => 'newpassword', 'password_confirmation' => 'newpassword',
        ])->assertSessionHasErrors(['password' => 'Your password needs an uppercase letter and a number.']);
    }

    public function test_admin_create_user_form_has_eyes_and_checklist_with_its_own_ids(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()
            ->assertSee('data-password-toggle="create_user_password"', false)
            ->assertSee('data-password-toggle="create_user_password_confirmation"', false)
            ->assertSee('id="rules-create_user_password"', false);

        $this->actingAs($admin)->post(route('admin.users.create'), [
            'name' => 'New Teacher', 'email' => 'nt@example.com', 'role' => 'teacher',
            'password' => 'lowercase1', 'password_confirmation' => 'lowercase1',
        ])->assertSessionHasErrors(['password' => 'Your password needs an uppercase letter.']);
    }

    // ── Register: one school, teacher or parent only ──

    public function test_register_form_has_no_student_role_and_no_school_choice(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('value="teacher"', $html);
        $this->assertStringContainsString('value="parent"', $html);
        $this->assertStringNotContainsString('value="student"', $html);
        $this->assertStringNotContainsString('name="school_id"', $html);
        $this->assertStringNotContainsString('School (Optional)', $html);
    }

    public function test_register_rejects_student_role_and_puts_the_user_in_the_one_school(): void
    {
        $school = \App\Models\School::create(['name' => 'Old Sagay ES']);
        \App\Models\School::create(['name' => 'Some Other School']);   // even if a second row exists, the first is used

        $this->post('/register', [
            'name' => 'Stu Dent', 'email' => 'stu@example.com', 'role' => 'student',
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ])->assertSessionHasErrors('role');
        $this->assertNull(User::byEmail('stu@example.com')->first());

        $this->mock(\App\Services\OtpMailer::class, fn ($m) => $m->shouldIgnoreMissing());
        $this->post('/register', [
            'name' => 'Tea Cher', 'email' => 'tea@example.com', 'role' => 'teacher', 'school_id' => 999,
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ])->assertSessionHasNoErrors();

        $user = User::byEmail('tea@example.com')->firstOrFail();
        $this->assertSame($school->id, $user->school_id);   // a posted school_id is ignored
        $this->assertSame('teacher', $user->role);
    }

    public function test_api_register_also_rejects_student_role(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Stu Dent', 'email' => 'api-stu@example.com', 'role' => 'student',
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ])->assertStatus(422);
    }
}
