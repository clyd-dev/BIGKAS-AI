<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\ReadingMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * A batch is a roster of ordinary assessments sharing a nullable batch_id.
 * Deleting a batch must never take a child's assessment with it.
 */
class AssessmentBatchTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    public function test_an_assessment_with_no_batch_is_a_single_child_assessment(): void
    {
        $assessment = Assessment::factory()->create();

        $this->assertNull($assessment->batch_id);
        $this->assertNull($assessment->batch);
    }

    public function test_a_batch_holds_its_entries_and_its_defaults(): void
    {
        $batch = AssessmentBatch::factory()->create([
            'language' => 'fil',
            'assessment_type' => Assessment::TYPE_COMBINED,
            'status' => AssessmentBatch::STATUS_COLLECTING,
        ]);
        $entry = Assessment::factory()->create(['batch_id' => $batch->id]);

        $this->assertTrue($batch->entries->contains($entry));
        $this->assertSame($batch->id, $entry->batch->id);
    }

    public function test_deleting_a_batch_keeps_its_assessments_and_their_results(): void
    {
        $batch = AssessmentBatch::factory()->create();
        $entry = Assessment::factory()->create(['batch_id' => $batch->id]);

        // The result is the half of this test's name that used to go unproven,
        // and it is the half that matters: a measurement about a child must not
        // leave with the roster it happened to be made on.
        $entry->createResult($this->classifiedAnalysis());
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $entry->id]);

        $batch->delete();

        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $entry->id,
            'reading_level' => 'independent',
        ]);
    }

    public function test_a_material_cited_by_a_batch_cannot_be_deleted(): void
    {
        $batch = AssessmentBatch::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);
        ReadingMaterial::findOrFail($batch->default_material_id)->delete();
    }

    public function test_a_new_batch_starts_collecting_and_has_no_submitted_entries(): void
    {
        $entry = Assessment::factory()->create(['batch_id' => AssessmentBatch::factory()]);

        // "Starts collecting" is half the claim, so it is asserted: a new batch
        // is collecting recordings, not analysing them.
        $this->assertSame(AssessmentBatch::STATUS_COLLECTING, $entry->batch->status);
        $this->assertNull($entry->batch->completed_at);

        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertNull($entry->analysis_started_at);
        $this->assertNull($entry->analysis_duration_ms);
    }

    public function test_the_scope_branch_indexes_exist(): void
    {
        $columns = collect(Schema::getIndexes('assessments'))->pluck('columns');

        $this->assertTrue($columns->contains(['batch_id']), 'assessments.batch_id is not indexed');
        $this->assertTrue(
            $columns->contains(['batch_id', 'submitted_for_analysis_at']),
            'assessments (batch_id, submitted_for_analysis_at) is not indexed'
        );
    }
}
