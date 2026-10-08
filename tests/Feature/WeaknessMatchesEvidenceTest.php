<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MLClassificationService;
use App\Services\ReadingInterpretationService;
use App\Services\SpeechToTextService;
use App\Services\WeaknessEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The stored primary weakness must agree with the per-skill rows the teacher
 * reads. They used to disagree: the classifier's phonetic/vowel/blend features
 * are shares of a reader's own errors, so a single vowel-ish slip read as a
 * maximal phonics signal and "Phonemic Awareness" was stored for readers whose
 * measured trouble was decoding and pace.
 */
class WeaknessMatchesEvidenceTest extends TestCase
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

    /** A grade 4 learner; the passage is read slowly and with wrong words. */
    private function assessment(): Assessment
    {
        $school = School::firstOrCreate(['name' => 'Ev School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'Ev', 'school_year' => '2026-2027',
        ]);
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
        $material = ReadingMaterial::create([
            'title' => 'Ev Passage',
            'content' => 'The brown dog ran fast across the wide green field to find his bone.',
            'language' => 'en', 'grade_level' => 4, 'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 14,
        ]);

        return Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);
    }

    /**
     * Words are swapped for unrelated ones (not sound-alikes) and read slowly,
     * so decoding and pace are the measured problems — never letter sounds.
     */
    private function stubSlowInaccurateReading(string $modelSays): void
    {
        $this->mock(SpeechToTextService::class, function ($mock) {
            $mock->shouldReceive('transcribe')->andReturn([
                // "brown"->"big", "fast"->"slowly", "wide"->"huge": wrong words
                // that sound nothing like the originals.
                'text' => 'The big dog ran slowly across the huge green field to find his bone',
                'words' => [], 'duration' => 60, 'engine' => 'local_whisper',
            ]);
        });

        $this->mock(MLClassificationService::class, function ($mock) use ($modelSays) {
            $mock->shouldReceive('classify')->andReturn([
                'primary' => $modelSays,
                'secondary' => null,
                'confidence' => 0.42,
                // Phonemic likeliest, then fluency, then decoding.
                'all_scores' => [0.05, 0.45, 0.15, 0.30, 0.05],
            ]);
        });
    }

    public function test_phonemic_is_not_stored_when_the_skill_row_says_it_is_fine(): void
    {
        $this->stubSlowInaccurateReading('1'); // the model says Phonemic Awareness
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk()->assertJsonPath('success', true);

        $result = $assessment->fresh()->result;
        $this->assertNotNull($result);

        $ml = $result->ml_analysis_json ?? [];
        $statuses = WeaknessEvidence::statuses(
            $ml,
            $assessment->learner->grade_level,
            $assessment->comprehensionScore()
        );

        // The reading really was slow and inaccurate, with no sound-alike pattern.
        $this->assertSame('ok', $statuses[1], 'Letter sounds should be clear in this reading.');
        $this->assertTrue(
            WeaknessEvidence::showsProblem($statuses[2]) || WeaknessEvidence::showsProblem($statuses[3]),
            'Decoding or pace should be flagged for a slow, inaccurate reading.'
        );

        $this->assertNotSame(
            1,
            $result->primary_weakness,
            'Phonemic Awareness must not be stored when that skill measured clear.'
        );
        $this->assertContains($result->primary_weakness, [2, 3]);
    }

    /** Whatever is stored must be a skill the teacher's own rows flag. */
    public function test_the_stored_weakness_always_matches_a_flagged_skill_row(): void
    {
        $this->stubSlowInaccurateReading('1');
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk();

        $assessment = $assessment->fresh();
        $picked = $assessment->result->primary_weakness;

        // The results page renders these rows via ReadingInterpretationService::weakness().
        $rows = collect(app(ReadingInterpretationService::class)->weakness($assessment)['skills'])
            ->keyBy('id');
        $this->assertNotEmpty($rows, 'The results page builds its rows from weakness()["skills"].');

        if ($picked !== null && $picked !== 0) {
            $this->assertTrue(
                WeaknessEvidence::showsProblem($rows[$picked]['status'] ?? null),
                "Stored weakness {$picked} is shown as \"{$rows[$picked]['status']}\" on the results page."
            );
        }
    }

    /** A genuine phonics case is still reported as one. */
    public function test_phonemic_is_still_stored_when_the_slips_do_sound_alike(): void
    {
        $this->mock(SpeechToTextService::class, function ($mock) {
            $mock->shouldReceive('transcribe')->andReturn([
                // Every wrong word is a near-homophone of the target.
                'text' => 'The brown dog ran fast acrost the wid green feeld to fine his bown',
                'words' => [], 'duration' => 10, 'engine' => 'local_whisper',
            ]);
        });
        $this->mock(MLClassificationService::class, function ($mock) {
            $mock->shouldReceive('classify')->andReturn([
                'primary' => '1', 'secondary' => null, 'confidence' => 0.7,
                'all_scores' => [0.05, 0.6, 0.2, 0.1, 0.05],
            ]);
        });

        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk();

        $assessment = $assessment->fresh();
        $statuses = WeaknessEvidence::statuses(
            $assessment->result->ml_analysis_json ?? [],
            $assessment->learner->grade_level,
            null
        );

        $this->assertTrue(
            WeaknessEvidence::showsProblem($statuses[1]),
            'Sound-alike misreadings should flag the letter-sounds row.'
        );
        $this->assertSame(1, $assessment->result->primary_weakness);
    }

    /** The teacher-facing rows and the guard read from one rule, not two. */
    public function test_skill_rows_and_the_stored_weakness_use_the_same_thresholds(): void
    {
        $this->stubSlowInaccurateReading('3');
        $assessment = $this->assessment();

        $this->actingAs($this->teacher)->postJson(route('assessments.analyze', $assessment), [
            'audio' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ])->assertOk();

        $assessment = $assessment->fresh();
        $signs = collect(app(ReadingInterpretationService::class)->weakness($assessment)['skills'])
            ->keyBy('id');
        $statuses = WeaknessEvidence::statuses(
            $assessment->result->ml_analysis_json ?? [],
            $assessment->learner->grade_level,
            $assessment->comprehensionScore()
        );

        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame(
                $statuses[$id],
                $signs[$id]['status'],
                "Skill {$id} is judged differently by the results page and the weakness guard."
            );
        }
    }
}
