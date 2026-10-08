<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolInfoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    }

    public function test_admin_sees_form_and_can_update_school_information(): void
    {
        $school = School::create(['name' => 'Old Sagay ES', 'division' => 'Negros Occidental', 'school_id_number' => 'SCH-001']);

        $this->actingAs($this->admin())->get(route('admin.schools'))
            ->assertOk()->assertSee('School Information')->assertSee('Negros Occidental');

        $this->actingAs($this->admin())->put(route('admin.schools.update', $school), [
            'name' => 'Old Sagay ES', 'division' => 'Sagay City', 'district' => 'Sagay City',
            'region' => 'Region VI', 'school_id_number' => 'SCH-001', 'principal_name' => 'Dr. Maria Santos',
        ])->assertRedirect();

        $this->assertSame('Sagay City', $school->fresh()->division);
    }

    public function test_school_id_must_stay_unique_but_may_keep_its_own(): void
    {
        $a = School::create(['name' => 'A', 'school_id_number' => '111111']);
        School::create(['name' => 'B', 'school_id_number' => '222222']);

        $this->actingAs($this->admin())->put(route('admin.schools.update', $a), ['name' => 'A', 'school_id_number' => '222222'])
            ->assertSessionHasErrors('school_id_number');
        $this->actingAs($this->admin())->put(route('admin.schools.update', $a), ['name' => 'A2', 'school_id_number' => '111111'])
            ->assertSessionHasNoErrors();
    }
}
