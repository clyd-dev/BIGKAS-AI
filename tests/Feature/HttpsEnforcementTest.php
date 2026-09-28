<?php

namespace Tests\Feature;

use App\Http\Middleware\HttpsEnforcementMiddleware;
use Illuminate\Http\Request;
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

    public function test_up_health_check_is_excluded_from_https_redirect(): void
    {
        $middleware = new HttpsEnforcementMiddleware();

        // APP_ENV is testing so the production check can't be flipped here;
        // unit-test the exclusion directly instead.
        $this->assertTrue($middleware->shouldExclude(Request::create('/up', 'GET')));
        $this->assertFalse($middleware->shouldExclude(Request::create('/login', 'GET')));
    }
}
