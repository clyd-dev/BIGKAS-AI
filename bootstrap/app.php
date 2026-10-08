<?php

use App\Support\ExpiredSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust TLS-terminating proxies (nginx, Cloudflare) so
        // $request->secure() honors X-Forwarded-Proto. Without this,
        // HttpsEnforcementMiddleware would redirect-loop behind a proxy.
        // Set TRUSTED_PROXIES=* (all) or a comma-separated list of proxy IPs.
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        $middleware->append(\App\Http\Middleware\HttpsEnforcementMiddleware::class);

        // Register custom middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'teacher.assigned' => \App\Http\Middleware\EnsureTeacherAssigned::class,
            'student.auth' => \App\Http\Middleware\StudentAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A stale CSRF token is a routine event (a tab left open past the
        // session lifetime), not a crash. The default 419 screen is a dead
        // end that also leaves the old session intact — see ExpiredSession.
        // The handler rewrites TokenMismatchException to a 419 HttpException
        // before render callbacks run, so match on the status, not the class.
        $exceptions->render(fn (HttpException $e, Request $request) => $e->getStatusCode() === 419
            ? ExpiredSession::respond($request)
            : null);
    })->create();
