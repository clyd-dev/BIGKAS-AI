# OTP Email-Code Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Laravel's signed-link email verification with a Gmail-delivered 6-digit code (via PHPMailer) pasted into a dedicated page; verified users redirect to `/login` and are never auto-logged in.

**Architecture:** New `email_verification_codes` table (bcrypt-hashed codes, one row per user) + `OtpMailer` service wrapping PHPMailer + `VerificationCodeController` (show/verify/resend) + rewired `AuthController@register`; old signed-link routes removed, `verification.notice` kept as a redirect alias.

**Tech Stack:** Laravel 12, PHP 8.2, PHPMailer (`phpmailer/phpmailer`), Gmail SMTP (port 587, STARTTLS), PHPUnit feature tests, Blade (`layouts.auth`).

## Global Constraints

- Laravel built-ins only, except the spec-mandated `phpmailer/phpmailer` dependency.
- No out-of-scope source changes; do not touch password reset, API auth, learner PIN login, or ability enforcement.
- Backward-compatible schema: additive migration only, no column changes to existing tables.
- Never store or log a plaintext code; only bcrypt hashes in the database.
- Never call `Auth::login` in the verification flow; success always redirects to `route('login')`.
- Resolve `OtpMailer` through the container (`app(OtpMailer::class)`), never `new OtpMailer`, so tests can fake it.
- `role`, `is_active`, `email_verified_at` stay guarded — set via explicit assignment only.
- One behavior change per commit; run the affected tests before every commit.

---

### Task 1: Dependency, migration, and code model

**Files:**
- Modify: `composer.json` (require block)
- Create: `database/migrations/2026_09_29_030000_create_email_verification_codes_table.php` (exact timestamp will differ when generated via artisan — that is fine)
- Create: `app/Models/EmailVerificationCode.php`
- Test: `tests/Feature/VerificationCodeTest.php` (first test only in this task)

**Interfaces:**
- Consumes: `App\Models\User`
- Produces: `EmailVerificationCode::issueFor(User $user): string` (deletes any existing row for the user, stores bcrypt hash + 30-min expiry + zeroed attempts, returns the plaintext code); `isExpired(): bool`; `hasMaxAttempts(): bool` (attempts >= 5)

- [ ] **Step 1: Install PHPMailer**

Run: `composer require phpmailer/phpmailer`
Expected: package added to `composer.json` require, `vendor/phpmailer/phpmailer` exists.

- [ ] **Step 2: Create the migration**

Run: `php artisan make:migration create_email_verification_codes_table`
Expected: migration file created. Replace its body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 255);
            $table->dateTime('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
```

- [ ] **Step 3: Write the failing test (model issues hashed, expiring codes)**

Create `tests/Feature/VerificationCodeTest.php`:

```php
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
}
```

Run: `php artisan test --filter=test_issue_for_stores_hashed_code_with_expiry`
Expected: FAIL with "Class App\Models\EmailVerificationCode not found".

- [ ] **Step 4: Write minimal model implementation**

Create `app/Models/EmailVerificationCode.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class EmailVerificationCode extends Model
{
    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 30;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh code for the user, voiding any previous one.
     * Returns the PLAINTEXT code — the caller sends it, never stores it.
     */
    public static function issueFor(User $user): string
    {
        static::where('user_id', $user->id)->delete();

        $code = (string) random_int(100000, 999999);

        static::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
        ]);

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    public function hasMaxAttempts(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=test_issue_for_stores_hashed_code_with_expiry`
Expected: PASS.

- [ ] **Step 6: Run migration on dev database and commit**

Run: `php artisan migrate --force`
Expected: `create_email_verification_codes_table DONE`.

```bash
git add composer.json composer.lock database/migrations/*create_email_verification_codes_table.php app/Models/EmailVerificationCode.php tests/Feature/VerificationCodeTest.php
git commit -m "feat(otp): add verification codes table and issuing model"
```

---

### Task 2: OtpMailer service (PHPMailer over Gmail SMTP)

**Files:**
- Create: `app/Services/OtpMailer.php`
- Modify: `.env.example` (add `MAIL_ENCRYPTION` + Gmail App Password guidance)
- Test: `tests/Unit/OtpMailerTest.php`

**Interfaces:**
- Consumes: `config('mail.mailers.smtp.*')`, `config('mail.from.*')`
- Produces: `OtpMailer::buildMessage(string $email, string $name, string $code): PHPMailer\PHPMailer\PHPMailer` (configured, unsent — used by tests); `OtpMailer::sendCode(string $email, string $name, string $code): void` (sends; throws `RuntimeException('Could not send verification email.')` on SMTP failure, chaining the PHPMailer exception)

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/OtpMailerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\OtpMailer;
use Tests\TestCase;

class OtpMailerTest extends TestCase
{
    public function test_build_message_contains_code_and_recipient_without_sending(): void
    {
        $mailer = new OtpMailer();

        $message = $mailer->buildMessage('juan@gmail.com', 'Juan', '482916');
        $message->preSend();
        $mime = $message->getSentMIMEMessage();

        $this->assertSame('juan@gmail.com', $message->getToAddresses()[0][0]);
        $this->assertStringContainsString('482916', $mime);
        $this->assertStringContainsString('BIGKAS-AI', $message->Subject);
    }
}
```

Run: `php artisan test --filter=test_build_message_contains_code_and_recipient_without_sending`
Expected: FAIL with "Class App\Services\OtpMailer not found".

- [ ] **Step 2: Write minimal implementation**

Create `app/Services/OtpMailer.php`:

```php
<?php

namespace App\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class OtpMailer
{
    /**
     * Build (but do not send) the verification email.
     */
    public function buildMessage(string $email, string $name, string $code): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) config('mail.mailers.smtp.host');
        $mail->Port = (int) config('mail.mailers.smtp.port', 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) config('mail.mailers.smtp.username');
        $mail->Password = (string) config('mail.mailers.smtp.password');
        $mail->SMTPSecure = $this->encryption();
        $mail->Timeout = 10;

        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($email, $name);

        $mail->Subject = 'Your BIGKAS-AI verification code';
        $mail->Body = "Hi {$name},\n\nYour BIGKAS-AI verification code is: {$code}\n\n"
            . "It expires in 30 minutes. If you did not create this account, ignore this email.\n";
        $mail->AltBody = $mail->Body;

        return $mail;
    }

    /**
     * Send the verification code email. Throws on SMTP failure.
     */
    public function sendCode(string $email, string $name, string $code): void
    {
        try {
            $this->buildMessage($email, $name, $code)->send();
        } catch (PHPMailerException $e) {
            throw new \RuntimeException('Could not send verification email.', 0, $e);
        }
    }

    private function encryption(): string
    {
        return match (config('mail.mailers.smtp.encryption', 'tls')) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
    }
}
```

`encryption()` reads a NEW config key `mail.mailers.smtp.encryption`, wired in Step 4.

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=test_build_message_contains_code_and_recipient_without_sending`
Expected: PASS (no SMTP traffic — `preSend()` never connects).

- [ ] **Step 4: Wire the encryption config key + document Gmail setup**

In `config/mail.php`, inside the `smtp` mailer array after `'password'`, add:

```php
'encryption' => env('MAIL_ENCRYPTION', 'tls'),
```

In `.env.example`, after the `MAIL_PASSWORD` line, add:

```
MAIL_ENCRYPTION=tls
# Gmail SMTP needs an App Password (Google Account → Security → 2-Step
# Verification → App passwords). A regular Gmail password will NOT work.
```

- [ ] **Step 5: Re-run test and commit**

Run: `php artisan test --filter=OtpMailerTest`
Expected: PASS.

```bash
git add app/Services/OtpMailer.php config/mail.php .env.example tests/Unit/OtpMailerTest.php
git commit -m "feat(otp): add OtpMailer service over Gmail SMTP"
```

---

### Task 3: Controller, routes, view, and register rewiring

**Files:**
- Create: `app/Http/Controllers/VerificationCodeController.php`
- Create: `resources/views/auth/verify-code.blade.php`
- Modify: `app/Http/Controllers/AuthController.php` (register method only, lines 93-122)
- Modify: `routes/web.php` (EMAIL VERIFICATION block, lines 53-79)
- Test: `tests/Feature/VerificationCodeTest.php` (append); `tests/Feature/PasswordPolicyTest.php` (fake mailer)

**Interfaces:**
- Consumes: `EmailVerificationCode::issueFor()`, `app(OtpMailer::class)->sendCode()`, session key `pending_verification_user_id`
- Produces: routes `verification-code.show` (GET `/verify-code`), `verification-code.verify` (POST `/verify-code`, `throttle:10,1`), `verification-code.resend` (POST `/verify-code/resend`, `throttle:3,1`); kept alias `verification.notice` (GET `/email/verify` → redirect logic, `auth` middleware)

Controller behavior contract (implement exactly this):
- Effective user = authenticated-but-unverified user if logged in, else the user from session marker `pending_verification_user_id`. No user id in URLs.
- `show`: authenticated AND verified → dashboard. No effective user → redirect `register`. Effective user already verified → redirect `login`. Otherwise render view with masked email (`maskEmail`: keep first char of local part, `***`, full domain — `maskEmail('juan@gmail.com')` returns `'j***@gmail.com'`).
- `verify` (validate `code` required|digits:6): no code row OR expired OR max attempts → delete row, issue fresh code, send (best effort), redirect back with info "This code has expired. We have sent a fresh one." Wrong code → increment attempts (if now maxed, delete row + auto-resend as above with "Too many attempts. We have sent a fresh one."). Correct code → explicit `$user->email_verified_at = now(); $user->save();` delete row, `session()->forget` marker, `ActivityLog::log('email_verified', ...)`, redirect `login` with success. NEVER `Auth::login`.
- `resend`: no effective user → redirect `register`. Else issue fresh code (voids old via row replace), send best effort, back with success "A fresh verification code has been sent."
- Mail sending is best effort everywhere: wrap `sendCode` in try/catch; on failure proceed with a warning flash instead of erroring.
- `register`: remove `$user->sendEmailVerificationNotification();`. After save: `$code = EmailVerificationCode::issueFor($user);` try send via `app(OtpMailer::class)->sendCode($user->email, $user->name, $code)`; store marker; redirect `route('verification-code.show')` with success (or warning flash if mail failed: "Registered! We could not send the code — click Resend.").

Routes contract: replace the whole EMAIL VERIFICATION block (lines 53-79) with the three code routes (show unthrottled GET; verify + resend throttled as above) plus kept `verification.notice` GET `/email/verify` with `auth` middleware: verified → dashboard; else set session marker to own id, redirect to `verification-code.show` (covers legacy authenticated sessions without loops).

- [ ] **Step 1: Write the failing feature tests (append to VerificationCodeTest)**

```php
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
        'user_id' => \App\Models\User::where('email', 'newparent@gmail.com')->first()->id,
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
```

Note: `000001` is a valid-format wrong guess (not in the rejected common-PIN list, which only applies to learner PINs anyway). If `issueFor` ever returns `000001` (1-in-a-million), re-run.

Run: `php artisan test --filter=VerificationCodeTest`
Expected: FAIL — routes/controller do not exist (404s / errors).

- [ ] **Step 2: Implement VerificationCodeController**

Create `app/Http/Controllers/VerificationCodeController.php` with this exact implementation (it uses `EmailVerificationCode::MAX_ATTEMPTS` / `TTL_MINUTES` from Task 1 — do not hardcode 5 or 30):

```php
<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\OtpMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class VerificationCodeController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::check() && $request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $user = $this->effectiveUser($request);
        if (! $user) {
            return redirect()->route('register');
        }
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('login');
        }

        return view('auth.verify-code', ['email' => $this->maskEmail($user->email)]);
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        $user = $this->effectiveUser($request);
        if (! $user || $user->hasVerifiedEmail()) {
            return redirect()->route('login');
        }

        $row = EmailVerificationCode::where('user_id', $user->id)->first();

        if (! $row || $row->isExpired() || $row->hasMaxAttempts()) {
            $this->refreshCode($user);

            return back()->with('info', 'This code has expired. We have sent a fresh one.');
        }

        if (! Hash::check($request->input('code'), $row->code_hash)) {
            $row->increment('attempts');
            if ($row->fresh()->hasMaxAttempts()) {
                $this->refreshCode($user);

                return back()->with('info', 'Too many attempts. We have sent a fresh one.');
            }

            return back()->with('error', 'Incorrect code. Please try again.');
        }

        // email_verified_at is guarded — explicit assignment only. Never Auth::login here.
        $user->email_verified_at = now();
        $user->save();
        $row->delete();
        $request->session()->forget('pending_verification_user_id');

        ActivityLog::log('email_verified', 'User verified email via code', 'user', $user->id);

        return redirect()->route('login')->with('success', 'Account verified. Please log in.');
    }

    public function resend(Request $request)
    {
        $user = $this->effectiveUser($request);
        if (! $user) {
            return redirect()->route('register');
        }
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('login');
        }

        $code = EmailVerificationCode::issueFor($user);
        $sent = $this->sendBestEffort($user, $code);

        if ($sent) {
            return back()->with('success', 'A fresh verification code has been sent to your email.');
        }

        return back()->with('warning', 'We could not send the code — please try again.');
    }

    /**
     * The account this code page acts on: the logged-in-but-unverified
     * user, else the user stored in the session marker at registration.
     * No user id ever comes from the URL.
     */
    private function effectiveUser(Request $request): ?User
    {
        if (Auth::check() && ! $request->user()->hasVerifiedEmail()) {
            return $request->user();
        }

        $id = $request->session()->get('pending_verification_user_id');
        if (! $id) {
            return null;
        }

        return User::find($id);
    }

    private function refreshCode(User $user): void
    {
        $code = EmailVerificationCode::issueFor($user);
        $this->sendBestEffort($user, $code);
    }

    private function sendBestEffort(User $user, string $code): bool
    {
        try {
            app(OtpMailer::class)->sendCode($user->email, $user->name, $code);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        return substr($parts[0], 0, 1).'***@'.$parts[1];
    }
}
```

- [ ] **Step 3: Rewire routes**

Replace `routes/web.php` lines 53-79 (the EMAIL VERIFICATION block) with:

```php
// ============================================
// EMAIL VERIFICATION (OTP code via PHPMailer)
// ============================================

Route::get('/verify-code', [VerificationCodeController::class, 'show'])->name('verification-code.show');
Route::post('/verify-code', [VerificationCodeController::class, 'verify'])->name('verification-code.verify')->middleware('throttle:10,1');
Route::post('/verify-code/resend', [VerificationCodeController::class, 'resend'])->name('verification-code.resend')->middleware('throttle:3,1');

// Legacy alias: keeps the `verified` middleware and old tests working.
Route::get('/email/verify', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    if ($user->hasVerifiedEmail()) {
        return redirect()->route('dashboard');
    }
    $request->session()->put('pending_verification_user_id', $user->id);

    return redirect()->route('verification-code.show');
})->middleware('auth')->name('verification.notice');
```

Add the import at the top of `routes/web.php`:

```php
use App\Http\Controllers\VerificationCodeController;
```

- [ ] **Step 4: Rewire register + create the view**

In `AuthController@register`, replace `$user->sendEmailVerificationNotification();` with:

```php
$code = \App\Models\EmailVerificationCode::issueFor($user);

$mailSent = true;
try {
    app(\App\Services\OtpMailer::class)->sendCode($user->email, $user->name, $code);
} catch (\Throwable $e) {
    $mailSent = false;
}

ActivityLog::log('register', 'New user registered', 'user', $user->id);

$request->session()->put('pending_verification_user_id', $user->id);

if ($mailSent) {
    return redirect()->route('verification-code.show')
        ->with('success', 'Registration successful! We sent a 6-digit code to your email.');
}

return redirect()->route('verification-code.show')
    ->with('warning', 'Registered! We could not send the code — click Resend below.');
```

Remove the old `return redirect()->route('login')...` lines it replaces.

Create `resources/views/auth/verify-code.blade.php` extending `layouts.auth` (mirror `verify-email.blade.php` structure): title "Enter Verification Code", masked-email paragraph, success/warning/info/error flashes, POST form to `verification-code.verify` with a text input named `code` (`inputmode="numeric"`, `autocomplete="one-time-code"`, `maxlength="6"`, autofocus), Verify button, second POST form to `verification-code.resend` with Resend button.

- [ ] **Step 5: Auto-verify admin-created users + test**

Spec §2.5 requires admin-created accounts to skip code verification. `AdminController@createUser` (lines 177-187) currently never sets `email_verified_at`, so those accounts would be stranded. After `$user->save();` (line 187), add:

```php
// Admin-created accounts skip code verification — auto-verified.
$user->email_verified_at = now();
$user->save();
```

Append this test to `tests/Feature/VerificationCodeTest.php`:

```php
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
    $user = \App\Models\User::where('email', 'staffteacher@gmail.com')->firstOrFail();
    $this->assertTrue($user->hasVerifiedEmail());
    $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $user->id]);
}
```

Run: `php artisan test --filter="VerificationCodeTest|PasswordPolicyTest|EmailVerificationTest"`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/VerificationCodeController.php app/Http/Controllers/AuthController.php app/Http/Controllers/AdminController.php routes/web.php resources/views/auth/verify-code.blade.php tests/Feature/VerificationCodeTest.php tests/Feature/PasswordPolicyTest.php
git commit -m "feat(otp): add code verification flow and rewire registration"
```

- [ ] **Step 6: Fix PasswordPolicyTest mailer + run tests**

`POST /register` now sends mail. In `tests/Feature/PasswordPolicyTest.php`, add at the start of `test_strong_password_accepted_on_register`:

```php
$this->mock(\App\Services\OtpMailer::class, function ($mock) {
    $mock->shouldIgnoreMissing();
});
```

Run: `php artisan test --filter="VerificationCodeTest|PasswordPolicyTest|EmailVerificationTest"`
Expected: all PASS.

---

### Task 4: Cleanup, docs, and full verification

**Files:**
- Delete: `resources/views/auth/verify-email.blade.php` (unused — notice route is now a redirect)
- Modify: `docs/superpowers/specs/2026-09-29-otp-verification-design.md` (only if behavior deviated — note the deviation instead of rewriting)
- Test: full suite + live SMTP check

- [ ] **Step 1: Delete the dead view and grep for leftovers**

```bash
git rm resources/views/auth/verify-email.blade.php
```

Run (PowerShell): `Get-ChildItem -Recurse -Include *.php,*.blade.php app,routes,resources,tests,config | Select-String -Pattern "sendEmailVerificationNotification|verification\.send|verification\.verify|verify-email"`
Expected: no matches except the kept `verification.notice` alias and its test.

- [ ] **Step 2: Run the FULL suite**

Run: `php artisan test`
Expected: all green (35 pre-existing + new OTP tests), 0 failures. Fix any regression before continuing — do not bundle fixes.

- [ ] **Step 3: Verify migrations end-to-end (scratch DB, MySQL if available)**

Run: `php artisan migrate --force` on the dev database; confirm the new table migrates cleanly alongside the four prior security migrations.
Expected: all DONE, no errors.

- [ ] **Step 4: Live Gmail check (requires real App Password in `.env`)**

With `MAIL_USERNAME` + 16-char `MAIL_PASSWORD` set and `php artisan config:clear` run, register a test account through the browser, confirm the email arrives in Gmail, paste the code, confirm redirect to `/login` as guest, then log in. If no App Password is available, record this step as NOT DONE in the commit message trailer and hand back to the user.

- [ ] **Step 5: Commit (stage OTP paths only — never `git add -A`; the tree has unrelated dirty files)**

```bash
git status --short
git add <OTP-related paths shown above, e.g. tests/Feature/VerificationCodeTest.php>
git add docs/superpowers/plans/2026-09-29-otp-verification.md
git commit -m "chore(otp): remove dead signed-link verification view and verify full suite"
```

---

## Open points for the user (do not resolve unilaterally)

1. Legacy unverified accounts (e.g. local `parent2-5`, `teacher4-6`) can never log in to request a code. The `verification.notice` alias covers already-authenticated sessions, but logged-out legacy users are stranded. Recommended follow-up: on correct-password-but-unverified login, issue a code and redirect to the code page instead of the generic error. Needs explicit approval — it touches the login security posture.
2. Task 4 Step 4 needs a real Gmail App Password; without it, live SMTP stays unverified by tests (faked at the service boundary).
