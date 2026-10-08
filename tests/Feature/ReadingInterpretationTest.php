<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AnalysisAdvisorService;
use App\Services\ReadingAnalyzerService;
use App\Services\ReadingInterpretationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReadingInterpretationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSAGE = 'The brown dog ran across the wide green field and barked at the little bird. '
        . 'Then the dog sat under a tall tree and waited for his friend to come home.';

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    /** Run a spoken transcript through the real analyzer and store it as a result. */
    private function assess(string $spoken, array $transcriptionExtra = [], array $analysisExtra = []): Assessment
    {
        $school = School::firstOrCreate(['name' => 'Interp School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::firstOrCreate(
            ['school_id' => $school->id, 'grade_level' => 4, 'section' => 'Interp', 'school_year' => '2025-2026'],
            ['teacher_id' => $this->teacher->id]
        );
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);

        $material = ReadingMaterial::create([
            'title' => 'Interp Passage', 'content' => self::PASSAGE, 'language' => 'en',
            'grade_level' => 4, 'difficulty' => 'easy', 'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 30,
        ]);

        $assessment = Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        $transcription = array_merge(['text' => $spoken, 'words' => [], 'duration' => 30], $transcriptionExtra);
        $analysis = app(ReadingAnalyzerService::class)->analyze($transcription, self::PASSAGE, 30);

        $assessment->createResult(array_merge($analysis, [
            'provenance' => ['stt_engine' => 'local_whisper', 'classifier' => 'ml', 'mean_word_confidence' => 0.9],
        ], $analysisExtra));

        return $assessment->fresh(['result', 'verdict', 'comprehensionAnswers']);
    }

    private function reasonTitled(array $interpretation, string $title): ?array
    {
        return collect($interpretation['reasons'])->firstWhere('title', $title);
    }

    public function test_independent_reading_explains_the_margin(): void
    {
        $interpretation = app(ReadingInterpretationService::class)->for($this->assess(self::PASSAGE));

        $this->assertSame('independent', $interpretation['level']);
        $this->assertStringContainsString('31 of 31 words', $this->reasonTitled($interpretation, 'Word accuracy')['text']);
        $this->assertStringContainsString('97%', $interpretation['rule']);
        $this->assertNotNull($this->reasonTitled($interpretation, 'Margin'));
    }

    public function test_instructional_reading_says_how_close_each_boundary_is(): void
    {
        // 2 miscues in 31 words = 93.5%
        $spoken = str_replace(['ran', 'tall'], ['run', 'small'], self::PASSAGE);
        $interpretation = app(ReadingInterpretationService::class)->for($this->assess($spoken));

        $this->assertSame('instructional', $interpretation['level']);

        $distance = $this->reasonTitled($interpretation, 'Distance to the next level')['text'];
        $this->assertStringContainsString('would have reached Independent', $distance);
        $this->assertStringContainsString('would have dropped it to Frustration', $distance);
    }

    public function test_frustration_reading_names_the_miscues_that_caused_it(): void
    {
        // skip five words, mispronounce one
        $spoken = 'The dog run across the field and barked at the bird. Then the dog sat under a tree and waited for his friend to come home.';
        $interpretation = app(ReadingInterpretationService::class)->for($this->assess($spoken));

        $this->assertSame('frustration', $interpretation['level']);
        $this->assertStringContainsString('below the 90%', $this->reasonTitled($interpretation, 'Word accuracy')['text']);

        $what = $this->reasonTitled($interpretation, 'What went wrong')['text'];
        $this->assertStringContainsString('skipped', $what);
        $this->assertStringContainsString('"ran" read as "run"', $what);

        $this->assertStringContainsString(
            'more words read correctly would have reached Instructional',
            $this->reasonTitled($interpretation, 'Distance to the next level')['text']
        );
    }

    public function test_self_corrections_are_credited_not_penalised(): void
    {
        $spoken = str_replace('field', 'feed field', self::PASSAGE);
        $interpretation = app(ReadingInterpretationService::class)->for($this->assess($spoken));

        $this->assertSame('independent', $interpretation['level']);
        $this->assertStringContainsString('caught and fixed 1 of their own mistake', $this->reasonTitled($interpretation, 'Fluency')['text']);
    }

    public function test_teacher_override_is_explained_against_the_numbers(): void
    {
        $assessment = $this->assess(self::PASSAGE);

        $assessment->verdict()->create([
            'decision' => 'overridden', 'final_reading_level' => 'instructional',
            'reason' => 'Read accurately but word by word, without understanding.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $interpretation = app(ReadingInterpretationService::class)->for($assessment->fresh(['result', 'verdict']));

        $this->assertSame('instructional', $interpretation['level']);
        $this->assertStringContainsString(
            'On the numbers alone that is Independent; the teacher set it to Instructional',
            $this->reasonTitled($interpretation, 'Word accuracy')['text']
        );
    }

    public function test_a_noisy_recording_casts_doubt_on_the_level(): void
    {
        $assessment = $this->assess('The dog', [
            'audio_quality' => ['measured' => true, 'rating' => 'noisy', 'snr_db' => 6.5, 'total_seconds' => 30,
                'speech_seconds' => 9, 'non_speech_seconds' => 21, 'noise_floor_db' => -30, 'clipping_ratio' => 0,
                'leading_silence' => 1, 'background_events' => [['start' => 4.2, 'end' => 5.0, 'peak_db' => -18]]],
        ]);

        $interpretation = app(ReadingInterpretationService::class)->for($assessment);
        $doubt = $this->reasonTitled($interpretation, 'Before you trust this level');

        $this->assertNotNull($doubt);
        $this->assertStringContainsString('noisy', $doubt['text']);
        $this->assertStringContainsString('may describe the recording, not the reader', $doubt['text']);

        $titles = array_column(app(AnalysisAdvisorService::class)->for($assessment)['findings'], 'title');
        $this->assertContains('The recording is noisy', $titles);
        $this->assertContains('1 background sound between words', $titles);
    }

    public function test_a_recording_with_no_speech_is_a_critical_finding(): void
    {
        $assessment = $this->assess('I', [
            'audio_quality' => ['measured' => true, 'rating' => 'no_speech', 'snr_db' => null, 'total_seconds' => 14.2,
                'speech_seconds' => 0, 'non_speech_seconds' => 14.2, 'noise_floor_db' => -41.6, 'clipping_ratio' => 0,
                'leading_silence' => null, 'background_events' => []],
            'segments' => [['text' => 'I', 'start' => 0, 'end' => 2, 'no_speech_prob' => 0.52]],
        ]);

        $findings = app(AnalysisAdvisorService::class)->for($assessment)['findings'];

        $this->assertSame('critical', $findings[0]['level']);
        $this->assertSame('No speech was detected in this recording', $findings[0]['title']);
        $this->assertContains('Part of the transcript may be invented', array_column($findings, 'title'));
    }

    public function test_no_speech_is_never_explained_as_the_childs_reading(): void
    {
        $assessment = $this->assess('I', [
            'audio_quality' => ['measured' => true, 'rating' => 'no_speech', 'snr_db' => null, 'total_seconds' => 14.2,
                'speech_seconds' => 0, 'non_speech_seconds' => 14.2, 'noise_floor_db' => -41.6, 'clipping_ratio' => 0,
                'leading_silence' => null, 'background_events' => []],
        ]);

        $interpretation = app(ReadingInterpretationService::class)->for($assessment);
        $titles = array_column($interpretation['reasons'], 'title');

        $this->assertSame('This is not a measurement of the learner', $titles[0]);
        $this->assertStringContainsString('does not describe the learner', $interpretation['meaning']);

        // No pedagogical story may be told about an empty recording.
        $this->assertNotContains('What went wrong', $titles);
        $this->assertNotContains('Distance to the next level', $titles);
        $this->assertNotContains('Fluency', $titles);
    }

    public function test_a_reading_that_stopped_early_shows_how_the_attempted_part_went(): void
    {
        // Reads the first sentence perfectly (15 words), then stops.
        $assessment = $this->assess('The brown dog ran across the wide green field and barked at the little bird.');

        $interpretation = app(ReadingInterpretationService::class)->for($assessment);
        $stopped = $this->reasonTitled($interpretation, 'Stopped before the end');

        $this->assertSame('frustration', $interpretation['level']);
        $this->assertNotNull($stopped);
        $this->assertStringContainsString('after "bird"', $stopped['text']);
        $this->assertStringContainsString('the last 16 of 31 words were never attempted', $stopped['text']);
        $this->assertStringContainsString('15 were correct (100%), which on its own would be Independent', $stopped['text']);

        $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('Stopped before the end')
            ->assertSee('reading stopped here')
            ->assertSee('Not reached <b>16</b>', false);
    }

    public function test_results_page_shows_the_interpretation_and_audio_capture(): void
    {
        $spoken = 'teacher can I start now ' . str_replace(['ran', 'field'], ['run', 'feed field'], self::PASSAGE);

        $assessment = $this->assess($spoken, [
            'audio_quality' => ['measured' => true, 'rating' => 'clean', 'snr_db' => 24.1, 'total_seconds' => 30,
                'speech_seconds' => 24, 'non_speech_seconds' => 6, 'noise_floor_db' => -52, 'clipping_ratio' => 0,
                'leading_silence' => 0.8, 'background_events' => []],
        ]);

        $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('Why this level?')
            ->assertSee('Instructional')
            ->assertSee('What the microphone picked up')
            ->assertSee('Clear recording')
            ->assertSee('teacher can i start now')
            ->assertSee('Mispronounced <b>1</b>', false)
            ->assertSee('Self-corrected <b>1</b>', false)
            ->assertSee('Not reading <b>5</b>', false);
    }

    public function test_results_analysed_before_this_feature_still_render(): void
    {
        $assessment = $this->assess(self::PASSAGE);

        // Strip everything this feature added, as an older stored result would look.
        $legacy = collect($assessment->result->ml_analysis_json)
            ->except(['miscues', 'non_reading', 'audio_quality'])
            ->all();
        $legacy['word_comparison'] = array_map(
            fn ($w) => ['reference' => $w['reference'], 'spoken' => $w['spoken'], 'status' => $w['status']],
            $legacy['word_comparison']
        );
        $assessment->result->update(['ml_analysis_json' => $legacy]);

        $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('Why this level?')
            ->assertSee('Independent')
            ->assertSee('Reading Comparison');
    }
}
