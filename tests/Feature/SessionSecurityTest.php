<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    protected function shippedSessionDefaults(): array
    {
        // Clear any developer-local .env overrides (untracked, must-not-touch)
        // so the test evaluates the SHIPPED defaults hermetically.
        putenv('SESSION_ENCRYPT');
        unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

        // Load the actual file under test so its real default expressions are evaluated.
        return require config_path('session.php');
    }

    public function test_session_encrypt_defaults_true(): void
    {
        $defaults = $this->shippedSessionDefaults();

        $this->assertTrue($defaults['encrypt']);
    }

    public function test_session_secure_defaults_true(): void
    {
        $defaults = $this->shippedSessionDefaults();

        $this->assertTrue($defaults['secure']);
    }
}
