<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ComprehensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The "Reading Comprehension" figure in the skill breakdown comes from the
 * comprehension questions and from nowhere else.
 *
 * It used to be estimated from the audio as accuracy x 0.3 + fluency x 7, so a
 * child who was never asked a question still got a comprehension percentage
 * worked out from how their voice sounded. The model does not predict
 * comprehension either, so with no questions there is no number: the pages say
 * "Not checked" rather than showing a score of zero.
 */
class ComprehensionSkillScoreTest extends TestCase
{
    use RefreshDatabase;

    private const PASSAGE = 'The dog ran fast. He jumped over the log. Then he sat down to rest.';

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->teacher = User::factory()->create([
            'role' => 'teacher', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function learner(): Learner
    {
        $school = School::firstOrCreate(['name' => 'Skill School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::firstOrCreate(
            ['school_id' => $school->id, 'grade_level' => 4, 'section' => 'Skill', 'school_year' => '2025-2026'],
            ['teacher_id' => $this->teacher->id]
        );

        return Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);
    }

    /** A completed, measured reading with a strong accuracy and pace. */
    private function reading(Learner $learner, string $type = ReadingMaterial::TYPE_ORAL_READING): Assessment
    {
        $material = ReadingMaterial::create([
            'title' => 'Skill Passage ' . uniqid(), 'content' => self::PASSAGE, 'language' => 'en',
            'grade_level' => 4, 'difficulty' => 'easy', 'type' => $type, 'word_count' => 15,
        ]);

        $assessment = Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en',
            'assessment_type' => $type === ReadingMaterial::TYPE_COMPREHENSION
                ? Assessment::TYPE_COMPREHENSION
                : Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        $assessment->createResult([
            'accuracy_rate' => 100.0, 'words_per_minute' => 130.0, 'reading_level' => 'independent',
            'total_words' => 15, 'fluency_score' => 8,
            'miscues' => array_fill_keys([
                'mispronunciation', 'substitution', 'omission', 'not_reached', 'insertion',
                'repetition', 'self_correction', 'off_passage', 'hesitation', 'long_pause', 'unclear',
            ], 0) + ['correct' => 15],
            'word_comparison' => [],
            'provenance' => ['stt_engine' => 'local_whisper', 'classifier' => 'ml'],
        ]);

        return $assessment->fresh(['result', 'learner']);
    }

    /** Asks four questions and answers `$right` of them correctly. */
    private function answer(Assessment $assessment, int $right): void
    {
        foreach (range(1, 4) as $i) {
            ComprehensionQuestion::create([
                'material_id' => $assessment->material_id, 'question' => "Q{$i}?", 'question_type' => 'literal',
                'correct_answer' => 'Right', 'option_a' => 'Right', 'option_b' => 'Wrong',
                'option_c' => 'No', 'option_d' => 'Nope', 'sort_order' => $i,
            ]);
        }

        $ids = $assessment->material->comprehensionQuestions()->pluck('id');
        $answers = [];
        foreach ($ids as $i => $id) {
            $answers[$id] = $i < $right ? 'A' : 'B';
        }

        app(ComprehensionService::class)->record($assessment, $answers);
    }

    public function test_no_questions_asked_means_no_comprehension_score(): void
    {
        $learner = $this->learner();
        $this->reading($learner);

        $this->assertNull(
            $learner->getSkillBreakdown()['comprehension'],
            'A reading with no questions must not produce a comprehension figure.'
        );
    }

    public function test_the_comprehension_score_is_the_questions_result(): void
    {
        $learner = $this->learner();
        $assessment = $this->reading($learner, ReadingMaterial::TYPE_COMPREHENSION);
        $this->answer($assessment, 3);

        $this->assertSame(75.0, $learner->fresh()->getSkillBreakdown()['comprehension']);
        $this->assertSame(75.0, $assessment->fresh()->result->getSkillScores()['comprehension']);
    }

    /**
     * The old formula on these numbers (100 x 0.3 + 8 x 7 = 86) would have
     * shown a strong comprehension score for a child who answered nothing.
     */
    public function test_the_audio_never_stands_in_for_the_questions(): void
    {
        $learner = $this->learner();
        $assessment = $this->reading($learner);

        $skillScores = $assessment->fresh()->result->getSkillScores();

        $this->assertNull($skillScores['comprehension']);
        $this->assertArrayNotHasKey(
            'comprehension',
            $assessment->result->ml_analysis_json['skill_scores'] ?? [],
            'The analyzer must not write a comprehension estimate into the saved analysis.'
        );
    }

    public function test_the_report_page_says_not_checked_instead_of_zero(): void
    {
        $learner = $this->learner();
        $this->reading($learner);

        $html = $this->actingAs($this->teacher)
            ->get(route('reports.learner', $learner))
            ->assertOk()
            ->getContent();

        $breakdown = substr($html, strpos($html, 'Skill Breakdown'));
        $breakdown = substr($breakdown, 0, strpos($breakdown, '</div></div>') ?: 4000);

        $this->assertStringContainsString('Reading Comprehension', $breakdown);
        $this->assertStringContainsString('Not checked', $breakdown);
    }
}
