<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentVerdict;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AnalysisAdvisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionSupportTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function assessmentFor(User $teacher, array $analysis = []): Assessment
    {
        $school = School::firstOrCreate(
            ['name' => 'DSS Test School'],
            ['address' => 'Sagay City', 'division' => 'Sagay City']
        );

        $class = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'grade_level' => 4,
            'section' => 'DSS-' . $teacher->id,
            'school_year' => '2025-2026',
        ]);

        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);

        $material = ReadingMaterial::create([
            'title' => 'DSS Passage',
            'content' => 'The dog ran fast across the field today.',
            'language' => 'en',
            'grade_level' => 4,
            'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING,
            'word_count' => 8,
        ]);

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        $assessment->createResult(array_merge([
            'accuracy_rate' => 88.0,
            'words_per_minute' => 70,
            'reading_level' => 'instructional',
            'error_count' => 2,
            'substitutions' => 1,
            'omissions' => 1,
            'insertions' => 0,
            'self_corrections' => 0,
            'confidence_score' => 0.85,
            'total_words' => 8,
            'omissions' => 1,
            'duration_seconds' => 60,
            'provenance' => [
                'stt_engine' => 'local_whisper',
                'stt_is_mock' => false,
                'mean_word_confidence' => 0.92,
                'classifier' => 'ml',
                'model_version' => '3.0.0',
            ],
        ], $analysis));

        return $assessment->fresh(['result', 'comprehensionAnswers']);
    }

    private function findingTitles(Assessment $assessment): array
    {
        $advice = app(AnalysisAdvisorService::class)->for($assessment);

        return array_column($advice['findings'], 'title');
    }

    public function test_clean_run_reports_no_problems(): void
    {
        $assessment = $this->assessmentFor($this->teacher());

        $titles = $this->findingTitles($assessment);

        $this->assertContains('No problems detected in the analysis', $titles);
    }

    public function test_mock_transcription_is_flagged_as_critical(): void
    {
        $assessment = $this->assessmentFor($this->teacher(), [
            'provenance' => [
                'stt_engine' => 'mock',
                'stt_is_mock' => true,
                'classifier' => 'ml',
            ],
        ]);

        $advice = app(AnalysisAdvisorService::class)->for($assessment);

        $this->assertSame('critical', $advice['findings'][0]['level']);
        $this->assertStringContainsString('not based on the child', $advice['findings'][0]['title']);
        $this->assertSame('critical', $advice['pipeline']['Speech-to-Text']['state']);
    }

    public function test_rule_based_fallback_is_disclosed(): void
    {
        $assessment = $this->assessmentFor($this->teacher(), [
            'provenance' => [
                'stt_engine' => 'local_whisper',
                'classifier' => 'rule_based',
                'mean_word_confidence' => 0.9,
            ],
        ]);

        $this->assertContains('Weakness came from a rule, not the model', $this->findingTitles($assessment));
    }

    public function test_noisy_audio_suggests_reassessment(): void
    {
        $assessment = $this->assessmentFor($this->teacher(), [
            'provenance' => [
                'stt_engine' => 'local_whisper',
                'classifier' => 'ml',
                'mean_word_confidence' => 0.42,
            ],
        ]);

        $advice = app(AnalysisAdvisorService::class)->for($assessment);
        $titles = array_column($advice['findings'], 'title');
        $noisy = collect($advice['findings'])->firstWhere('title', 'Speech recognition was unsure of the words');

        $this->assertContains('Speech recognition was unsure of the words', $titles);
        $this->assertStringContainsString('re-assess', strtolower($noisy['action']));
    }

    public function test_implausibly_low_accuracy_is_flagged(): void
    {
        $assessment = $this->assessmentFor($this->teacher(), ['accuracy_rate' => 22.0]);

        $this->assertContains('Accuracy is unusually low', $this->findingTitles($assessment));
    }

    public function test_truncated_recording_is_flagged(): void
    {
        $assessment = $this->assessmentFor($this->teacher(), [
            'total_words' => 100,
            'omissions' => 70, // only 30 of 100 words captured
        ]);

        $this->assertContains('Only part of the passage was captured', $this->findingTitles($assessment));
    }

    public function test_model_reliability_is_disclosed(): void
    {
        $advice = app(AnalysisAdvisorService::class)->for($this->assessmentFor($this->teacher()));

        // Comes from ml-service/model_metadata.json rather than being hard-coded.
        $this->assertNotNull($advice['model']['test_accuracy']);
        $this->assertSame('Random Forest', $advice['model']['type']);
    }

    // ── Teacher verdict ──────────────────────────────────────────────

    public function test_accepting_the_result_records_a_verdict(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)
            ->post(route('assessments.verdict', $assessment), ['decision' => 'accepted'])
            ->assertRedirect(route('assessments.results', $assessment));

        $verdict = $assessment->fresh()->verdict;

        $this->assertTrue($verdict->isAccepted());
        $this->assertSame($teacher->id, $verdict->decided_by);
        $this->assertFalse($assessment->fresh()->isInvalidated());
    }

    public function test_override_replaces_the_judgement_without_touching_the_ai_result(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)->post(route('assessments.verdict', $assessment), [
            'decision' => 'overridden',
            'final_reading_level' => 'frustration',
            'final_primary_weakness' => 1,
            'reason' => 'Child was coughing throughout; transcript understated him.',
        ])->assertRedirect();

        $assessment = $assessment->fresh(['result', 'verdict']);

        // Teacher's conclusion is what counts...
        $this->assertSame('frustration', $assessment->effectiveReadingLevel());
        $this->assertSame(1, $assessment->effectivePrimaryWeakness());
        $this->assertTrue($assessment->wasEdited());

        // ...but the AI's original numbers are untouched.
        $this->assertSame('instructional', $assessment->result->reading_level);

        // And the learner's record follows the teacher.
        $this->assertSame('frustration', $assessment->learner->fresh()->reading_level);
    }

    public function test_override_requires_a_reason(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)->post(route('assessments.verdict', $assessment), [
            'decision' => 'overridden',
            'final_reading_level' => 'frustration',
        ])->assertSessionHasErrors('reason');

        $this->assertNull($assessment->fresh()->verdict);
    }

    public function test_manual_rescoring_recomputes_accuracy_and_wpm(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)->post(route('assessments.verdict', $assessment), [
            'decision' => 'overridden',
            'final_reading_level' => 'instructional',
            'reason' => 'Re-scored by hand against the recording.',
            'manual_scoring' => 1,
            'words_read' => 100,
            'manual_substitutions' => 3,
            'manual_omissions' => 2,
            'manual_insertions' => 0,
            'manual_self_corrections' => 4, // not a miscue
        ])->assertRedirect();

        $verdict = $assessment->fresh()->verdict;

        // 5 miscues out of 100 words = 95%
        $this->assertSame(95.0, $verdict->final_accuracy_rate);
        // 100 words over the recorded 60s = 100 wpm
        $this->assertSame(100.0, $verdict->final_words_per_minute);
        $this->assertSame(95.0, $assessment->fresh()->effectiveAccuracy());
    }

    public function test_invalidating_keeps_the_record_but_stops_it_counting(): void
    {
        $teacher = $this->teacher();

        // An earlier, trusted assessment the learner can fall back to.
        $first = $this->assessmentFor($teacher);
        $learner = $first->learner;
        $this->assertSame('instructional', $learner->fresh()->reading_level);

        // A later, bad one that drags the level down.
        $second = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $first->material_id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);
        $second->createResult([
            'accuracy_rate' => 20.0,
            'words_per_minute' => 10,
            'reading_level' => 'frustration',
        ]);
        $this->assertSame('frustration', $learner->fresh()->reading_level);

        $this->actingAs($teacher)->post(route('assessments.verdict', $second), [
            'decision' => 'invalidated',
            'reason' => 'Microphone failed; the recording is unusable.',
        ])->assertRedirect();

        $second = $second->fresh(['verdict']);

        // Still on record...
        $this->assertTrue($second->isInvalidated());
        $this->assertDatabaseHas('assessments', ['id' => $second->id]);
        $this->assertDatabaseHas('assessment_results', ['assessment_id' => $second->id]);

        // ...but the level falls back to the last usable assessment.
        $this->assertSame('instructional', $learner->fresh()->reading_level);
    }

    public function test_invalidate_requires_a_reason(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)
            ->post(route('assessments.verdict', $assessment), ['decision' => 'invalidated'])
            ->assertSessionHasErrors('reason');
    }

    public function test_changing_a_decision_replaces_the_previous_one(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)->post(route('assessments.verdict', $assessment), [
            'decision' => 'invalidated',
            'reason' => 'Looked unusable at first.',
        ]);

        $this->actingAs($teacher)->post(route('assessments.verdict', $assessment), [
            'decision' => 'accepted',
        ]);

        $assessment = $assessment->fresh(['verdict']);

        $this->assertDatabaseCount('assessment_verdicts', 1);
        $this->assertTrue($assessment->verdict->isAccepted());
        $this->assertFalse($assessment->isInvalidated());
        $this->assertNull($assessment->verdict->reason);
    }

    public function test_another_teacher_cannot_decide_on_someone_elses_learner(): void
    {
        $assessment = $this->assessmentFor($this->teacher());
        $outsider = $this->teacher();
        $this->assessmentFor($outsider);   // the outsider has a section of their own, but not this learner

        $this->actingAs($outsider)
            ->post(route('assessments.verdict', $assessment), ['decision' => 'accepted'])
            ->assertForbidden();

        $this->assertNull($assessment->fresh()->verdict);
    }

    public function test_admin_cannot_record_a_decision(): void
    {
        $assessment = $this->assessmentFor($this->teacher());

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('assessments.verdict', $assessment), ['decision' => 'accepted'])
            ->assertForbidden();
    }

    public function test_results_page_shows_pending_review_then_the_decision(): void
    {
        $teacher = $this->teacher();
        $assessment = $this->assessmentFor($teacher);

        $this->actingAs($teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('Pending your review')
            ->assertSee('AI/ML Analysis')
            ->assertSee('Accept AI Result');

        $assessment->verdict()->create([
            'decision' => AssessmentVerdict::DECISION_OVERRIDDEN,
            'final_reading_level' => 'frustration',
            'reason' => 'Noisy classroom.',
            'decided_by' => $teacher->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('Overridden by teacher')
            ->assertSee('Noisy classroom.');
    }
}
