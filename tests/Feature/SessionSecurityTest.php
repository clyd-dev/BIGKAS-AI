<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    public function test_session_encrypt_defaults_true(): void
    {
        $this->assertTrue(config('session.encrypt'));
    }

    public function test_session_secure_defaults_true(): void
    {
        $this->assertTrue(config('session.secure'));
    }
}
