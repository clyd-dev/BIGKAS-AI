<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\Learner;
use App\Models\User;
use App\Services\AnalysisQueueEstimator;
use App\Services\BatchEntryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * What a card says about WHERE a child's reading is, not just what stage it is at.
 *
 * A teacher cannot tell a slow lane from a stuck one unless the card names a
 * place in the line and a time. Two honesty rules are pinned here:
 *
 *  - the place is the place in the REAL serial lane, so another teacher's
 *    one-off reading ahead of this batch is counted. A number that counted only
 *    this batch would be a lie on a shared lane;
 *  - nothing inside Whisper is observable, so a `transcribing` card never
 *    renders a percentage-complete bar. Elapsed-versus-typical, or an
 *    indeterminate bar, and nothing that implies real progress.
 */
class BatchCardProgressTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The trait never fakes the disk and every state here needs audio.
        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    /** @return Learner[] */
    private function learnersOf(User $teacher): array
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))->orderBy('id')->get()->all();
    }

    private function boardBatch(User $teacher, array $overrides = []): AssessmentBatch
    {
        return AssessmentBatch::factory()->create(array_merge(['teacher_id' => $teacher->id], $overrides));
    }

    /**
     * A reading of someone else's, owed a score, sitting in the one serial lane.
     *
     * A failed non-batch recording with no result is claimed by
     * Assessment::awaitingRescore() exactly as a sent batch entry is - which is
     * the point: the lane does not care whose work it is.
     */
    private function oneOffAhead(): Assessment
    {
        return $this->assessmentWithAudio(['status' => Assessment::STATUS_FAILED]);
    }

    private function stateOf(Assessment $entry): array
    {
        $entry = $entry->fresh();

        return BatchEntryState::for($entry, null, app(AnalysisQueueEstimator::class)->lane([$entry]));
    }

    // ── queued: the place in the line ─────────────────────────────────

    public function test_a_queued_card_names_its_place_in_the_line(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        $learners = $this->learnersOf($teacher);

        $first = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[0]->id]);
        $second = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[1]->id]);
        $third = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[2]->id]);

        $this->assertSame('queued', $this->stateOf($third)['chip']);
        $this->assertSame(3, $this->stateOf($third)['position']);
        $this->assertStringContainsString('3rd in line', $this->stateOf($third)['label']);
        $this->assertStringContainsString('2nd in line', $this->stateOf($second)['label']);
        $this->assertSame(1, $this->stateOf($first)['position']);
    }

    /**
     * The case that makes the number true or a lie: the lane is shared, so a
     * batch of one can still be third if two other readings are ahead of it.
     */
    public function test_a_queued_card_counts_readings_outside_its_own_batch(): void
    {
        $teacher = $this->teacher();

        // Two one-off readings, created first, so they are ahead by id - which
        // is the order RescoreQueue::run() works in.
        $this->oneOffAhead();
        $this->oneOffAhead();

        $batch = $this->boardBatch($teacher);
        $mine = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $state = $this->stateOf($mine);

        $this->assertSame('queued', $state['chip']);
        $this->assertSame(3, $state['position'], 'the only entry in its batch is still third in a shared lane');
        $this->assertStringContainsString('3rd in line', $state['label']);
    }

    public function test_the_first_in_line_reads_as_next_up_rather_than_first(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $state = $this->stateOf($entry);

        $this->assertSame(1, $state['position']);
        $this->assertStringContainsString('Next up', $state['label']);
        $this->assertStringNotContainsString('1st', $state['label']);
    }

    public function test_a_queued_card_names_the_wait_in_minutes(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        // One timed run of two minutes, so the typical figure is measured
        // rather than seeded, and the arithmetic is checkable by hand.
        Assessment::factory()->create([
            'analysis_duration_ms' => 120_000,
            'analysis_started_at' => now()->subHour(),
        ]);

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        // One slot of 120s plus the scheduler's own minute = 180s = 3 min.
        $this->assertSame(3, $this->stateOf($entry)['eta_minutes']);
        $this->assertStringContainsString('about 3 min', $this->stateOf($entry)['label']);
    }

    public function test_the_board_shows_the_place_in_the_line_on_the_card(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        $learners = $this->learnersOf($teacher);

        $this->oneOffAhead();
        $this->oneOffAhead();
        $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $learners[0]->id]);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('3rd in line');
    }

    // ── transcribing: a loading indication, and no false progress ──────

    public function test_a_transcribing_card_shows_a_loading_indication_and_the_time_so_far(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        Assessment::factory()->create([
            'analysis_duration_ms' => 180_000,
            'analysis_started_at' => now()->subHour(),
        ]);

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
            'analysis_started_at' => now()->subMinutes(2),
            'analysis_duration_ms' => null,
        ]);

        $this->assertSame('transcribing', $this->stateOf($entry)['chip']);
        $this->assertStringContainsString('2 of about 3 min', $this->stateOf($entry)['label']);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            // An indeterminate Bootstrap progress bar, plus the chip spinner.
            ->assertSee('progress-bar-animated', false)
            ->assertSee('2 of about 3 min');
    }

    /**
     * Nothing inside Whisper is observable from here, so the bar must not carry
     * a width, a value or an aria-valuenow that claims a share of the work is
     * finished.
     */
    public function test_a_transcribing_card_claims_no_percentage_complete(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
            'analysis_started_at' => now()->subMinutes(2),
            'analysis_duration_ms' => null,
        ]);

        $html = $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()->getContent();

        $this->assertStringContainsString('progress-bar-animated', $html);
        $this->assertStringNotContainsString('aria-valuenow', $html);
        $this->assertDoesNotMatchRegularExpression('/%\s*complete/i', $html);
        $this->assertDoesNotMatchRegularExpression('/progress-bar[^>]*style="[^"]*width/i', $html);
    }

    /**
     * Past Assessment::STALE_PROCESSING_MINUTES the lane has given up on the
     * run, so the card must stop claiming anything is listening.
     */
    public function test_a_card_past_the_stale_bound_does_not_claim_to_be_listening(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
            'analysis_started_at' => now()->subMinutes(Assessment::STALE_PROCESSING_MINUTES + 20),
            'analysis_duration_ms' => null,
            'updated_at' => now()->subMinutes(Assessment::STALE_PROCESSING_MINUTES + 20),
        ]);

        $state = $this->stateOf($entry);

        $this->assertSame('transcribing', $state['chip'], 'started and never finished is still the truth');
        $this->assertFalse($state['running']);
        $this->assertStringNotContainsString('Listening to the recording now', $state['label']);
        $this->assertStringContainsString('longer than expected', $state['label']);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('longer than expected')
            ->assertDontSee('Listening to the recording now');
    }

    // ── the poll, and the cost of all this ────────────────────────────

    public function test_the_poll_carries_the_place_in_the_line(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->oneOffAhead();
        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()
            ->assertJsonPath('entries.' . $entry->id . '.position', 2);
    }

    /** Still strictly read-only, with the lane now asked where every card stands. */
    public function test_the_poll_writes_nothing_while_reporting_positions(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $batch->refresh();
        $before = [$batch->status, (string) $batch->updated_at];

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes++;
            }
        });

        $this->travelTo(now()->addMinutes(2));
        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))->assertOk();

        $batch->refresh();

        $this->assertSame(0, $writes, 'the poll sent a write');
        $this->assertSame($before, [$batch->status, (string) $batch->updated_at]);
    }

    /**
     * A card whose place moves from third to second must repaint, so the place
     * has to be part of data-sig - the signature the poll compares before it
     * decides a card is unchanged.
     */
    public function test_a_card_repaints_when_its_place_in_the_line_moves_up(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $aheadOne = $this->oneOffAhead();
        $this->oneOffAhead();

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $third = $this->signatureOn($teacher, $batch, $entry);

        // One reading ahead of it is scored and leaves the lane.
        $aheadOne->result()->create($this->classifiedAnalysis());
        $aheadOne->update(['status' => Assessment::STATUS_COMPLETED]);

        $second = $this->signatureOn($teacher, $batch, $entry);

        $this->assertStringContainsString('3rd in line', $third);
        $this->assertStringContainsString('2nd in line', $second);
        $this->assertNotSame($third, $second, 'data-sig did not move, so the card would never repaint');
    }

    /** The data-sig this board renders for one card. */
    private function signatureOn(User $teacher, AssessmentBatch $batch, Assessment $entry): string
    {
        $html = $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-entry="' . $entry->id . '"(.*?)data-sig="([^"]*)"/s',
            $html,
            'no card for this entry on the board'
        );

        preg_match('/data-entry="' . $entry->id . '"(.*?)data-sig="([^"]*)"/s', $html, $m);

        return $m[2];
    }

    /**
     * Positions and estimates are computed once per render, not once per card:
     * the number of queries a board costs must not grow with the roster.
     *
     * Same shape as BatchBoardTest's lane-query bound, widened to every query,
     * because a per-card position lookup would not carry the lane's SQL shape.
     */
    public function test_the_board_costs_the_same_number_of_queries_for_three_children_as_for_one(): void
    {
        $teacher = $this->teacher();
        $learners = $this->learnersOf($teacher);

        $small = $this->boardBatch($teacher);
        $this->sentBatchEntry(['batch_id' => $small->id, 'learner_id' => $learners[0]->id]);

        $large = $this->boardBatch($teacher);
        foreach ($learners as $learner) {
            $this->sentBatchEntry(['batch_id' => $large->id, 'learner_id' => $learner->id]);
        }

        $this->assertSame(
            $this->queryCount($teacher, $small),
            $this->queryCount($teacher, $large),
            'a board of three children costs more queries than a board of one'
        );
    }

    private function queryCount(User $teacher, AssessmentBatch $batch): int
    {
        // Warm anything cached on first render (the view cache, config), so the
        // two counts are comparable.
        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        return $count;
    }

    /**
     * The loading indication must belong to `transcribing` alone. It shipped
     * inside an existing class attribute - `class="progress bb-progress
     * @class([...])"` - and Blade's @class renders a whole `class="..."`
     * attribute, so that produced a nested one the browser discards. The bar
     * was therefore visible on every card in every state, including `done`,
     * which is what a teacher reported.
     *
     * Asserted on the markup rather than through a helper, because the defect
     * was in the markup and a state-level assertion sailed straight past it.
     */
    public function test_only_a_transcribing_card_shows_the_loading_bar(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        $learners = $this->learnersOf($teacher);

        // One card in each state that must NOT show a loading indication.
        $recorded = $this->batchEntryWithAudio([
            'batch_id' => $batch->id,
            'learner_id' => $learners[0]->id,
        ]);
        $done = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $learners[1]->id,
        ]);
        $done->createResult($this->classifiedAnalysis());
        $done->verdict()->create($this->acceptedVerdict());

        $this->assertSame('recorded', $this->stateOf($recorded)['chip']);
        $this->assertSame('done', $this->stateOf($done)['chip']);

        $html = $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()->getContent();

        // The bar's markup is always present and hidden by a class, so the
        // assertion has to be on the thing that actually governs visibility:
        // every data-progress element on this board must carry d-none.
        $bars = substr_count($html, 'data-progress');
        $this->assertSame(2, $bars, 'Each card carries one loading bar element.');
        $this->assertSame(
            $bars,
            substr_count($html, 'bb-progress d-none'),
            'A loading bar is showing on a board where nothing is being transcribed.'
        );

        // And the hiding class must be a real attribute, not a second
        // class="..." nested inside the first - which the browser discards.
        $this->assertStringNotContainsString('class="progress bb-progress class=', $html,
            'The hiding class is nested inside another class attribute, so the browser drops it.');

        // The chip spinner is likewise only for transcribing.
        $spinners = substr_count($html, 'data-chip-spinner');
        $this->assertSame(2, $spinners, 'Each card carries one spinner element.');
        $this->assertSame(
            $spinners,
            substr_count($html, 'spinner-border-sm d-none'),
            'Every spinner on a board with nothing transcribing must be hidden.'
        );

        // Neither control may carry a second class attribute: a duplicate is
        // discarded by the browser, which is how both of these came to show in
        // every state while the markup read correctly.
        $this->assertSame(0, preg_match_all('/<span[^>]*class=[^>]*class=/', $html),
            'A span carries two class attributes; the second is ignored.');
        $this->assertSame(0, preg_match_all('/<div[^>]*class=[^>]*class=/', $html),
            'A div carries two class attributes; the second is ignored.');
    }
}
