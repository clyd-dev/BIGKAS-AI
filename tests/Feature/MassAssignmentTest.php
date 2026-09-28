<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test@test.com',
            'password' => 'Password123',
            'role' => 'admin',
        ]);
        $this->assertNotEquals('admin', $user->fresh()->role);
    }

    public function test_user_sensitive_fields_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test2@test.com',
            'password' => 'Password123',
            'is_active' => false,
            'email_verified_at' => now(),
        ]);

        // DB defaults win: is_active defaults true, email_verified_at stays null.
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_learner_sensitive_fields_cannot_be_mass_assigned(): void
    {
        $learner = Learner::create([
            'first_name' => 'Test',
            'last_name' => 'Learner',
            'grade_level' => 1,
            'pin' => '123456',
            'is_active' => false,
            'total_xp' => 9999,
            'current_streak' => 99,
            'longest_streak' => 99,
        ]);

        $fresh = $learner->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertEquals(0, $fresh->total_xp);
        $this->assertEquals(0, $fresh->current_streak);
        $this->assertEquals(0, $fresh->longest_streak);
        $this->assertNull($fresh->getRawOriginal('pin'));
    }

    public function test_explicit_assignment_of_guarded_fields_still_works(): void
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'test3@test.com',
            'password' => 'Password123',
        ]);
        $user->role = 'teacher';
        $user->is_active = true;
        $user->save();

        $this->assertEquals('teacher', $user->fresh()->role);

        $learner = Learner::create([
            'first_name' => 'Test',
            'last_name' => 'Learner',
            'grade_level' => 1,
        ]);
        $learner->pin = '123456';
        $learner->save();

        $this->assertTrue($learner->fresh()->checkPin('123456'));
    }
}
