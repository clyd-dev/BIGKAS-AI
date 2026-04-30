<?php

namespace App\Http\Middleware;

use App\Models\Learner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentAuth
{
    /**
     * Ensure the request has a valid student (learner) session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $learnerId = session('student_learner_id');

        if (!$learnerId) {
            return redirect()->route('student.login')
                ->with('error', 'Please log in with your PIN.');
        }

        $learner = Learner::find($learnerId);

        if (!$learner || !$learner->is_active) {
            session()->forget('student_learner_id');
            return redirect()->route('student.login')
                ->with('error', 'Your account is not active. Please ask your teacher.');
        }

        // Share learner with all views
        view()->share('currentLearner', $learner);
        $request->attributes->set('learner', $learner);

        return $next($request);
    }
}
