<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpsEnforcementMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Load-balancer health checks hit http:///up — never redirect them.
        if ($this->shouldExclude($request)) {
            return $next($request);
        }

        // Strict no-op outside production (local/dev/test use HTTP).
        // In production, $request->secure() honors X-Forwarded-Proto only
        // when trusted proxies are configured (see TRUSTED_PROXIES).
        if (! app()->environment('production') || $request->secure()) {
            return $next($request);
        }

        return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
    }

    public function shouldExclude(Request $request): bool
    {
        return $request->is('up');
    }
}
