<?php

namespace Tests\Feature;

use App\Models\AssessmentBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

class BatchFeatureFlagTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    public function test_the_batch_routes_are_hidden_when_the_flag_is_off(): void
    {
        config(['assessment.batch_enabled' => false]);

        $this->actingAs($this->teacher())->get(route('assessments.batch.create'))->assertNotFound();
    }

    public function test_the_batch_setup_page_opens_when_the_flag_is_on(): void
    {
        config(['assessment.batch_enabled' => true]);

        $this->actingAs($this->teacher())->get(route('assessments.batch.create'))->assertOk();
    }

    public function test_the_flag_hides_every_batch_route_not_just_the_setup_page(): void
    {
        $teacher = $this->teacher();
        $batch = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);

        // A real batch, so model binding succeeds and the 404 can only come from
        // the flag - not from a missing record.
        config(['assessment.batch_enabled' => false]);
        $this->actingAs($teacher)->post(route('assessments.batch.send-all', $batch))->assertNotFound();

        // With the flag on the same URL reaches the controller, which is still
        // unbuilt (501). Proves the group guards the route rather than the action.
        config(['assessment.batch_enabled' => true]);
        $this->actingAs($teacher)->post(route('assessments.batch.send-all', $batch))->assertStatus(501);
    }

    public function test_the_flag_defaults_to_off(): void
    {
        $this->assertFalse(config('assessment.batch_enabled'));
    }

    public function test_the_roster_cap_is_fifteen(): void
    {
        $this->assertSame(15, config('assessment.batch_max_learners'));
    }
}
