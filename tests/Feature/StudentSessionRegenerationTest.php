<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentSessionRegenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_login_regenerates_session(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456', 'is_active' => true]);

        // Seed a real, started session with a known ID (simulating an
        // attacker-fixated session) and send it as the request's cookie,
        // so the pre-login ID is deterministic rather than unstarted.
        $store = $this->app['session']->driver();
        $store->setId(Str::random(40));
        $store->start();
        $oldSessionId = $store->getId();

        $response = $this->withCookie($store->getName(), $oldSessionId)
            ->post('/student/login', ['pin' => '123456']);

        $response->assertRedirect(route('student.dashboard'));
        $this->assertNotEquals($oldSessionId, $store->getId());
        $this->assertEquals($learner->id, $store->get('student_learner_id'));
    }
}
