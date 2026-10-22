<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentResult;
use App\Models\AssessmentSession;
use App\Models\ComprehensionAnswer;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Services\BatchEntryState;
use App\Services\BatchRosterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * Taking a child off the board, un-sending a reading, and stopping a batch.
 *
 * One rule stands over everything here: **nothing available on this board may
 * destroy a measured reading.** A teacher moving fast through fifteen cards
 * must not be able to delete a child's record with one misplaced tap, because
 * deleting an Assessment cascade-deletes its result, its verdict, its
 * comprehension answers and its sessions - and Learner::
 * readingLevelFromAssessments() derives the child's reading level from their
 * assessments, so the delete would silently change what parents see, what the
 * Practice Center recommends and what the admin chart counts.
 *
 * So a scored card leaves the batch by having batch_id set to null and nothing
 * else. Real deletion of a measured reading lives on the learner's own history
 * screen, not on a card.
 *
 * The other two things pinned here:
 *
 *  - A cancelled batch stays cancelled. cancel() un-sends every queued entry,
 *    and every one of those writes fires AssessmentObserver -> refreshStatus(),
 *    which would otherwise recompute the batch straight back to analyzing from
 *    inside the cancel itself.
 *  - Un-send refuses at write time, not only in the view. The teacher's board
 *    may be a minute old, the lane may have picked the reading up since, and a
 *    running Whisper process cannot be aborted honestly - so the write is
 *    refused rather than nulling the stamp underneath it.
 */
class BatchCancelTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The trait never fakes the disk, and these tests delete audio files:
        // without this they would be deleting real recordings.
        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /**
     * A real batch, built the way the teacher's form builds one, so every entry
     * has its session and its join code.
     */
    private function batchOf(User $teacher, int $children = 3): AssessmentBatch
    {
        $classId = $teacher->taughtClasses()->first()->id;
        $learners = Learner::where('class_id', $classId)->orderBy('id')->get();
        $extra = $children - $learners->count();

        if ($extra > 0) {
            $learners = $learners->merge(
                Learner::factory()->count($extra)->create(['class_id' => $classId, 'grade_level' => 4])
            );
        }

        return app(BatchRosterBuilder::class)->build($teacher, [
            'title' => 'Morning group',
            'language' => 'en',
            'assessment_type' => 'oral_reading',
            'default_material_id' => ReadingMaterial::factory()->create()->id,
            'school_class_id' => null,
            'entries' => $learners->take($children)->map(fn ($l) => [
                'learner_id' => $l->id, 'material_id' => null, 'allow_fallback' => false,
            ])->all(),
        // The floor said out loud: the builder's own default is the configured
        // two, and this suite deliberately builds one-entry batches to exercise
        // a board with a single card on it.
        ], 1);
    }

    /** Give this entry a stored recording, resting at `recorded`. */
    private function record(Assessment $entry): Assessment
    {
        $path = 'assessments/audio/entry_' . $entry->id . '.webm';
        Storage::disk('public')->put($path, 'audio');

        $entry->forceFill(['audio_file' => $path, 'submitted_for_analysis_at' => null])->save();

        return $entry->fresh();
    }

    /** Recorded, and sent by the teacher: in line for analysis. */
    private function queue(Assessment $entry): Assessment
    {
        $this->record($entry);
        $entry->forceFill(['submitted_for_analysis_at' => now()])->save();

        return $entry->fresh();
    }

    /** The lane has picked it up and Whisper is running: nothing can abort it. */
    private function transcribing(Assessment $entry): Assessment
    {
        $this->queue($entry);
        $entry->forceFill(['analysis_started_at' => now(), 'analysis_duration_ms' => null])->save();

        return $entry->fresh();
    }

    /** A measured reading: audio, a sent stamp and a classified result. */
    private function scored(Assessment $entry): Assessment
    {
        $this->queue($entry);
        $entry->createResult($this->classifiedAnalysis());

        return $entry->fresh();
    }

    /**
     * Measured, but the model could not name the weakness.
     *
     * A real result with real numbers and classification_status = unavailable.
     * scopeAwaitingRescore() goes on claiming it - there is no rule-based
     * fallback in this system, so a reading without a model answer is
     * incomplete - which is why its chip is `queued` and not `done`.
     */
    private function measuredButUnclassified(Assessment $entry): Assessment
    {
        $this->queue($entry);
        $entry->createResult($this->unclassifiedAnalysis());

        return $entry->fresh();
    }

    private function removeUrl(AssessmentBatch $batch, Assessment $entry): string
    {
        return route('assessments.batch.entry.remove', [$batch, $entry]);
    }

    private function unsendUrl(AssessmentBatch $batch, Assessment $entry): string
    {
        return route('assessments.batch.unsend', [$batch, $entry]);
    }

    // ── The boundary that matters most ────────────────────────────────

    public function test_removing_a_scored_card_keeps_the_assessment_and_its_result(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->scored($batch->entries()->orderBy('id')->first());

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $entry->id]);
        // The recording the result was worked out from is still on disk.
        Storage::disk('public')->assertExists($entry->audio_file);
    }

    public function test_removing_a_scored_card_does_not_change_the_childs_reading_level(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->scored($batch->entries()->orderBy('id')->first());
        $learner = $entry->learner;

        $before = $learner->fresh()->reading_level;
        $this->assertSame('independent', $before, 'fixture: the result should have set a level');

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))->assertRedirect();

        $this->assertSame($before, $learner->fresh()->reading_level);
        $this->assertSame($before, $learner->fresh()->readingLevelFromAssessments());
    }

    public function test_removing_a_scored_card_keeps_its_verdict_and_answers(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->scored($batch->entries()->orderBy('id')->first());

        $entry->verdict()->create($this->acceptedVerdict(['decided_by' => $batch->teacher_id]));
        ComprehensionAnswer::create([
            'assessment_id' => $entry->id,
            'question_text' => 'What colour is Max?',
            'selected_text' => 'Brown',
            'correct_text' => 'Brown',
            'is_correct' => true,
        ]);

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))->assertRedirect();

        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
        $this->assertDatabaseHas('assessment_verdicts', ['assessment_id' => $entry->id]);
        $this->assertDatabaseHas('comprehension_answers', ['assessment_id' => $entry->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $entry->id]);
    }

    public function test_no_board_action_can_delete_a_measured_reading(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $scored = $this->scored($entries[0]);
        $this->queue($entries[1]);

        $scored->verdict()->create($this->acceptedVerdict(['decided_by' => $batch->teacher_id]));
        ComprehensionAnswer::create([
            'assessment_id' => $scored->id,
            'question_text' => 'What colour is Max?',
            'selected_text' => 'Brown',
            'correct_text' => 'Brown',
            'is_correct' => true,
        ]);

        $audio = $scored->audio_file;
        $teacher = $batch->teacher;

        // Every door this board opens onto that child's reading, in turn.
        $this->actingAs($teacher)->post($this->unsendUrl($batch, $scored));
        $this->actingAs($teacher)->postJson(route('assessments.batch.send', [$batch, $scored]));
        $this->actingAs($teacher)->postJson(route('assessments.batch.answers', [$batch, $scored]), ['answers' => []]);
        $this->actingAs($teacher)->postJson(route('assessments.batch.upload-audio', [$batch, $scored]), [
            'audio' => UploadedFile::fake()->create('again.webm', 10, 'audio/webm'),
        ]);
        $this->actingAs($teacher)->post(route('assessments.batch.send-all', $batch));
        $this->actingAs($teacher)->post(route('assessments.batch.cancel', $batch));
        $this->actingAs($teacher)->delete($this->removeUrl($batch, $scored));

        // Still there, every row of it, and still the same recording.
        $this->assertDatabaseHas('assessments', ['id' => $scored->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $scored->id]);
        $this->assertDatabaseHas('assessment_verdicts', ['assessment_id' => $scored->id]);
        $this->assertDatabaseHas('comprehension_answers', ['assessment_id' => $scored->id]);
        $this->assertSame($audio, $scored->fresh()->audio_file);
        Storage::disk('public')->assertExists($audio);
    }

    // ── Pre-result removal is real deletion, and that is fine ─────────

    public function test_removing_a_waiting_card_deletes_the_entry_and_its_session(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $batch->entries()->orderBy('id')->first();

        $this->assertSame('waiting', BatchEntryState::for($entry)['chip']);
        $this->assertDatabaseHas('assessment_sessions', ['assessment_id' => $entry->id]);

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $this->assertDatabaseMissing('assessments', ['id' => $entry->id]);
        $this->assertDatabaseMissing('assessment_sessions', ['assessment_id' => $entry->id]);
    }

    public function test_removing_a_recorded_card_deletes_the_entry_and_its_audio_file(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->record($batch->entries()->orderBy('id')->first());

        $this->assertSame('recorded', BatchEntryState::for($entry)['chip']);

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))->assertRedirect();

        $this->assertDatabaseMissing('assessments', ['id' => $entry->id]);
        Storage::disk('public')->assertMissing($entry->audio_file);
    }

    // ── Un-send ───────────────────────────────────────────────────────

    public function test_cancelling_analysis_returns_a_queued_card_to_recorded(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->queue($batch->entries()->orderBy('id')->first());

        $this->assertSame('queued', BatchEntryState::for($entry)['chip']);

        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $entry->refresh();

        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertSame('recorded', BatchEntryState::for($entry)['chip']);
    }

    public function test_a_cancelled_analysis_is_no_longer_claimed_by_the_lane(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->queue($batch->entries()->orderBy('id')->first());

        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());

        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))->assertRedirect();

        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());

        // And it stays out of the lane: the fifteen-minute stale window must not
        // sweep it back in either.
        $this->travel(2)->hours();
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
        $this->travelBack();
    }

    public function test_cancelling_analysis_keeps_the_audio(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->queue($batch->entries()->orderBy('id')->first());

        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))->assertRedirect();

        $this->assertSame($entry->audio_file, $entry->fresh()->audio_file);
        Storage::disk('public')->assertExists($entry->audio_file);
    }

    public function test_a_transcribing_card_offers_no_cancel_action(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->transcribing($batch->entries()->orderBy('id')->first());

        $this->assertSame('transcribing', BatchEntryState::for($entry)['chip']);

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        // No un-send form for this child anywhere on the board, and no remove
        // button either: neither would do what it claimed. (The remove URL is
        // the panel's own path under another verb, so what is asserted is the
        // absence of the controls, plus the empty manage row the card renders
        // in its place.)
        $board->assertDontSee($this->unsendUrl($batch, $entry), false);
        $board->assertDontSee('Cancel analysis');
        $board->assertDontSee('Remove from batch');
        $board->assertSee('data-manage=""', false);
        $board->assertSee('Being analysed now');

        // The panel shares the card, so it says the same thing.
        $panel = $this->actingAs($batch->teacher)->get(route('assessments.batch.entry', [$batch, $entry]))->assertOk();
        $panel->assertDontSee($this->unsendUrl($batch, $entry), false);
        $panel->assertDontSee('Cancel analysis');
    }

    public function test_cancelling_analysis_is_refused_once_the_lane_has_started_the_reading(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->transcribing($batch->entries()->orderBy('id')->first());

        $sent = $entry->submitted_for_analysis_at;
        $started = $entry->analysis_started_at;

        // The teacher's board was drawn before the lane picked this up, so the
        // button she tapped was honest when it was rendered. The write is not.
        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'being analysed'));

        $entry->refresh();

        // Left exactly as it was.
        $this->assertNotNull($entry->submitted_for_analysis_at);
        $this->assertEquals($sent, $entry->submitted_for_analysis_at);
        $this->assertEquals($started, $entry->analysis_started_at);
        $this->assertSame('transcribing', BatchEntryState::for($entry)['chip']);
    }

    public function test_removing_a_transcribing_card_is_refused(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->transcribing($batch->entries()->orderBy('id')->first());

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'being analysed'));

        $this->assertDatabaseHas('assessments', [
            'id' => $entry->id,
            'batch_id' => $batch->id,
        ]);
        $this->assertNotNull($entry->fresh()->submitted_for_analysis_at);
        Storage::disk('public')->assertExists($entry->audio_file);
    }

    public function test_removing_a_queued_card_asks_the_teacher_to_cancel_the_analysis_first(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->queue($batch->entries()->orderBy('id')->first());

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'in line for analysis'));

        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => $batch->id]);
        Storage::disk('public')->assertExists($entry->audio_file);
    }

    public function test_un_sending_a_reading_that_was_never_sent_changes_nothing(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->record($batch->entries()->orderBy('id')->first());

        // A double-tapped Cancel analysis: the second press lands on a card that
        // is already back at recorded.
        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))->assertRedirect();

        $this->assertNull($entry->fresh()->submitted_for_analysis_at);
        $this->assertSame('recorded', BatchEntryState::for($entry->fresh())['chip']);
        Storage::disk('public')->assertExists($entry->audio_file);
    }

    public function test_removing_a_card_twice_answers_honestly_the_second_time(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $batch->entries()->orderBy('id')->first();

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))->assertRedirect();
        // The card is gone; the second tap of a double-tap finds nothing.
        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))->assertStatus(404);

        $this->assertDatabaseMissing('assessments', ['id' => $entry->id]);
    }

    // ── Batch cancel ──────────────────────────────────────────────────

    public function test_cancelling_a_batch_keeps_every_scored_reading(): void
    {
        $batch = $this->batchOf($this->teacher(), 3);
        $entries = $batch->entries()->orderBy('id')->get();
        $scored = $this->scored($entries[0]);
        $this->queue($entries[1]);

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $batch->refresh();

        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->status);
        $this->assertNotNull($batch->cancelled_at);

        // Kept, and kept *attached*: a cancelled batch is the record of what was
        // accomplished before the teacher stopped.
        $this->assertDatabaseHas('assessments', ['id' => $scored->id, 'batch_id' => $batch->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $scored->id]);
        Storage::disk('public')->assertExists($scored->audio_file);
        $this->assertSame('independent', $scored->learner->fresh()->reading_level);
    }

    public function test_cancelling_a_batch_unsends_every_queued_entry(): void
    {
        $batch = $this->batchOf($this->teacher(), 3);
        $entries = $batch->entries()->orderBy('id')->get();
        $first = $this->queue($entries[0]);
        $second = $this->queue($entries[1]);

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        foreach ([$first, $second] as $entry) {
            $entry->refresh();
            $this->assertNull($entry->submitted_for_analysis_at, 'a cancelled batch leaves nothing in the lane');
            $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
            // The recording is work the child did: it stays.
            Storage::disk('public')->assertExists($entry->audio_file);
            $this->assertSame('recorded', BatchEntryState::for($entry)['chip']);
        }
    }

    public function test_cancelling_a_batch_removes_entries_that_were_never_recorded(): void
    {
        $batch = $this->batchOf($this->teacher(), 3);
        $entries = $batch->entries()->orderBy('id')->get();
        $recorded = $this->record($entries[0]);
        $untouched = $entries[1];
        $alsoUntouched = $entries[2];

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        foreach ([$untouched, $alsoUntouched] as $entry) {
            $this->assertDatabaseMissing('assessments', ['id' => $entry->id]);
            $this->assertDatabaseMissing('assessment_sessions', ['assessment_id' => $entry->id]);
        }

        // A recording is work the child already did, so it is not an entry that
        // "was never recorded": it stays on the cancelled board.
        $this->assertDatabaseHas('assessments', ['id' => $recorded->id, 'batch_id' => $batch->id]);
        Storage::disk('public')->assertExists($recorded->audio_file);
    }

    public function test_a_cancelled_batch_holding_a_queued_and_a_scored_entry_stays_cancelled(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $this->scored($entries[0]);
        $this->queue($entries[1]);

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        // Un-sending the queued entry saves an Assessment, which fires
        // AssessmentObserver -> refreshStatus(). `cancelled` is terminal and set
        // by a person, so nothing recomputes it back from the entries.
        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);

        // And this is what pins the *ordering* - cancel() writing the status
        // before the un-send loop rather than after it. With the write last,
        // the un-sends drive refreshStatus() mid-method and a completed_at set
        // there survives the final overwrite of the status, so a batch the
        // teacher stopped would claim to have finished. Nothing on this board
        // completed, so there is no date to show for it.
        $this->assertNull($batch->fresh()->completed_at, 'a cancelled batch never completed');

        // And it survives anything that recomputes afterwards, too.
        $batch->fresh()->refreshStatus();
        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);

        $this->actingAs($batch->teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()->assertJson(['batch_status' => AssessmentBatch::STATUS_CANCELLED]);
    }

    /**
     * cancel() writes `cancelled` before it un-sends anything - pinned by
     * watching the batch from inside the loop.
     *
     * This is the assertion R42 was missing. Asserting the final row cannot see
     * the ordering at all: with the status written last, the un-sends do drive
     * AssessmentObserver -> refreshStatus() mid-method, but the entry just
     * un-sent reads `recorded`, so the recompute lands on `analyzing` - the
     * same value the batch already held - and cancel() then overwrites it. The
     * final row, completed_at included, is identical either way.
     *
     * What differs is what refreshStatus() *sees* while it runs. So this reads
     * the batch back from the database at the moment each entry is saved: with
     * the status written first it is already `cancelled`, which is what makes
     * refreshStatus()'s early return cover the whole method rather than only
     * its tail. Without that, the guard protects nothing during the one window
     * where a batch is being recomputed from entries the teacher just stopped,
     * and any future change to refreshStatus()'s rules turns it into the bug
     * R42 describes.
     */
    public function test_cancel_writes_its_status_before_it_un_sends_anything(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $this->scored($entries[0]);
        $this->queue($entries[1]);

        $seen = [];
        Assessment::updated(function (Assessment $entry) use ($batch, &$seen) {
            $seen[] = AssessmentBatch::find($batch->getKey())?->status;
        });

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        $this->assertNotEmpty($seen, 'fixture: the cancel must have un-sent at least one entry');
        $this->assertSame(
            [AssessmentBatch::STATUS_CANCELLED],
            array_values(array_unique($seen)),
            'every write inside cancel() must happen against a batch that is already cancelled'
        );
    }

    public function test_a_cancelled_batchs_join_codes_no_longer_admit_a_child(): void
    {
        $batch = $this->batchOf($this->teacher());
        // A child who has already recorded, so the entry survives the cancel and
        // there is something left for the student door to refuse.
        $entry = $this->record($batch->entries()->orderBy('id')->first());

        $this->assertDatabaseHas('assessment_sessions', ['assessment_id' => $entry->id]);

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        $child = $this->withSession(['student_learner_id' => $entry->learner_id]);

        $child->getJson(route('student.assessment.pending'))->assertOk()->assertJson(['has_pending' => false]);
        $this->withSession(['student_learner_id' => $entry->learner_id])
            ->get(route('student.assessment.read', $entry))->assertRedirect(route('student.dashboard'));
        $this->withSession(['student_learner_id' => $entry->learner_id])
            ->postJson(route('student.assessment.start', $entry))->assertStatus(403);
    }

    public function test_a_cancelled_batch_cannot_accept_new_recordings(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->record($batch->entries()->orderBy('id')->first());
        $audio = $entry->audio_file;

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        // The child's door.
        $this->withSession(['student_learner_id' => $entry->learner_id])
            ->postJson(route('student.assessment.upload-audio', $entry), [
                'audio' => UploadedFile::fake()->create('reading.webm', 10, 'audio/webm'),
            ])->assertStatus(403);

        // The teacher's own doors: nothing goes into the lane from a batch she
        // stopped.
        $this->actingAs($batch->teacher)->postJson(route('assessments.batch.send', [$batch, $entry]))
            ->assertStatus(422);
        $this->actingAs($batch->teacher)->post(route('assessments.batch.send-all', $batch));

        $entry->refresh();

        $this->assertSame($audio, $entry->audio_file);
        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_cancelling_a_batch_reports_what_will_be_kept_and_stopped(): void
    {
        $batch = $this->batchOf($this->teacher(), 5);
        $entries = $batch->entries()->orderBy('id')->get();
        $this->scored($entries[0]);
        $this->scored($entries[1]);
        $this->queue($entries[2]);
        // entries[3] and entries[4] are still waiting, never recorded.

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        // The confirmation names the consequences instead of asking a generic
        // "are you sure?".
        $board->assertSee('Cancel this batch?');
        $board->assertSee('2 children already assessed keep their results.');
        $board->assertSee('2 waiting and 1 queued will be stopped.');

        // And the action itself says what it actually did.
        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 children already assessed keep their results')
                && str_contains($message, '2 waiting') && str_contains($message, '1 queued'));
    }

    public function test_cancelling_a_batch_twice_leaves_the_first_cancel_alone(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $scored = $this->scored($entries[0]);

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        $cancelledAt = $batch->fresh()->cancelled_at;

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'already cancelled'));

        $this->assertEquals($cancelledAt, $batch->fresh()->cancelled_at);
        $this->assertDatabaseHas('assessments', ['id' => $scored->id, 'batch_id' => $batch->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $scored->id]);
    }

    public function test_cancelling_a_batch_where_every_child_is_done_keeps_them_all(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();

        foreach ($entries as $entry) {
            $scored = $this->scored($entry);
            $scored->verdict()->create($this->acceptedVerdict(['decided_by' => $batch->teacher_id]));
        }

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);

        foreach ($entries as $entry) {
            $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => $batch->id]);
            $this->assertDatabaseHas('assessment_results', ['assessment_id' => $entry->id]);
            $this->assertDatabaseHas('assessment_verdicts', ['assessment_id' => $entry->id]);
        }
    }

    public function test_cancelling_a_batch_leaves_a_reading_the_lane_has_already_started(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $running = $this->transcribing($entries[0]);

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        $running->refresh();

        // Whisper is still working on it; nulling the stamp would make the board
        // say `recorded` while a result is on its way.
        $this->assertNotNull($running->submitted_for_analysis_at);
        $this->assertSame('transcribing', BatchEntryState::for($running)['chip']);
        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);
    }

    // ── Authorization ─────────────────────────────────────────────────

    public function test_another_teacher_cannot_remove_an_entry_or_cancel_the_batch(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $queued = $this->queue($entries[0]);
        $waiting = $entries[1];

        $intruder = $this->otherTeacher();

        $this->actingAs($intruder)->delete($this->removeUrl($batch, $waiting))->assertStatus(403);
        $this->actingAs($intruder)->post($this->unsendUrl($batch, $queued))->assertStatus(403);
        $this->actingAs($intruder)->post(route('assessments.batch.cancel', $batch))->assertStatus(403);

        $this->assertDatabaseHas('assessments', ['id' => $waiting->id, 'batch_id' => $batch->id]);
        $this->assertNotNull($queued->fresh()->submitted_for_analysis_at);
        $this->assertNotSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->cancelled_at);

        // An admin may look at a board, but assessing - and stopping - is the
        // batch owner's own work.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true, 'email_verified_at' => now()]);
        $this->actingAs($admin)->post(route('assessments.batch.cancel', $batch))->assertStatus(403);
        $this->actingAs($admin)->delete($this->removeUrl($batch, $waiting))->assertStatus(403);

        $this->assertDatabaseHas('assessments', ['id' => $waiting->id, 'batch_id' => $batch->id]);
    }

    public function test_an_entry_from_another_batch_cannot_be_removed_through_this_board(): void
    {
        $teacher = $this->teacher();
        $batch = $this->batchOf($teacher, 2);
        $other = $this->batchOf($teacher, 1);
        $entry = $other->entries()->orderBy('id')->first();

        $this->actingAs($teacher)->delete($this->removeUrl($batch, $entry))->assertStatus(404);

        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => $other->id]);
    }

    // ── The confirmation on the one destructive action ────────────────

    /**
     * A name with an apostrophe must not cost the teacher her dialog.
     *
     * Asserted against the rendered attribute, not against the page merely
     * containing the name, because "the page renders" is exactly the assertion
     * that missed this. Blade's e() writes O'Brien as O&#039;Brien, the browser
     * HTML-decodes an attribute *before* parsing it as JavaScript, and the
     * apostrophe lands back inside the JS string literal: the handler is a
     * syntax error, never compiles, its `return false` never runs, and the form
     * submits with no dialog at all - deleting a child's entry and their
     * recording on one tap because of their surname.
     *
     * What keeps the handler compiling is the backslash addslashes() puts in
     * front of the apostrophe, which survives the entity round-trip. So that is
     * what is asserted: the escaped form present, the breaking form absent.
     */
    public function test_a_child_whose_name_has_an_apostrophe_still_gets_the_remove_confirmation(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->record($batch->entries()->orderBy('id')->first());
        $entry->learner->forceFill(['first_name' => 'Mary', 'last_name' => 'O\'Brien'])->save();

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        // The dialog is still there, and the name in it is escaped for
        // JavaScript before Blade escapes it for HTML.
        $board->assertSee('onsubmit="return confirm(\'Remove Mary O\\&#039;Brien and delete the recording', false);

        // And the shape that breaks the handler is nowhere on the page: an
        // apostrophe inside the confirm() string with no backslash before it.
        $this->assertStringNotContainsString(
            'confirm(\'Remove Mary O&#039;Brien',
            $board->getContent(),
            'an unescaped apostrophe makes the handler a syntax error, so Remove submits with no dialog at all'
        );

        // The panel shares the card, so it is safe there too.
        $this->actingAs($batch->teacher)->get(route('assessments.batch.entry', [$batch, $entry]))->assertOk()
            ->assertSee('onsubmit="return confirm(\'Remove Mary O\\&#039;Brien', false);
    }

    /** The detach confirmation carries a name too, so it is escaped as well. */
    public function test_an_apostrophe_name_is_escaped_in_the_detach_confirmation_too(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->scored($batch->entries()->orderBy('id')->first());
        $entry->learner->forceFill(['first_name' => 'Jude', 'last_name' => 'D\'Cruz'])->save();

        $this->assertSame('needs verdict', BatchEntryState::for($entry->fresh())['chip']);

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        $board->assertSee('onsubmit="return confirm(\'Take Jude D\\&#039;Cruz off this board?', false);
        $this->assertStringNotContainsString('confirm(\'Take Jude D&#039;Cruz', $board->getContent());
    }

    /**
     * Deleting a waiting or recorded entry takes any comprehension answers the
     * teacher had already typed with it, so the confirmation names them.
     */
    public function test_the_remove_confirmation_names_the_comprehension_answers_it_would_take(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entries = $batch->entries()->orderBy('id')->get();
        $entry = $this->record($entries[0]);
        $bare = $this->record($entries[1]);

        ComprehensionAnswer::create([
            'assessment_id' => $entry->id,
            'question_text' => 'What colour is Max?',
            'selected_text' => 'Brown',
            'correct_text' => 'Brown',
            'is_correct' => true,
        ]);

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();

        $board->assertSee('The 1 comprehension answer already entered for them goes too.', false);
        // A card with none does not invent them.
        $this->assertSame('recorded', BatchEntryState::for($bare)['chip']);
        $board->assertDontSee('0 comprehension');
    }

    // ── A measured reading always has a door off the board ────────────

    /**
     * The circular state: a result, no classification, still `queued`.
     *
     * Before this, remove said "cancel the analysis first" and un-send said
     * "take this child out of the batch" - each door pointing at the other,
     * batch_id never changing, the card unable to leave at all. A result
     * exists, so the answer is the one every other scored state already gets:
     * detach.
     */
    public function test_a_measured_but_unclassified_reading_can_leave_the_board(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->measuredButUnclassified($batch->entries()->orderBy('id')->first());

        // No verdict, deliberately: scopeAwaitingRescore() only claims an
        // unclassified result while no teacher has ruled on it, and a verdict
        // would take the card straight to `done` - out of the state this test
        // is about.
        ComprehensionAnswer::create([
            'assessment_id' => $entry->id,
            'question_text' => 'What colour is Max?',
            'selected_text' => 'Brown',
            'correct_text' => 'Brown',
            'is_correct' => true,
        ]);

        // The fixture really is the circular state, not merely a scored card.
        $entry = $entry->fresh();
        $this->assertNotNull($entry->result, 'fixture: there must be a result');
        $this->assertSame(
            AssessmentResult::STATUS_UNAVAILABLE,
            $entry->result->classification_status,
            'fixture: the model must have failed to classify it'
        );
        $this->assertSame('queued', BatchEntryState::for($entry)['chip'], 'fixture: it must still read queued');

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect(route('assessments.batch.board', $batch))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'no longer on this board'));

        // Detached, with everything measured intact. That is the whole of it.
        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $entry->id,
            'classification_status' => AssessmentResult::STATUS_UNAVAILABLE,
        ]);
        $this->assertDatabaseHas('comprehension_answers', ['assessment_id' => $entry->id]);
        $this->assertSame($entry->audio_file, $entry->fresh()->audio_file);
        Storage::disk('public')->assertExists($entry->audio_file);
        $this->assertNotNull(
            $entry->fresh()->submitted_for_analysis_at,
            'detaching writes batch_id and nothing else'
        );
    }

    /**
     * A queued card with nothing measured is still asked to cancel the analysis
     * first: that refusal was right, it was only too wide.
     */
    public function test_a_queued_card_with_no_result_is_still_asked_to_cancel_the_analysis_first(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->queue($batch->entries()->orderBy('id')->first());

        $this->assertNull($entry->result);

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'in line for analysis'));

        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => $batch->id]);
    }

    // ── An engine outage must not pin a card to the board ─────────────

    /**
     * A card stuck `transcribing` past the lane's own staleness bound.
     *
     * AssessmentScorer::score() stamps analysis_started_at before it calls the
     * engine, and RescoreQueue::rescore() calls markFailed() *after* that - so
     * an outage or a killed worker leaves the pair saying "started, never
     * finished" indefinitely. The lane re-claims such a run after
     * Assessment::STALE_PROCESSING_MINUTES; past that the write-time refusal
     * stands down too, or the teacher is left with a card that offers her
     * nothing and refuses everything.
     */
    public function test_a_card_stuck_transcribing_past_the_stale_bound_can_be_un_sent(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->transcribing($batch->entries()->orderBy('id')->first());

        // While the run is alive, the door is shut.
        $this->assertTrue(BatchEntryState::isRunningNow($entry));
        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'being analysed'));

        // The engine died. Nothing has touched the row since.
        $this->travel(Assessment::STALE_PROCESSING_MINUTES + 1)->minutes();

        $entry = $entry->fresh();
        $this->assertSame('transcribing', BatchEntryState::for($entry)['chip'], 'the chip is still the truth');
        $this->assertFalse(BatchEntryState::isRunningNow($entry), 'nothing is listening to this recording any more');

        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))
            ->assertRedirect(route('assessments.batch.board', $batch))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'taken out of the lane'));

        $entry->refresh();

        $this->assertNull($entry->submitted_for_analysis_at);
        // A door off the board is not a licence to throw the child's work away.
        Storage::disk('public')->assertExists($entry->audio_file);

        $this->travelBack();
    }

    public function test_a_card_stuck_transcribing_past_the_stale_bound_offers_a_door_on_the_board(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->transcribing($batch->entries()->orderBy('id')->first());

        // While it runs: the explaining line, and no controls at all.
        $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk()
            ->assertSee('data-manage=""', false)
            ->assertSee('Being analysed now')
            ->assertDontSee('Cancel analysis');

        $this->travel(Assessment::STALE_PROCESSING_MINUTES + 1)->minutes();

        $board = $this->actingAs($batch->teacher)->get(route('assessments.batch.board', $batch))->assertOk();
        $board->assertSee('Cancel analysis');
        $board->assertSee($this->unsendUrl($batch, $entry), false);
        $board->assertDontSee('Being analysed now');

        // The poll reports it too, so an open board is re-rendered into the row
        // that now has a door rather than skipped as unchanged.
        $this->actingAs($batch->teacher)->get(route('assessments.batch.poll', $batch))
            ->assertOk()
            ->assertJsonPath('entries.' . $entry->id . '.chip', 'transcribing')
            ->assertJsonPath('entries.' . $entry->id . '.running', false);

        $this->travelBack();
    }

    public function test_a_card_stuck_transcribing_past_the_stale_bound_can_be_removed(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->transcribing($batch->entries()->orderBy('id')->first());

        $this->travel(Assessment::STALE_PROCESSING_MINUTES + 1)->minutes();

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'no longer on this board'));

        // Detached, not deleted: nothing measured it, but the recording is the
        // child's own work and the row is their history.
        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => null]);
        Storage::disk('public')->assertExists($entry->audio_file);

        $this->travelBack();
    }

    // ── The backstop reads the database, not its caller's memory ──────

    /**
     * A result that lands while the teacher's request is in flight.
     *
     * deleteEntry() used to re-read $entry->result, a relation the call site had
     * already loaded, so its "even if a caller asks" guarantee was an in-memory
     * re-read of the caller's own copy. Here the result is written straight to
     * the table, behind the loaded model's back - which is what a concurrent
     * scoring run looks like from inside this request - and the backstop must
     * still refuse.
     */
    public function test_the_delete_backstop_sees_a_result_written_behind_the_loaded_model(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->record($batch->entries()->orderBy('id')->first());

        // Loaded and cached exactly as the controller loads it, with no result.
        $entry->load('result', 'verdict');
        $this->assertNull($entry->result);

        // Now a result exists in the database. The model above cannot see it.
        AssessmentResult::create([
            'assessment_id' => $entry->id,
            'accuracy_rate' => 90,
            'words_per_minute' => 80,
            'fluency_score' => 75,
            'reading_level' => 'independent',
            'classification_status' => AssessmentResult::STATUS_CLASSIFIED,
        ]);
        $this->assertNull($entry->result, 'the cached relation is still empty - that is the window');

        $deleteEntry = new \ReflectionMethod(\App\Http\Controllers\AssessmentBatchController::class, 'deleteEntry');
        $deleteEntry->setAccessible(true);

        try {
            $deleteEntry->invoke(app(\App\Http\Controllers\AssessmentBatchController::class), $entry);
            $this->fail('deleteEntry() must refuse an entry whose result is in the database');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }

        // Nothing died: the row, the result, the session and the recording.
        $this->assertDatabaseHas('assessments', ['id' => $entry->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $entry->id]);
        $this->assertDatabaseHas('assessment_sessions', ['assessment_id' => $entry->id]);
        Storage::disk('public')->assertExists($entry->audio_file);
    }

    /**
     * The destructive path cannot half-happen.
     *
     * deleteEntry() removes the sessions and then the entry. Without a
     * transaction, a failure between the two leaves a child's sessions gone and
     * their entry behind - and the audio file gone with them, which no rollback
     * could bring back. So the database work is one transaction and the file is
     * removed only after it commits.
     *
     * The failure is injected on the entry's own delete, which is the only
     * statement that can realistically fail after the sessions have gone.
     */
    public function test_a_failed_delete_leaves_the_entry_its_sessions_and_its_recording(): void
    {
        $batch = $this->batchOf($this->teacher());
        $entry = $this->record($batch->entries()->orderBy('id')->first());
        $audio = $entry->audio_file;

        $this->assertDatabaseHas('assessment_sessions', ['assessment_id' => $entry->id]);

        Assessment::deleting(function () {
            throw new \RuntimeException('the database went away mid-delete');
        });

        $deleteEntry = new \ReflectionMethod(\App\Http\Controllers\AssessmentBatchController::class, 'deleteEntry');
        $deleteEntry->setAccessible(true);

        try {
            $deleteEntry->invoke(app(\App\Http\Controllers\AssessmentBatchController::class), $entry);
            $this->fail('the injected failure should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('went away', $e->getMessage());
        }

        // Nothing half-happened: the row, its sessions and its recording are
        // all exactly as they were.
        $this->assertDatabaseHas('assessments', ['id' => $entry->id, 'batch_id' => $batch->id]);
        $this->assertDatabaseHas('assessment_sessions', ['assessment_id' => $entry->id]);
        Storage::disk('public')->assertExists($audio);
    }

    // ── An emptied board claims nothing ───────────────────────────────

    public function test_a_batch_emptied_of_its_last_entry_stops_claiming_to_be_analysing(): void
    {
        $batch = $this->batchOf($this->teacher(), 1);
        $entry = $this->queue($batch->entries()->orderBy('id')->first());

        $batch->fresh()->refreshStatus();
        $this->assertSame(
            AssessmentBatch::STATUS_ANALYZING,
            $batch->fresh()->status,
            'fixture: a sent reading makes the batch analysing'
        );

        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $entry))->assertRedirect();
        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $entry))->assertRedirect();

        $this->assertDatabaseMissing('assessments', ['id' => $entry->id]);
        $this->assertSame(0, $batch->fresh()->entries()->count());

        // Nothing is being analysed and nothing was completed, so it claims
        // neither.
        $this->assertSame(AssessmentBatch::STATUS_COLLECTING, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
    }

    // ── Tidying a batch she has already stopped ───────────────

    /**
     * A cancelled batch may still be tidied - but never destructively.
     *
     * Cancelling is not the same act as abandoning: the board stays, and a
     * teacher who has stopped a batch may legitimately want to take a child off
     * it. So remove is not refused on a cancelled batch, which would leave her
     * a board she can read and cannot clear.
     *
     * What is refused is the deletion. A recording is a child's own work and the
     * teacher believes this batch is closed, so nothing she taps on it may take
     * a recording off the disk: a cancelled batch’s entry with audio is
     * detached - batch_id to null, the recording kept on the child’s own
     * history - exactly as a measured reading already is.
     */
    public function test_removing_a_recorded_entry_from_a_cancelled_batch_keeps_the_recording(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $entries = $batch->entries()->orderBy('id')->get();
        $recorded = $this->record($entries[0]);
        $audio = $recorded->audio_file;

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();
        $this->assertSame(AssessmentBatch::STATUS_CANCELLED, $batch->fresh()->status);
        $this->assertDatabaseHas('assessments', ['id' => $recorded->id, 'batch_id' => $batch->id]);

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $recorded))
            ->assertRedirect(route('assessments.batch.board', $batch));

        // Off the board, as she asked - and the reading still exists.
        $this->assertDatabaseHas('assessments', ['id' => $recorded->id, 'batch_id' => null]);
        Storage::disk('public')->assertExists($audio);
    }

    /**
     * The same thing said from the teacher’s side: she is told the recording
     * was kept, not that the child was deleted.
     */
    public function test_the_message_says_the_recording_was_kept(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $recorded = $this->record($batch->entries()->orderBy('id')->first());

        $this->actingAs($batch->teacher)->post(route('assessments.batch.cancel', $batch))->assertRedirect();

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $recorded))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'kept')
                && ! str_contains($message, 'not been sent'));
    }

    /**
     * A card with nothing recorded is still deleted outright, cancelled or not:
     * there is no recording to protect and no measurement to keep, and leaving
     * a detached pending assessment on the child’s record would put a reading
     * in their history that never happened.
     *
     * cancel() deletes every waiting entry itself, so this state is reached
     * only by a board a moment out of date - which is exactly why it is checked
     * at write time.
     */
    public function test_a_waiting_entry_on_a_cancelled_batch_is_still_deleted(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $waiting = $batch->entries()->orderBy('id')->first();

        $batch->forceFill(['status' => AssessmentBatch::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        $this->actingAs($batch->teacher)->delete($this->removeUrl($batch, $waiting))->assertRedirect();

        $this->assertDatabaseMissing('assessments', ['id' => $waiting->id]);
        $this->assertDatabaseMissing('assessment_sessions', ['assessment_id' => $waiting->id]);
    }

    /**
     * Un-send is not refused on a cancelled batch either, and that is the
     * deliberate half of this: un-sending only ever takes a reading *out* of
     * the lane. Refusing it is the one answer that could do harm - a reading
     * left in the lane on a batch the teacher has stopped, with no way for her
     * to stop it being scored.
     *
     * cancel() un-sends every queued entry itself, so the status is forced here
     * rather than cancelled through the action: this is the stale-board race, and
     * it must write nothing worse than a null stamp.
     */
    public function test_un_sending_on_a_cancelled_batch_takes_the_reading_out_of_the_lane(): void
    {
        $batch = $this->batchOf($this->teacher(), 2);
        $queued = $this->queue($batch->entries()->orderBy('id')->first());

        $batch->forceFill(['status' => AssessmentBatch::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        $this->actingAs($batch->teacher)->post($this->unsendUrl($batch, $queued))->assertRedirect();

        $this->assertNull($queued->fresh()->submitted_for_analysis_at);
        $this->assertSame('recorded', BatchEntryState::for($queued->fresh())['chip']);
        Storage::disk('public')->assertExists($queued->audio_file);
    }
}
