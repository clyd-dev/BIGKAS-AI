<?php

namespace Tests\Concerns;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentResult;
use App\Models\AssessmentVerdict;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the batch assessment tests. The using test must have
 * RefreshDatabase and must fake the "public" disk itself, once (Storage::fake in
 * setUp): the helpers never fake it, because faking again would delete the audio
 * of every entry already made.
 */
trait MakesBatchAssessments
{
    private const BATCH_PASSAGE = 'I have a dog his name is Max Max is brown he has a long tail';

    /** A batch entry with a stored recording, not yet sent. Creates a batch unless batch_id is given. */
    protected function batchEntryWithAudio(array $attributes = []): Assessment
    {
        if (! array_key_exists('batch_id', $attributes)) {
            $attributes['batch_id'] = AssessmentBatch::factory()->create()->id;
        }

        return $this->assessmentWithAudio($attributes);
    }

    /** A batch entry with a stored recording that the teacher has sent for analysis. */
    protected function sentBatchEntry(array $attributes = []): Assessment
    {
        return $this->batchEntryWithAudio(array_merge(['submitted_for_analysis_at' => now()], $attributes));
    }

    /** A recording with stored audio and no batch. */
    protected function assessmentWithAudio(array $attributes = []): Assessment
    {
        $path = 'assessments/audio/' . Str::uuid() . '.webm';
        Storage::disk('public')->put($path, 'audio');

        // audio_file goes in at creation so a caller-supplied updated_at survives.
        return Assessment::factory()->create(array_merge(['audio_file' => $path], $attributes))->fresh();
    }

    /** A minimal valid createResult() payload that was classified. */
    protected function classifiedAnalysis(array $overrides = []): array
    {
        return array_merge([
            'accuracy_rate' => 90,
            'words_per_minute' => 80,
            'fluency_score' => 75,
            'reading_level' => 'independent',
            'primary_weakness' => 'Independent Reader',
            'confidence_score' => 0.8,
            'classification_status' => AssessmentResult::STATUS_CLASSIFIED,
        ], $overrides);
    }

    /** The same payload when the model could not classify: still owed a classification. */
    protected function unclassifiedAnalysis(array $overrides = []): array
    {
        return $this->classifiedAnalysis(array_merge([
            'reading_level' => 'frustration',
            'primary_weakness' => null,
            'confidence_score' => null,
            'classification_status' => AssessmentResult::STATUS_UNAVAILABLE,
        ], $overrides));
    }

    /**
     * A minimal valid verdict()->create() payload: the teacher accepted the AI's
     * reading as it stands. No final_* column is set, so the weakness still
     * comes from the model through Assessment::weaknessOutcome().
     */
    protected function acceptedVerdict(array $overrides = []): array
    {
        return array_merge([
            'decision' => AssessmentVerdict::DECISION_ACCEPTED,
            'decided_by' => User::factory()->create(['role' => User::ROLE_TEACHER])->id,
            'decided_at' => now(),
        ], $overrides);
    }

    /**
     * A healthy Flask: transcription answers, the classifier answers.
     *
     * It takes no arguments on purpose. It used to accept $stt and $classifier,
     * and no call site in the suite ever passed either - but fakeEngine(stt: false)
     * read like an outage while still answering /api/transcribe with a full
     * transcript, so a test built on it scored the reading happily and proved
     * nothing. The parameters are gone so that call cannot be written: an outage
     * is deadSpeechEngine(), which is the only helper that produces one.
     */
    protected function fakeEngine(): void
    {
        $t = 0.0;
        $words = array_map(function ($w) use (&$t) {
            $word = ['word' => $w, 'start' => round($t, 2), 'end' => round($t + 0.4, 2), 'confidence' => 0.95];
            $t += 0.45;

            return $word;
        }, explode(' ', self::BATCH_PASSAGE));

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/api/health' => Http::response([
                'whisper_loaded' => true, 'classifier_loaded' => true,
                'model_stale' => false, 'model_version' => '3.0.0-test',
            ]),
            '*/api/transcribe' => Http::response([
                'text' => self::BATCH_PASSAGE, 'words' => $words, 'duration' => 6.5, 'language' => 'en',
            ]),
            '*/api/classify' => Http::response([
                'primary_weakness' => 'Independent Reader', 'secondary_weakness' => null,
                'confidence' => 0.8, 'scores' => [], 'model_version' => '3.0.0-test',
            ]),
        ]);
    }

    /**
     * A speech engine that is genuinely down - nothing can transcribe anything.
     *
     * The only helper that produces one. fakeEngine() cannot be talked into it:
     * it used to take $stt, and reporting whisper_loaded = false on /api/health
     * was **not** an outage - its own /api/transcribe stub still answered 200
     * with a full transcript, and SpeechToTextService::transcribe() posts
     * straight to that endpoint without ever consulting health, so a test built
     * on it scored the reading happily and proved nothing. Layering a second
     * Http::fake() on top could not rescue it either: fake() appends stubs and
     * the first match wins, so the 200 stayed. That parameter is now gone.
     *
     * So this helper builds the outage from a clean factory instead: health
     * down, transcribe dead, classifier dead, and the cloud Whisper key cleared
     * so the fallback in SpeechToTextService cannot answer and stt_ok cannot be
     * rescued by it. After it, the lane stands down (RescoreQueue::run()
     * reports `skipped` and scores nothing) and any scoring attempt that gets
     * through anyway throws SpeechServiceUnavailable.
     *
     * BatchEntryPanelTest::test_a_dead_speech_engine_really_cannot_transcribe
     * pins all of that, so this helper cannot quietly become another engine
     * that still answers.
     */
    protected function deadSpeechEngine(): void
    {
        config(['services.openai.api_key' => null, 'services.whisper.use_local' => true]);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/api/health' => Http::response([
                'whisper_loaded' => false, 'classifier_loaded' => false,
                'model_stale' => false, 'model_version' => '3.0.0-test',
            ]),
            '*/api/transcribe' => Http::response('speech engine not loaded', 503),
            '*/api/classify' => Http::response('no model', 503),
        ]);

        // The status is cached for a few seconds; drop it so the next probe is
        // this engine and not the last one.
        \Illuminate\Support\Facades\Cache::forget('ml.service.status');
    }

    /** A teacher with one class and learners in it. */
    protected function teacher(): User
    {
        return $this->teacherWithClass('Batch A');
    }

    /** Another teacher, with a class and learners of their own. */
    protected function otherTeacher(): User
    {
        return $this->teacherWithClass('Batch B');
    }

    private function teacherWithClass(string $section): User
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true, 'email_verified_at' => now()]);
        $school = School::firstOrCreate(['name' => 'Batch School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => $section, 'school_year' => '2026-2027',
        ]);
        Learner::factory()->count(3)->create(['class_id' => $class->id, 'grade_level' => 4]);

        return $teacher;
    }
}
