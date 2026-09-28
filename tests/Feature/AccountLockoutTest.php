<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Task 1 put throttle:5,1 on POST /login: the 6th request would get
        // a 429 before reaching the lockout logic. Bypass throttle here to
        // isolate account lockout (throttle itself is covered by
        // AuthRateLimitingTest). Routes are untouched.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_user_is_locked_after_5_failed_logins(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        // NOTE: must be >= 6 chars to reach the login logic — the plan's
        // 'wrong' (5 chars) is rejected by the form's min:6 rule first.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        // NOTE: lockout returns a 302 with a flashed error rendered by the
        // login view, so follow the redirect before asserting (verbatim
        // assertion from the plan).
        $response = $this->from('/login')->followingRedirects()->post('/login', ['email' => $user->email, 'password' => 'password123']);
        $response->assertSee('locked');
    }

    public function test_successful_user_login_resets_counters(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password123']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_at);
    }

    public function test_matched_inactive_learner_pin_increments_but_stays_generic(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456', 'is_active' => false]);

        $response = $this->from('/student/login')->followingRedirects()->post('/student/login', ['pin' => '123456']);

        $response->assertSee('Invalid PIN');
        $this->assertSame(1, $learner->fresh()->failed_login_attempts);
    }

    public function test_unmatched_pin_increments_nothing(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456', 'is_active' => true]);

        $response = $this->from('/student/login')->followingRedirects()->post('/student/login', ['pin' => '000000']);

        $response->assertSee('Invalid PIN');
        $this->assertSame(0, $learner->fresh()->failed_login_attempts);
    }

    public function test_learner_is_locked_after_10_matched_failures(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456', 'is_active' => false]);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/student/login', ['pin' => '123456']);
        }

        $learner->refresh();
        $this->assertSame(10, $learner->failed_login_attempts);
        $this->assertTrue($learner->isLocked());
    }
}
