<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Learner;
use Illuminate\Http\Request;

class StudentAuthController extends Controller
{
    public function showLogin()
    {
        if (session('student_learner_id')) {
            return redirect()->route('student.dashboard');
        }

        return view('student.auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        // Scan all PIN holders (not just active ones) so a matched but
        // locked/inactive row can have its failure counter incremented.
        // A wrong PIN matches no row, so there is nothing to increment —
        // the Task 1 throttle already bounds blind guessing.
        $learner = Learner::whereNotNull('pin')
            ->get()
            ->first(fn (Learner $candidate) => $candidate->checkPin($request->pin));

        if ($learner && ($learner->isLocked() || !$learner->is_active)) {
            $attempts = $learner->failed_login_attempts + 1;
            $lockout = ['failed_login_attempts' => $attempts];
            if ($attempts >= 10) {
                $lockout['locked_at'] = now()->addMinutes(15);
            }
            $learner->update($lockout);

            // Log the failed attempt (learner id + IP + user agent only —
            // NEVER the attempted PIN value).
            ActivityLog::log(
                'student_login_failed',
                "Failed student login attempt for learner #{$learner->id}.",
                'learner',
                $learner->id
            );

            return back()->with('error', 'Invalid PIN. Please try again or ask your teacher.');
        }

        if (!$learner) {
            // No row matched: nothing to increment (Task 1 throttle bounds
            // blind guessing). Log without any PIN value or learner id.
            ActivityLog::log(
                'student_login_failed',
                'Failed student login attempt with an unrecognized PIN.',
                'learner',
                null
            );

            return back()->with('error', 'Invalid PIN. Please try again or ask your teacher.');
        }

        $learner->update(['failed_login_attempts' => 0, 'locked_at' => null]);

        // PINs issued before they could be viewed are bcrypt hashes; the PIN was just proven correct,
        // so store it encrypted from now on and the teacher can see it.
        $learner->upgradeLegacyPin($request->pin);

        // Expiry is DETECTION ONLY — never block login here (no rotation UX
        // yet). Log distinctly so teachers/admins can act.
        if ($learner->isPinExpired()) {
            ActivityLog::log(
                'student_login_expired_pin',
                "Learner #{$learner->id} logged in with an expired PIN.",
                'learner',
                $learner->id
            );
        }

        session(['student_learner_id' => $learner->id]);
        $request->session()->regenerate();

        // Record daily activity for streak
        $learner->recordActivity();

        return redirect()->route('student.dashboard');
    }

    public function logout()
    {
        $learner = Learner::find(session('student_learner_id'));
        if ($learner) {
            ActivityLog::create([
                'user_id' => null,
                'action' => 'learner_logout',
                'description' => "Learner {$learner->first_name} {$learner->last_name} logged out",
                'subject_type' => 'learner',
                'subject_id' => $learner->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
        }

        session()->forget('student_learner_id');
        return redirect()->route('student.login')
            ->with('success', 'You have been logged out. See you next time!');
    }
}
