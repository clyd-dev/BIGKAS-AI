<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinHashingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_is_hashed_in_database(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456']);
        $this->assertNotEquals('123456', $learner->pin);
        $this->assertStringStartsWith('$2y$', $learner->pin);
    }

    public function test_checkPin_validates_correctly(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456']);
        $this->assertTrue($learner->checkPin('123456'));
        $this->assertFalse($learner->checkPin('654321'));
    }
}
