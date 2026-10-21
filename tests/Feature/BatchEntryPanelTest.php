<?php

namespace Tests\Feature;

use App\Exceptions\SpeechServiceUnavailable;
use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Services\AssessmentScorer;
use App\Services\BatchEntryState;
use App\Services\MLClassificationService;
use App\Services\RescoreQueue;
use App\Services\SpeechToTextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesBatchAssessments;
use Tests\TestCase;

/**
 * The card panel: where a teacher conducts one child's reading, listens to it
 * and sends it for analysis.
 *
 * Two things are pinned here above everything else:
 *
 *  - A stored recording **rests**. Uploading makes the entry `recorded`, never
 *    queued: the teacher listens before committing lane time, and the scoring
 *    lane is strictly one at a time, so nothing may enter it unasked.
 *  - Comprehension answers reach ComprehensionService::record() **before**
 *    anything is sent (spec 4.3). The Send gate makes that structural - send()
 *    refuses without answers, so an unanswered entry can never be in the lane -
 *    and test_answers_survive_when_no_speech_engine_answers is the regression
 *    guard: the child answered, the engine was down, and the work is still there.
 */
class BatchEntryPanelTest extends TestCase
{
    use MakesBatchAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The trait never fakes the disk and this whole task is about audio:
        // without this, every upload here would be written among the real
        // recordings on the real disk.
        Storage::fake('public');
        config(['assessment.batch_enabled' => true]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function learnerOf(User $teacher, int $index = 0): Learner
    {
        return Learner::whereIn('class_id', $teacher->taughtClasses()->pluck('id'))
            ->orderBy('id')->get()->get($index);
    }

    private function panelBatch(User $teacher, array $overrides = []): AssessmentBatch
    {
        return AssessmentBatch::factory()->create(array_merge(['teacher_id' => $teacher->id], $overrides));
    }

    /** A passage with real words in it, so the panel has something to show. */
    private function passage(string $title = 'Max the Dog'): ReadingMaterial
    {
        return ReadingMaterial::factory()->create([
            'title' => $title,
            'content' => 'I have a dog his name is Max Max is brown he has a long tail',
            'language' => 'en',
            'grade_level' => 4,
        ]);
    }

    /** A material that really carries comprehension questions. */
    private function materialWithQuestions(int $count = 1): ReadingMaterial
    {
        $material = $this->passage('Max the Dog, with questions');

        for ($i = 1; $i <= $count; $i++) {
            ComprehensionQuestion::create([
                'material_id' => $material->id,
                'question' => "Question {$i}: what colour is Max?",
                'question_type' => 'literal',
                // Stored as the option's text, which is how isCorrect() compares.
                'correct_answer' => 'Brown',
                'option_a' => 'Brown',
                'option_b' => 'Blue',
                'sort_order' => $i,
            ]);
        }

        return $material;
    }

    /** An entry on this teacher's board with nothing recorded yet. */
    private function waitingEntry(User $teacher, array $overrides = []): Assessment
    {
        $batch = $overrides['batch'] ?? $this->panelBatch($teacher);
        unset($overrides['batch']);

        return Assessment::factory()->create(array_merge([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnerOf($teacher)->id,
            'assessor_id' => $teacher->id,
            'material_id' => $this->passage()->id,
        ], $overrides))->fresh();
    }

    /** An entry on this teacher's board with a recording saved and not sent. */
    private function recordedEntry(User $teacher, array $overrides = []): Assessment
    {
        $batch = $overrides['batch'] ?? $this->panelBatch($teacher);
        unset($overrides['batch']);

        return $this->batchEntryWithAudio(array_merge([
            'batch_id' => $batch->id,
            'learner_id' => $this->learnerOf($teacher)->id,
            'assessor_id' => $teacher->id,
            'material_id' => $this->passage()->id,
        ], $overrides));
    }

    /** A recorded entry whose type really needs comprehension answers. */
    private function comprehensionEntry(User $teacher, array $overrides = [], int $questions = 1): Assessment
    {
        $entry = $this->recordedEntry($teacher, array_merge([
            'assessment_type' => Assessment::TYPE_COMPREHENSION,
            'material_id' => $this->materialWithQuestions($questions)->id,
        ], $overrides));

        $this->assertTrue($entry->needsComprehensionTest(), 'The fixture must really need comprehension answers.');

        return $entry;
    }

    private function recording(string $name = 'recording.webm'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'audio/webm');
    }

    /** question id => option letter, for every question on this entry's material. */
    private function allAnswers(Assessment $entry, string $letter = 'A'): array
    {
        return $entry->material->comprehensionQuestions()->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => $letter])->all();
    }

    // ── The recorded resting state ────────────────────────────────────

    public function test_an_uploaded_recording_rests_at_recorded_and_is_not_queued(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);

        $response = $this->actingAs($teacher)->post(
            route('assessments.batch.upload-audio', [$entry->batch_id, $entry->id]),
            ['audio' => $this->recording()]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('state.chip', 'recorded')
            ->assertJsonPath('state.playable', true)
            ->assertJsonPath('state.action', 'Send for analysis');

        $entry->refresh();
        $this->assertNotNull($entry->audio_file);
        Storage::disk('public')->assertExists($entry->audio_file);
        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_sending_puts_the_entry_in_the_lane(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send', [$entry->batch_id, $entry->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('state.chip', 'queued');

        $entry->refresh();
        $this->assertNotNull($entry->submitted_for_analysis_at);
        $this->assertTrue(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_re_recording_replaces_the_audio_and_keeps_it_unsent(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);
        $first = $entry->audio_file;

        $this->actingAs($teacher)->post(
            route('assessments.batch.upload-audio', [$entry->batch_id, $entry->id]),
            ['audio' => $this->recording()]
        )->assertOk()->assertJsonPath('state.chip', 'recorded');

        $entry->refresh();
        $this->assertNotSame($first, $entry->audio_file);
        Storage::disk('public')->assertExists($entry->audio_file);
        Storage::disk('public')->assertMissing($first);
        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    /**
     * Card honesty: a measured reading is never quietly replaced underneath its
     * own numbers.
     *
     * Follow Task 8's derivation order with a result in place and no stamp: it
     * never reaches `recorded` (which requires no result), so the card would
     * keep reading `needs verdict` or `done` - a reading level and an accuracy
     * figure belonging to a recording that is no longer on disk. So the
     * re-record is refused instead, and the result is left strictly alone: no
     * board action may destroy a measured reading, and deleting one cascades
     * into verdicts and answers and silently recomputes the learner's level.
     */
    public function test_a_child_with_a_result_cannot_be_silently_re_recorded(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher, ['submitted_for_analysis_at' => now()->subHour()]);
        $entry->createResult($this->classifiedAnalysis());
        $entry = $entry->fresh(['result', 'verdict', 'material', 'learner']);

        $audio = $entry->audio_file;
        $chip = BatchEntryState::for($entry)['chip'];
        $this->assertSame('needs verdict', $chip, 'The fixture must really be a measured reading.');

        $this->actingAs($teacher)->postJson(
            route('assessments.batch.upload-audio', [$entry->batch_id, $entry->id]),
            ['audio' => $this->recording()]
        )->assertStatus(422);

        $fresh = $entry->fresh(['result', 'verdict', 'material', 'learner']);

        // The recording on disk, the result row and the card all unchanged.
        $this->assertSame($audio, $fresh->audio_file);
        Storage::disk('public')->assertExists($audio);
        $this->assertDatabaseCount('assessment_results', 1);
        $this->assertSame($entry->result->id, $fresh->result->id);
        $this->assertSame($entry->result->accuracy_rate, $fresh->result->accuracy_rate);
        $this->assertSame($chip, BatchEntryState::for($fresh)['chip']);
    }

    // ── What the panel shows ──────────────────────────────────────────

    public function test_the_panel_offers_both_a_join_code_and_conduct_here(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);

        // Task 10 owns issuing a session for every batch entry; this panel only
        // reads one, so the fixture creates it here.
        $session = AssessmentSession::create([
            'assessment_id' => $entry->id,
            'learner_id' => $entry->learner_id,
            'teacher_id' => $teacher->id,
            'material_id' => $entry->material_id,
            'session_code' => 'ZX9Q4T',
            'status' => 'waiting',
            'started_at' => now(),
        ]);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$entry->batch_id, $entry->id]))
            ->assertOk()
            ->assertSee($session->session_code)
            ->assertSee('Conduct here');
    }

    public function test_the_panel_shows_the_passage_the_child_must_read(): void
    {
        $teacher = $this->teacher();
        $material = $this->passage('The Brown Dog');
        $entry = $this->waitingEntry($teacher, ['material_id' => $material->id]);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$entry->batch_id, $entry->id]))
            ->assertOk()
            ->assertSee('The Brown Dog')
            ->assertSee($material->content)
            ->assertSee($entry->learner->full_name);
    }

    public function test_the_audio_player_appears_once_a_recording_exists(): void
    {
        $teacher = $this->teacher();
        $waiting = $this->waitingEntry($teacher);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$waiting->batch_id, $waiting->id]))
            ->assertOk()
            ->assertDontSee('<audio', false);

        $recorded = $this->recordedEntry($teacher);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$recorded->batch_id, $recorded->id]))
            ->assertOk()
            ->assertSee('<audio', false)
            ->assertSee($recorded->getAudioUrl(), false);
    }

    public function test_the_panel_asks_the_comprehension_questions_when_the_type_needs_them(): void
    {
        $teacher = $this->teacher();
        $oral = $this->recordedEntry($teacher);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$oral->batch_id, $oral->id]))
            ->assertOk()
            ->assertDontSee('Comprehension Questions');

        $entry = $this->comprehensionEntry($teacher, questions: 2);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$entry->batch_id, $entry->id]))
            ->assertOk()
            ->assertSee('Comprehension Questions')
            ->assertSee('Question 1: what colour is Max?')
            ->assertSee('Question 2: what colour is Max?');
    }

    // ── The Send gate, enforced structurally ──────────────────────────

    public function test_a_comprehension_entry_cannot_be_sent_without_answers(): void
    {
        $teacher = $this->teacher();
        $entry = $this->comprehensionEntry($teacher);

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send', [$entry->batch_id, $entry->id]))
            ->assertStatus(422);

        $entry->refresh();
        $this->assertNull($entry->submitted_for_analysis_at);
        // The whole point of the gate: an unanswered entry can never be in the lane.
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());
    }

    public function test_recording_answers_then_sending_works(): void
    {
        $teacher = $this->teacher();
        $entry = $this->comprehensionEntry($teacher, questions: 2);

        $this->actingAs($teacher)->postJson(
            route('assessments.batch.answers', [$entry->batch_id, $entry->id]),
            ['answers' => $this->allAnswers($entry)]
        )->assertOk()->assertJsonPath('comprehension_score', 100);

        $entry->refresh();
        $this->assertTrue($entry->hasComprehensionAnswers());
        $this->assertNull($entry->submitted_for_analysis_at, 'Recording answers must not send anything.');

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send', [$entry->batch_id, $entry->id]))
            ->assertOk()
            ->assertJsonPath('state.chip', 'queued');

        $entry->refresh();
        $this->assertNotNull($entry->submitted_for_analysis_at);
        $this->assertSame(100.0, $entry->comprehensionScore());
    }

    public function test_answers_are_stored_before_anything_is_sent(): void
    {
        $teacher = $this->teacher();
        $entry = $this->comprehensionEntry($teacher, questions: 2);
        $questionIds = $entry->material->comprehensionQuestions()->pluck('id')->all();

        // Half-answered: the answers service refuses the whole set, and because
        // the answers are recorded first, nothing reaches the lane.
        $this->actingAs($teacher)->postJson(
            route('assessments.batch.send', [$entry->batch_id, $entry->id]),
            ['answers' => [$questionIds[0] => 'A']]
        )->assertStatus(422);

        $entry->refresh();
        $this->assertFalse($entry->hasComprehensionAnswers());
        $this->assertNull($entry->submitted_for_analysis_at);
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($entry->id)->exists());

        // Fully answered: recorded first, then the entry is stamped - never the
        // other way round.
        $this->actingAs($teacher)->postJson(
            route('assessments.batch.send', [$entry->batch_id, $entry->id]),
            ['answers' => $this->allAnswers($entry)]
        )->assertOk();

        $entry->refresh();
        $this->assertCount(2, $entry->comprehensionAnswers()->get());
        $this->assertNotNull($entry->submitted_for_analysis_at);

        $lastAnswer = $entry->comprehensionAnswers()->orderByDesc('created_at')->first();
        $this->assertNotNull($lastAnswer->created_at);
        $this->assertTrue(
            $lastAnswer->created_at->lessThanOrEqualTo(Carbon::parse($entry->submitted_for_analysis_at)),
            'The answers must be stored no later than the moment the entry was sent.'
        );
    }

    public function test_answers_survive_when_no_speech_engine_answers(): void
    {
        $teacher = $this->teacher();
        $entry = $this->comprehensionEntry($teacher, questions: 2);

        $this->actingAs($teacher)->postJson(
            route('assessments.batch.answers', [$entry->batch_id, $entry->id]),
            ['answers' => $this->allAnswers($entry)]
        )->assertOk();

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send', [$entry->batch_id, $entry->id]))
            ->assertOk();

        $entry->refresh();
        $this->assertNotNull($entry->submitted_for_analysis_at);
        $this->assertSame(100.0, $entry->comprehensionScore());

        $this->deadSpeechEngine();

        // The lane stands down entirely when the engine is down...
        $summary = app(RescoreQueue::class)->run();
        $this->assertNotNull($summary['skipped']);
        $this->assertSame(0, $summary['scored']);

        // ...and a scoring attempt that gets through anyway cannot score it
        // either. This is exactly what RescoreQueue::rescore() does on an outage.
        try {
            app(SpeechToTextService::class)->transcribe($entry->getAudioPath(), 'en');
            $this->fail('The speech engine was supposed to be down.');
        } catch (SpeechServiceUnavailable $e) {
            // expected
        }

        try {
            app(AssessmentScorer::class)->score($entry);
            $this->fail('Nothing should have been scored with the engine down.');
        } catch (SpeechServiceUnavailable $e) {
            $entry->markFailed($e->getMessage());
        }

        // The child answered the questions. The engine was down. The work is
        // still there.
        $entry->refresh();
        $this->assertTrue($entry->hasComprehensionAnswers());
        $this->assertCount(2, $entry->comprehensionAnswers()->get());
        $this->assertSame(100.0, $entry->comprehensionScore());
        $this->assertNull($entry->result);
    }

    /**
     * The dead engine itself, pinned.
     *
     * MakesBatchAssessments::deadSpeechEngine() is the only thing in this suite
     * that really simulates an outage - fakeEngine(stt: false) leaves
     * /api/transcribe answering 200, so a test built on it scores the reading
     * and proves nothing. This test exists so the helper cannot quietly become
     * another engine that still answers, and so the regression guard above
     * keeps meaning what it says.
     */
    public function test_a_dead_speech_engine_really_cannot_transcribe(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher, ['submitted_for_analysis_at' => now()]);

        $this->deadSpeechEngine();

        // 1. Nothing can report the speech engine up.
        $this->assertFalse(app(MLClassificationService::class)->serviceStatus(fresh: true)['stt_ok']);

        // 2. The lane stands down rather than burning attempts on an outage.
        $summary = app(RescoreQueue::class)->run();
        $this->assertNotNull($summary['skipped']);
        $this->assertSame(0, $summary['scored']);

        // 3. And the transcription itself genuinely fails - the assertion that
        //    fakeEngine(stt: false) would not have survived.
        $this->expectException(SpeechServiceUnavailable::class);
        app(SpeechToTextService::class)->transcribe($entry->getAudioPath(), 'en');
    }

    // ── Send all ──────────────────────────────────────────────────────

    public function test_send_all_sends_every_eligible_recorded_entry(): void
    {
        $teacher = $this->teacher();
        $batch = $this->panelBatch($teacher);

        $one = $this->recordedEntry($teacher, ['batch' => $batch]);
        $two = $this->recordedEntry($teacher, ['batch' => $batch, 'learner_id' => $this->learnerOf($teacher, 1)->id]);
        $waiting = $this->waitingEntry($teacher, ['batch' => $batch, 'learner_id' => $this->learnerOf($teacher, 2)->id]);

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send-all', $batch))
            ->assertOk()
            ->assertJsonPath('sent', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertNotNull($one->fresh()->submitted_for_analysis_at);
        $this->assertNotNull($two->fresh()->submitted_for_analysis_at);
        $this->assertNull($waiting->fresh()->submitted_for_analysis_at, 'A child who has not read yet is not sent.');
    }

    public function test_send_all_skips_entries_missing_answers_and_reports_them(): void
    {
        $teacher = $this->teacher();
        $batch = $this->panelBatch($teacher);

        $ready = $this->recordedEntry($teacher, ['batch' => $batch]);
        $unanswered = $this->comprehensionEntry($teacher, [
            'batch' => $batch,
            'learner_id' => $this->learnerOf($teacher, 1)->id,
        ]);

        // The board's own button is a plain form post, so that path must work too.
        $this->actingAs($teacher)
            ->post(route('assessments.batch.send-all', $batch))
            ->assertRedirect(route('assessments.batch.board', $batch));

        $this->assertNotNull($ready->fresh()->submitted_for_analysis_at);
        $this->assertNull($unanswered->fresh()->submitted_for_analysis_at);
        $this->assertFalse(Assessment::awaitingRescore()->whereKey($unanswered->id)->exists());

        $flash = session('success');
        $this->assertNotNull($flash);
        $this->assertStringContainsString('1', $flash);
        $this->assertStringContainsString('comprehension', $flash);
    }

    public function test_send_all_does_not_touch_entries_already_sent(): void
    {
        $teacher = $this->teacher();
        $batch = $this->panelBatch($teacher);

        $alreadySent = $this->recordedEntry($teacher, [
            'batch' => $batch,
            'submitted_for_analysis_at' => now()->subHours(2),
        ]);
        $fresh = $this->recordedEntry($teacher, ['batch' => $batch, 'learner_id' => $this->learnerOf($teacher, 1)->id]);
        $stamp = $alreadySent->submitted_for_analysis_at;

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send-all', $batch))
            ->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('skipped', 0);

        $this->assertSame($stamp, $alreadySent->fresh()->submitted_for_analysis_at);
        $this->assertNotNull($fresh->fresh()->submitted_for_analysis_at);
    }

    // ── Safety ────────────────────────────────────────────────────────

    public function test_a_dangerous_file_extension_is_not_written_as_given(): void
    {
        $teacher = $this->teacher();
        $entry = $this->waitingEntry($teacher);

        $this->actingAs($teacher)->post(
            route('assessments.batch.upload-audio', [$entry->batch_id, $entry->id]),
            ['audio' => $this->recording('recording.php')]
        )->assertOk();

        $entry->refresh();
        $this->assertNotNull($entry->audio_file);
        // The audio folder is served publicly, so the client's extension is
        // never trusted: anything off the allow-list becomes .webm.
        $this->assertStringEndsWith('.webm', $entry->audio_file);
        $this->assertStringNotContainsString('.php', $entry->audio_file);
        Storage::disk('public')->assertExists($entry->audio_file);
        Storage::disk('public')->assertMissing('assessments/audio/recording.php');
    }

    public function test_a_teacher_cannot_open_or_upload_to_another_teachers_batch(): void
    {
        $teacher = $this->teacher();
        $other = $this->otherTeacher();
        $entry = $this->recordedEntry($other);

        // Genuinely unreachable: access is per pivot row or per class taught,
        // never per school, and these two teachers share a school.
        $this->assertDatabaseMissing('learner_user', [
            'user_id' => $teacher->id,
            'learner_id' => $entry->learner_id,
        ]);
        $this->assertDatabaseMissing('classes', [
            'id' => $entry->learner->class_id,
            'teacher_id' => $teacher->id,
        ]);

        $this->actingAs($teacher)->get(route('assessments.batch.entry', [$entry->batch_id, $entry->id]))
            ->assertForbidden();

        $this->actingAs($teacher)->post(
            route('assessments.batch.upload-audio', [$entry->batch_id, $entry->id]),
            ['audio' => $this->recording()]
        )->assertForbidden();

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send', [$entry->batch_id, $entry->id]))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->postJson(route('assessments.batch.send-all', $entry->batch_id))
            ->assertForbidden();

        $this->assertSame($entry->audio_file, $entry->fresh()->audio_file);
        $this->assertNull($entry->fresh()->submitted_for_analysis_at);
    }

    public function test_an_admin_cannot_record_in_a_batch(): void
    {
        $teacher = $this->teacher();
        $entry = $this->recordedEntry($teacher);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true, 'email_verified_at' => now()]);

        // Admins browse, teachers assess - the LearnerAdminReadOnlyTest precedent.
        $this->actingAs($admin)->get(route('assessments.batch.entry', [$entry->batch_id, $entry->id]))
            ->assertForbidden();
        $this->actingAs($admin)->post(
            route('assessments.batch.upload-audio', [$entry->batch_id, $entry->id]),
            ['audio' => $this->recording()]
        )->assertForbidden();
        $this->actingAs($admin)->postJson(
            route('assessments.batch.answers', [$entry->batch_id, $entry->id]),
            ['answers' => []]
        )->assertForbidden();
        $this->actingAs($admin)
            ->postJson(route('assessments.batch.send', [$entry->batch_id, $entry->id]))
            ->assertForbidden();
        $this->actingAs($admin)
            ->postJson(route('assessments.batch.send-all', $entry->batch_id))
            ->assertForbidden();

        $this->assertNull($entry->fresh()->submitted_for_analysis_at);
    }
}
