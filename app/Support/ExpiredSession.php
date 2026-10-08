<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What to do when a form's CSRF token no longer matches the session.
 *
 * Laravel's default is the "419 Page Expired" screen, which is a dead end: it
 * explains nothing a teacher can act on, and it leaves the old session in
 * place — so a logout that hit a stale token appeared to work while the
 * account stayed signed in. Each case gets a way forward instead.
 */
final class ExpiredSession
{
    /** Paths where a stale token must still end the session. */
    private const LOGOUT_PATHS = ['logout', 'student/logout'];

    public static function respond(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Your session expired before this could be saved. Reload the page, sign in again, and retry.',
            ], 419);
        }

        if ($request->is(...self::LOGOUT_PATHS)) {
            // They asked to leave the account. Staying signed in is the worse
            // outcome by far, so honour it and report it as a plain logout.
            SignOut::everywhere($request);

            return redirect()->route('login')->with('success', 'You have been logged out.');
        }

        $studentPortal = $request->is('student/*');

        // Anything else: hand back a page with a live token. Signing in again
        // is the only route back once the session behind the form is gone.
        if ($request->hasSession()) {
            $request->session()->regenerateToken();
        }

        return redirect()->route($studentPortal ? 'student.login' : 'login')
            ->with('error', $studentPortal
                ? 'You were away too long, so we signed you out. Please enter your PIN again.'
                : 'Your session timed out for security. Please sign in again — nothing was lost.');
    }
}
