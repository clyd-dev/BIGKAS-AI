<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'microphone=(self), camera=(), geolocation=()');
        $response->headers->set('X-XSS-Protection', '0');

        // Every page here is tied to one signed-in person. Laravel's default
        // "no-cache, private" still lets the browser keep the page in history,
        // which showed the previous account's dashboard after a logout and
        // re-served login forms holding a CSRF token the server had forgotten.
        // Only no-store keeps them out of the back/forward cache.
        if (! $this->hasPublicCaching($response)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    /** A response that deliberately asked to be cached keeps its own headers. */
    private function hasPublicCaching(Response $response): bool
    {
        return str_contains($response->headers->get('Cache-Control', ''), 'public');
    }
}
