<?php

namespace Tests\Concerns;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Shared fixtures for the batch assessment tests. The using test must have
 * RefreshDatabase; audio is stored on a faked "public" disk.
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
        Storage::fake('public');

        $assessment = Assessment::factory()->create($attributes);

        $path = 'assessments/audio/a' . $assessment->id . '.webm';
        Storage::disk('public')->put($path, 'audio');
        $assessment->forceFill(['audio_file' => $path])->save();

        return $assessment->fresh();
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

    /** Flask up or down, per part: transcription and the classifier. */
    protected function fakeEngine(bool $stt = true, bool $classifier = true): void
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
                'whisper_loaded' => $stt, 'classifier_loaded' => $classifier,
                'model_stale' => false, 'model_version' => '3.0.0-test',
            ]),
            '*/api/transcribe' => Http::response([
                'text' => self::BATCH_PASSAGE, 'words' => $words, 'duration' => 6.5, 'language' => 'en',
            ]),
            '*/api/classify' => $classifier
                ? Http::response([
                    'primary_weakness' => 'Independent Reader', 'secondary_weakness' => null,
                    'confidence' => 0.8, 'scores' => [], 'model_version' => '3.0.0-test',
                ])
                : Http::response('no model', 503),
        ]);
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
