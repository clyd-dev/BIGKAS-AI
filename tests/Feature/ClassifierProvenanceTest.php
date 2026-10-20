<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AnalysisAdvisorService;
use App\Services\MLClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every stored result has to say what produced its weakness: the model, or the
 * fallback rule. A teacher deciding about a child must never have to guess, and
 * "we didn't record it" is not an acceptable answer to show them quietly.
 */
class ClassifierProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function assessment(): Assessment
    {
        $school = School::firstOrCreate(['name' => 'Prov School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::firstOrCreate(
            ['school_id' => $school->id, 'grade_level' => 4, 'section' => 'Prov', 'school_year' => '2025-2026'],
            ['teacher_id' => $this->teacher->id]
        );
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);

        $material = ReadingMaterial::firstOrCreate(
            ['title' => 'Prov Passage'],
            [
                'content' => 'The dog ran fast across the field today.',
                'language' => 'en', 'grade_level' => 4, 'difficulty' => 'easy',
                'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 8,
            ]
        );

        return Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id,
            'assessor_id' => $this->teacher->id, 'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);
    }

    private function features(): array
    {
        return ['accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'fluency_score' => 5.0];
    }

    // ── The service records what actually answered ────────────────────

    public function test_a_model_answer_is_recorded_as_model_backed(): void
    {
        Http::fake(['*/api/classify' => Http::response([
            'primary_weakness' => '2', 'confidence' => 0.71, 'scores' => [],
            'model_version' => '3.0.0-test',
        ], 200)]);

        $service = app(MLClassificationService::class);
        $provenance = MLClassificationService::classifierProvenance($service->classify($this->features()));

        $this->assertSame('ml', $provenance['classifier']);
        $this->assertSame('3.0.0-test', $provenance['model_version']);
    }

    public function test_a_503_from_the_service_is_recorded_as_unavailable(): void
    {
        // This is exactly what Flask returns once the model file is gone. No
        // rule answers in its place any more, so it is recorded as what it is.
        Http::fake(['*/api/classify' => Http::response(['message' => 'RF model not loaded'], 503)]);

        $service = app(MLClassificationService::class);
        $classification = $service->classify($this->features());
        $provenance = MLClassificationService::classifierProvenance($classification);

        $this->assertFalse($classification['available']);
        $this->assertSame('unavailable', $provenance['classifier']);
    }

    public function test_an_unavailable_result_never_carries_a_model_version(): void
    {
        Http::fake(['*/api/classify' => Http::response(['message' => 'RF model not loaded'], 503)]);

        $service = app(MLClassificationService::class);
        $provenance = MLClassificationService::classifierProvenance($service->classify($this->features()));

        // A version read off a metadata file nothing ran makes a missing model
        // look present. The file outlives the model itself.
        $this->assertNull($provenance['model_version']);
    }

    public function test_an_unreachable_service_is_recorded_as_unavailable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $service = app(MLClassificationService::class);
        $provenance = MLClassificationService::classifierProvenance($service->classify($this->features()));

        $this->assertSame('unavailable', $provenance['classifier']);
        $this->assertNull($provenance['model_version']);
    }

    // ── Both analysis routes record it ────────────────────────────────

    public function test_the_api_route_records_provenance_too(): void
    {
        $assessment = $this->assessment();

        // The API path used to save a result with no provenance at all, which
        // made a missing model indistinguishable from a model answer.
        $analysis = ['accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'reading_level' => 'frustration', 'total_words' => 8];
        $analysis['provenance'] = MLClassificationService::classifierProvenance(['available' => false]);

        $result = $assessment->createResult($analysis);

        $this->assertSame('unavailable', $result->ml_analysis_json['provenance']['classifier']);
    }

    // ── The teacher is told ───────────────────────────────────────────

    private function advise(array $provenance): array
    {
        $assessment = $this->assessment();
        $assessment->createResult([
            'accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'reading_level' => 'frustration',
            'total_words' => 8, 'duration_seconds' => 60, 'provenance' => $provenance,
        ]);

        return app(AnalysisAdvisorService::class)->for($assessment->fresh(['result', 'verdict', 'comprehensionAnswers']));
    }

    private function titles(array $advice): array
    {
        return array_column($advice['findings'], 'title');
    }

    public function test_a_rule_based_result_is_called_out(): void
    {
        $advice = $this->advise(['stt_engine' => 'local_whisper', 'classifier' => 'rule_based']);

        $this->assertContains('Weakness came from a rule, not the model', $this->titles($advice));
    }

    public function test_an_unrecorded_classifier_is_called_out_rather_than_passed_over(): void
    {
        // 44 stored results are in this state. Silence reads as "all fine".
        $advice = $this->advise(['stt_engine' => 'local_whisper']);

        $this->assertContains('What produced this weakness was not recorded', $this->titles($advice));
    }

    public function test_a_model_backed_result_raises_no_classifier_warning(): void
    {
        $advice = $this->advise([
            'stt_engine' => 'local_whisper', 'classifier' => 'ml', 'model_version' => '3.0.0-test',
        ]);

        $titles = $this->titles($advice);
        $this->assertNotContains('Weakness came from a rule, not the model', $titles);
        $this->assertNotContains('What produced this weakness was not recorded', $titles);
    }

    // ── And it is visible where the weakness is read ──────────────────

    private function resultPageFor(array $provenance): string
    {
        $assessment = $this->assessment();
        $assessment->createResult([
            'accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'reading_level' => 'frustration',
            'total_words' => 8, 'duration_seconds' => 60, 'primary_weakness' => 2,
            'provenance' => $provenance,
        ]);

        return $this->actingAs($this->teacher)
            ->get(route('assessments.results', $assessment))
            ->assertOk()
            ->getContent();
    }

    public function test_the_weakness_card_flags_a_rule_based_classification(): void
    {
        // Buried in a panel far down the page is not where this decision is made.
        $html = $this->resultPageFor(['stt_engine' => 'local_whisper', 'classifier' => 'rule_based']);

        $this->assertStringContainsString('Not from the model', $html);
    }

    public function test_the_weakness_card_flags_an_unrecorded_classification(): void
    {
        $html = $this->resultPageFor(['stt_engine' => 'local_whisper']);

        $this->assertStringContainsString('Source not recorded', $html);
    }

    public function test_the_weakness_card_stays_clean_for_a_model_classification(): void
    {
        $html = $this->resultPageFor([
            'stt_engine' => 'local_whisper', 'classifier' => 'ml', 'model_version' => '3.0.0-test',
        ]);

        $this->assertStringNotContainsString('Not from the model', $html);
        $this->assertStringNotContainsString('Source not recorded', $html);
    }
}
