<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearnerAdminReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_admin_can_view_index_and_show(): void
    {
        $admin = $this->user('admin');
        $learner = Learner::factory()->create();

        $this->actingAs($admin)->get(route('learners.index'))
            ->assertOk()
            ->assertDontSee('Add Learner');
        $this->actingAs($admin)->get(route('learners.show', $learner))
            ->assertOk()
            ->assertDontSee('Delete Learner');
    }

    public function test_admin_cannot_modify_roster(): void
    {
        $admin = $this->user('admin');
        $learner = Learner::factory()->create();

        $this->actingAs($admin)->get(route('learners.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('learners.store'), [])->assertForbidden();
        $this->actingAs($admin)->get(route('learners.edit', $learner))->assertForbidden();
        $this->actingAs($admin)->put(route('learners.update', $learner), [])->assertForbidden();
        $this->actingAs($admin)->delete(route('learners.destroy', $learner))->assertForbidden();
        $this->actingAs($admin)->post(route('learners.import.preview'))->assertForbidden();
        $this->assertDatabaseHas('learners', ['id' => $learner->id]);
    }

    public function test_index_is_paginated_and_keeps_filters(): void
    {
        $admin = $this->user('admin');
        Learner::factory()->count(20)->create(['reading_level' => 'independent']);

        $this->actingAs($admin)->get(route('learners.index', ['reading_level' => 'independent']))
            ->assertOk()
            ->assertSee('Showing 1–10 of 20')
            ->assertSee('reading_level=independent');
    }
}
