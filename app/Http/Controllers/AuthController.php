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

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            // Update last login
            $user = Auth::user();
            $user->update(['last_login_at' => now()]);

            ActivityLog::log('login', 'User logged in');

            return redirect()->intended(route('dashboard'))
                ->with('success', 'Welcome back, ' . $user->name . '!');
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
            'password' => 'required|min:6|confirmed',
            'role' => 'required|in:teacher,parent,student',
            'school_id' => 'nullable|exists:schools,id',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // Auto-hashed via cast
            'role' => $request->role,
            'school_id' => $request->school_id,
            'phone' => $request->phone,
        ]);

        Auth::login($user);

        ActivityLog::log('register', 'New user registered', 'user', $user->id);

        return redirect()->route('dashboard')
            ->with('success', 'Registration successful! Welcome to BIGKAS-AI.');
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
            'password' => 'required|min:6|confirmed',
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
