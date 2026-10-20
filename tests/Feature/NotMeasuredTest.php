<?php

namespace Tests\Feature;

use App\Exceptions\SpeechServiceUnavailable;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A reading that was never measured must never look like one.
 *
 * With no speech engine the system used to score a canned transcript of the
 * "dog Max" passage: 100% accuracy, perfect pace, no errors, "independent".
 * Accepting that result then made a child an independent reader. There is no
 * placeholder any more, and a result that was not measured from the child's
 * voice shows nothing, counts for nothing and cannot be accepted.
 */
class NotMeasuredTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();
        Cache::forget('ml.service.status');

        $this->teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $school = School::firstOrCreate(['name' => 'NM School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $this->class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'NM', 'school_year' => '2026-2027',
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function learner(): Learner
    {
        return Learner::factory()->create(['class_id' => $this->class->id, 'grade_level' => 4]);
    }

    private function material(): ReadingMaterial
    {
        return ReadingMaterial::firstOrCreate(
            ['title' => 'NM Passage'],
            [
                'content' => 'I have a dog his name is Max Max is brown he has a long tail',
                'language' => 'en', 'grade_level' => 4, 'difficulty' => 'easy',
                'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 15,
            ]
        );
    }

    private function assessment(array $result, array $ml = [], bool $recording = false, ?Learner $learner = null): Assessment
    {
        $assessment = Assessment::create([
            'learner_id' => ($learner ?? $this->learner())->id,
            'material_id' => $this->material()->id,
            'assessor_id' => $this->teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        if ($recording) {
            Storage::disk('public')->put('recordings/nm.webm', 'audio');
            $assessment->update(['audio_file' => 'recordings/nm.webm']);
        }

        $assessment->createResult(array_merge([
            'accuracy_rate' => 80.0, 'words_per_minute' => 90.0, 'reading_level' => 'frustration',
            'total_words' => 15, 'duration_seconds' => 60,
        ], $result, $ml));

        return $assessment->fresh(['result', 'verdict', 'learner']);
    }

    /** The result the placeholder produced: a flawless reading of its own text. */
    private function placeholder(?Learner $learner = null, bool $recording = false): Assessment
    {
        return $this->assessment(
            ['accuracy_rate' => 100.0, 'words_per_minute' => 279.2, 'reading_level' => 'independent', 'error_count' => 0],
            ['provenance' => ['stt_engine' => 'mock', 'stt_is_mock' => true, 'classifier' => 'ml']],
            $recording, $learner
        );
    }

    private function real(string $level = 'instructional', float $accuracy = 92.0, ?Learner $learner = null): Assessment
    {
        return $this->assessment(
            ['accuracy_rate' => $accuracy, 'words_per_minute' => 100.0, 'reading_level' => $level],
            ['provenance' => ['stt_engine' => 'local_whisper', 'classifier' => 'ml']],
            false, $learner
        );
    }

    private function verdict(Assessment $a, array $attributes): void
    {
        $a->verdict()->create(array_merge([
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ], $attributes));
    }

    // ── No placeholder, in any environment ────────────────────────────

    public function test_there_is_no_placeholder_transcript_even_in_development(): void
    {
        // This is the default on a developer's machine, and the panel's.
        app()['env'] = 'local';
        config([
            'services.whisper.use_local' => false,
            'services.whisper.allow_mock' => true,
            'services.openai.api_key' => '',
        ]);

        $this->assertFalse(method_exists(SpeechToTextService::class, 'mockTranscribe'));

        $this->expectException(SpeechServiceUnavailable::class);
        app(SpeechToTextService::class)->transcribe(__FILE__, 'en');
    }

    public function test_the_allow_mock_setting_no_longer_exists(): void
    {
        $this->assertArrayNotHasKey('allow_mock', config('services.whisper'));
    }

    public function test_with_the_engine_down_where_the_placeholder_used_to_be_allowed_nothing_is_scored(): void
    {
        // The testing environment allowed the placeholder by default, like local.
        config(['services.whisper.use_local' => true, 'services.openai.api_key' => '']);
        Http::fake(['*/api/transcribe' => Http::response('down', 503)]);

        $assessment = Assessment::create([
            'learner_id' => $this->learner()->id, 'material_id' => $this->material()->id,
            'assessor_id' => $this->teacher->id, 'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING, 'status' => Assessment::STATUS_PENDING,
        ]);

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertStatus(503)->assertJsonPath('success', false);

        $this->assertDatabaseCount('assessment_results', 0);
        $this->assertNull($assessment->fresh()->learner->reading_level);
    }

    // ── What counts as measured ───────────────────────────────────────

    public function test_a_placeholder_result_is_not_measured(): void
    {
        $this->assertSame('mock', $this->placeholder()->notMeasuredReason());
    }

    public function test_a_recording_with_no_speech_is_not_measured(): void
    {
        $a = $this->assessment(
            ['accuracy_rate' => 0.0, 'reading_level' => 'frustration'],
            ['provenance' => ['stt_engine' => 'local_whisper'], 'audio_quality' => ['rating' => 'no_speech']]
        );

        $this->assertSame('no_speech', $a->notMeasuredReason());
    }

    public function test_a_real_reading_is_measured(): void
    {
        $this->assertNull($this->real()->notMeasuredReason());
    }

    public function test_the_teachers_own_count_is_a_measurement(): void
    {
        $a = $this->placeholder();
        $this->verdict($a, [
            'decision' => 'overridden', 'manual_scoring' => true, 'words_read' => 15,
            'manual_substitutions' => 2, 'final_accuracy_rate' => 86.7, 'final_words_per_minute' => 80,
            'final_reading_level' => 'instructional', 'reason' => 'Counted by hand while listening.',
        ]);

        $this->assertNull($a->fresh(['result', 'verdict'])->notMeasuredReason());
    }

    public function test_an_unmeasured_result_reports_no_numbers(): void
    {
        $a = $this->placeholder();

        $this->assertNull($a->effectiveAccuracy());
        $this->assertNull($a->effectiveWordsPerMinute());
        $this->assertNull($a->effectiveReadingLevel());
        $this->assertSame('not_measured', $a->weaknessOutcome()['reason']);
    }

    public function test_accepting_does_not_make_a_placeholder_result_count(): void
    {
        $a = $this->placeholder();
        // How it happened: the result was accepted as-is.
        $this->verdict($a, ['decision' => 'accepted']);

        $a = $a->fresh(['result', 'verdict']);

        $this->assertNull($a->effectiveReadingLevel());
        $this->assertNull($a->effectiveAccuracy());
    }

    // ── The child's record ────────────────────────────────────────────

    public function test_an_accepted_placeholder_does_not_make_a_child_an_independent_reader(): void
    {
        $learner = $this->learner();
        $a = $this->placeholder($learner);
        $this->verdict($a, ['decision' => 'accepted']);

        $learner->refresh()->updateReadingLevel();

        $this->assertNull($learner->fresh()->reading_level);
    }

    public function test_a_stale_level_from_a_placeholder_is_cleared(): void
    {
        $learner = $this->learner();
        $learner->update(['reading_level' => 'independent']);
        $this->placeholder($learner);

        $learner->refresh()->updateReadingLevel();

        $this->assertNull($learner->fresh()->reading_level);
    }

    public function test_the_level_falls_back_to_the_last_real_reading(): void
    {
        $learner = $this->learner();
        $this->real('instructional', 92.0, $learner);
        $this->placeholder($learner);   // newer, but not a measurement

        $learner->refresh()->updateReadingLevel();

        $this->assertSame('instructional', $learner->fresh()->reading_level);
    }

    // ── Accept is refused ─────────────────────────────────────────────

    public function test_a_placeholder_result_cannot_be_accepted(): void
    {
        $a = $this->placeholder();

        $this->actingAs($this->teacher)
            ->post(route('assessments.verdict', $a), ['decision' => 'accepted'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('assessment_verdicts', 0);
    }

    public function test_the_level_cannot_be_overridden_without_counting_the_reading_yourself(): void
    {
        $a = $this->placeholder();

        $this->actingAs($this->teacher)
            ->post(route('assessments.verdict', $a), [
                'decision' => 'overridden', 'final_reading_level' => 'independent',
                'reason' => 'Looks fine to me.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('assessment_verdicts', 0);
        $this->assertNull($a->learner->fresh()->reading_level);
    }

    public function test_it_can_be_invalidated(): void
    {
        $a = $this->placeholder();

        $this->actingAs($this->teacher)
            ->post(route('assessments.verdict', $a), [
                'decision' => 'invalidated', 'reason' => 'The speech engine was not running.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assessment_verdicts', ['assessment_id' => $a->id, 'decision' => 'invalidated']);
    }

    public function test_it_can_be_re_scored_by_the_teacher(): void
    {
        $a = $this->placeholder();

        $this->actingAs($this->teacher)
            ->post(route('assessments.verdict', $a), [
                'decision' => 'overridden', 'final_reading_level' => 'instructional',
                'reason' => 'Counted by hand while the child read.',
                'manual_scoring' => 1, 'words_read' => 15, 'manual_substitutions' => 1, 'manual_omissions' => 0,
                'manual_insertions' => 0, 'manual_self_corrections' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($a->fresh(['result', 'verdict'])->notMeasuredReason());
        $this->assertSame('instructional', $a->learner->fresh()->reading_level);
    }

    public function test_a_real_result_can_still_be_accepted(): void
    {
        $a = $this->real('instructional', 92.0);

        $this->actingAs($this->teacher)
            ->post(route('assessments.verdict', $a), ['decision' => 'accepted'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('instructional', $a->learner->fresh()->reading_level);
    }

    // ── The results page shows nothing, honestly ──────────────────────

    private function page(Assessment $a): string
    {
        return $this->actingAs($this->teacher)->get(route('assessments.results', $a))->assertOk()->getContent();
    }

    public function test_the_page_says_nothing_was_measured_and_shows_no_numbers(): void
    {
        $html = $this->page($this->placeholder());

        $this->assertStringContainsString('Nothing was measured', $html);
        $this->assertStringContainsString('placeholder transcript', $html);
        $this->assertStringContainsString('not counted', $html);

        // None of what the placeholder produced.
        $this->assertStringNotContainsString('100.0%', $html);
        $this->assertStringNotContainsString('279', $html);
        $this->assertStringNotContainsString('Words / Minute', $html);

        // No "Independent" result badge, and the override form does not
        // pre-select it (it is still one of the three levels a teacher may pick).
        $this->assertStringNotContainsString('badge bg-success fs-6 px-3 py-2">Independent', $html);
        $this->assertDoesNotMatchRegularExpression('/value="independent"\s+selected/', $html);
    }

    public function test_the_page_offers_no_accept_button(): void
    {
        $html = $this->page($this->placeholder());

        $this->assertStringNotContainsString('Accept AI Result', $html);
        $this->assertStringNotContainsString('Accept the AI result as-is', $html);
    }

    public function test_with_a_saved_recording_the_teacher_can_analyze_it_again(): void
    {
        $a = $this->placeholder(recording: true);

        $html = $this->page($a);

        $this->assertStringContainsString(route('assessments.reanalyze', $a), $html);
    }

    public function test_without_a_recording_the_teacher_is_pointed_to_a_new_assessment(): void
    {
        $a = $this->placeholder(recording: false);

        $html = $this->page($a);

        $this->assertStringContainsString('Re-assess', $html);
        $this->assertStringContainsString(route('assessments.start', $a->learner), $html);
    }

    public function test_a_real_result_still_shows_its_numbers(): void
    {
        $html = $this->page($this->real('instructional', 92.0));

        $this->assertStringContainsString('92.0%', $html);
        $this->assertStringNotContainsString('Nothing was measured', $html);
    }

    // ── Everywhere else the numbers would have appeared ───────────────

    public function test_the_history_list_flags_it_and_shows_no_numbers(): void
    {
        $a = $this->placeholder();

        $this->actingAs($this->teacher)
            ->get(route('assessments.learner-history', $a->learner))
            ->assertOk()
            ->assertSee('Not measured')
            ->assertDontSee('279')
            ->assertDontSee('100.0');
    }

    public function test_the_learners_averages_leave_it_out(): void
    {
        $learner = $this->learner();
        $this->real('instructional', 90.0, $learner);
        $this->placeholder($learner);

        // 100% from the placeholder would drag the average to 95.
        $this->assertSame(90.0, $learner->fresh()->getStats()['average_accuracy']);
        $this->assertCount(1, $learner->getProgressData());
        $this->assertCount(1, $learner->getAssessmentResults());
    }

    public function test_a_teachers_dashboard_averages_leave_it_out(): void
    {
        $learner = $this->learner();
        $this->real('instructional', 90.0, $learner);
        $this->placeholder($learner);

        $this->actingAs($this->teacher)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('90.0%')
            ->assertDontSee('95.0%');
    }

    public function test_the_measured_scope_keeps_real_readings_and_the_teachers_own_count(): void
    {
        $real = $this->real();
        $this->placeholder();
        $scored = $this->placeholder();
        $this->verdict($scored, [
            'decision' => 'overridden', 'manual_scoring' => true, 'words_read' => 15,
            'final_accuracy_rate' => 80.0, 'final_words_per_minute' => 70, 'final_reading_level' => 'frustration',
            'reason' => 'Counted by hand.',
        ]);

        $ids = AssessmentResult::measured()->pluck('assessment_id')->all();

        $this->assertEqualsCanonicalizing([$real->id, $scored->id], $ids);
    }

    public function test_a_parent_is_told_it_could_not_be_scored_not_shown_a_perfect_score(): void
    {
        $a = $this->placeholder();
        $parent = User::factory()->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);
        $a->learner->users()->attach($parent->id, ['relationship' => 'parent']);

        $this->actingAs($parent)
            ->get(route('parent.children.assessment-detail', [$a->learner, $a]))
            ->assertOk()
            ->assertSee("couldn't be scored", false)
            ->assertDontSee('100 out of 100')
            ->assertDontSee('279');
    }

    public function test_a_parents_profile_does_not_use_it_as_the_latest_reading(): void
    {
        $learner = $this->learner();
        $this->real('instructional', 90.0, $learner);
        $this->placeholder($learner);
        $parent = User::factory()->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);
        $learner->users()->attach($parent->id, ['relationship' => 'parent']);

        $this->actingAs($parent)
            ->get(route('parent.children.profile', $learner))
            ->assertOk()
            ->assertDontSee('100 out of 100');
    }

    // ── Told before the child reads ───────────────────────────────────

    private function health(array $body): void
    {
        Http::fake(['*/api/health' => Http::response($body, 200)]);
    }

    public function test_the_start_page_warns_when_the_speech_engine_is_not_loaded(): void
    {
        config(['services.openai.api_key' => '']);
        $this->health([
            'classifier_loaded' => true, 'whisper_loaded' => false,
            'model_stale' => false, 'model_version' => '3.0.0-test',
        ]);

        $this->actingAs($this->teacher)
            ->get(route('assessments.start', $this->learner()))
            ->assertOk()
            ->assertSee('speech engine is not loaded')
            ->assertSee('can still record');
    }

    public function test_the_start_page_says_nothing_can_be_scored_when_the_service_is_down(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->teacher)
            ->get(route('assessments.start', $this->learner()))
            ->assertOk()
            ->assertSee('cannot be scored');
    }

    public function test_a_cloud_speech_key_means_recordings_can_still_be_scored(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        $this->health(['classifier_loaded' => true, 'whisper_loaded' => false, 'model_stale' => false, 'model_version' => 'x']);

        $this->actingAs($this->teacher)
            ->get(route('assessments.start', $this->learner()))
            ->assertOk()
            ->assertDontSee('speech engine is not loaded');
    }
}
