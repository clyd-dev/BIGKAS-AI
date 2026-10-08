<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A teacher who has not been given a grade & section yet can look around, but cannot add learners or
 * conduct assessments. Admins and other roles pass through untouched.
 */
class EnsureTeacherAssigned
{
    public const MESSAGE = 'You can add learners and conduct assessments once the principal assigns you a grade & section.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isTeacher() && ! $user->hasAssignedClass()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => self::MESSAGE], 403);
            }

            return redirect()->route('dashboard')->with('error', self::MESSAGE);
        }

        return $next($request);
    }
}
