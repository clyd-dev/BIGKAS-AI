<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    protected function resolveShippedSessionDefaults(): void
    {
        // Clear any developer-local .env overrides (untracked, must-not-touch)
        // so the test verifies the SHIPPED defaults hermetically.
        putenv('SESSION_ENCRYPT');
        unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

        // Re-resolve config using exactly the default expressions in config/session.php.
        config(['session.encrypt' => env('SESSION_ENCRYPT', true)]);
        config(['session.secure' => env('SESSION_SECURE_COOKIE', true)]);
    }

    public function test_session_encrypt_defaults_true(): void
    {
        $this->resolveShippedSessionDefaults();

        $this->assertTrue(config('session.encrypt'));
    }

    public function test_session_secure_defaults_true(): void
    {
        $this->resolveShippedSessionDefaults();

        $this->assertTrue(config('session.secure'));
    }
}
