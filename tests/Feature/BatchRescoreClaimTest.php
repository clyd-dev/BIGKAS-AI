<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * A sent batch entry is owed a score at once, through the existing rescore lane.
 * Nothing else about who is owed a score changes.
 */
class BatchRescoreClaimTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    public function test_a_sent_batch_entry_is_owed_a_score_at_once(): void
    {
        $entry = $this->sentBatchEntry(['status' => Assessment::STATUS_PROCESSING]);

        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_an_unsent_batch_entry_is_never_claimed(): void
    {
        $entry = $this->batchEntryWithAudio();   // audio saved, not sent

        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_a_single_child_recording_still_waits_for_the_stale_rule(): void
    {
        $solo = $this->assessmentWithAudio([
            'batch_id' => null,
            'status' => Assessment::STATUS_PROCESSING,
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->assertFalse(Assessment::awaitingRescore()->whereKey($solo->id)->exists());
    }

    public function test_a_sent_batch_entry_that_already_has_a_result_is_not_owed_a_score(): void
    {
        $entry = $this->sentBatchEntry();
        $entry->createResult($this->classifiedAnalysis());

        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_a_batch_entry_with_no_audio_is_not_owed_a_score(): void
    {
        $entry = Assessment::factory()->create([
            'batch_id' => AssessmentBatch::factory(),
            'audio_file' => null,
            'submitted_for_analysis_at' => now(),   // even if somehow marked sent
        ]);

        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_a_sent_entry_whose_result_lacks_a_classification_is_still_owed_one(): void
    {
        $entry = $this->sentBatchEntry();
        $entry->createResult($this->unclassifiedAnalysis());

        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }
}
