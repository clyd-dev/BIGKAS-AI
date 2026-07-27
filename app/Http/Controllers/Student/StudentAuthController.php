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
            'pin' => 'required|string|size:6',
        ]);

        $learner = Learner::where('pin', $request->pin)
            ->where('is_active', true)
            ->first();

        if (!$learner) {
            return back()->with('error', 'Invalid PIN. Please try again or ask your teacher.');
        }

        session(['student_learner_id' => $learner->id]);

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
