<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MLClassificationService;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every page that shows or uses a weakness says the same honest thing when the
 * model could not classify it: here are the measurements, there is no label,
 * and this is what you can do about it. Nothing is guessed in its place.
 */
class ClassificationPagesTest extends TestCase
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

        $school = School::firstOrCreate(['name' => 'Pages School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $this->class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'Pg', 'school_year' => '2026-2027',
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
            ['title' => 'Pages Passage'],
            [
                'content' => 'The brown dog ran fast across the wide green field to find his bone.',
                'language' => 'en', 'grade_level' => 4, 'difficulty' => 'easy',
                'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 14,
            ]
        );
    }

    /** A saved result with the given status; `$recording` keeps a real file on disk. */
    private function assessment(array $result, array $provenance = [], bool $recording = false, ?Learner $learner = null): Assessment
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
            Storage::disk('public')->put('recordings/pages.webm', 'audio');
            $assessment->update(['audio_file' => 'recordings/pages.webm']);
        }

        $assessment->createResult(array_merge([
            'accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'reading_level' => 'frustration',
            'total_words' => 14, 'duration_seconds' => 60, 'provenance' => $provenance,
        ], $result));

        return $assessment->fresh(['result', 'verdict', 'comprehensionAnswers', 'learner']);
    }

    private function unavailable(bool $recording = true, ?Learner $learner = null): Assessment
    {
        return $this->assessment(
            ['primary_weakness' => null, 'classification_status' => 'unavailable', 'confidence_score' => null],
            ['stt_engine' => 'local_whisper', 'classifier' => 'unavailable'],
            $recording, $learner
        );
    }

    private function results(Assessment $assessment): string
    {
        return $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))->assertOk()->getContent();
    }

    // ── The results page ──────────────────────────────────────────────

    public function test_an_unavailable_result_says_so_plainly_and_keeps_the_measurements(): void
    {
        $html = $this->results($this->unavailable());

        $this->assertStringContainsString('Weakness classification unavailable', $html);
        $this->assertStringContainsString('model was not running', $html);
        // The reading was still measured.
        $this->assertStringContainsString('Word Accuracy', $html);
        $this->assertStringContainsString('62.0%', $html);
    }

    public function test_an_unavailable_result_names_no_weakness_and_recommends_nothing(): void
    {
        $html = $this->results($this->unavailable());

        $this->assertStringNotContainsString('Primary:', $html);
        $this->assertStringNotContainsString('Recommended Interventions', $html);
        // The card's "no weakness" badge (the help text also quotes the phrase).
        $this->assertStringNotContainsString('bi-check-circle me-1"></i> No significant weakness detected', $html);
    }

    public function test_with_a_recording_the_teacher_can_re_analyze_instead_of_re_reading(): void
    {
        $assessment = $this->unavailable(recording: true);

        $html = $this->results($assessment);

        $this->assertStringContainsString(route('assessments.reanalyze', $assessment), $html);
        $this->assertStringContainsString('Re-analyze', $html);
    }

    public function test_without_a_recording_the_teacher_is_pointed_to_a_new_assessment(): void
    {
        $assessment = $this->unavailable(recording: false);

        $html = $this->results($assessment);

        $this->assertStringNotContainsString(route('assessments.reanalyze', $assessment), $html);
        $this->assertStringContainsString('Re-assess', $html);
        $this->assertStringContainsString(route('assessments.start', $assessment->learner), $html);
    }

    public function test_a_model_suggestion_the_measurements_do_not_back_is_explained(): void
    {
        $assessment = $this->assessment(
            ['primary_weakness' => null, 'classification_status' => 'model_unsupported', 'confidence_score' => null],
            ['stt_engine' => 'local_whisper', 'classifier' => 'ml', 'model_version' => '3.0.0-test']
        );

        $html = $this->results($assessment);

        $this->assertStringContainsString('Weakness classification unavailable', $html);
        $this->assertStringContainsString('not supported by the measurements', $html);
    }

    public function test_a_weakness_the_teacher_sets_is_shown_as_theirs(): void
    {
        $assessment = $this->unavailable();
        $assessment->verdict()->create([
            'decision' => 'overridden', 'final_primary_weakness' => 2,
            'reason' => 'Guesses at unfamiliar words; decoding is the problem.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $html = $this->results($assessment->fresh());

        $this->assertStringContainsString('Primary: Decoding Accuracy', $html);
        $this->assertStringContainsString('Set by you', $html);
        $this->assertStringNotContainsString('Weakness classification unavailable', $html);
    }

    public function test_a_classified_result_shows_its_weakness_without_any_warning(): void
    {
        $assessment = $this->assessment(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['stt_engine' => 'local_whisper', 'classifier' => 'ml', 'model_version' => '3.0.0-test']
        );

        $html = $this->results($assessment);

        $this->assertStringContainsString('Primary: Decoding Accuracy', $html);
        $this->assertStringNotContainsString('Weakness classification unavailable', $html);
        $this->assertStringNotContainsString('Source not recorded', $html);
    }

    public function test_the_override_form_does_not_default_an_unclassified_result_to_no_weakness(): void
    {
        $html = $this->results($this->unavailable());

        // "No weakness" is a conclusion. Not having one is not.
        $this->assertStringContainsString('Leave unclassified', $html);
    }

    // ── Re-analyze: reuse the saved recording ─────────────────────────

    private function stubReanalysis(array $classification): void
    {
        $this->mock(SpeechToTextService::class, function ($mock) {
            $mock->shouldReceive('transcribe')->andReturn([
                'text' => 'The big dog ran slowly across the huge green field to find his bone',
                'words' => [], 'duration' => 60, 'engine' => 'local_whisper',
            ]);
        });

        $this->mock(MLClassificationService::class, function ($mock) use ($classification) {
            $mock->shouldReceive('classify')->andReturn($classification);
        });
    }

    public function test_re_analyzing_replaces_the_result_from_the_saved_recording(): void
    {
        $assessment = $this->unavailable(recording: true);
        $this->stubReanalysis([
            'available' => true, 'primary' => '2', 'secondary' => null, 'confidence' => 0.7,
            'all_scores' => [0.05, 0.1, 0.6, 0.2, 0.05], 'model_version' => '3.0.0-test',
        ]);

        $this->actingAs($this->teacher)
            ->post(route('assessments.reanalyze', $assessment))
            ->assertRedirect(route('assessments.results', $assessment))
            ->assertSessionHas('success');

        $result = $assessment->fresh()->result;
        $this->assertSame('classified', $result->classification_status);
        $this->assertSame(2, $result->primary_weakness);
    }

    public function test_re_analyzing_while_the_model_is_still_down_says_so_and_stays_unclassified(): void
    {
        $assessment = $this->unavailable(recording: true);
        $this->stubReanalysis([
            'available' => false, 'primary' => null, 'secondary' => null, 'confidence' => null,
            'all_scores' => [], 'model_version' => null, 'reason' => 'model_not_loaded',
        ]);

        $response = $this->actingAs($this->teacher)->post(route('assessments.reanalyze', $assessment));

        $response->assertRedirect(route('assessments.results', $assessment));
        $this->assertSame('unavailable', $assessment->fresh()->result->classification_status);
        $this->assertNull($assessment->fresh()->result->primary_weakness);
    }

    public function test_re_analyzing_needs_a_recording(): void
    {
        $assessment = $this->unavailable(recording: false);

        $this->actingAs($this->teacher)
            ->post(route('assessments.reanalyze', $assessment))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('unavailable', $assessment->fresh()->result->classification_status);
    }

    public function test_a_teacher_cannot_re_analyze_another_classs_assessment(): void
    {
        $other = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);
        SchoolClass::create([
            'school_id' => $this->class->school_id, 'teacher_id' => $other->id,
            'grade_level' => 5, 'section' => 'Other', 'school_year' => '2026-2027',
        ]);
        $assessment = $this->unavailable(recording: true);

        $this->actingAs($other)->post(route('assessments.reanalyze', $assessment))->assertForbidden();
    }

    // ── Pre-flight: told before the child reads, not after ────────────

    private function health(array $body, int $status = 200): void
    {
        Http::fake(['*/api/health' => Http::response($body, $status)]);
    }

    public function test_the_start_page_warns_when_the_analysis_service_is_not_running(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->teacher)
            ->get(route('assessments.start', $this->learner()))
            ->assertOk()
            ->assertSee('analysis service is not running')
            // The session is not blocked: recording still works.
            ->assertSee('You can still record');
    }

    public function test_the_new_assessment_page_warns_when_no_model_is_loaded(): void
    {
        $this->health(['classifier_loaded' => false, 'whisper_loaded' => true, 'model_file_present' => false, 'model_stale' => false]);

        $this->actingAs($this->teacher)
            ->get(route('assessments.create'))
            ->assertOk()
            ->assertSee('no model is loaded');
    }

    public function test_a_model_whose_file_is_missing_is_flagged_before_it_stops_working(): void
    {
        $this->health(['classifier_loaded' => true, 'whisper_loaded' => true, 'model_file_present' => false, 'model_stale' => true, 'model_version' => '3.0.0-test']);

        $this->actingAs($this->teacher)
            ->get(route('assessments.start', $this->learner()))
            ->assertOk()
            ->assertSee('its file is missing from the server');
    }

    public function test_a_healthy_service_raises_no_warning(): void
    {
        $this->health(['classifier_loaded' => true, 'whisper_loaded' => true, 'model_file_present' => true, 'model_stale' => false, 'model_version' => '3.0.0-test']);

        $this->actingAs($this->teacher)
            ->get(route('assessments.start', $this->learner()))
            ->assertOk()
            ->assertDontSee('analysis service is not running')
            ->assertDontSee('no model is loaded');
    }

    // ══ Group B: every other page that shows or uses the label ═════════

    private function parentOf(Learner $learner): User
    {
        $parent = User::factory()->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);
        $learner->users()->attach($parent->id, ['relationship' => 'parent']);

        return $parent;
    }

    private function legacy(int $weakness, array $provenance, ?Learner $learner = null): Assessment
    {
        return $this->assessment(['primary_weakness' => $weakness], $provenance, false, $learner);
    }

    // ── Parents: plain words, no technical jargon ─────────────────────

    public function test_a_parent_is_told_plainly_that_the_focus_area_is_not_yet_checked(): void
    {
        $assessment = $this->unavailable(recording: false);
        $parent = $this->parentOf($assessment->learner);

        $this->actingAs($parent)
            ->get(route('parent.children.assessment-detail', [$assessment->learner, $assessment]))
            ->assertOk()
            ->assertSee('not yet checked')
            ->assertSee('teacher will review')
            // A parent is not asked to understand the machinery.
            ->assertDontSee('model was not running')
            ->assertDontSee('analysis service');
    }

    public function test_a_parent_profile_does_not_name_a_focus_area_that_was_never_classified(): void
    {
        $assessment = $this->unavailable(recording: false);
        $parent = $this->parentOf($assessment->learner);

        $this->actingAs($parent)
            ->get(route('parent.children.profile', $assessment->learner))
            ->assertOk()
            ->assertDontSee('can grow most in')
            ->assertSee('not yet checked');
    }

    public function test_a_parent_still_sees_the_focus_area_for_a_classified_result(): void
    {
        $assessment = $this->assessment(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['stt_engine' => 'local_whisper', 'classifier' => 'ml', 'model_version' => '3.0.0-test']
        );
        $parent = $this->parentOf($assessment->learner);

        $this->actingAs($parent)
            ->get(route('parent.children.assessment-detail', [$assessment->learner, $assessment]))
            ->assertOk()
            ->assertSee('decoding accuracy', false)
            ->assertDontSee('not yet checked');
    }

    public function test_a_parent_still_sees_a_legacy_label_but_it_is_called_preliminary(): void
    {
        // Legacy keeps working. A parent is told it is early, not why.
        $assessment = $this->legacy(2, ['classifier' => 'rule_based']);
        $parent = $this->parentOf($assessment->learner);

        $this->actingAs($parent)
            ->get(route('parent.children.assessment-detail', [$assessment->learner, $assessment]))
            ->assertOk()
            ->assertSee('decoding accuracy', false)
            ->assertSee('preliminary');
    }

    public function test_a_parent_does_not_get_home_tips_aimed_at_a_weakness_that_was_never_classified(): void
    {
        $assessment = $this->unavailable(recording: false);
        $parent = $this->parentOf($assessment->learner);

        $this->actingAs($parent)
            ->get(route('parent.children.assessment-detail', [$assessment->learner, $assessment]))
            ->assertOk()
            ->assertSee('Keep the reading habit going');
    }

    // ── Reports (teacher) ─────────────────────────────────────────────

    public function test_the_learner_report_marks_an_unclassified_reading_instead_of_a_dash(): void
    {
        $assessment = $this->unavailable(recording: false);

        $this->actingAs($this->teacher)
            ->get(route('reports.learner', $assessment->learner))
            ->assertOk()
            ->assertSee('Not classified');
    }

    public function test_the_learner_report_still_names_a_classified_and_a_legacy_weakness(): void
    {
        $learner = $this->learner();
        $this->assessment(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['classifier' => 'ml'], false, $learner
        );
        $this->legacy(3, ['classifier' => 'rule_based'], $learner);

        $this->actingAs($this->teacher)
            ->get(route('reports.learner', $learner))
            ->assertOk()
            ->assertSee('Decoding Accuracy')
            ->assertSee('Oral Reading Fluency');
    }

    public function test_the_printed_report_shows_the_same_honest_wording(): void
    {
        $assessment = $this->unavailable(recording: false);

        $this->actingAs($this->teacher)
            ->get(route('reports.print', $assessment->learner))
            ->assertOk()
            ->assertSee('Not classified');
    }

    // ── History ───────────────────────────────────────────────────────

    public function test_the_history_list_flags_a_reading_that_needs_re_analysis(): void
    {
        $assessment = $this->unavailable(recording: true);

        $this->actingAs($this->teacher)
            ->get(route('assessments.learner-history', $assessment->learner))
            ->assertOk()
            ->assertSee('Needs re-analysis');
    }

    public function test_the_history_list_stays_quiet_for_a_classified_reading(): void
    {
        $assessment = $this->assessment(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['classifier' => 'ml']
        );

        $this->actingAs($this->teacher)
            ->get(route('assessments.learner-history', $assessment->learner))
            ->assertOk()
            ->assertDontSee('Needs re-analysis');
    }

    // ── Admin ─────────────────────────────────────────────────────────

    public function test_the_admin_dashboard_counts_results_awaiting_classification(): void
    {
        $this->unavailable(recording: false);
        $this->unavailable(recording: false);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('2 results awaiting classification');
    }

    public function test_the_admin_weakness_counts_leave_unclassified_results_out(): void
    {
        $this->unavailable(recording: false);
        $this->assessment(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['classifier' => 'ml']
        );
        $this->legacy(3, ['classifier' => 'rule_based']);

        $counts = \App\Models\AssessmentResult::weaknessCounts();

        // Classified and legacy count; the unclassified one is not a "none".
        $this->assertSame(1, $counts['counts'][2] ?? 0);
        $this->assertSame(1, $counts['counts'][3] ?? 0);
        $this->assertSame(1, $counts['awaiting']);
    }

    public function test_the_admin_weakness_counts_follow_a_teachers_verdict(): void
    {
        $assessment = $this->unavailable(recording: false);
        $assessment->verdict()->create([
            'decision' => 'overridden', 'final_primary_weakness' => 1,
            'reason' => 'Mixes up letter sounds throughout.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $counts = \App\Models\AssessmentResult::weaknessCounts();

        $this->assertSame(1, $counts['counts'][1] ?? 0);
        $this->assertSame(0, $counts['awaiting']);
    }

    public function test_an_invalidated_result_is_not_counted_at_all(): void
    {
        $assessment = $this->assessment(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['classifier' => 'ml']
        );
        $assessment->verdict()->create([
            'decision' => 'invalidated', 'reason' => 'Microphone unplugged.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $counts = \App\Models\AssessmentResult::weaknessCounts();

        $this->assertSame(0, $counts['counts'][2] ?? 0);
        $this->assertSame(0, $counts['awaiting']);
    }

    // ── Interventions follow the same outcome ─────────────────────────

    public function test_the_recommender_suggests_nothing_for_an_unclassified_result(): void
    {
        $assessment = $this->unavailable(recording: false);

        $recommendations = app(\App\Services\InterventionRecommenderService::class)
            ->getRecommendations($assessment->result, $assessment->learner, 'teacher');

        $this->assertSame([], $recommendations);
    }

    public function test_the_recommender_honours_the_teachers_own_weakness(): void
    {
        $assessment = $this->unavailable(recording: false);
        \App\Models\Intervention::create([
            'name' => 'Sight Word Drill', 'description' => 'Practise decoding.', 'target_weakness' => 2,
            'activity_type' => 'drill', 'instructions' => 'Go through the list.',
            'grade_level_min' => 1, 'grade_level_max' => 6,
        ]);
        $assessment->verdict()->create([
            'decision' => 'overridden', 'final_primary_weakness' => 2,
            'reason' => 'Decoding is clearly the issue.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $recommendations = app(\App\Services\InterventionRecommenderService::class)
            ->getRecommendations($assessment->fresh(['result', 'verdict'])->result, $assessment->learner, 'teacher');

        $this->assertNotEmpty($recommendations);
        $this->assertSame('Sight Word Drill', $recommendations[0]['intervention']['name']);
    }

    // ── Practice: a missing label is not "no weakness" ────────────────

    public function test_practice_reports_why_there_is_no_label(): void
    {
        $r = MLClassificationService::resolve(
            ['available' => false, 'primary' => null, 'confidence' => null, 'all_scores' => [], 'reason' => 'unreachable'],
            [1 => 'ok', 2 => 'concern', 3 => 'ok', 4 => 'unknown']
        );

        // Exactly what PracticeController hands the app, so it can tell a child's
        // app "not classified" apart from "no weakness" (0).
        $this->assertNull($r['primary_weakness']);
        $this->assertSame('unavailable', $r['classification_status']);
    }

    // ── The guard: nothing reads the raw label behind the outcome's back ─

    public function test_no_page_or_service_reads_the_raw_weakness_column(): void
    {
        $allowed = [
            // The model that owns the column, and the outcome built from it.
            'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'Assessment.php',
            'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'AssessmentResult.php',
        ];

        $offenders = [];

        foreach (['app', 'resources' . DIRECTORY_SEPARATOR . 'views'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! preg_match('/\.php$/', $file->getFilename())) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(base_path()) + 1);

                if (in_array($relative, $allowed, true)) {
                    continue;
                }

                if (preg_match('/->primary_weakness\b/', file_get_contents($file->getPathname()))) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These read ->primary_weakness directly. Use Assessment::weaknessOutcome() so an unclassified or\n"
            . "invalidated result, and a teacher's own verdict, are handled the same way everywhere:\n- "
            . implode("\n- ", $offenders)
        );
    }
}
