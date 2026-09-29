<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\School;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Show login form.
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    /**
     * Process login.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Check if user is active
        $user = User::where('email', $credentials['email'])->first();

        if ($user && !$user->is_active) {
            return back()->with('error', 'Your account has been deactivated. Please contact the administrator.');
        }

        if ($user && $user->isLocked()) {
            return back()->with('error', 'Your account is temporarily locked due to too many failed login attempts. Please try again later.');
        }

        if ($user && !$user->hasVerifiedEmail()) {
            return back()->with('error', 'Please verify your email address before logging in.');
        }

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            // Update last login and reset lockout counters
            $user = Auth::user();
            $user->update(['last_login_at' => now(), 'failed_login_attempts' => 0, 'locked_at' => null]);

            ActivityLog::log('login', 'User logged in');

            return redirect()->intended(route('dashboard'))
                ->with('success', 'Welcome back, ' . $user->name . '!');
        }

        if ($user) {
            $attempts = $user->failed_login_attempts + 1;
            $lockout = ['failed_login_attempts' => $attempts];
            if ($attempts >= 5) {
                $lockout['locked_at'] = now()->addMinutes(15);
            }
            $user->update($lockout);
        }

        return back()->with('error', 'Invalid email or password.');
    }

    /**
     * Show registration form.
     */
    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $schools = School::active()->orderBy('name')->get();

        return view('auth.register', compact('schools'));
    }

    /**
     * Process registration.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
            'role' => 'required|in:teacher,parent,student',
            'school_id' => 'nullable|exists:schools,id',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // Auto-hashed via cast
            'school_id' => $request->school_id,
            'phone' => $request->phone,
        ]);

        // role is guarded — set via explicit assignment, never mass assignment.
        $user->role = $request->role;
        $user->save();

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
    }

    /**
     * Logout user.
     */
    public function logout(Request $request)
    {
        ActivityLog::log('logout', 'User logged out');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been logged out.');
    }

    /**
     * Show forgot password form.
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Process forgot password.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->with('error', __($status));
        }

        ActivityLog::log('forgot_password', "Password reset requested for: {$request->email}", 'user', null);

        return redirect()->route('login')
            ->with('success', 'If an account with that email exists, we have sent a password reset link.');
    }

    /**
     * Show reset password form.
     */
    public function showResetPassword(string $token, Request $request)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    /**
     * Process password reset.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
        ]);

        $status = Password::reset(
            $request->only('email', 'token', 'password', 'password_confirmation'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->with('error', __($status));
        }

        ActivityLog::log('reset_password', "Password reset completed for: {$request->email}", 'user', null);

        return redirect()->route('login')->with('success', 'Your password has been reset.');
    }
}
