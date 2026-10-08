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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordingKeptTest extends TestCase
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

    private function pendingAssessment(): Assessment
    {
        $school = School::firstOrCreate(['name' => 'Rec School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'Rec', 'school_year' => '2025-2026',
        ]);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $material = ReadingMaterial::create([
            'title' => 'Rec Passage', 'content' => 'The dog ran to the park.', 'language' => 'en',
            'grade_level' => 4, 'difficulty' => 'easy', 'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 6,
        ]);

        return Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    /** Stand in for the speech and ML services; returns the paths transcribe() was asked to read. */
    private function stubServices(array &$transcribedPaths): void
    {
        $this->mock(SpeechToTextService::class, function ($mock) use (&$transcribedPaths) {
            $mock->shouldReceive('transcribe')->andReturnUsing(function ($path) use (&$transcribedPaths) {
                $transcribedPaths[] = $path;

                return ['text' => 'the dog ran to the park', 'words' => [], 'duration' => 10, 'engine' => 'local_whisper'];
            });
        });

        $this->mock(MLClassificationService::class, function ($mock) {
            $mock->shouldReceive('classify')->andReturn([
                'primary' => '0', 'secondary' => null, 'confidence' => 0.8, 'all_scores' => [],
            ]);
        });
    }

    public function test_a_browser_recording_is_saved_and_is_what_gets_transcribed(): void
    {
        $paths = [];
        $this->stubServices($paths);
        $assessment = $this->pendingAssessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk()->assertJsonPath('success', true);

        $assessment->refresh();

        $this->assertNotNull($assessment->audio_file, 'The recording must be saved against the assessment.');
        Storage::disk('public')->assertExists($assessment->audio_file);
        $this->assertSame('available', $assessment->audioStatus());

        // The saved copy was analysed, not a throwaway temporary upload.
        $this->assertSame([Storage::disk('public')->path($assessment->audio_file)], $paths);

        $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('<audio controls', false)
            ->assertDontSee('No recording was saved');
    }

    public function test_the_client_chosen_file_extension_is_never_trusted(): void
    {
        $paths = [];
        $this->stubServices($paths);
        $assessment = $this->pendingAssessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.php', 20, 'audio/webm'),
        ])->assertOk();

        $this->assertStringEndsWith('.webm', $assessment->fresh()->audio_file);
        $this->assertStringNotContainsString('.php', $assessment->fresh()->audio_file);
    }

    public function test_a_new_recording_replaces_the_old_file_instead_of_orphaning_it(): void
    {
        $paths = [];
        $this->stubServices($paths);
        $assessment = $this->pendingAssessment();

        Storage::disk('public')->put('assessments/audio/old.webm', 'old-bytes');
        $assessment->update(['audio_file' => 'assessments/audio/old.webm']);

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk();

        Storage::disk('public')->assertMissing('assessments/audio/old.webm');
        $this->assertNotSame('assessments/audio/old.webm', $assessment->fresh()->audio_file);
        Storage::disk('public')->assertExists($assessment->fresh()->audio_file);
    }

    public function test_an_assessment_that_was_never_recorded_says_so_plainly(): void
    {
        $paths = [];
        $this->stubServices($paths);
        $assessment = $this->pendingAssessment();
        $assessment->update(['status' => Assessment::STATUS_COMPLETED]);
        $assessment->createResult(['accuracy_rate' => 95, 'words_per_minute' => 80, 'reading_level' => 'instructional']);

        $this->assertSame('not_saved', $assessment->fresh()->audioStatus());

        $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('No recording was saved for this assessment')
            ->assertDontSee('no longer available')
            ->assertDontSee('<audio controls', false);
    }

    public function test_a_saved_recording_whose_file_vanished_is_reported_as_missing(): void
    {
        $paths = [];
        $this->stubServices($paths);
        $assessment = $this->pendingAssessment();
        $assessment->update(['status' => Assessment::STATUS_COMPLETED, 'audio_file' => 'assessments/audio/gone.webm']);
        $assessment->createResult(['accuracy_rate' => 95, 'words_per_minute' => 80, 'reading_level' => 'instructional']);

        $this->assertSame('missing', $assessment->fresh()->audioStatus());

        $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))
            ->assertOk()
            ->assertSee('can no longer be found on the server');
    }
}
