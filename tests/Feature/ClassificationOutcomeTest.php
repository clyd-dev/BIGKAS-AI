<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MLClassificationService;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Only the model may author a weakness label. When it cannot answer, the result
 * says so plainly and stores no label - there is no rule standing in for it.
 *
 * Three doors used to let a rule author a label: the fallback in
 * MLClassificationService, the analyzer's own rule label handed to the gate as
 * a backup, and the gate's last-resort "clearest problem" pick. These tests pin
 * all three shut, and pin what the system says instead.
 */
class ClassificationOutcomeTest extends TestCase
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

    // ── Fixtures ──────────────────────────────────────────────────────

    private function features(): array
    {
        return ['accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'fluency_score' => 5.0];
    }

    /** @return array<int, string> */
    private function statuses(string $p, string $d, string $f, string $c): array
    {
        return [1 => $p, 2 => $d, 3 => $f, 4 => $c];
    }

    private function freshService(): MLClassificationService
    {
        return new MLClassificationService();
    }

    private function pendingAssessment(): Assessment
    {
        $school = School::firstOrCreate(['name' => 'Outcome School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::firstOrCreate(
            ['school_id' => $school->id, 'grade_level' => 4, 'section' => 'Out', 'school_year' => '2026-2027'],
            ['teacher_id' => $this->teacher->id]
        );
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $material = ReadingMaterial::firstOrCreate(
            ['title' => 'Outcome Passage'],
            [
                'content' => 'The brown dog ran fast across the wide green field to find his bone.',
                'language' => 'en', 'grade_level' => 4, 'difficulty' => 'easy',
                'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 14,
            ]
        );

        return Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    /** The slow, inaccurate reading used elsewhere: decoding and pace are flagged. */
    private function stubReading(array $classification): void
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

    private function analyse(Assessment $assessment): AssessmentResult
    {
        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk()->assertJsonPath('success', true);

        return $assessment->fresh()->result;
    }

    private function unavailable(string $reason = 'model_not_loaded'): array
    {
        return [
            'available' => false, 'primary' => null, 'secondary' => null, 'confidence' => null,
            'all_scores' => [], 'model_version' => null, 'reason' => $reason,
        ];
    }

    // ── The service: honest about whether it answered ─────────────────

    public function test_a_model_answer_is_available_and_carries_its_version(): void
    {
        Http::fake(['*/api/classify' => Http::response([
            'primary_weakness' => '2', 'confidence' => 0.71,
            'scores' => [0.1, 0.1, 0.5, 0.1, 0.2], 'model_version' => '3.0.0-test',
        ], 200)]);

        $c = $this->freshService()->classify($this->features());

        $this->assertTrue($c['available']);
        $this->assertSame('2', $c['primary']);
        $this->assertSame('3.0.0-test', $c['model_version']);
    }

    public function test_a_503_means_unavailable_and_names_no_weakness(): void
    {
        // What Flask returns when the model file is gone.
        Http::fake(['*/api/classify' => Http::response(['message' => 'RF model not loaded'], 503)]);

        $c = $this->freshService()->classify($this->features());

        $this->assertFalse($c['available']);
        $this->assertNull($c['primary']);
        $this->assertNull($c['confidence']);
        $this->assertSame('model_not_loaded', $c['reason']);
    }

    public function test_an_unreachable_service_means_unavailable_and_names_no_weakness(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $c = $this->freshService()->classify($this->features());

        $this->assertFalse($c['available']);
        $this->assertNull($c['primary']);
        $this->assertSame('unreachable', $c['reason']);
    }

    public function test_a_disabled_service_is_unavailable_and_is_never_called(): void
    {
        Http::fake();
        config(['services.ml_api.enabled' => false]);

        $c = $this->freshService()->classify($this->features());

        $this->assertFalse($c['available']);
        $this->assertSame('disabled', $c['reason']);
        Http::assertNothingSent();
    }

    public function test_the_rule_based_fallback_no_longer_exists(): void
    {
        // Door one. A rule answering in the model's place is what the panel
        // could not tell apart from a real result.
        $this->assertFalse(method_exists(MLClassificationService::class, 'fallbackClassification'));
        $this->assertArrayNotHasKey('is_fallback', $this->freshService()->classify($this->features()));
    }

    // ── Label authorship: the model's own ranking or nothing ──────────

    public function test_a_supported_model_pick_is_classified_and_the_raw_output_is_kept(): void
    {
        $c = ['available' => true, 'primary' => '2', 'confidence' => 0.7,
            'all_scores' => [0.1, 0.1, 0.5, 0.1, 0.2], 'model_version' => '3.0.0-test'];

        $r = MLClassificationService::resolve($c, $this->statuses('ok', 'concern', 'ok', 'ok'));

        $this->assertSame(2, $r['primary_weakness']);
        $this->assertSame('classified', $r['classification_status']);
        $this->assertSame(0.7, $r['confidence_score']);
        // What the model said, kept beside what was stored, so they can be compared.
        $this->assertSame('2', $r['classifier_output']['primary']);
        $this->assertSame([0.1, 0.1, 0.5, 0.1, 0.2], $r['classifier_output']['all_scores']);
        $this->assertSame('3.0.0-test', $r['classifier_output']['model_version']);
    }

    public function test_the_models_own_next_choice_is_used_when_its_top_pick_is_unsupported(): void
    {
        $c = ['available' => true, 'primary' => '1', 'confidence' => 0.45,
            'all_scores' => [0.05, 0.45, 0.2, 0.3, 0.0]];

        $r = MLClassificationService::resolve($c, $this->statuses('ok', 'watch', 'concern', 'unknown'));

        // Still the model's ranking, so still a model-authored label.
        $this->assertSame(3, $r['primary_weakness']);
        $this->assertSame('classified', $r['classification_status']);
        // The raw pick stays on record, so the adjustment is visible.
        $this->assertSame('1', $r['classifier_output']['primary']);
    }

    public function test_a_rule_never_picks_the_label_when_the_model_gave_no_ranking(): void
    {
        // Door three. The measurements flag decoding, but the model's pick is
        // unsupported and it offered no ranking to choose from. A rule used to
        // step in here and the result was still stamped as the model's.
        $c = ['available' => true, 'primary' => '1', 'confidence' => 0.6, 'all_scores' => []];

        $r = MLClassificationService::resolve($c, $this->statuses('ok', 'concern', 'watch', 'unknown'));

        $this->assertNull($r['primary_weakness']);
        $this->assertSame('model_unsupported', $r['classification_status']);
    }

    public function test_an_unreadable_model_answer_stores_nothing(): void
    {
        $r = MLClassificationService::resolve(
            ['available' => true, 'primary' => 'banana', 'confidence' => 0.9, 'all_scores' => []],
            $this->statuses('ok', 'concern', 'ok', 'ok')
        );

        $this->assertNull($r['primary_weakness']);
        $this->assertSame('model_unsupported', $r['classification_status']);
    }

    public function test_the_model_saying_no_weakness_is_a_real_answer(): void
    {
        $r = MLClassificationService::resolve(
            ['available' => true, 'primary' => '0', 'confidence' => 0.8, 'all_scores' => [0.8, 0.05, 0.05, 0.05, 0.05]],
            $this->statuses('ok', 'ok', 'ok', 'ok')
        );

        $this->assertSame(0, $r['primary_weakness']);
        $this->assertSame('classified', $r['classification_status']);
    }

    public function test_an_unavailable_model_stores_no_label_and_no_confidence(): void
    {
        $r = MLClassificationService::resolve($this->unavailable('unreachable'), $this->statuses('ok', 'concern', 'ok', 'ok'));

        $this->assertNull($r['primary_weakness']);
        $this->assertSame('unavailable', $r['classification_status']);
        // A confidence nobody measured is worse than none.
        $this->assertNull($r['confidence_score']);
        $this->assertSame('unreachable', $r['classifier_output']['reason']);
    }

    public function test_provenance_names_an_unavailable_classifier_and_no_model_version(): void
    {
        $p = MLClassificationService::classifierProvenance($this->unavailable());

        $this->assertSame('unavailable', $p['classifier']);
        $this->assertNull($p['model_version']);
    }

    // ── Persistence through the real routes ───────────────────────────

    public function test_an_unavailable_model_still_saves_the_measurements_but_no_label(): void
    {
        $this->stubReading($this->unavailable());

        $result = $this->analyse($this->pendingAssessment());

        // The reading was measured, so the measurements are kept...
        $this->assertGreaterThan(0, (float) $result->accuracy_rate);
        $this->assertNotNull($result->reading_level);

        // ...but nothing is guessed in the model's place.
        $this->assertNull($result->primary_weakness);
        $this->assertNull($result->secondary_weakness);
        $this->assertNull($result->confidence_score);
        $this->assertSame('unavailable', $result->classification_status);
        $this->assertSame('unavailable', $result->ml_analysis_json['provenance']['classifier']);
        $this->assertNull($result->ml_analysis_json['provenance']['model_version']);
    }

    public function test_the_analyzers_own_rule_label_is_never_stored(): void
    {
        // Door two. The analyzer computes a rule-based label on every reading.
        // It used to be handed to the gate as a backup and stored whenever the
        // model's answer was empty. Here the model answers with nothing usable.
        $this->stubReading(['available' => true, 'primary' => null, 'secondary' => null,
            'confidence' => 0.5, 'all_scores' => []]);

        $result = $this->analyse($this->pendingAssessment());

        $this->assertNull($result->primary_weakness);
        $this->assertSame('model_unsupported', $result->classification_status);
        // And its scores must not sit in the record posing as the model's.
        $this->assertArrayNotHasKey('all_scores', $result->ml_analysis_json);
    }

    public function test_a_model_result_records_what_the_model_actually_said(): void
    {
        $this->stubReading(['available' => true, 'primary' => '2', 'secondary' => null,
            'confidence' => 0.66, 'all_scores' => [0.05, 0.1, 0.6, 0.2, 0.05], 'model_version' => '3.0.0-test']);

        $result = $this->analyse($this->pendingAssessment());

        $this->assertSame('classified', $result->classification_status);
        $this->assertSame(2, $result->primary_weakness);
        $this->assertSame('2', $result->ml_analysis_json['classifier_output']['primary']);
        $this->assertSame([0.05, 0.1, 0.6, 0.2, 0.05], $result->ml_analysis_json['classifier_output']['all_scores']);
        $this->assertSame('ml', $result->ml_analysis_json['provenance']['classifier']);
        $this->assertSame('3.0.0-test', $result->ml_analysis_json['provenance']['model_version']);
    }

    public function test_re_analysing_after_the_model_returns_replaces_an_unclassified_result(): void
    {
        $assessment = $this->pendingAssessment();

        $this->stubReading($this->unavailable());
        $this->assertSame('unavailable', $this->analyse($assessment)->classification_status);

        $this->mock(MLClassificationService::class, function ($mock) {
            $mock->shouldReceive('classify')->andReturn([
                'available' => true, 'primary' => '2', 'secondary' => null, 'confidence' => 0.7,
                'all_scores' => [0.05, 0.1, 0.6, 0.2, 0.05], 'model_version' => '3.0.0-test',
            ]);
        });

        $again = $this->analyse($assessment);

        $this->assertSame('classified', $again->classification_status);
        $this->assertSame(2, $again->primary_weakness);
        $this->assertSame(0.7, (float) $again->confidence_score);
    }

    // ── One outcome, read the same way by every page ──────────────────

    private function storedResult(array $attributes, array $provenance = []): Assessment
    {
        $assessment = $this->pendingAssessment();
        $assessment->update(['status' => Assessment::STATUS_COMPLETED]);

        $assessment->createResult(array_merge([
            'accuracy_rate' => 62.0, 'words_per_minute' => 70.0, 'reading_level' => 'frustration',
            'total_words' => 14, 'duration_seconds' => 60,
            'provenance' => $provenance,
        ], $attributes));

        return $assessment->fresh(['result', 'verdict', 'comprehensionAnswers']);
    }

    public function test_a_classified_result_reads_as_classified(): void
    {
        $a = $this->storedResult(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['classifier' => 'ml', 'model_version' => '3.0.0-test']
        );

        $o = $a->weaknessOutcome();

        $this->assertSame('classified', $o['state']);
        $this->assertSame(2, $o['id']);
        $this->assertTrue($o['usable']);
        $this->assertSame(2, $a->effectivePrimaryWeakness());
    }

    public function test_an_unavailable_result_reads_as_unclassified_and_feeds_nothing(): void
    {
        $a = $this->storedResult(
            ['primary_weakness' => null, 'classification_status' => 'unavailable', 'confidence_score' => null],
            ['classifier' => 'unavailable']
        );

        $o = $a->weaknessOutcome();

        $this->assertSame('unclassified', $o['state']);
        $this->assertSame('unavailable', $o['reason']);
        $this->assertNull($o['id']);
        $this->assertFalse($o['usable']);
        $this->assertNull($a->effectivePrimaryWeakness());
        $this->assertCount(0, $a->getRecommendedInterventions());
    }

    public function test_an_unsupported_model_result_reads_as_unclassified(): void
    {
        $a = $this->storedResult(
            ['primary_weakness' => null, 'classification_status' => 'model_unsupported', 'confidence_score' => null],
            ['classifier' => 'ml', 'model_version' => '3.0.0-test']
        );

        $o = $a->weaknessOutcome();

        $this->assertSame('unclassified', $o['state']);
        $this->assertSame('model_unsupported', $o['reason']);
        $this->assertFalse($o['usable']);
    }

    public function test_a_teacher_can_set_the_weakness_when_the_model_could_not(): void
    {
        $a = $this->storedResult(
            ['primary_weakness' => null, 'classification_status' => 'unavailable', 'confidence_score' => null],
            ['classifier' => 'unavailable']
        );

        $a->verdict()->create([
            'decision' => 'overridden', 'final_primary_weakness' => 2,
            'reason' => 'Clearly guessing at unfamiliar words; decoding is the issue.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $o = $a->fresh(['result', 'verdict'])->weaknessOutcome();

        $this->assertSame('teacher_set', $o['state']);
        $this->assertSame(2, $o['id']);
        $this->assertTrue($o['usable']);
    }

    public function test_an_invalidated_result_feeds_nothing(): void
    {
        $a = $this->storedResult(
            ['primary_weakness' => 2, 'classification_status' => 'classified', 'confidence_score' => 0.7],
            ['classifier' => 'ml']
        );

        $a->verdict()->create([
            'decision' => 'invalidated', 'reason' => 'The microphone was unplugged.',
            'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $o = $a->fresh(['result', 'verdict'])->weaknessOutcome();

        $this->assertSame('unclassified', $o['state']);
        $this->assertSame('invalidated', $o['reason']);
        $this->assertNull($a->fresh(['result', 'verdict'])->effectivePrimaryWeakness());
    }

    // ── Legacy: keeps working, and says what it is ────────────────────

    public function test_a_legacy_model_result_keeps_its_label(): void
    {
        // Saved before the status column existed, stamped by the model.
        $a = $this->storedResult(
            ['primary_weakness' => 2],
            ['classifier' => 'ml', 'model_version' => '3.0.0-12feature']
        );

        $o = $a->weaknessOutcome();

        $this->assertSame('legacy', $o['state']);
        $this->assertSame(2, $o['id']);
        $this->assertTrue($o['usable']);
        $this->assertSame('model', $o['source']);
        $this->assertNull($o['warning']);
    }

    public function test_a_legacy_rule_based_result_keeps_its_label_with_the_existing_warning(): void
    {
        $a = $this->storedResult(['primary_weakness' => 2], ['classifier' => 'rule_based']);

        $o = $a->weaknessOutcome();

        $this->assertSame('legacy', $o['state']);
        $this->assertSame(2, $o['id']);
        $this->assertTrue($o['usable']);
        $this->assertSame('rule', $o['source']);
        $this->assertNotNull($o['warning']);
    }

    public function test_a_legacy_result_with_no_recorded_source_keeps_its_label_with_a_warning(): void
    {
        $a = $this->storedResult(['primary_weakness' => 1], []);

        $o = $a->weaknessOutcome();

        $this->assertSame('legacy', $o['state']);
        $this->assertSame(1, $o['id']);
        $this->assertTrue($o['usable']);
        $this->assertSame('unrecorded', $o['source']);
        $this->assertNotNull($o['warning']);
    }
}
