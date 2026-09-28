# Auth Security Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all critical, high, and medium security vulnerabilities in BIGKAS-AI authentication system — from brute-force exposure and plaintext PINs to session encryption and API token hygiene.

**Architecture:** Phase-by-phase hardening: rate limiting first (blocks immediate exploitation), then credential storage (PIN hashing), then session/token security, then policy enforcement (password rules, email verification), then cleanup (mass assignment, headers).

**Tech Stack:** Laravel 12, PHP 8.2, Sanctum, MySQL/SQLite, Blade, Tailwind CSS 4.

## Global Constraints

- Do NOT modify any source code outside of auth/security scope
- Do NOT break existing functionality (all existing tests must pass)
- Maintain backward compatibility with existing database schema where possible
- PIN must remain 6-digit for student UX; hashing must be transparent
- Must use Laravel built-in features where available (RateLimiter, Sanctum, Hash)

---

## Phase 1: CRITICAL — Rate Limiting and Password Reset (5 tasks)

### Task 1: Add Rate Limiting to All Authentication Endpoints

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/AuthRateLimitingTest.php` (create)

**Interfaces:**
- Consumes: Laravel `ThrottleRequests` middleware (`throttle:5,1` etc.)
- Produces: All auth endpoints return HTTP 429 when rate limit exceeded

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AuthRateLimitingTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter AuthRateLimitingTest`
Expected: FAIL (no throttle middleware yet, logins return 302/200 not 429)

- [ ] **Step 3: Add throttle middleware to web auth routes**

In `routes/web.php`, add `->middleware('throttle:5,1')` to POST `/login`, `->middleware('throttle:3,1')` to POST `/register`, POST `/forgot-password`, POST `/reset-password`, and `->middleware('throttle:10,1')` to POST `/student/login` (named `login.submit`).

- [ ] **Step 4: Add throttle middleware to API auth routes**

In `routes/api.php`, wrap POST `/auth/login` and POST `/auth/register` in `Route::middleware('throttle:5,1')->group(...)`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter AuthRateLimitingTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add routes/web.php routes/api.php tests/Feature/AuthRateLimitingTest.php
git commit -m "security: add rate limiting to all authentication endpoints"
```

---

### Task 2: Remove Password Reset Token from Logs and Use Laravel Broker

**Files:**
- Modify: `app/Http/Controllers/AuthController.php`
- Test: `tests/Feature/PasswordResetSecurityTest.php` (create)

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Password` broker
- Produces: Reset tokens sent via notification, never logged

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PasswordResetSecurityTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_sends_notification_not_log(): void
    {
        Mail::fake();
        $user = \App\Models\User::factory()->create(['email' => 'test@example.com']);
        $this->post('/forgot-password', ['email' => 'test@example.com']);
        Mail::assertSent(\Illuminate\Auth\Notifications\ResetPassword::class);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PasswordResetSecurityTest`
Expected: FAIL (custom implementation logs token instead of sending mail)

- [ ] **Step 3: Replace custom forgotPassword body with Password broker**

In `app/Http/Controllers/AuthController.php`, replace the custom token generation + `logger()->info(...)` block in `forgotPassword()` with:

```php
$status = Password::sendResetLink($request->only('email'));

if ($status !== Password::RESET_LINK_SENT) {
    return back()->with('error', __($status));
}
```

Delete the `logger()->info()` line entirely. Add `use Illuminate\Support\Facades\Password;` import. Remove `use Illuminate\Support\Facades\DB;` if unused elsewhere.

- [ ] **Step 4: Replace custom resetPassword body with Password broker**

Replace `resetPassword()` body with:

```php
$status = Password::reset(
    $request->only('email', 'token', 'password', 'password_confirmation'),
    function ($user, $password) {
        $user->forceFill(['password' => Hash::make($password)])->save();
    }
);

if ($status !== Password::PASSWORD_RESET) {
    return back()->with('error', __($status));
}

return redirect()->route('login')->with('success', 'Your password has been reset.');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter PasswordResetSecurityTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AuthController.php tests/Feature/PasswordResetSecurityTest.php
git commit -m "security: remove password reset token logging, use Laravel broker"
```

---

### Task 3: Hash Student PINs and Use Secure RNG

**Files:**
- Modify: `app/Models/Learner.php`
- Modify: `app/Http/Controllers/Student/StudentAuthController.php`
- Modify: `database/migrations/*_add_pin_hash_migration.php` (create)
- Test: `tests/Feature/PinHashingTest.php` (create)

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Hash`, `random_int()`
- Produces: `Learner::checkPin(string): bool`; PINs stored as bcrypt hashes

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PinHashingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinHashingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_is_hashed_in_database(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456']);
        $this->assertNotEquals('123456', $learner->pin);
        $this->assertStringStartsWith('$2y$', $learner->pin);
    }

    public function test_checkPin_validates_correctly(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456']);
        $this->assertTrue($learner->checkPin('123456'));
        $this->assertFalse($learner->checkPin('654321'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PinHashingTest`
Expected: FAIL (pin stored plaintext, checkPin undefined)

- [ ] **Step 3: Add hashed cast and checkPin to Learner model**

In `app/Models/Learner.php`, add `'pin' => 'hashed'` to `$casts` and add:

```php
public function checkPin(string $pin): bool
{
    return Hash::check($pin, $this->attributes['pin']);
}
```

Ensure `use Illuminate\Support\Facades\Hash;` is imported.

- [ ] **Step 4: Update StudentAuthController login to use checkPin**

Replace the `Learner::where('pin', $request->pin)` query with a lookup by active status plus `$learner->checkPin($request->pin)` verification. Keep the generic "Invalid PIN" error message.

- [ ] **Step 5: Replace mt_rand with random_int in generatePin**

In `Learner::generatePin()`, replace `mt_rand(0, 999999)` with `random_int(0, 999999)`.

- [ ] **Step 6: Create data migration hashing existing plaintext PINs**

Create a migration that loops all learners and re-hashes any PIN not starting with `$2y$`.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter PinHashingTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/Learner.php app/Http/Controllers/Student/StudentAuthController.php database/migrations tests/Feature/PinHashingTest.php
git commit -m "security: hash student PINs, replace mt_rand with random_int"
```

---

### Task 4: Add Account Lockout After Failed Attempts

**Files:**
- Modify: `database/migrations/*_add_lockout_columns.php` (create: users + learners)
- Modify: `app/Models/User.php`
- Modify: `app/Models/Learner.php`
- Modify: `app/Http/Controllers/AuthController.php`
- Modify: `app/Http/Controllers/Student/StudentAuthController.php`
- Test: `tests/Feature/AccountLockoutTest.php` (create)

**Interfaces:**
- Consumes: `failed_login_attempts` (int), `locked_at` (timestamp nullable) columns
- Produces: `isLocked(): bool` on User and Learner; lock after 5 fails (users) / 10 fails (learners), 15-min lock

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AccountLockoutTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_is_locked_after_5_failed_logins(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password123']);
        $response->assertSee('locked');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter AccountLockoutTest`
Expected: FAIL (no lockout columns/methods)

- [ ] **Step 3: Create migration for lockout columns**

Add `failed_login_attempts` (integer default 0) and `locked_at` (timestamp nullable) to both `users` and `learners` tables.

- [ ] **Step 4: Add isLocked to User and Learner models**

```php
public function isLocked(): bool
{
    if ($this->locked_at && $this->locked_at->gt(now())) {
        return true;
    }
    if ($this->locked_at && $this->locked_at->lte(now())) {
        $this->update(['failed_login_attempts' => 0, 'locked_at' => null]);
        return false;
    }
    return false;
}
```

Add `locked_at` datetime cast and fillable entries on both models.

- [ ] **Step 5: Wire lockout into AuthController login**

Check `isLocked()` before `Auth::attempt` (return "temporarily locked" message). Increment counter on failure, set 15-min lock at 5 fails. Reset counters on success.

- [ ] **Step 6: Wire lockout into StudentAuthController login**

Same pattern: lock check first, increment on bad PIN, 15-min lock at 10 fails, reset on success.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter AccountLockoutTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/User.php app/Models/Learner.php app/Http/Controllers/AuthController.php app/Http/Controllers/Student/StudentAuthController.php tests/Feature/AccountLockoutTest.php
git commit -m "security: add account lockout after failed login attempts"
```

---

### Task 5: Add Rate Limiting to API Password Reset

**Files:**
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/Api/AuthApiController.php`
- Test: `tests/Feature/ApiPasswordResetRateLimitTest.php` (create)

**Interfaces:**
- Consumes: `Password` broker, `throttle:3,1` middleware
- Produces: `POST /api/auth/forgot-password` and `POST /api/auth/reset-password` endpoints, rate limited

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ApiPasswordResetRateLimitTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ApiPasswordResetRateLimitTest`
Expected: FAIL (route does not exist → 404)

- [ ] **Step 3: Implement forgotPassword and resetPassword in AuthApiController**

Use `Password::sendResetLink()` and `Password::reset()` returning the controller's JSON success/error envelopes.

- [ ] **Step 4: Register throttled routes in api.php**

```php
Route::middleware('throttle:3,1')->group(function () {
    Route::post('/auth/forgot-password', [AuthApiController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthApiController::class, 'resetPassword']);
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter ApiPasswordResetRateLimitTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add routes/api.php app/Http/Controllers/Api/AuthApiController.php tests/Feature/ApiPasswordResetRateLimitTest.php
git commit -m "security: add rate limiting to API password reset"
```

---

## Phase 2: HIGH — Session Security, API Tokens, Email Verification (4 tasks)

### Task 6: Enable Session Encryption and Secure Cookies

**Files:**
- Modify: `.env.example`
- Modify: `config/session.php`
- Test: `tests/Feature/SessionSecurityTest.php` (create)

**Interfaces:**
- Consumes: `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE` env vars
- Produces: Encrypted session payloads, Secure-flagged cookies

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SessionSecurityTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    public function test_session_encrypt_defaults_true(): void
    {
        $this->assertTrue(config('session.encrypt'));
    }

    public function test_session_secure_defaults_true(): void
    {
        $this->assertTrue(config('session.secure'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SessionSecurityTest`
Expected: FAIL (encrypt defaults false, secure defaults null)

- [ ] **Step 3: Flip defaults**

In `.env.example`, set `SESSION_ENCRYPT=true` and add `SESSION_SECURE_COOKIE=true` plus `SESSION_SAME_SITE=lax`. In `config/session.php`, change encrypt default to `true` and secure default to `true`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SessionSecurityTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add .env.example config/session.php tests/Feature/SessionSecurityTest.php
git commit -m "security: enable session encryption and secure cookies"
```

---

### Task 7: Add Sanctum Token Expiration and Abilities

**Files:**
- Modify: `config/sanctum.php` (create if missing)
- Modify: `app/Http/Controllers/Api/AuthApiController.php`
- Test: `tests/Feature/ApiTokenAbilitiesTest.php` (create)

**Interfaces:**
- Consumes: Sanctum `createToken(name, abilities)`, `expiration` config (43200 min = 30 days)
- Produces: Role-scoped tokens (admin `*`, teacher set, parent set)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ApiTokenAbilitiesTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ApiTokenAbilitiesTest`
Expected: FAIL (token created with no abilities)

- [ ] **Step 3: Add expiration config and ability mapping**

Ensure `config/sanctum.php` sets `'expiration' => 43200`. In `AuthApiController::login` and `::register`, map role to abilities (admin `['*']`, teacher `['assessment','learner','intervention','material','report','message']`, parent `['learner','message']`) and pass to `createToken()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter ApiTokenAbilitiesTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/sanctum.php app/Http/Controllers/Api/AuthApiController.php tests/Feature/ApiTokenAbilitiesTest.php
git commit -m "security: add Sanctum token expiration and ability scoping"
```

---

### Task 8: Add Session Regeneration to Student Login

**Files:**
- Modify: `app/Http/Controllers/Student/StudentAuthController.php`
- Test: `tests/Feature/StudentSessionRegenerationTest.php` (create)

**Interfaces:**
- Consumes: `$request->session()->regenerate()`
- Produces: Fresh session ID after every student PIN login

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StudentSessionRegenerationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSessionRegenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_login_regenerates_session(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456', 'is_active' => true]);
        $oldSessionId = session()->getId();
        $this->post('/student/login', ['pin' => '123456']);
        $this->assertNotEquals($oldSessionId, session()->getId());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter StudentSessionRegenerationTest`
Expected: FAIL (session ID unchanged)

- [ ] **Step 3: Add session regeneration**

In `StudentAuthController::login()`, after setting `student_learner_id` in session, add `$request->session()->regenerate();`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter StudentSessionRegenerationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Student/StudentAuthController.php tests/Feature/StudentSessionRegenerationTest.php
git commit -m "security: regenerate session on student login to prevent fixation"
```

---

### Task 9: Add Email Verification Requirement

**Files:**
- Modify: `app/Models/User.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/AuthController.php`
- Test: `tests/Feature/EmailVerificationTest.php` (create)

**Interfaces:**
- Consumes: `Illuminate\Contracts\Auth\MustVerifyEmail`, `verified` middleware
- Produces: Unverified users redirected to verification notice; register no longer auto-logs-in

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EmailVerificationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/verify-email');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter EmailVerificationTest`
Expected: FAIL (no verified middleware, dashboard renders)

- [ ] **Step 3: Implement MustVerifyEmail + middleware + register change**

Make `User` implement `MustVerifyEmail`. Wrap authenticated route groups in `routes/web.php` with the `verified` middleware. In `AuthController::register()`, remove `Auth::login($user)` and redirect to login with "verify your email" message.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter EmailVerificationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php routes/web.php app/Http/Controllers/AuthController.php tests/Feature/EmailVerificationTest.php
git commit -m "security: require email verification before dashboard access"
```

---

## Phase 3: MEDIUM — Password Policy, Security Headers, Hardening (4 tasks)

### Task 10: Strengthen Password Policy and PIN Format

**Files:**
- Modify: `app/Http/Controllers/AuthController.php`
- Modify: `app/Http/Controllers/Api/AuthApiController.php`
- Modify: `app/Models/Learner.php`
- Test: `tests/Feature/PasswordPolicyTest.php` (create)

**Interfaces:**
- Consumes: `min:8` + lowercase/uppercase/digit regex rules
- Produces: Weak passwords rejected on web + API register/reset; common PINs rejected

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PasswordPolicyTest.php`:

```php
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
            'password' => 'weak',
            'password_confirmation' => 'weak',
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
```

- [ ] **Step 2: Run test to verify weak-password assertion fails first**

Run: `php artisan test --filter PasswordPolicyTest`
Expected: FAIL on first assertion detail (min:6 accepts some weak input the new rule must reject — e.g. `weak12` passes old rule but fails new complexity rule; adjust test password to `weak12` if needed to demonstrate the gap)

- [ ] **Step 3: Strengthen validation rules**

Change password rule to `'required|string|min:8|confirmed|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/'` in web register/reset and API register/reset. Tighten PIN rule to `'required|string|size:6|regex:/^[0-9]+$/'` and reject common PINs (`000000`, `123456`, `111111`, `222222`, `654321`, `987654`) in `Learner::generatePin()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter PasswordPolicyTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AuthController.php app/Http/Controllers/Api/AuthApiController.php app/Models/Learner.php tests/Feature/PasswordPolicyTest.php
git commit -m "security: strengthen password policy and PIN format"
```

---

### Task 11: Add Security Headers Middleware

**Files:**
- Create: `app/Http/Middleware/SecurityHeadersMiddleware.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/SecurityHeadersTest.php` (create)

**Interfaces:**
- Consumes: `$middleware->append()` in bootstrap
- Produces: X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy no-referrer, Permissions-Policy, X-XSS-Protection on all responses

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SecurityHeadersTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_response_has_security_headers(): void
    {
        $response = $this->get('/');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter SecurityHeadersTest`
Expected: FAIL (headers missing)

- [ ] **Step 3: Create middleware and register it**

Create `SecurityHeadersMiddleware` setting the five headers. Register via `$middleware->append(...)` in `bootstrap/app.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter SecurityHeadersTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/SecurityHeadersMiddleware.php bootstrap/app.php tests/Feature/SecurityHeadersTest.php
git commit -m "security: add security headers middleware"
```

---

### Task 12: Add CORS Configuration

**Files:**
- Create: `app/Http/Middleware/CorsMiddleware.php` (only if Laravel's built-in HandleCors is insufficient; prefer `config/cors.php` + built-in middleware)
- Modify: `bootstrap/app.php` or `config/cors.php`
- Test: `tests/Feature/CorsTest.php` (create)

**Interfaces:**
- Consumes: Origin restricted to `APP_URL`
- Produces: Access-Control-Allow-Origin/Methods/Headers on API responses

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CorsTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_api_response_has_cors_headers(): void
    {
        $response = $this->getJson('/api/ml/health', ['Origin' => config('app.url')]);
        $response->assertHeader('Access-Control-Allow-Origin');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter CorsTest`
Expected: FAIL (no CORS headers)

- [ ] **Step 3: Configure CORS restricted to APP_URL**

Prefer Laravel's built-in CORS (`config/cors.php` with `allowed_origins` set to the app URL + `HandleCors` middleware). Only create a custom middleware if the built-in one is unavailable.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter CorsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/cors.php bootstrap/app.php tests/Feature/CorsTest.php
git commit -m "security: add CORS configuration restricted to app URL"
```

---

### Task 13: Remove Sensitive Fields from Mass Assignment

**Files:**
- Modify: `app/Models/User.php`
- Modify: `app/Models/Learner.php`
- Modify: `app/Http/Controllers/AuthController.php` (verify explicit assignment still works)
- Modify: `app/Http/Controllers/Api/AuthApiController.php` (verify explicit assignment still works)
- Test: `tests/Feature/MassAssignmentTest.php` (create)

**Interfaces:**
- Consumes: `$guarded` on both models
- Produces: `role`, `is_active`, `email_verified_at` (User) and `pin`, `is_active`, `total_xp`, `current_streak`, `longest_streak` (Learner) no longer mass-assignable

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MassAssignmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@test.com',
            'password' => 'Password123',
            'role' => 'admin',
        ]);
        $this->assertNotEquals('admin', $user->fresh()->role);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MassAssignmentTest`
Expected: FAIL (role is fillable, gets set to admin)

- [ ] **Step 3: Guard sensitive fields, keep explicit assignment working**

Add `$guarded` arrays on both models. Verify existing controller `create()` calls still set role/pin explicitly and registration flows keep working (adjust controllers only if guarded fields break them).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MassAssignmentTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php app/Models/Learner.php app/Http/Controllers/AuthController.php app/Http/Controllers/Api/AuthApiController.php tests/Feature/MassAssignmentTest.php
git commit -m "security: remove sensitive fields from mass assignment"
```

---

## Phase 4: LOW — Cleanup and Hardening (3 tasks)

### Task 14: Add PIN Expiration and Failed Attempt Logging

**Files:**
- Modify: `database/migrations/*_add_pin_created_at.php` (create)
- Modify: `app/Models/Learner.php`
- Modify: `app/Http/Controllers/Student/StudentAuthController.php`
- Test: `tests/Feature/PinExpirationTest.php` (create)

**Interfaces:**
- Consumes: `pin_created_at` timestamp column
- Produces: `isPinExpired(): bool` (6-month expiry); failed PIN attempts logged to activity log

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PinExpirationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_pin_is_detected(): void
    {
        $learner = Learner::factory()->create([
            'pin' => '123456',
            'pin_created_at' => now()->subMonths(7),
        ]);
        $this->assertTrue($learner->isPinExpired());
    }

    public function test_fresh_pin_is_not_expired(): void
    {
        $learner = Learner::factory()->create([
            'pin' => '123456',
            'pin_created_at' => now(),
        ]);
        $this->assertFalse($learner->isPinExpired());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PinExpirationTest`
Expected: FAIL (column/method missing)

- [ ] **Step 3: Add column, method, and logging**

Add nullable `pin_created_at` migration. Add `isPinExpired()` (6-month threshold) to Learner. Log failed PIN attempts via ActivityLog in `StudentAuthController::login()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter PinExpirationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/Learner.php app/Http/Controllers/Student/StudentAuthController.php tests/Feature/PinExpirationTest.php
git commit -m "security: add PIN expiration and failed attempt logging"
```

---

### Task 15: Add HTTPS Enforcement in Production

**Files:**
- Create: `app/Http/Middleware/HttpsEnforcementMiddleware.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/HttpsEnforcementTest.php` (create)

**Interfaces:**
- Consumes: `app()->environment('production')`, `$request->secure()`
- Produces: HTTP requests redirected to HTTPS only in production

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HttpsEnforcementTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HttpsEnforcementTest extends TestCase
{
    public function test_middleware_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\HttpsEnforcementMiddleware::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter HttpsEnforcementTest`
Expected: FAIL (class missing)

- [ ] **Step 3: Create middleware and register it**

Middleware redirects non-secure requests to HTTPS only when `app()->environment('production')`. Register via `$middleware->append()` in `bootstrap/app.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter HttpsEnforcementTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/HttpsEnforcementMiddleware.php bootstrap/app.php tests/Feature/HttpsEnforcementTest.php
git commit -m "security: add HTTPS enforcement middleware for production"
```

---

### Task 16: Verify All Changes and Run Full Test Suite

**Files:** none (verification only)

- [ ] **Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests pass. Fix any failures before proceeding.

- [ ] **Step 2: Run database migrations**

Run: `php artisan migrate --force`
Expected: All migrations run successfully.

- [ ] **Step 3: Verify security headers**

Run: `curl -I http://localhost:8000/`
Expected: X-Frame-Options, X-Content-Type-Options, Referrer-Policy present.

- [ ] **Step 4: Verify rate limiting**

Hit POST `/login` 6+ times with bad credentials.
Expected: Last response is HTTP 429.

- [ ] **Step 5: Final commit (only if verification changed files)**

```bash
git add .
git commit -m "security: complete auth security hardening - all phases implemented"
```

---

## Implementation Order Summary

Phase 1 (CRITICAL):
Task 1 Rate limiting all auth endpoints
Task 2 Password reset logging fix + Laravel broker
Task 3 Hash student PINs + random_int
Task 4 Account lockout after failed attempts
Task 5 API rate limiting for password reset

Phase 2 (HIGH):
Task 6 Session encryption + secure cookies
Task 7 Sanctum token expiration + abilities
Task 8 Session regeneration on student login
Task 9 Email verification requirement

Phase 3 (MEDIUM):
Task 10 Password strength + PIN format rules
Task 11 Security headers middleware
Task 12 CORS configuration
Task 13 Remove sensitive fields from mass assignment

Phase 4 (LOW):
Task 14 PIN expiration + failed attempt logging
Task 15 HTTPS enforcement
Task 16 Full test suite verification

---

## Risk Assessment

| Risk | Mitigation |
|------|-----------|
| Migrating plaintext PINs to hashes could break existing students | Run data migration before deployment; test on staging first |
| Rate limiting could lock out legitimate users | Use generous limits (10 attempts/min for PIN, 5/min for login) |
| Email verification may prevent immediate use | Provide clear verification flow with resend option |
| Session encryption may invalidate existing sessions | Document that all sessions will be invalidated on deploy |
| Sanctum ability scoping may break API clients | Test all existing API calls against new scopes |
| HTTPS enforcement may break local development | Only enforce in production environment |

---

## Post-Implementation Checklist

- [ ] All tests pass
- [ ] All migrations run cleanly
- [ ] Rate limiting verified (429 responses on throttled endpoints)
- [ ] PIN hashes verified in database
- [ ] Session cookies have Secure flag
- [ ] Security headers present on all responses
- [ ] API tokens have expiration dates
- [ ] Password reset emails are sent (not logged)
- [ ] Account lockout verified (5 failed = locked)
- [ ] Email verification required for dashboard access
- [ ] Mass assignment vulnerability closed
- [ ] No sensitive fields in logs
- [ ] No sensitive data in .env.example defaults
- [ ] Full security audit re-run confirms all findings resolved
