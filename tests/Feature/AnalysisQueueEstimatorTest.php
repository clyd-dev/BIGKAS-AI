<?php

namespace Tests\Feature;

use App\Exceptions\SpeechServiceUnavailable;
use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Services\AnalysisQueueEstimator;
use App\Services\AssessmentScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * Scoring is timed on every run, and the batch board turns those timings into
 * an honest "about N minutes" instead of a guess.
 */
class AnalysisQueueEstimatorTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_typical_duration_is_the_median_of_recent_runs(): void
    {
        foreach ([60_000, 120_000, 300_000] as $i => $ms) {
            Assessment::factory()->create(['analysis_duration_ms' => $ms, 'analysis_started_at' => now()->subMinutes(10 - $i)]);
        }

        $this->assertSame(120.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
    }

    public function test_the_typical_duration_falls_back_to_a_seed_with_no_data(): void
    {
        $this->assertSame(150.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
    }

    public function test_only_the_last_twenty_runs_count(): void
    {
        // Newest first: 5 quick runs, 15 middling, then 10 very old slow ones.
        // Only a window of 20 puts the median on a middling run.
        $runs = [[5, 10_000], [15, 100_000], [10, 1_000_000]];
        $age = 0;
        foreach ($runs as [$count, $ms]) {
            foreach (range(1, $count) as $i) {
                Assessment::factory()->create(['analysis_duration_ms' => $ms, 'analysis_started_at' => now()->subMinutes(++$age)]);
            }
        }

        $this->assertSame(100.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
    }

    public function test_the_window_follows_when_runs_happened_not_row_order(): void
    {
        // Re-analysed rows keep their low ids but hold the newest measurements.
        foreach (range(1, 11) as $i) {
            Assessment::factory()->create(['analysis_duration_ms' => 10_000, 'analysis_started_at' => now()->subMinutes($i)]);
        }
        foreach (range(1, 20) as $i) {
            Assessment::factory()->create(['analysis_duration_ms' => 200_000, 'analysis_started_at' => now()->subHours($i)]);
        }

        $this->assertSame(10.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
    }

    public function test_the_median_of_an_even_number_of_runs_is_the_middle_pair_average(): void
    {
        foreach ([60_000, 100_000] as $i => $ms) {
            Assessment::factory()->create(['analysis_duration_ms' => $ms, 'analysis_started_at' => now()->subMinutes(10 - $i)]);
        }

        $this->assertSame(80.0, app(AnalysisQueueEstimator::class)->typicalSeconds());
    }

    public function test_a_batch_estimate_counts_only_entries_still_owed_a_score(): void
    {
        $batch = AssessmentBatch::factory()->create();
        $scored = $this->sentBatchEntry(['batch_id' => $batch->id]);
        $scored->createResult($this->classifiedAnalysis());
        $this->sentBatchEntry(['batch_id' => $batch->id]);
        $this->sentBatchEntry(['batch_id' => $batch->id]);

        $estimate = app(AnalysisQueueEstimator::class)->forBatch($batch->fresh());

        $this->assertSame(2, $estimate['remaining']);
        $this->assertGreaterThan(0, $estimate['seconds']);
    }

    public function test_the_estimate_is_the_work_to_do_at_the_typical_pace_plus_one_scheduler_tick(): void
    {
        $other = $this->sentBatchEntry();
        $batch = AssessmentBatch::factory()->create();
        $this->sentBatchEntry(['batch_id' => $batch->id]);
        $this->sentBatchEntry(['batch_id' => $batch->id]);
        $estimator = app(AnalysisQueueEstimator::class);

        $estimate = $estimator->forBatch($batch->fresh());

        // 1 ahead + 2 of its own, at the 150 s seed, plus one tick of latency.
        $this->assertSame(1, $estimate['ahead']);
        $this->assertSame((int) ceil(3 * 150.0 + $estimator->tickSeconds()), $estimate['seconds']);
    }

    public function test_an_entry_being_scored_right_now_still_counts_as_remaining(): void
    {
        $batch = AssessmentBatch::factory()->create();
        $this->sentBatchEntry(['batch_id' => $batch->id, 'last_rescore_at' => now(), 'status' => Assessment::STATUS_PROCESSING]);

        $estimate = app(AnalysisQueueEstimator::class)->forBatch($batch->fresh());

        $this->assertSame(1, $estimate['remaining']);
        $this->assertGreaterThan(0, $estimate['seconds']);
    }

    public function test_an_entry_that_used_up_its_attempts_is_not_waited_for(): void
    {
        $batch = AssessmentBatch::factory()->create();
        $this->sentBatchEntry(['batch_id' => $batch->id, 'rescore_attempts' => 3]);

        $this->assertSame(0, app(AnalysisQueueEstimator::class)->forBatch($batch->fresh())['remaining']);
    }

    public function test_an_empty_or_fully_scored_batch_has_nothing_to_wait_for(): void
    {
        $empty = AssessmentBatch::factory()->create();
        $done = AssessmentBatch::factory()->create();
        $this->sentBatchEntry(['batch_id' => $done->id])->createResult($this->classifiedAnalysis());

        foreach ([$empty, $done] as $batch) {
            $this->assertSame(
                ['remaining' => 0, 'ahead' => 0, 'seconds' => 0],
                app(AnalysisQueueEstimator::class)->forBatch($batch->fresh())
            );
        }
    }

    public function test_other_teachers_work_counts_as_ahead(): void
    {
        $older = $this->sentBatchEntry(['batch_id' => AssessmentBatch::factory()->create()->id]);
        $batch = AssessmentBatch::factory()->create();
        $this->sentBatchEntry(['batch_id' => $batch->id]);

        $this->assertSame(1, app(AnalysisQueueEstimator::class)->forBatch($batch->fresh())['ahead']);
    }

    public function test_work_queued_behind_the_batch_and_its_own_entries_are_not_ahead(): void
    {
        $batch = AssessmentBatch::factory()->create();
        $this->sentBatchEntry(['batch_id' => $batch->id]);
        $this->sentBatchEntry(['batch_id' => $batch->id]);
        $this->sentBatchEntry();   // someone else, but later in the lane

        $this->assertSame(0, app(AnalysisQueueEstimator::class)->forBatch($batch->fresh())['ahead']);
    }

    public function test_scoring_records_how_long_it_took(): void
    {
        $this->fakeEngine();
        $assessment = $this->assessmentWithAudio();

        app(AssessmentScorer::class)->score($assessment);

        $this->assertNotNull($assessment->fresh()->analysis_started_at);
        $this->assertGreaterThan(0, $assessment->fresh()->analysis_duration_ms);
    }

    public function test_a_run_in_progress_has_a_start_and_no_duration_yet(): void
    {
        config(['services.whisper.use_local' => true, 'services.openai.api_key' => '']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response('down', 503)]);
        $assessment = $this->assessmentWithAudio(['analysis_duration_ms' => 5_000]);

        try {
            app(AssessmentScorer::class)->score($assessment);
            $this->fail('Scoring should have thrown with the speech engine down.');
        } catch (SpeechServiceUnavailable $e) {
            // expected: the run started and never finished
        }

        $fresh = $assessment->fresh();
        $this->assertNotNull($fresh->analysis_started_at);
        $this->assertNull($fresh->analysis_duration_ms);
    }
}
