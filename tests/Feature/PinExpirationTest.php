<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_pin_is_detected(): void
    {
        $learner = Learner::factory()->create([
            'pin' => '123456',
            'pin_created_at' => now()->subMonths(7),
        ]);
        $this->assertTrue($learner->isPinExpired());
    }

    public function test_fresh_pin_is_not_expired(): void
    {
        $learner = Learner::factory()->create([
            'pin' => '123456',
            'pin_created_at' => now(),
        ]);
        $this->assertFalse($learner->isPinExpired());
    }
}
