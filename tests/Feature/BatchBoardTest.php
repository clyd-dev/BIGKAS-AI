<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentResult;
use App\Models\AssessmentVerdict;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Services\BatchEntryState;
use App\Services\RescoreQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * The card board: one card per child, each showing an honest state.
 *
 * Two honesty rules are pinned here independently of the derivation order, so a
 * later reshuffle of that order cannot break them quietly:
 *
 *  - a raw `failed` status that the rescore lane still claims reads `queued`,
 *    because RescoreQueue::rescore() marks an engine outage as failed by design
 *    and leaves the attempt uncounted;
 *  - a result the model could not classify is never `done`, because there is no
 *    rule-based fallback anywhere in this system and a reading without a model
 *    answer is incomplete, not complete.
 */
class BatchBoardTest extends TestCase
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

    // ── Fixtures ──────────────────────────────────────────────────────

    /** @return Learner[] */
    private function learnersOf(User $teacher): array
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))->orderBy('id')->get()->all();
    }

    private function boardBatch(User $teacher, array $overrides = []): AssessmentBatch
    {
        return AssessmentBatch::factory()->create(array_merge(['teacher_id' => $teacher->id], $overrides));
    }

    /** A material that really carries comprehension questions. */
    private function materialWithQuestions(): ReadingMaterial
    {
        $material = ReadingMaterial::factory()->create();

        ComprehensionQuestion::create([
            'material_id' => $material->id,
            'question' => 'What colour is Max?',
            'question_type' => 'literal',
            'correct_answer' => 'A',
            'option_a' => 'Brown',
            'option_b' => 'Blue',
            'sort_order' => 1,
        ]);

        return $material;
    }

    // ── Review Focus #1: an outage is not a failure ───────────────────

    public function test_an_entry_waiting_for_a_downed_engine_reads_as_queued_not_failed(): void
    {
        $entry = $this->sentBatchEntry();
        $entry->markFailed('The speech engine is down.');   // what RescoreQueue does on an outage

        $entry = $entry->fresh();

        // Proved independently of the derivation order: the raw column really
        // does say failed, the lane really does still claim it, and the chip
        // still must not say failed.
        $this->assertSame(Assessment::STATUS_FAILED, $entry->status);
        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
        $this->assertLessThan(RescoreQueue::MAX_ATTEMPTS, (int) $entry->rescore_attempts);

        $state = BatchEntryState::for($entry);

        $this->assertSame('queued', $state['chip']);
        $this->assertStringNotContainsStringIgnoringCase('failed', $state['label']);
    }

    public function test_an_entry_that_used_up_its_attempts_reads_as_failed(): void
    {
        $entry = $this->sentBatchEntry(['rescore_attempts' => RescoreQueue::MAX_ATTEMPTS]);

        $this->assertSame('failed', BatchEntryState::for($entry->fresh())['chip']);
    }

    // ── Review Focus #2: the Send gate ────────────────────────────────

    public function test_a_comprehension_entry_without_answers_cannot_be_sent(): void
    {
        $entry = $this->batchEntryWithAudio([
            'assessment_type' => Assessment::TYPE_COMBINED,
            'material_id' => $this->materialWithQuestions()->id,
        ]);

        $state = BatchEntryState::for($entry->fresh());

        $this->assertSame('recorded', $state['chip']);
        $this->assertStringContainsStringIgnoringCase('question', $state['label']);
        $this->assertNull($state['action']);   // Send is not offered until answers exist
    }

    // ── Review Focus #3: learners the teacher no longer reaches ───────

    public function test_the_board_omits_a_learner_the_teacher_no_longer_reaches(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $batch = $this->boardBatch($teacher);

        $mine = $this->learnersOf($teacher)[0];
        $mine->update(['first_name' => 'Reachable', 'last_name' => 'Zebedee']);
        $theirs = $this->learnersOf($other)[0];
        $theirs->update(['first_name' => 'Unreachable', 'last_name' => 'Quimby']);

        // Same school proves nothing: access is per class taught or per pivot row.
        $this->assertFalse($teacher->taughtClasses()->where('id', $theirs->class_id)->exists());
        $this->assertFalse($teacher->learners()->where('learners.id', $theirs->id)->exists());

        $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $mine->id]);
        $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $theirs->id]);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('Zebedee')
            ->assertDontSee('Quimby');
    }

    public function test_the_board_does_not_error_when_every_learner_moved_away(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $batch = $this->boardBatch($teacher);

        foreach ($this->learnersOf($other) as $gone) {
            $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $gone->id]);
        }

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('No children on this board');

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()
            ->assertJsonPath('entries', []);
    }

    // ── The seven states ──────────────────────────────────────────────

    public function test_an_entry_with_no_audio_reads_as_waiting(): void
    {
        $entry = Assessment::factory()->create(['batch_id' => AssessmentBatch::factory()->create()->id]);

        $state = BatchEntryState::for($entry->fresh());

        $this->assertSame('waiting', $state['chip']);
        $this->assertFalse($state['playable']);
    }

    public function test_a_recorded_entry_reads_as_recorded_and_is_playable(): void
    {
        $entry = $this->batchEntryWithAudio();

        $state = BatchEntryState::for($entry->fresh());

        $this->assertSame('recorded', $state['chip']);
        $this->assertTrue($state['playable']);
        $this->assertNotNull($state['action']);
    }

    public function test_an_entry_being_scored_right_now_reads_as_transcribing(): void
    {
        // The state comes from Task 1's timing columns, not from raw status.
        $entry = $this->sentBatchEntry([
            'status' => Assessment::STATUS_PROCESSING,
            'analysis_started_at' => now(),
            'analysis_duration_ms' => null,
        ]);

        // It also satisfies the rescore lane, which is why transcribing is
        // checked before queued.
        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());

        $this->assertSame('transcribing', BatchEntryState::for($entry->fresh())['chip']);
    }

    public function test_a_sent_entry_waiting_its_turn_reads_as_queued(): void
    {
        $entry = $this->sentBatchEntry();

        $this->assertSame('queued', BatchEntryState::for($entry->fresh())['chip']);
    }

    public function test_a_scored_entry_awaiting_a_verdict_reads_as_needs_verdict(): void
    {
        $entry = $this->sentBatchEntry();
        $entry->createResult($this->classifiedAnalysis());

        $state = BatchEntryState::for($entry->fresh());

        $this->assertSame('needs verdict', $state['chip']);
        $this->assertNotNull($state['result_url']);
    }

    public function test_an_entry_with_a_verdict_reads_as_done(): void
    {
        $entry = $this->sentBatchEntry();
        $entry->createResult($this->classifiedAnalysis());
        $entry->verdict()->create($this->acceptedVerdict());

        $state = BatchEntryState::for($entry->fresh());

        $this->assertSame('done', $state['chip']);
        $this->assertSame('good', $state['tone']);
    }

    public function test_an_unclassified_result_reads_as_queued_not_done(): void
    {
        $entry = $this->sentBatchEntry();
        $entry->createResult($this->unclassifiedAnalysis());

        $entry = $entry->fresh();

        // Proved independently of the derivation order: a result really is
        // stored, it really is unclassified, and it still must not read done.
        $this->assertNotNull($entry->result);
        $this->assertSame(AssessmentResult::STATUS_UNAVAILABLE, $entry->result->classification_status);
        $this->assertSame('unclassified', $entry->weaknessOutcome()['state']);

        $state = BatchEntryState::for($entry);

        $this->assertSame('queued', $state['chip']);
        $this->assertNotSame('done', $state['chip']);
    }

    // ── Audio availability ────────────────────────────────────────────

    public function test_audio_is_playable_in_every_state_once_a_recording_exists(): void
    {
        $states = [];

        $states['recorded'] = $this->batchEntryWithAudio()->fresh();

        $states['transcribing'] = $this->sentBatchEntry([
            'status' => Assessment::STATUS_PROCESSING, 'analysis_started_at' => now(),
        ])->fresh();

        $states['queued'] = $this->sentBatchEntry()->fresh();

        $states['failed'] = $this->sentBatchEntry(['rescore_attempts' => RescoreQueue::MAX_ATTEMPTS])->fresh();

        $needsVerdict = $this->sentBatchEntry();
        $needsVerdict->createResult($this->classifiedAnalysis());
        $states['needs verdict'] = $needsVerdict->fresh();

        $done = $this->sentBatchEntry();
        $done->createResult($this->classifiedAnalysis());
        $done->verdict()->create($this->acceptedVerdict());
        $states['done'] = $done->fresh();

        foreach ($states as $chip => $entry) {
            $state = BatchEntryState::for($entry);

            $this->assertSame($chip, $state['chip'], "expected the $chip fixture to read as $chip");
            $this->assertTrue($state['playable'], "audio must stay playable at $chip");
        }
    }

    // ── Check Result ──────────────────────────────────────────────────

    public function test_check_result_appears_as_soon_as_a_reading_is_analysed(): void
    {
        $needsVerdict = $this->sentBatchEntry();
        $needsVerdict->createResult($this->classifiedAnalysis());

        $done = $this->sentBatchEntry();
        $done->createResult($this->classifiedAnalysis());
        $done->verdict()->create($this->acceptedVerdict());

        foreach ([$needsVerdict, $done] as $entry) {
            $state = BatchEntryState::for($entry->fresh());

            $this->assertSame(route('assessments.results', $entry), $state['result_url']);
        }
    }

    public function test_check_result_is_absent_before_a_result_exists(): void
    {
        $entries = [
            Assessment::factory()->create(['batch_id' => AssessmentBatch::factory()->create()->id]),
            $this->batchEntryWithAudio(),
            $this->sentBatchEntry(),
            $this->sentBatchEntry(['status' => Assessment::STATUS_PROCESSING, 'analysis_started_at' => now()]),
            $this->sentBatchEntry(['rescore_attempts' => RescoreQueue::MAX_ATTEMPTS]),
        ];

        foreach ($entries as $entry) {
            $this->assertNull(BatchEntryState::for($entry->fresh())['result_url']);
        }
    }

    // ── The board and the batch's own status ──────────────────────────

    public function test_the_board_reads_weaknesses_through_weakness_outcome(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        [$first, $second] = $this->learnersOf($teacher);

        $shown = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $first->id]);
        $shown->createResult($this->classifiedAnalysis(['primary_weakness' => 2]));
        $shown->verdict()->create($this->acceptedVerdict());

        // Same raw column, but the reading was thrown out: weaknessOutcome()
        // gives no label, so the board must not print one.
        $hidden = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $second->id]);
        $hidden->createResult($this->classifiedAnalysis(['primary_weakness' => 3]));
        $hidden->verdict()->create($this->acceptedVerdict([
            'decision' => AssessmentVerdict::DECISION_INVALIDATED,
        ]));

        $this->assertSame(3, (int) $hidden->fresh()->result->primary_weakness);
        $this->assertSame('unclassified', $hidden->fresh()->weaknessOutcome()['state']);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('Decoding Accuracy')
            ->assertDontSee('Oral Reading Fluency');
    }

    /** The standing reassurance, and the estimate line beside it. */
    public function test_the_board_promises_that_analysis_continues_after_you_close_it(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $this->actingAs($teacher)->get(route('assessments.batch.board', $batch))
            ->assertOk()
            ->assertSee('Analysis continues even if you close this page')
            ->assertSee('still to score')
            ->assertSee('Send all ready')
            // Sending is safe fifteen at a time; accepting a verdict never is.
            ->assertDontSee('Accept all');
    }

    public function test_poll_reports_the_estimate_and_every_entry(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        [$a, $b, $c] = $this->learnersOf($teacher);

        $waiting = Assessment::factory()->create(['batch_id' => $batch->id, 'learner_id' => $a->id]);
        $recorded = $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $b->id]);
        $queued = $this->sentBatchEntry(['batch_id' => $batch->id, 'learner_id' => $c->id]);

        $json = $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()
            ->assertJsonStructure([
                'entries', 'batch_status',
                'estimate' => ['remaining', 'ahead', 'seconds'],
                'ready_to_send',
            ])
            ->json();

        $this->assertSame(['waiting', 'recorded', 'queued'], [
            $json['entries'][$waiting->id]['chip'],
            $json['entries'][$recorded->id]['chip'],
            $json['entries'][$queued->id]['chip'],
        ]);
        $this->assertCount(3, $json['entries']);
        $this->assertSame(1, $json['estimate']['remaining']);
        $this->assertGreaterThan(0, $json['estimate']['seconds']);
        $this->assertSame(AssessmentBatch::STATUS_ANALYZING, $json['batch_status']);
    }

    public function test_poll_counts_how_many_cards_are_ready_to_send(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);
        [$a, $b, $c] = $this->learnersOf($teacher);

        $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $a->id]);
        $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $b->id]);

        // Recorded, but it cannot be sent: its comprehension answers are missing.
        $this->batchEntryWithAudio([
            'batch_id' => $batch->id,
            'learner_id' => $c->id,
            'assessment_type' => Assessment::TYPE_COMBINED,
            'material_id' => $this->materialWithQuestions()->id,
        ]);

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()
            ->assertJsonPath('ready_to_send', 2);
    }

    public function test_another_teacher_cannot_open_or_poll_this_board(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $batch = $this->boardBatch($teacher);

        $mine = $this->learnersOf($teacher)[0];
        $this->batchEntryWithAudio(['batch_id' => $batch->id, 'learner_id' => $mine->id]);

        // Same school proves nothing: the other teacher reaches this child
        // through neither a pivot row nor a class they teach.
        $this->assertFalse($other->taughtClasses()->where('id', $mine->class_id)->exists());
        $this->assertFalse($other->learners()->where('learners.id', $mine->id)->exists());

        $this->actingAs($other)->get(route('assessments.batch.board', $batch))->assertForbidden();
        $this->actingAs($other)->get(route('assessments.batch.poll', $batch))->assertForbidden();
    }

    public function test_a_batch_turns_to_analyzing_once_any_entry_has_audio(): void
    {
        $batch = AssessmentBatch::factory()->create();
        Assessment::factory()->create(['batch_id' => $batch->id]);

        $this->assertSame(AssessmentBatch::STATUS_COLLECTING, $batch->fresh()->status);

        $this->batchEntryWithAudio(['batch_id' => $batch->id]);

        $this->assertSame(AssessmentBatch::STATUS_ANALYZING, $batch->fresh()->status);
    }

    public function test_a_cancelled_batch_is_not_revived_by_polling(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher, [
            'status' => AssessmentBatch::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);

        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))->assertOk();

        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
    }

    // ── The batch must finish with nobody watching ────────────────────

    public function test_a_batch_completes_without_anyone_opening_the_board(): void
    {
        $batch = AssessmentBatch::factory()->create();
        $entry = $this->sentBatchEntry(['batch_id' => $batch->id]);

        // score it the way the scheduler does - no HTTP request, no poll
        $entry->createResult($this->classifiedAnalysis());
        $entry->verdict()->create($this->acceptedVerdict());

        $this->assertSame(AssessmentBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
    }

    public function test_poll_writes_nothing(): void
    {
        $teacher = $this->teacher();
        $batch = $this->boardBatch($teacher);

        $entry = $this->sentBatchEntry([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnersOf($teacher)[0]->id,
        ]);
        $entry->createResult($this->classifiedAnalysis());
        $entry->verdict()->create($this->acceptedVerdict());

        // The stored status is already right before anyone looks at the board.
        $before = $batch->fresh();
        $this->assertSame(AssessmentBatch::STATUS_COMPLETED, $before->status);

        $statusBefore = $before->status;
        $updatedAtBefore = $before->updated_at->toDateTimeString();

        // Travel on, so any write at all would move updated_at.
        $this->travelTo(now()->addMinutes(2));

        $this->actingAs($teacher)->get(route('assessments.batch.poll', $batch))->assertOk();

        $after = $batch->fresh();

        $this->assertSame($statusBefore, $after->status);
        $this->assertSame($updatedAtBefore, $after->updated_at->toDateTimeString());
    }
}
