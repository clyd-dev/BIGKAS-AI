<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function teacherWithClass(): array
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $school = School::firstOrCreate(
            ['name' => 'API Test School'],
            ['address' => 'Sagay City', 'division' => 'Sagay City']
        );

        $class = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'grade_level' => 4,
            'section' => 'API-' . $teacher->id,
            'school_year' => '2025-2026',
        ]);

        $learner = Learner::factory()->create([
            'class_id' => $class->id,
            'grade_level' => 4,
        ]);

        return [$teacher, $learner];
    }

    private function material(): ReadingMaterial
    {
        return ReadingMaterial::create([
            'title' => 'API Passage',
            'content' => 'The cat sat on the mat and looked at the bird.',
            'language' => 'en',
            'grade_level' => 4,
            'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING,
            'word_count' => 10,
        ]);
    }

    public function test_index_returns_the_callers_own_assessments(): void
    {
        [$teacher, $learner] = $this->teacherWithClass();
        $material = $this->material();

        $mine = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);

        // Another teacher's assessment must not leak into the list.
        [$otherTeacher, $otherLearner] = $this->teacherWithClass();
        Assessment::create([
            'learner_id' => $otherLearner->id,
            'material_id' => $material->id,
            'assessor_id' => $otherTeacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/assessments')->assertOk();

        $ids = collect($response->json('data.assessments'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);
    }

    public function test_store_records_the_assessor_and_type(): void
    {
        [$teacher, $learner] = $this->teacherWithClass();
        $material = $this->material();

        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/assessments', [
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessment_type' => 'comprehension',
        ])->assertCreated();

        $assessment = Assessment::find($response->json('data.assessment_id'));

        $this->assertSame($teacher->id, $assessment->assessor_id);
        $this->assertSame('comprehension', $assessment->assessment_type);
        $this->assertSame(Assessment::STATUS_PENDING, $assessment->status);
    }

    public function test_store_rejects_a_learner_outside_the_callers_class(): void
    {
        [$teacher, ] = $this->teacherWithClass();
        [, $otherLearner] = $this->teacherWithClass();
        $material = $this->material();

        Sanctum::actingAs($teacher);

        $this->postJson('/api/assessments', [
            'learner_id' => $otherLearner->id,
            'material_id' => $material->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_upload_audio_persists_the_path_and_status(): void
    {
        Storage::fake('public');

        [$teacher, $learner] = $this->teacherWithClass();
        $material = $this->material();

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/assessments/{$assessment->id}/audio", [
            'audio' => UploadedFile::fake()->create('reading.wav', 20, 'audio/wav'),
        ])->assertOk();

        $assessment->refresh();

        $this->assertNotNull($assessment->audio_file, 'audio_file should be persisted');
        $this->assertStringContainsString('assessments/audio', $assessment->audio_file);
        $this->assertSame(Assessment::STATUS_PROCESSING, $assessment->status);
        Storage::disk('public')->assertExists($assessment->audio_file);
    }

    public function test_results_uses_the_real_result_relation(): void
    {
        [$teacher, $learner] = $this->teacherWithClass();
        $material = $this->material();

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        Sanctum::actingAs($teacher);

        // No result yet.
        $this->getJson("/api/assessments/{$assessment->id}/results")->assertNotFound();

        $assessment->createResult([
            'accuracy_rate' => 88.5,
            'words_per_minute' => 62,
            'reading_level' => 'instructional',
        ]);

        $this->getJson("/api/assessments/{$assessment->id}/results")
            ->assertOk()
            ->assertJsonPath('data.result.accuracy_rate', 88.5)
            ->assertJsonPath('data.result.reading_level', 'instructional');
    }

    public function test_another_teacher_cannot_read_an_assessment(): void
    {
        [$teacher, $learner] = $this->teacherWithClass();
        [$otherTeacher, ] = $this->teacherWithClass();
        $material = $this->material();

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);

        Sanctum::actingAs($otherTeacher);

        $this->getJson("/api/assessments/{$assessment->id}")->assertForbidden();
        $this->getJson("/api/assessments/{$assessment->id}/results")->assertForbidden();
        $this->postJson("/api/assessments/{$assessment->id}/analyze")->assertForbidden();
    }

    public function test_analyze_requires_audio(): void
    {
        [$teacher, $learner] = $this->teacherWithClass();
        $material = $this->material();

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/assessments/{$assessment->id}/analyze")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_weakness_label_and_number_both_map_to_the_same_id(): void
    {
        $map = \App\Services\MLClassificationService::class;

        $this->assertSame(0, $map::mapWeaknessToId('0'));
        $this->assertSame(0, $map::mapWeaknessToId('Independent Reader'));
        $this->assertSame(1, $map::mapWeaknessToId('Phonemic Awareness'));
        $this->assertSame(3, $map::mapWeaknessToId('3'));
        $this->assertSame(4, $map::mapWeaknessToId('Reading Comprehension'));
        $this->assertNull($map::mapWeaknessToId('something unexpected'));
    }
}
