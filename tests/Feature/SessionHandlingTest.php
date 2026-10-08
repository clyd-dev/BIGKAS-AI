<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Teachers reported "419 Page Expired" on login and logout, and sessions that
 * came back to the previous account after logging out. Three separate defects
 * produced that:
 *
 *  1. The session cookie was marked Secure by default, so over plain HTTP
 *     (the server is reached by IP, no certificate yet) the browser dropped it.
 *     Every POST then arrived with a brand-new session and no matching token.
 *  2. Pages carried no no-store, so the browser could serve a signed-in page —
 *     or a login form holding a dead token — from history after logout.
 *  3. A token mismatch was a dead end: the Page Expired screen left the old
 *     session intact, so logging out could silently fail.
 */
class SessionHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function sessionCookie($response)
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        return null;
    }

    /** Over plain HTTP the cookie must be sent, or no session can ever hold. */
    public function test_the_session_cookie_is_usable_over_plain_http(): void
    {
        $cookie = $this->sessionCookie($this->get('http://localhost/login'));

        $this->assertNotNull($cookie, 'No session cookie was set on the login page.');
        $this->assertFalse(
            $cookie->isSecure(),
            'A Secure session cookie is discarded by the browser over plain HTTP, which is what caused the 419s.'
        );
    }

    /** On HTTPS it must still be Secure — the hardening is kept, not dropped. */
    public function test_the_session_cookie_is_secure_over_https(): void
    {
        $cookie = $this->sessionCookie($this->get('https://localhost/login'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure(), 'HTTPS requests must still get a Secure session cookie.');
        $this->assertTrue($cookie->isHttpOnly());
    }

    /** A signed-in page must not be left in history for the next person. */
    public function test_a_signed_in_page_is_never_stored_by_the_browser(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($teacher)->get(route('dashboard'));

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** A login form served from history carries a token the server forgot. */
    public function test_the_login_page_is_never_stored_by_the_browser(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** A stale token on login means "sign in again", not a dead-end screen. */
    public function test_a_stale_token_on_login_returns_a_fresh_login_form(): void
    {
        $request = Request::create(route('login'), 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));
    }

    /** Logging out must log you out even when the page's token went stale. */
    public function test_a_stale_token_on_logout_still_logs_the_user_out(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        Auth::login($teacher);
        $this->assertTrue(Auth::check());

        $request = Request::create(route('logout'), 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));
        $this->assertFalse(Auth::check(), 'A stale token must not keep the user signed in.');
    }

    /** An expired token on an AJAX call gets an answer the page can act on. */
    public function test_a_stale_token_on_a_json_request_is_explained(): void
    {
        $request = Request::create('/assessments/1/analyze', 'POST', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('session', strtolower($response->getContent()));
    }

    /**
     * The learner portal shares one session cookie with the staff portal, and
     * its logout only forgot the learner key. Anything else in the session —
     * including a signed-in teacher — survived, so the browser went straight
     * back to the previous account.
     */
    public function test_student_logout_clears_the_whole_session(): void
    {
        $learner = Learner::factory()->create(['is_active' => true]);
        $teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        Auth::login($teacher);
        session(['student_learner_id' => $learner->id]);
        $tokenBefore = session()->token();

        $response = $this->post(route('student.logout'));

        $response->assertRedirect(route('student.login'));
        $this->assertNull(session('student_learner_id'));
        $this->assertFalse(Auth::check(), 'A staff login must not survive a learner logout on the same device.');
        $this->assertNotSame($tokenBefore, session()->token(), 'The CSRF token must be regenerated on logout.');
    }

    /** Staff logout already invalidated; keep it covered against regressions. */
    public function test_staff_logout_clears_the_whole_session(): void
    {
        $learner = Learner::factory()->create(['is_active' => true]);
        $teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $this->actingAs($teacher);
        session(['student_learner_id' => $learner->id]);
        $tokenBefore = session()->token();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertFalse(Auth::check());
        $this->assertNull(session('student_learner_id'));
        $this->assertNotSame($tokenBefore, session()->token());
    }
}
