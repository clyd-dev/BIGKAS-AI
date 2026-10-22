<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * When nothing is driving the lane, the board has to say so.
 *
 * This came from a real session: a batch was sent, every card sat at `queued`
 * for ever, and the board went on saying "in line for analysis" - because no
 * `schedule:run` was driving the scheduler, so nothing ever picked the
 * recordings up. The feature was correct and the screen was useless. The two
 * real causes (no crontab line; a killed run still holding
 * withoutOverlapping(120)) produce exactly this symptom, and both are in
 * docs/deployment-vps.md, Phase 7.
 *
 * What is pinned here is as much about when the warning must NOT appear. A
 * batch legitimately waits behind another teacher's reading, and a warning that
 * cried wolf every time the lane was merely busy would teach everyone to ignore
 * it - which is worse than no warning at all.
 */
class BatchLaneStallTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    /** @return Learner[] */
    private function learnersOf(User $teacher): array
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))->orderBy('id')->get()->all();
    }

    private function boardBatch(User $teacher): AssessmentBatch
    {
        return AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);
    }

    /** A reading sent $minutes ago and never touched since. */
    private function sentAgo(AssessmentBatch $batch, Learner $learner, int $minutes): Assessment
    {
        return $this->batchEntryWithAudio([
            'batch_id' => $batch->id,
            'learner_id' => $learner->id,
            'submitted_for_analysis_at' => now()->subMinutes($minutes),
            'updated_at' => now()->subMinutes($minutes),
        ]);
    }

    /** Whether the board's warning is shown, hidden, or missing altogether. */
    private function warningShownOn(User $teacher, AssessmentBatch $batch): bool
    {
        $html = $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-lane-stall class="([^"]*)"/',
            $html,
            'the board carries no lane-stall banner at all, so it could never be raised by a poll'
        );

        preg_match('/data-lane-stall class="([^"]*)"/', $html, $m);

        return ! str_contains($m[1], 'd-none');
    }

    public function test_the_board_says_so_when_nothing_has_been_scored_for_longer_than_expected(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        // Sent an hour ago. Nothing anywhere has ever been scored: no
        // analysis_started_at, no last_rescore_at - the lane has no pulse.
        $this->sentAgo($batch, $this->learnersOf($teacher)[0], 60);

        $this->assertTrue($this->warningShownOn($teacher, $batch));

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            // An observation and who to ask - never a diagnosis, and never an
            // artisan command for a teacher with no terminal.
            ->assertSee('Nothing has been scored for 60 minutes')
            ->assertSee('may not be running')
            ->assertDontSee('schedule:run')
            ->assertDontSee('schedule:work')
            ->assertDontSee('schedule:clear-cache');
    }

    /**
     * The false positive that would ruin the warning: one lane, shared, and a
     * batch waiting behind someone else's reading is working exactly as
     * designed.
     */
    public function test_no_warning_while_the_lane_is_busy_on_another_teachers_reading(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->sentAgo($batch, $this->learnersOf($teacher)[0], 60);

        // Somebody else's recording, being transcribed right now.
        $this->assessmentWithAudio([
            'status' => Assessment::STATUS_PROCESSING,
            'analysis_started_at' => now()->subMinute(),
            'analysis_duration_ms' => null,
        ]);

        $this->assertFalse(
            $this->warningShownOn($teacher, $batch),
            'the lane is alive and busy elsewhere, so this board must not accuse the server'
        );
    }

    public function test_no_warning_on_a_batch_with_nothing_waiting(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        // A roster with no recordings yet: nothing is owed a score, so nothing
        // is being kept waiting.
        Assessment::factory()->create([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
            'audio_file' => null,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->assertFalse($this->warningShownOn($teacher, $batch));
    }

    public function test_no_warning_on_a_batch_that_has_only_just_been_sent(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->sentAgo($batch, $this->learnersOf($teacher)[0], 0);

        $this->assertFalse(
            $this->warningShownOn($teacher, $batch),
            'the lane cannot be behind on work it was given seconds ago'
        );
    }

    public function test_the_warning_goes_away_once_something_is_scored(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        $learners = $this->learnersOf($teacher);

        $this->sentAgo($batch, $learners[0], 60);
        $this->sentAgo($batch, $learners[1], 60);

        $this->assertTrue($this->warningShownOn($teacher, $batch));

        // The lane wakes up and scores the first one.
        $first = $batch->entries()->orderBy('id')->first();
        $first->result()->create($this->classifiedAnalysis());
        $first->forceFill([
            'status' => Assessment::STATUS_COMPLETED,
            'analysis_started_at' => now()->subSeconds(30),
            'analysis_duration_ms' => 20_000,
            'last_rescore_at' => now(),
        ])->save();

        $this->assertFalse(
            $this->warningShownOn($teacher, $batch),
            'the lane has moved, so the warning must clear itself'
        );
    }

    public function test_the_poll_reports_the_stall_so_it_can_be_raised_without_a_reload(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->sentAgo($batch, $this->learnersOf($teacher)[0], 45);

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes++;
            }
        });

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()
            ->assertJsonPath('lane_stalled_minutes', 45);

        $this->assertSame(0, $writes, 'the poll sent a write');
    }

    public function test_the_entry_panel_says_the_same_thing(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $entry = $this->sentAgo($batch, $this->learnersOf($teacher)[0], 45);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$batch, $entry]))
            ->assertOk()
            ->assertSee('Nothing has been scored for 45 minutes')
            ->assertSee('may not be running');
    }

    /** The whole derivation is still one pair of queries, however long the roster. */
    public function test_the_stall_check_does_not_cost_a_query_per_card(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);

        $small = $this->boardBatch($teacher);
        $this->sentAgo($small, $learners[0], 60);

        $large = $this->boardBatch($teacher);
        foreach ($learners as $learner) {
            $this->sentAgo($large, $learner, 60);
        }

        $this->assertTrue($this->warningShownOn($teacher, $large));
        $this->assertSame($this->queryCount($teacher, $small), $this->queryCount($teacher, $large));
    }

    private function queryCount(User $teacher, AssessmentBatch $batch): int
    {
        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        return $count;
    }
}
