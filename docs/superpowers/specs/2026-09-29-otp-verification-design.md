# OTP Email-Code Verification — Design Spec

Date: 2026-09-29
Status: approved by user (4/4 sections + no-auto-login constraint)
Approach: A — verification-codes table + PHPMailer service

## 1. Objective

Replace Laravel's signed-link email verification with a Gmail-delivered
6-digit code that the user pastes into a dedicated BIGKAS-AI web page.
Registration-only. Sent with PHPMailer over the existing Gmail SMTP
settings in `.env`. After successful verification the user is redirected
to `/login` and logs in manually — **never auto-logged in**.

## 2. Flow

1. User registers (name, email, password, role). Account created
   **unverified**. A 6-digit code from `random_int(100000, 999999)` is
   generated, bcrypt-hashed, stored with 30-minute expiry, and emailed.
   Browser redirects to `/verify-code`.
2. `/verify-code` shows the masked email (`j***@gmail.com`), one
   paste-friendly input (`inputmode=numeric`,
   `autocomplete=one-time-code`), a Verify button, and a Resend link.
3. Correct code within policy → `email_verified_at` set, code row
   deleted, redirect to `/login` with "Account verified, please log in."
   No `Auth::login` happens at any point in this flow.
4. Unverified users **cannot log in** — login rejects with the existing
   generic message, revealing nothing about verification status.
5. Admin-created accounts skip verification (auto-verified, as today).
6. The old signed-link verify/resend routes and the verification-notice
   resend flow are removed — exactly one verification path exists.
7. API registration is unchanged (mobile clients do not paste email codes).
8. Learner PIN login is unchanged (learners have no email field).

## 3. Components

- **Migration — `email_verification_codes` table:** `id`, `user_id`
  (FK to `users`, cascade on delete), `code_hash` (string 255, bcrypt),
  `expires_at` (datetime), `attempts` (unsigned integer, default 0),
  timestamps. One row per user: issuing a new code deletes/replaces the
  existing row, which enforces "resend voids old" at the data layer.
  Additive table only — backward-compatible schema.
- **`OtpMailer` service (`app/Services/OtpMailer.php`):** thin wrapper
  around PHPMailer (`composer require phpmailer/phpmailer`). Reads
  `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` from config.
  Single public method
  `sendCode(string $email, string $name, string $code): void`.
  Throws on SMTP failure so callers can degrade gracefully.
- **`VerificationCodeController`:** `show` (GET `/verify-code`),
  `verify` (POST, `throttle:10,1`), `resend` (POST, `throttle:3,1`).
  `AuthController@register` creates user + code + sends mail, stores a
  pending-verification marker in session, redirects to `/verify-code`.
- **View `auth/verify-code.blade.php`:** reuses the existing auth layout;
  masked email, error/success flashes, resend link (rate-limited to
  3/min by route throttle; no separate cooldown timer).

## 4. Security rules

- Codes stored bcrypt-hashed only; verified with `Hash::check`.
- 30-minute server-side expiry; expired codes are deleted on sight and
  the user must resend.
- 5 wrong attempts voids the code (row deleted; resend required).
- Throttle: verify 10/min, resend 3/min.
- No enumeration: generic messages everywhere; resend always claims
  success; the code page never confirms whether an email is registered.
- Session binding: the code page serves only the account that registered
  in that session; no user id in the URL.

## 5. Error handling

- SMTP failure at register: account still created (unverified); code page
  shows "We couldn't send the code — click Resend." No 500, no trace.
- Expired/voided code submitted: "This code has expired. We've sent a
  fresh one." (auto-resend in that case).
- Already-verified user opens `/verify-code`: redirect to `/login`.
- Resend when no pending code exists: redirect to `/register`.

## 6. Testing

Feature tests (PHPMailer faked at the service boundary):
register → code sent + redirect; correct code → verified + login redirect
+ no session authenticated; wrong code × 5 → voided; expired code →
rejected; resend → old code dead, new code works; unverified login →
blocked; admin-created user → verified, unaffected; SMTP failure →
graceful message. Full suite must stay green; no existing tests broken.

## 7. Out of scope

- Password reset flow (untouched, still Laravel broker).
- Ability enforcement for API tokens, PIN rotation UX, MySQL staging
  verification — tracked as prior follow-ups.
