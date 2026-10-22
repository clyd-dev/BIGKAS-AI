<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * The fourth door (R48): the pre-existing single-child actions
 * AssessmentController::uploadAudio() and retry(), reached from a learner's
 * history through "Continue" -> assessments.show, used to take a batch entry
 * like any other reading.
 *
 * Two measured consequences, both pinned below:
 *
 *  - uploadAudio() left the entry `processing` with submitted_for_analysis_at
 *    still NULL, so Assessment::scopeAwaitingRescore()'s stale-`processing`
 *    branch claimed it STALE_PROCESSING_MINUTES later: a reading scored with no
 *    Send, while the board's card still read `recorded`. That is the one
 *    invariant the whole batch feature is built on.
 *  - retry() nulled audio_file and deleted the file, destroying an unsent
 *    recording the teacher was about to listen to.
 *
 * The single-child behaviour is unchanged, and that is pinned here too: an
 * assessment with batch_id === null takes the identical path to the identical
 * result, upload and retry alike.
 */
class BatchSingleChildDoorTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here writes or deletes audio; without this they would do
        // it among the real recordings on the real disk.
        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function learnerOf(User $teacher): Learner
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))
            ->orderBy('id')->firstOrFail();
    }

    private function passage(): ReadingMaterial
    {
        return ReadingMaterial::factory()->create([
            'title' => 'Max the Dog',
            'content' => 'I have a dog his name is Max Max is brown he has a long tail',
            'language' => 'en',
            'grade_level' => 4,
        ]);
    }

    /** A batch entry of this teacher's with nothing recorded yet - what the upload form is offered for. */
    private function waitingEntry(User $teacher): Assessment
    {
        $batch = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);

        return Assessment::factory()->create([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnerOf($teacher)->id,
            'assessor_id' => $teacher->id,
            'material_id' => $this->passage()->id,
            'status' => Assessment::STATUS_PENDING,
            'audio_file' => null,
        ])->fresh();
    }

    /** A batch entry of this teacher's with a recording saved and not sent. */
    private function recordedEntry(User $teacher): Assessment
    {
        $batch = AssessmentBatch::factory()->create(['teacher_id' => $teacher->id]);

        return $this->batchEntryWithAudio([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnerOf($teacher)->id,
            'assessor_id' => $teacher->id,
            'material_id' => $this->passage()->id,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    /** The same shapes with no batch behind them: the single-child readings. */
    private function soloWaiting(User $teacher): Assessment
    {
        return Assessment::factory()->create([
            'batch_id' => null,
            'learner_id' => $this->learnerOf($teacher)->id,
            'assessor_id' => $teacher->id,
            'material_id' => $this->passage()->id,
            'status' => Assessment::STATUS_PENDING,
            'audio_file' => null,
        ])->fresh();
    }

    private function soloRecorded(User $teacher): Assessment
    {
        return $this->assessmentWithAudio([
            'batch_id' => null,
            'learner_id' => $this->learnerOf($teacher)->id,
            'assessor_id' => $teacher->id,
            'material_id' => $this->passage()->id,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    private function recording(): UploadedFile
    {
        return UploadedFile::fake()->create('recording.webm', 12, 'audio/webm');
    }

    // ── The upload door ───────────────────────────────────────────────

    public function test_the_single_child_upload_refuses_a_batch_entry_and_stores_nothing(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);

        $response = $this->actingAs($teacher)
            ->post(route('assessments.upload-audio', $entry), ['audio' => $this->recording()]);

        $response->assertRedirect(route('assessments.batch.entry', [$entry->batch_id, $entry]));
        $response->assertSessionHas('error', fn ($message) => str_contains($message, 'group assessment'));

        $entry->refresh();
        $this->assertNull($entry->audio_file, 'No recording may be written through the refused door.');
        $this->assertSame(Assessment::STATUS_PENDING, $entry->status);
        $this->assertNull($entry->submitted_for_analysis_at);
    }

    /**
     * The sharp one. Before the guard this assertion failed: the refused upload
     * had left `processing` with no send stamp, and sixteen minutes later the
     * lane claimed the entry and scored a reading the teacher never sent.
     */
    public function test_the_rescore_lane_never_claims_a_batch_entry_the_single_child_upload_touched(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);

        $this->actingAs($teacher)
            ->post(route('assessments.upload-audio', $entry), ['audio' => $this->recording()]);

        $this->travel(Assessment::STALE_PROCESSING_MINUTES + 1)->minutes();

        $this->assertFalse(
            Assessment::awaitingRescore()->whereKey($entry->id)->exists(),
            'An entry the teacher never sent must not be owed a score, however it was touched.'
        );
    }

    /** A recorded batch entry is refused too - a re-record from here is the same door. */
    public function test_the_single_child_upload_refuses_a_batch_entry_that_already_has_a_recording(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);
        $saved = $entry->audio_file;

        $this->actingAs($teacher)
            ->post(route('assessments.upload-audio', $entry), ['audio' => $this->recording()])
            ->assertRedirect(route('assessments.batch.entry', [$entry->batch_id, $entry]));

        $entry->refresh();
        $this->assertSame($saved, $entry->audio_file, 'The saved recording may not be replaced from here.');
        Storage::disk('public')->assertExists($saved);
    }

    /** The recorder's own fetch caller gets a refusal it can read, not a redirect. */
    public function test_a_json_caller_is_refused_with_a_conflict(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);

        $response = $this->actingAs($teacher)->postJson(
            route('assessments.upload-audio', $entry),
            ['audio' => $this->recording()]
        );

        $response->assertStatus(409);
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('group assessment', $response->json('message'));
        $this->assertNull($entry->fresh()->audio_file);
    }

    // ── The retry door ────────────────────────────────────────────────

    public function test_record_again_cannot_destroy_an_unsent_batch_recording(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);
        $saved = $entry->audio_file;

        $response = $this->actingAs($teacher)->post(route('assessments.retry', $entry));

        // Asserted before the redirect: this is what the door destroyed.
        $entry->refresh();
        $this->assertSame($saved, $entry->audio_file, 'The unsent recording must survive the click.');
        Storage::disk('public')->assertExists($saved);
        $this->assertSame(Assessment::STATUS_PENDING, $entry->status);

        $response->assertRedirect(route('assessments.batch.entry', [$entry->batch_id, $entry]));
        $response->assertSessionHas('error', fn ($message) => str_contains($message, 'group assessment'));
    }

    // ── With the feature switched off ─────────────────────────────────

    public function test_with_the_feature_off_the_teacher_lands_on_the_learners_history_not_a_404(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);
        $saved = $entry->audio_file;
        config(['assessment.batch_enabled' => false]);

        $response = $this->actingAs($teacher)->post(route('assessments.retry', $entry));

        $response->assertRedirect(route('assessments.learner-history', $entry->learner_id));
        $response->assertSessionHas('error', fn ($message) => str_contains($message, 'switched off'));

        $this->assertSame($saved, $entry->fresh()->audio_file);
        Storage::disk('public')->assertExists($saved);
    }

    public function test_with_the_feature_off_the_upload_is_refused_the_same_way(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);
        config(['assessment.batch_enabled' => false]);

        $this->actingAs($teacher)
            ->post(route('assessments.upload-audio', $entry), ['audio' => $this->recording()])
            ->assertRedirect(route('assessments.learner-history', $entry->learner_id));

        $this->assertNull($entry->fresh()->audio_file);
    }

    // ── The single child, behaviourally untouched ─────────────────────

    public function test_a_single_child_upload_still_stores_the_recording_and_goes_to_processing(): void
    {
        $teacher = $this->teacher();
        $solo = $this->soloWaiting($teacher);

        $response = $this->actingAs($teacher)
            ->post(route('assessments.upload-audio', $solo), ['audio' => $this->recording()]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $solo->refresh();
        $this->assertNotNull($solo->audio_file);
        $this->assertSame(Assessment::STATUS_PROCESSING, $solo->status);
        Storage::disk('public')->assertExists($solo->audio_file);

        // And the stale rule over it is the one it always was.
        $this->travel(Assessment::STALE_PROCESSING_MINUTES + 1)->minutes();
        $this->assertTrue(Assessment::awaitingRescore()->whereKey($solo->id)->exists());
    }

    public function test_a_single_child_retry_still_discards_the_recording_and_returns_to_the_reading(): void
    {
        $teacher = $this->teacher();
        $solo = $this->soloRecorded($teacher);
        $saved = $solo->audio_file;

        $this->actingAs($teacher)->post(route('assessments.retry', $solo))
            ->assertRedirect(route('assessments.show', $solo));

        $solo->refresh();
        $this->assertNull($solo->audio_file);
        $this->assertSame(Assessment::STATUS_PENDING, $solo->status);
        Storage::disk('public')->assertMissing($saved);
    }

    public function test_the_single_child_upload_still_validates_its_file(): void
    {
        $teacher = $this->teacher();
        $solo = $this->soloWaiting($teacher);

        $this->actingAs($teacher)
            ->post(route('assessments.upload-audio', $solo), [])
            ->assertSessionHasErrors('audio');
    }

    // ── The screen the door was reached from ──────────────────────────

    public function test_the_single_child_screen_offers_neither_button_for_a_batch_entry(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);

        $page = $this->actingAs($teacher)->get(route('assessments.show', $entry));

        $page->assertOk();
        $page->assertDontSee(route('assessments.retry', $entry));
        $page->assertDontSee(route('assessments.upload-audio', $entry));
    }

    public function test_the_single_child_screen_still_offers_both_for_a_reading_of_its_own(): void
    {
        $teacher = $this->teacher();
        $solo = $this->soloRecorded($teacher);
        $waiting = $this->soloWaiting($teacher);

        $this->actingAs($teacher)->get(route('assessments.show', $solo))
            ->assertSee(route('assessments.retry', $solo));

        $this->actingAs($teacher)->get(route('assessments.show', $waiting))
            ->assertSee(route('assessments.upload-audio', $waiting));
    }
}
