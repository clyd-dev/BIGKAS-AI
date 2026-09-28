<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            'student.auth' => \App\Http\Middleware\StudentAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
