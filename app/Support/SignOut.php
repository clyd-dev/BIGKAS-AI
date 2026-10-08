<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ending a session in one place.
 *
 * The staff portal and the learner portal share a single session cookie: staff
 * sit on the `web` guard, learners on a `student_learner_id` key. Forgetting
 * only one of those left the other signed in, so a learner logging out on a
 * shared tablet could land back on the teacher's dashboard. Every logout path
 * goes through here instead.
 */
final class SignOut
{
    /** Leave nothing behind: the guard, the learner key, the CSRF token. */
    public static function everywhere(Request $request): void
    {
        Auth::logout();

        if (! $request->hasSession()) {
            return;
        }

        // invalidate() clears the data and rotates the session id; the token
        // has to be rotated too, or the next form carries the dead one.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
