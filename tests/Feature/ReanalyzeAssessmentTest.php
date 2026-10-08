<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\NewAssessmentCompleted;
use App\Services\MLClassificationService;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * assessment_results.assessment_id is unique, so analysing the same assessment
 * twice used to fail with a raw "duplicate entry" SQL error. Analysing again is
 * a normal thing to do (a slow run, a page reload, a retry), so the second run
 * must replace the first result instead of crashing.
 */
class ReanalyzeAssessmentTest extends TestCase
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
        $school = School::firstOrCreate(['name' => 'Re School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'Re', 'school_year' => '2026-2027',
        ]);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $material = ReadingMaterial::create([
            'title' => 'Re Passage', 'content' => 'The dog ran to the park.', 'language' => 'en',
            'grade_level' => 4, 'difficulty' => 'easy', 'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 6,
        ]);

        return Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    private function stubServices(float $accuracy = 95.0): void
    {
        $this->mock(SpeechToTextService::class, function ($mock) {
            $mock->shouldReceive('transcribe')->andReturn([
                'text' => 'the dog ran to the park', 'words' => [], 'duration' => 10, 'engine' => 'local_whisper',
            ]);
        });

        $this->mock(MLClassificationService::class, function ($mock) {
            $mock->shouldReceive('classify')->andReturn([
                'primary' => '0', 'secondary' => null, 'confidence' => 0.8, 'all_scores' => [],
            ]);
        });
    }

    private function analyze(Assessment $assessment)
    {
        return $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ]);
    }

    public function test_analysing_the_same_assessment_twice_replaces_the_result(): void
    {
        $this->stubServices();
        $assessment = $this->assessment();

        $this->analyze($assessment)->assertOk()->assertJsonPath('success', true);
        $firstResultId = $assessment->fresh()->result->id;

        // The teacher taps Analyze again (slow VPS run, reloaded page, retry).
        $this->analyze($assessment)->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseCount('assessment_results', 1);
        $this->assertSame(
            $firstResultId,
            $assessment->fresh()->result->id,
            'Re-analysing should update the existing result row, not create a second one.'
        );
    }

    public function test_the_second_analysis_does_not_notify_the_parent_again(): void
    {
        $this->stubServices();
        $assessment = $this->assessment();

        $parent = User::factory()->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);
        $assessment->learner->users()->attach($parent->id, ['relationship' => 'parent']);

        $this->analyze($assessment)->assertOk();
        $this->analyze($assessment)->assertOk();

        Notification::assertSentToTimes($parent, NewAssessmentCompleted::class, 1);
    }

    /**
     * The recording page polls for a learner recording remotely and reloads on
     * a status change. Analysing sets the status to "processing", so without
     * this guard the poller reloaded the page mid-analysis: the saved audio came
     * back as a second player and Analyze became live again, which is how the
     * same assessment got analysed twice.
     */
    public function test_the_status_poller_stands_down_once_this_page_owns_the_flow(): void
    {
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('BIGKAS_LOCAL_FLOW', false);
    }

    public function test_a_failed_analysis_does_not_leak_database_detail_to_the_teacher(): void
    {
        $assessment = $this->assessment();

        $this->mock(SpeechToTextService::class, function ($mock) {
            $mock->shouldReceive('transcribe')->andThrow(
                new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: secret_table.column')
            );
        });

        $response = $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertStatus(500)->assertJsonPath('success', false);

        $message = $response->json('message');
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('secret_table', $message);
        $this->assertStringContainsString("#{$assessment->id}", $message, 'The teacher needs an id to report.');
    }

    public function test_createResult_is_idempotent_at_the_model_level(): void
    {
        $assessment = $this->assessment();

        $first = $assessment->createResult(['accuracy_rate' => 80, 'words_per_minute' => 60, 'reading_level' => 'instructional']);
        $second = $assessment->createResult(['accuracy_rate' => 97, 'words_per_minute' => 95, 'reading_level' => 'independent']);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('assessment_results', 1);
        $this->assertSame(97.0, (float) $second->accuracy_rate, 'The newer numbers should win.');
        $this->assertSame('independent', $second->reading_level);
    }
}
