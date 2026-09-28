<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSessionRegenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_login_regenerates_session(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456', 'is_active' => true]);
        $oldSessionId = session()->getId();
        $this->post('/student/login', ['pin' => '123456']);
        $this->assertNotEquals($oldSessionId, session()->getId());
    }
}
