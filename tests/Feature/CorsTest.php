<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_api_response_has_cors_headers(): void
    {
        $response = $this->getJson('/api/ml/health', ['Origin' => config('app.url')]);
        $response->assertHeader('Access-Control-Allow-Origin');
    }
}
