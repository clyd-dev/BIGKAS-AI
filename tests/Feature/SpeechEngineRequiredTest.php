<?php

namespace Tests\Feature;

use App\Exceptions\SpeechServiceUnavailable;
use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * With no speech engine answering, the service used to return a hardcoded
 * passage and the app scored it — storing a reading level and an intervention
 * plan that described nothing. Outside development it must refuse instead.
 */
class SpeechEngineRequiredTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();

        $this->teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function assessment(): Assessment
    {
        $school = School::firstOrCreate(['name' => 'STT School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'STT', 'school_year' => '2026-2027',
        ]);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $material = ReadingMaterial::create([
            'title' => 'STT Passage', 'content' => 'The dog ran to the park.', 'language' => 'en',
            'grade_level' => 4, 'difficulty' => 'easy', 'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 6,
        ]);

        return Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    /** No Flask, no API key, and the placeholder not allowed. */
    private function noEngineAvailable(): void
    {
        config([
            'services.whisper.use_local' => true,
            'services.whisper.allow_mock' => false,
            'services.openai.api_key' => '',
        ]);

        Http::fake(['*/api/transcribe' => Http::response('connection refused', 500)]);
    }

    public function test_the_service_refuses_rather_than_returning_a_placeholder(): void
    {
        $this->noEngineAvailable();

        $this->expectException(SpeechServiceUnavailable::class);

        app(SpeechToTextService::class)->transcribe(__FILE__, 'en');
    }

    public function test_analyze_stores_no_assessment_when_no_engine_answers(): void
    {
        $this->noEngineAvailable();
        $assessment = $this->assessment();

        $response = $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertStatus(503)->assertJsonPath('success', false);

        // Nothing scored, and no placeholder text anywhere near the learner.
        $this->assertDatabaseCount('assessment_results', 0);
        $this->assertNull($assessment->fresh()->result);
        $this->assertNull($assessment->fresh()->learner->reading_level);

        // The recording survives, so the teacher can simply analyse again.
        $this->assertNotNull($assessment->fresh()->audio_file);
        Storage::disk('public')->assertExists($assessment->fresh()->audio_file);

        $message = $response->json('message');
        // The teacher is told the recording survived and pointed at the retry,
        // not left to assume the session has to be redone.
        $this->assertStringContainsString('recording is saved', $message);
        $this->assertStringContainsString('Analyze', $message);
        $this->assertStringContainsString('no need to record again', $message);
        $this->assertStringNotContainsString('Max', $message);
    }

    /**
     * The failure must not be a dead end: the recording page used to disappear
     * entirely once an assessment was marked failed, stranding the saved audio
     * and forcing the learner to read the whole passage again.
     */
    public function test_a_failed_assessment_can_still_be_analysed_from_its_page(): void
    {
        $this->noEngineAvailable();
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertStatus(503);

        $assessment->refresh();
        $this->assertSame(Assessment::STATUS_FAILED, $assessment->status);
        $this->assertTrue($assessment->awaitingAnalysis());

        $this->actingAs($this->teacher)->get(route('assessments.show', $assessment))
            ->assertOk()
            // The saved recording and a live Analyze button are both still there.
            ->assertSee('id="audioPlayback"', false)
            ->assertSee('id="btnAnalyze"', false)
            ->assertDontSee('id="btnAnalyze" class="btn btn-success btn-sm d-none"', false)
            // ...and the explanation is shown, not hidden.
            ->assertSee('has not been scored yet')
            ->assertDontSee('id="analysisProblem" class="alert alert-warning text-start small mb-2 d-none"', false)
            ->assertSee('tap <strong>Analyze</strong> to try again', false)
            ->assertSee('Record again');
    }

    public function test_the_learner_history_flags_an_unscored_recording(): void
    {
        $this->noEngineAvailable();
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertStatus(503);

        $this->actingAs($this->teacher)
            ->get(route('assessments.learner-history', $assessment->learner))
            ->assertOk()
            ->assertSee('Not scored — recording saved')
            ->assertSee('Analyze again');
    }

    /** Once the engine is back, the same saved recording scores normally. */
    public function test_analysing_again_after_the_engine_returns_scores_the_saved_recording(): void
    {
        $this->noEngineAvailable();
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertStatus(503);

        $savedAudio = $assessment->fresh()->audio_file;

        // The engine comes back; the teacher taps Analyze with no new recording.
        $this->mock(\App\Services\SpeechToTextService::class, function ($mock) {
            $mock->shouldReceive('transcribe')->andReturn([
                'text' => 'the dog ran to the park', 'words' => [], 'duration' => 10, 'engine' => 'local_whisper',
            ]);
        });
        $this->mock(\App\Services\MLClassificationService::class, function ($mock) {
            $mock->shouldReceive('classify')->andReturn([
                'primary' => '0', 'secondary' => null, 'confidence' => 0.8, 'all_scores' => [],
            ]);
        });

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment))
            ->assertOk()->assertJsonPath('success', true);

        $assessment->refresh();
        $this->assertNotNull($assessment->result, 'The saved recording should score on the retry.');
        $this->assertSame(Assessment::STATUS_COMPLETED, $assessment->status);
        $this->assertSame($savedAudio, $assessment->audio_file, 'The original recording should be the one scored.');
        $this->assertFalse($assessment->awaitingAnalysis());
    }

    public function test_the_placeholder_is_still_available_for_local_development(): void
    {
        config([
            'services.whisper.use_local' => false,
            'services.whisper.allow_mock' => true,
            'services.openai.api_key' => '',
        ]);

        $transcription = app(SpeechToTextService::class)->transcribe(__FILE__, 'en');

        $this->assertSame('mock', $transcription['engine']);
        $this->assertTrue($transcription['is_mock']);
    }

    public function test_an_unset_flag_means_local_and_testing_only(): void
    {
        config(['services.whisper.allow_mock' => null]);

        // The suite runs in the "testing" environment, where it is allowed.
        $this->assertTrue($this->mockAllowed());

        app()['env'] = 'production';
        $this->assertFalse($this->mockAllowed(), 'Production must never fall back to the placeholder.');
    }

    /** Reach the service's protected decision without duplicating its rule. */
    private function mockAllowed(): bool
    {
        $method = new \ReflectionMethod(SpeechToTextService::class, 'mockAllowed');

        return $method->invoke(app(SpeechToTextService::class));
    }

    public function test_the_transcription_timeout_allows_minutes_not_seconds(): void
    {
        // The recorder stops at 3 minutes of audio, and local CPU inference is
        // slower than real time, so anything near 120s silently loses work.
        $this->assertGreaterThanOrEqual(240, (int) config('services.whisper.timeout'));
    }
}
