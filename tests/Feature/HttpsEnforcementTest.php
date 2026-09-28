<?php

namespace Tests\Feature;

use Tests\TestCase;

class HttpsEnforcementTest extends TestCase
{
    public function test_middleware_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\HttpsEnforcementMiddleware::class));
    }

    public function test_no_redirect_outside_production(): void
    {
        // APP_ENV in tests is not production: HTTP must NOT redirect.
        $response = $this->get('http://localhost/');
        $response->assertOk();
    }
}
