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
