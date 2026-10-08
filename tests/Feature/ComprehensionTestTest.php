<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ComprehensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComprehensionTestTest extends TestCase
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

    private function comprehensionMaterial(int $questionCount = 2): ReadingMaterial
    {
        $material = ReadingMaterial::create([
            'title' => 'Comprehension Passage',
            'content' => 'Max is a brown dog. He runs in the park every morning.',
            'language' => 'en',
            'grade_level' => 4,
            'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_COMPREHENSION,
            'word_count' => 11,
        ]);

        for ($i = 1; $i <= $questionCount; $i++) {
            ComprehensionQuestion::create([
                'material_id' => $material->id,
                'question' => "Question {$i}?",
                'question_type' => 'literal',
                'correct_answer' => 'Right',
                'option_a' => 'Right',
                'option_b' => 'Wrong B',
                'option_c' => 'Wrong C',
                'option_d' => 'Wrong D',
                'sort_order' => $i,
            ]);
        }

        return $material->fresh('comprehensionQuestions');
    }

    private function assessmentFor(User $teacher, ReadingMaterial $material, string $type): Assessment
    {
        $school = \App\Models\School::firstOrCreate(
            ['name' => 'Test Elementary School'],
            ['address' => 'Sagay City', 'division' => 'Sagay City']
        );

        $class = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'grade_level' => 4,
            'section' => 'Test',
            'school_year' => '2025-2026',
        ]);

        $learner = Learner::factory()->create([
            'class_id' => $class->id,
            'grade_level' => 4,
        ]);

        return Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $teacher->id,
            'language' => 'en',
            'assessment_type' => $type,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    public function test_assessment_type_is_persisted_on_creation(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial();
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);

        $this->assertSame('comprehension', $assessment->fresh()->assessment_type);
        $this->assertTrue($assessment->needsComprehensionTest());
    }

    public function test_oral_reading_assessment_needs_no_comprehension_test(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial();
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_ORAL_READING);

        $this->assertFalse($assessment->needsComprehensionTest());
    }

    public function test_comprehension_type_without_questions_needs_no_test(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(0);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);

        $this->assertFalse($assessment->needsComprehensionTest());
    }

    public function test_service_scores_answers_and_stores_snapshots(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(4);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);
        $ids = $material->comprehensionQuestions->pluck('id');

        // 3 of 4 correct ('A' holds the correct answer).
        $score = app(ComprehensionService::class)->record($assessment, [
            $ids[0] => 'A',
            $ids[1] => 'A',
            $ids[2] => 'A',
            $ids[3] => 'B',
        ]);

        $this->assertSame(75.0, $score);
        $this->assertCount(4, $assessment->comprehensionAnswers);
        $this->assertSame(75.0, $assessment->comprehensionScore());

        $wrong = $assessment->comprehensionAnswers()->where('is_correct', false)->first();
        $this->assertSame('B', $wrong->selected_option);
        $this->assertSame('Wrong B', $wrong->selected_text);
        $this->assertSame('Right', $wrong->correct_text);
        $this->assertNotEmpty($wrong->question_text);
    }

    public function test_service_rejects_incomplete_answers(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(3);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);
        $ids = $material->comprehensionQuestions->pluck('id');

        $this->expectException(ValidationException::class);

        app(ComprehensionService::class)->record($assessment, [$ids[0] => 'A']);
    }

    public function test_retaking_replaces_the_previous_attempt(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(2);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);
        $ids = $material->comprehensionQuestions->pluck('id');
        $service = app(ComprehensionService::class);

        $service->record($assessment, [$ids[0] => 'B', $ids[1] => 'B']);
        $this->assertSame(0.0, $assessment->comprehensionScore());

        $service->record($assessment, [$ids[0] => 'A', $ids[1] => 'A']);

        $this->assertCount(2, $assessment->fresh()->comprehensionAnswers);
        $this->assertSame(100.0, $assessment->comprehensionScore());
    }

    public function test_analyze_is_rejected_without_comprehension_answers(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')
            ->put('assessments/audio/fake.webm', 'audio-bytes');

        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(2);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);
        $assessment->update(['audio_file' => 'assessments/audio/fake.webm']);

        $this->actingAs($teacher)
            ->postJson(route('assessments.analyze', $assessment))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('comprehension_answers', 0);
    }

    public function test_teacher_sees_questions_and_no_upload_form_once_audio_exists(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(2);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);

        // Before any audio: upload offered as an alternative to recording.
        $this->actingAs($teacher)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Upload Audio')
            ->assertSee('Comprehension Questions');

        // Once audio exists, uploading another file is no longer offered.
        $assessment->update([
            'audio_file' => 'assessments/audio/fake.webm',
            'status' => Assessment::STATUS_PROCESSING,
        ]);

        $this->actingAs($teacher)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertDontSee('Upload Audio');
    }

    public function test_student_cannot_submit_reading_without_answering_questions(): void
    {
        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(2);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);

        $response = $this->withSession(['student_learner_id' => $assessment->learner_id])
            ->postJson(route('student.assessment.upload-audio', $assessment), [
                'audio' => \Illuminate\Http\UploadedFile::fake()->create('reading.webm', 10),
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('comprehension_answers', 0);
        $this->assertSame(Assessment::STATUS_PENDING, $assessment->fresh()->status);
    }

    public function test_student_submission_stores_answers_and_hands_off_to_teacher(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $teacher = $this->teacher();
        $material = $this->comprehensionMaterial(2);
        $assessment = $this->assessmentFor($teacher, $material, Assessment::TYPE_COMPREHENSION);
        $ids = $material->comprehensionQuestions->pluck('id');

        $this->withSession(['student_learner_id' => $assessment->learner_id])
            ->postJson(route('student.assessment.upload-audio', $assessment), [
                'audio' => \Illuminate\Http\UploadedFile::fake()->create('reading.webm', 10),
                'answers' => [$ids[0] => 'A', $ids[1] => 'C'],
            ])
            ->assertOk();

        $assessment->refresh();

        $this->assertSame(Assessment::STATUS_PROCESSING, $assessment->status);
        $this->assertCount(2, $assessment->comprehensionAnswers);
        $this->assertSame(50.0, $assessment->comprehensionScore());

        // Teacher now sees the learner's answers instead of a blank quiz.
        $this->actingAs($teacher)->get(route('assessments.show', $assessment))
            ->assertOk()
            ->assertSee('Answered by learner');
    }
}
