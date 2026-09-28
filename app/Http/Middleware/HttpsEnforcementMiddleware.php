<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpsEnforcementMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Strict no-op outside production (local/dev/test use HTTP).
        // In production, $request->secure() honors X-Forwarded-Proto only
        // when trusted proxies are configured (see TRUSTED_PROXIES).
        if (! app()->environment('production') || $request->secure()) {
            return $next($request);
        }

        return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
    }
}
