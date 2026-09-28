<?php

/*
|--------------------------------------------------------------------------
| CORS allowed origin derivation
|--------------------------------------------------------------------------
|
| CORS origins must be origin-only (scheme://host[:port]) — never a URL
| with a path. HandleCors exact-matches the request Origin header, so a
| path-carrying APP_URL (e.g. http://localhost/bigkas-ai) would never
| match. Derive the bare origin here; an explicit CORS_ALLOWED_ORIGIN
| env value takes precedence when set.
|
*/

$appOrigin = (function (): string {
    $raw = env('CORS_ALLOWED_ORIGIN') ?: env('APP_URL', 'http://localhost');
    $raw = (string) $raw;

    $parts = parse_url($raw);

    if (! is_array($parts) || empty($parts['host'])) {
        return rtrim($raw, '/');
    }

    $origin = ($parts['scheme'] ?? 'http') . '://' . $parts['host'];

    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    return $origin;
})();

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    | Security: origins are restricted to the bare application origin derived
    | from APP_URL (or CORS_ALLOWED_ORIGIN when set). Never use a wildcard
    | here; the API is consumed same-origin by first-party Blade/JS, and
    | native mobile clients ignore CORS.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([$appOrigin]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
