<?php

namespace Tests\Feature;

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

    public function test_the_flag_defaults_to_off(): void
    {
        $this->assertFalse(config('assessment.batch_enabled'));
    }

    public function test_the_roster_cap_is_fifteen(): void
    {
        $this->assertSame(15, config('assessment.batch_max_learners'));
    }
}
