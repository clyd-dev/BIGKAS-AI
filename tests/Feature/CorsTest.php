<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_api_response_has_cors_headers(): void
    {
        $response = $this->getJson('/api/ml/health', ['Origin' => config('app.url')]);

        // Must echo the bare app origin — never a wildcard.
        $expected = $this->bareOrigin((string) config('app.url'));

        $response->assertHeader('Access-Control-Allow-Origin', $expected);
        $this->assertNotEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * Derive the bare origin (scheme://host[:port]) from a URL, mirroring
     * the derivation in config/cors.php.
     */
    private function bareOrigin(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return rtrim($url, '/');
        }

        $origin = ($parts['scheme'] ?? 'http') . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
