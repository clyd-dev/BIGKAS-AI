<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VerificationCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_for_stores_hashed_code_with_expiry(): void
    {
        $user = User::factory()->unverified()->create();

        $code = EmailVerificationCode::issueFor($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = EmailVerificationCode::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertFalse($row->isExpired());
        $this->assertFalse($row->hasMaxAttempts());
    }

    public function test_register_redirects_to_code_page_and_sends_code(): void
    {
        $this->app->instance(
            \App\Services\OtpMailer::class,
            \Mockery::mock(\App\Services\OtpMailer::class, function ($mock) {
                $mock->shouldReceive('sendCode')->once()->with('newparent@gmail.com', 'New Parent', \Mockery::pattern('/^\d{6}$/'));
            })
        );

        $response = $this->post('/register', [
            'name' => 'New Parent',
            'email' => 'newparent@gmail.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
            'role' => 'parent',
        ]);

        $response->assertRedirect(route('verification-code.show'));
        $this->assertGuest();
        $this->assertDatabaseHas('email_verification_codes', [
            'user_id' => \App\Models\User::byEmail('newparent@gmail.com')->first()->id,
        ]);
    }

    public function test_correct_code_verifies_and_redirects_to_login_without_logging_in(): void
    {
        $this->app->instance(\App\Services\OtpMailer::class, \Mockery::mock(\App\Services\OtpMailer::class)->shouldIgnoreMissing());
        $user = \App\Models\User::factory()->unverified()->create();
        $code = \App\Models\EmailVerificationCode::issueFor($user);

        $response = $this->withSession(['pending_verification_user_id' => $user->id])
            ->post(route('verification-code.verify'), ['code' => $code]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $user->id]);
    }

    public function test_five_wrong_codes_void_and_resend_works(): void
    {
        $this->app->instance(\App\Services\OtpMailer::class, \Mockery::mock(\App\Services\OtpMailer::class)->shouldIgnoreMissing());
        $user = \App\Models\User::factory()->unverified()->create();
        $old = \App\Models\EmailVerificationCode::issueFor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->withSession(['pending_verification_user_id' => $user->id])
                ->post(route('verification-code.verify'), ['code' => '000001']);
        }

        $new = \App\Models\EmailVerificationCode::issueFor($user);
        $this->withSession(['pending_verification_user_id' => $user->id])
            ->post(route('verification-code.verify'), ['code' => $old])
            ->assertRedirect();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->withSession(['pending_verification_user_id' => $user->id])
            ->post(route('verification-code.verify'), ['code' => $new])
            ->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_code_rejected(): void
    {
        $this->app->instance(\App\Services\OtpMailer::class, \Mockery::mock(\App\Services\OtpMailer::class)->shouldIgnoreMissing());
        $user = \App\Models\User::factory()->unverified()->create();
        $code = \App\Models\EmailVerificationCode::issueFor($user);
        \App\Models\EmailVerificationCode::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->withSession(['pending_verification_user_id' => $user->id])
            ->post(route('verification-code.verify'), ['code' => $code])
            ->assertSessionHas('info');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_cannot_login(): void
    {
        $user = \App\Models\User::factory()->unverified()->create([
            'password' => \Illuminate\Support\Facades\Hash::make('StrongPass1'),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'StrongPass1'])
            ->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_admin_created_user_is_auto_verified(): void
    {
        $admin = \App\Models\User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.users.create'), [
            'name' => 'Staff Teacher',
            'email' => 'staffteacher@gmail.com',
            'role' => 'teacher',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
        ]);

        $response->assertRedirect(route('admin.users'));
        $user = \App\Models\User::byEmail('staffteacher@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $user->id]);
    }
}
