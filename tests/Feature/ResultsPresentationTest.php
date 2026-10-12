<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentVerdict;
use App\Models\ComprehensionQuestion;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AnalysisAdvisorService;
use App\Services\ComprehensionService;
use App\Services\ReadingInterpretationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What the results page tells a teacher: the comparison panels, the "why this
 * level" scale, what the microphone heard, and why the weakness is what it is.
 * Numbers are set explicitly so each rule is pinned down on its own.
 */
class ResultsPresentationTest extends TestCase
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

    private function counts(array $override = []): array
    {
        return array_merge(array_fill_keys([
            'correct', 'mispronunciation', 'substitution', 'omission', 'not_reached', 'insertion',
            'repetition', 'self_correction', 'off_passage', 'hesitation', 'long_pause', 'unclear',
        ], 0), $override);
    }

    /** A stored result with exactly the numbers given. */
    private function scored(array $analysis = [], array $material = []): Assessment
    {
        $school = School::firstOrCreate(['name' => 'Present School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $class = SchoolClass::firstOrCreate(
            ['school_id' => $school->id, 'grade_level' => 4, 'section' => 'Present', 'school_year' => '2025-2026'],
            ['teacher_id' => $this->teacher->id]
        );
        $learner = Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4]);

        $material = ReadingMaterial::create(array_merge([
            'title' => 'Present Passage', 'content' => self::PASSAGE, 'language' => 'en',
            'grade_level' => 4, 'difficulty' => 'easy', 'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 15,
        ], $material));

        $assessment = Assessment::create([
            'learner_id' => $learner->id, 'material_id' => $material->id, 'assessor_id' => $this->teacher->id,
            'language' => 'en', 'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_COMPLETED,
        ]);

        $assessment->createResult(array_merge([
            'accuracy_rate' => 100.0, 'words_per_minute' => 130.0, 'reading_level' => 'independent',
            'total_words' => 15, 'fluency_score' => 8,
            'miscues' => $this->counts(['correct' => 15]),
            'word_comparison' => [],
            'provenance' => ['stt_engine' => 'local_whisper', 'classifier' => 'ml'],
        ], $analysis));

        return $assessment->fresh(['result', 'verdict', 'comprehensionAnswers', 'learner']);
    }

    private function skill(array $weakness, int $id): array
    {
        return collect($weakness['skills'])->firstWhere('id', $id);
    }

    // ── Reading comparison ────────────────────────────────────────────

    private function comparisonRows(): array
    {
        $words = ['the', 'dog', 'ran', 'fast', 'he', 'jumped'];

        return [
            ['reference' => 'the', 'spoken' => 'the', 'status' => 'correct', 'miscue' => 'correct'],
            ['reference' => 'dog', 'spoken' => 'dog', 'status' => 'correct', 'miscue' => 'correct'],
            ['reference' => 'ran', 'spoken' => 'run', 'status' => 'substitution', 'miscue' => 'mispronunciation'],
            ['reference' => 'fast', 'spoken' => null, 'status' => 'omission', 'miscue' => 'omission'],
            ['reference' => 'he', 'spoken' => 'he', 'status' => 'correct', 'miscue' => 'correct', 'unclear' => true, 'confidence' => 0.3],
            ['reference' => 'jumped', 'spoken' => null, 'status' => 'omission', 'miscue' => 'not_reached'],
            ['reference' => 'over', 'spoken' => null, 'status' => 'omission', 'miscue' => 'not_reached'],
        ];
    }

    private function resultPage(Assessment $assessment): string
    {
        return $this->actingAs($this->teacher)->get(route('assessments.results', $assessment))->assertOk()->getContent();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end - $start);
    }

    public function test_the_original_passage_is_plain_with_no_colour_marks(): void
    {
        $html = $this->resultPage($this->scored(['word_comparison' => $this->comparisonRows()]));

        // The body of the left-hand panel only.
        $this->assertSame(1, preg_match('/Original Passage<\/span>.*?<div class="p-3 reading-pane">(.*?)<\/div>/s', $html, $match));
        $original = $match[1];

        $this->assertStringContainsString('The dog ran fast. He jumped over the log.', $original, 'The real passage, with punctuation.');
        $this->assertStringNotContainsString('<span', $original, 'No word may be tagged on the original side.');
        $this->assertStringNotContainsString('class="w', $original);
    }

    public function test_a_skipped_word_stays_visible_in_yellow_on_the_childs_side(): void
    {
        $html = $this->resultPage($this->scored(['word_comparison' => $this->comparisonRows()]));

        $child = substr($html, strpos($html, 'What the Child Read'));

        // The word itself is printed, tagged with the skipped colour — not a dot.
        $this->assertMatchesRegularExpression('/class="w w-omit"[^>]*>fast<\/span>/', $child);
        $this->assertStringNotContainsString('w-gap', $child);

        // The mispronounced word shows what the child said.
        $this->assertMatchesRegularExpression('/class="w w-mis"[^>]*>run<\/span>/', $child);
    }

    public function test_words_never_reached_are_still_shown_not_hidden(): void
    {
        $html = $this->resultPage($this->scored(['word_comparison' => $this->comparisonRows()]));
        $child = substr($html, strpos($html, 'What the Child Read'));

        $this->assertMatchesRegularExpression('/class="w w-nr"[^>]*>jumped<\/span>/', $child);
        $this->assertMatchesRegularExpression('/class="w w-nr"[^>]*>over<\/span>/', $child);
        $this->assertSame(1, substr_count($child, 'reading stopped here'), 'One marker, where the reading ended.');
    }

    public function test_an_unsure_word_gets_a_wavy_underline_and_is_explained_in_the_key(): void
    {
        $html = $this->resultPage($this->scored(['word_comparison' => $this->comparisonRows()]));

        $this->assertMatchesRegularExpression('/class="w w-unclear"[^>]*>he<\/span>/', $html);
        $this->assertStringContainsString('Unsure how it sounded', $html);
        $this->assertStringContainsString('text-decoration: underline wavy', $html);
        $this->assertStringNotContainsString('border-bottom: 2px dotted', $html);
    }

    public function test_the_key_only_lists_what_actually_happened(): void
    {
        $html = $this->resultPage($this->scored(['word_comparison' => [
            ['reference' => 'the', 'spoken' => 'the', 'status' => 'correct', 'miscue' => 'correct'],
        ]]));

        $key = $this->between($html, 'comparison-key', 'Original Passage');

        $this->assertStringContainsString('Every word was read as written', $key);
        $this->assertStringNotContainsString('Repeated', $key);
        $this->assertStringNotContainsString('Mispronounced', $key);
    }

    // ── Why this level ────────────────────────────────────────────────

    public function test_the_scale_puts_the_marker_inside_the_band_the_numbers_give(): void
    {
        $service = app(ReadingInterpretationService::class);

        $low = $service->for($this->scored(['accuracy_rate' => 45.0, 'reading_level' => 'frustration']))['scale'];
        $mid = $service->for($this->scored(['accuracy_rate' => 93.0, 'reading_level' => 'instructional']))['scale'];
        $high = $service->for($this->scored(['accuracy_rate' => 99.0, 'reading_level' => 'independent']))['scale'];

        $this->assertSame('frustration', $low['by_numbers']);
        $this->assertLessThan(33.4, $low['position']);

        $this->assertSame('instructional', $mid['by_numbers']);
        $this->assertGreaterThan(33.3, $mid['position']);
        $this->assertLessThan(66.7, $mid['position']);

        $this->assertSame('independent', $high['by_numbers']);
        $this->assertGreaterThan(66.6, $high['position']);
    }

    public function test_the_trust_warning_is_pulled_out_as_a_callout(): void
    {
        $assessment = $this->scored([
            'audio_quality' => ['measured' => true, 'rating' => 'noisy', 'snr_db' => 5.0, 'total_seconds' => 20,
                'speech_seconds' => 8, 'non_speech_seconds' => 12, 'noise_floor_db' => -30, 'clipping_ratio' => 0, 'background_events' => []],
        ]);

        $reasons = app(ReadingInterpretationService::class)->for($assessment)['reasons'];
        $trust = collect($reasons)->firstWhere('title', 'Before you trust this level');

        $this->assertTrue($trust['callout']);
        $this->assertStringContainsString('Before you trust this level', $this->resultPage($assessment));
    }

    // ── What the microphone picked up ─────────────────────────────────

    public function test_the_microphone_summary_is_in_plain_language(): void
    {
        $assessment = $this->scored([
            'audio_quality' => ['measured' => true, 'rating' => 'moderate_noise', 'snr_db' => 14.2, 'total_seconds' => 40,
                'speech_seconds' => 31, 'non_speech_seconds' => 9, 'noise_floor_db' => -44, 'clipping_ratio' => 0,
                'leading_silence' => 1.1, 'trailing_silence' => 0.4,
                'background_events' => [['start' => 12.4, 'end' => 13.9, 'peak_db' => -20]]],
            'non_reading' => [
                'off_passage' => [['text' => 'teacher can I start', 'words' => 4, 'start' => 0.5, 'end' => 2.0]],
                'sounds' => [['label' => 'coughing', 'start' => 20.0, 'end' => 20.8]],
                'unreliable_segments' => [],
            ],
        ]);

        $audio = app(AnalysisAdvisorService::class)->for($assessment)['audio'];

        $this->assertSame('Some background noise', $audio['headline']);
        $this->assertSame('Medium', $audio['noise']);
        $this->assertSame(31, $audio['speech_seconds']);
        $this->assertSame(78, $audio['speech_pct']);

        // Everything heard other than the reading, earliest first.
        $this->assertSame(['talk', 'noise', 'sound'], array_column($audio['items'], 'kind'));
        $this->assertSame('teacher can I start', $audio['items'][0]['quote']);
        $this->assertSame('Coughing', $audio['items'][2]['title']);

        // The engineering numbers are kept, but not the headline.
        $this->assertSame('14.2 dB', $audio['technical']['Voice above the background']);

        $html = $this->resultPage($assessment);
        $this->assertStringContainsString('Other sounds heard', $html);
        $this->assertStringContainsString('Background noise', $html);
        $this->assertStringContainsString('Technical details', $html);
    }

    public function test_listen_buttons_appear_only_when_there_is_a_recording_to_play(): void
    {
        $analysis = [
            'audio_quality' => ['measured' => true, 'rating' => 'clean', 'snr_db' => 25.0, 'total_seconds' => 20,
                'speech_seconds' => 18, 'non_speech_seconds' => 2, 'noise_floor_db' => -50, 'clipping_ratio' => 0, 'background_events' => []],
            'non_reading' => ['off_passage' => [['text' => 'hello', 'words' => 1, 'start' => 3.0, 'end' => 3.5]], 'sounds' => [], 'unreliable_segments' => []],
        ];

        // No recording was kept: a play button would do nothing.
        $this->assertStringNotContainsString('seek-btn"', $this->resultPage($this->scored($analysis)));
    }

    public function test_a_clean_recording_with_nothing_else_says_so(): void
    {
        $assessment = $this->scored([
            'audio_quality' => ['measured' => true, 'rating' => 'clean', 'snr_db' => 25.0, 'total_seconds' => 20,
                'speech_seconds' => 18, 'non_speech_seconds' => 2, 'noise_floor_db' => -50, 'clipping_ratio' => 0, 'background_events' => []],
        ]);

        $this->assertStringContainsString('Nothing besides the child reading was picked up', $this->resultPage($assessment));
        $this->assertSame('Low', app(AnalysisAdvisorService::class)->for($assessment)['audio']['noise']);
    }

    public function test_an_unmeasured_recording_does_not_pretend_to_be_clean(): void
    {
        $audio = app(AnalysisAdvisorService::class)->for($this->scored())['audio'];

        $this->assertFalse($audio['measured']);
        $this->assertSame('Recording quality was not checked', $audio['headline']);
    }

    // ── Why this weakness ─────────────────────────────────────────────

    public function test_a_decoding_problem_is_judged_on_the_words_attempted(): void
    {
        // 8 of 12 attempted words right = 66.7%.
        $assessment = $this->scored([
            'accuracy_rate' => 53.3, 'reading_level' => 'frustration', 'total_words' => 15, 'primary_weakness' => 2,
            'miscues' => $this->counts(['correct' => 8, 'substitution' => 3, 'omission' => 1, 'not_reached' => 3]),
        ]);

        $weakness = app(ReadingInterpretationService::class)->weakness($assessment);

        $this->assertSame('concern', $this->skill($weakness, 2)['status']);
        $this->assertStringContainsString('8 of the 12 words attempted', $this->skill($weakness, 2)['text']);
        $this->assertStringContainsString('3 words never reached are not counted', $this->skill($weakness, 2)['text']);
        $this->assertSame('supported', $weakness['primary']['fit']);
        $this->assertSame('Why Decoding Accuracy?', $weakness['primary']['headline']);
    }

    public function test_stopping_early_is_not_mistaken_for_a_decoding_problem(): void
    {
        // 14 of 14 attempted words right; only the ending was never reached.
        $assessment = $this->scored([
            'accuracy_rate' => 46.7, 'reading_level' => 'frustration', 'total_words' => 30, 'primary_weakness' => 2,
            'miscues' => $this->counts(['correct' => 14, 'not_reached' => 16]),
        ]);

        $weakness = app(ReadingInterpretationService::class)->weakness($assessment);

        $this->assertSame('ok', $this->skill($weakness, 2)['status'], 'The words that were attempted were all right.');

        // So the model's "Decoding" pick is flagged as poorly supported, not repeated back.
        $this->assertSame('weak', $weakness['primary']['fit']);
        $this->assertStringContainsString('shows little direct sign of it', $weakness['primary']['text']);
    }

    public function test_sound_alike_slips_point_to_letter_sound_trouble(): void
    {
        $assessment = $this->scored([
            'primary_weakness' => 1,
            'miscues' => $this->counts(['correct' => 9, 'mispronunciation' => 4, 'substitution' => 1, 'omission' => 1]),
            'word_comparison' => [
                ['reference' => 'ran', 'spoken' => 'run', 'status' => 'substitution', 'miscue' => 'mispronunciation'],
            ],
        ]);

        $phonemic = $this->skill(app(ReadingInterpretationService::class)->weakness($assessment), 1);

        $this->assertSame('concern', $phonemic['status']);
        $this->assertStringContainsString('4 of 6 slips sounded like the right word ("ran" read as "run")', $phonemic['text']);
    }

    public function test_pace_is_judged_against_the_grade_benchmark(): void
    {
        // Grade 4 minimum is 90 words per minute (config/bigkas.php).
        $slow = app(ReadingInterpretationService::class)->weakness($this->scored([
            'words_per_minute' => 55.0, 'primary_weakness' => 3,
            'miscues' => $this->counts(['correct' => 15, 'long_pause' => 2, 'hesitation' => 1]),
        ]));
        $steady = app(ReadingInterpretationService::class)->weakness($this->scored(['words_per_minute' => 130.0]));

        $this->assertSame('concern', $this->skill($slow, 3)['status']);
        $this->assertStringContainsString('Read at 55 words per minute; a Grade 4 reader is expected to reach about 120', $this->skill($slow, 3)['text']);
        $this->assertStringContainsString('2 long pauses and 1 hesitation', $this->skill($slow, 3)['text']);

        $this->assertSame('ok', $this->skill($steady, 3)['status']);
        $this->assertStringContainsString('No noticeable pauses', $this->skill($steady, 3)['text']);
    }

    public function test_comprehension_is_only_judged_when_the_questions_were_asked(): void
    {
        $untested = app(ReadingInterpretationService::class)->weakness($this->scored(['primary_weakness' => 4]));

        $this->assertSame('unknown', $this->skill($untested, 4)['status']);
        $this->assertSame('weak', $untested['primary']['fit']);
        $this->assertStringContainsString('So this is a guess, not something measured', $untested['primary']['text']);

        // Asked and answered: 1 of 4 right = 25%, in the Frustration range.
        $assessment = $this->scored(['primary_weakness' => 4], ['type' => ReadingMaterial::TYPE_COMPREHENSION]);
        $assessment->update(['assessment_type' => Assessment::TYPE_COMPREHENSION]);

        foreach (range(1, 4) as $i) {
            ComprehensionQuestion::create([
                'material_id' => $assessment->material_id, 'question' => "Q{$i}?", 'question_type' => 'literal',
                'correct_answer' => 'Right', 'option_a' => 'Right', 'option_b' => 'Wrong', 'option_c' => 'No', 'option_d' => 'Nope', 'sort_order' => $i,
            ]);
        }
        $ids = $assessment->material->comprehensionQuestions()->pluck('id');
        app(ComprehensionService::class)->record($assessment, [$ids[0] => 'A', $ids[1] => 'B', $ids[2] => 'B', $ids[3] => 'B']);

        $tested = app(ReadingInterpretationService::class)->weakness($assessment->fresh(['result', 'verdict', 'comprehensionAnswers', 'learner']));

        $this->assertSame('concern', $this->skill($tested, 4)['status']);
        $this->assertStringContainsString('1 of 4 comprehension questions were answered correctly (25%)', $this->skill($tested, 4)['text']);
        $this->assertSame('supported', $tested['primary']['fit']);
    }

    public function test_when_the_model_and_the_evidence_disagree_the_clearer_sign_is_named(): void
    {
        // Model says Fluency, but the pace is fine and the decoding is poor.
        $assessment = $this->scored([
            'accuracy_rate' => 60.0, 'reading_level' => 'frustration', 'words_per_minute' => 140.0, 'primary_weakness' => 3,
            'confidence_score' => 0.33,
            'miscues' => $this->counts(['correct' => 9, 'substitution' => 5, 'omission' => 1]),
        ]);

        $weakness = app(ReadingInterpretationService::class)->weakness($assessment);

        $this->assertSame('weak', $weakness['primary']['fit']);
        $this->assertStringContainsString('The clearer sign is with reading words correctly', $weakness['primary']['text']);
        $this->assertStringContainsString('not very sure about this (33%)', $weakness['primary']['confidence_note']);
    }

    public function test_no_weakness_is_questioned_when_a_skill_shows_a_problem(): void
    {
        $assessment = $this->scored([
            'accuracy_rate' => 70.0, 'reading_level' => 'frustration', 'primary_weakness' => 0,
            'miscues' => $this->counts(['correct' => 10, 'substitution' => 5]),
        ]);

        $primary = app(ReadingInterpretationService::class)->weakness($assessment)['primary'];

        $this->assertSame('weak', $primary['fit']);
        $this->assertStringContainsString('flagged no weakness, but this reading does show a sign of a problem', $primary['text']);
    }

    public function test_a_teacher_changed_weakness_is_acknowledged(): void
    {
        $assessment = $this->scored(['primary_weakness' => 3]);
        $assessment->verdict()->create([
            'decision' => 'overridden', 'final_reading_level' => 'instructional', 'final_primary_weakness' => 1,
            'reason' => 'Mixes up letter sounds.', 'decided_by' => $this->teacher->id, 'decided_at' => now(),
        ]);

        $note = app(ReadingInterpretationService::class)->weakness($assessment->fresh(['result', 'verdict', 'comprehensionAnswers', 'learner']))['final_note'];

        $this->assertSame('You set the weakness to Phonemic Awareness (the model said Oral Reading Fluency).', $note);
    }

    public function test_an_empty_recording_never_gets_a_weakness_story(): void
    {
        $weakness = app(ReadingInterpretationService::class)->weakness($this->scored([
            'primary_weakness' => 2,
            'audio_quality' => ['measured' => true, 'rating' => 'no_speech', 'snr_db' => null, 'total_seconds' => 14,
                'speech_seconds' => 0, 'non_speech_seconds' => 14, 'noise_floor_db' => -40, 'clipping_ratio' => 0, 'background_events' => []],
        ]));

        $this->assertSame('unusable', $weakness['primary']['fit']);
        $this->assertSame(['unknown', 'unknown', 'unknown'], array_column(array_slice($weakness['skills'], 0, 3), 'status'));
    }

    public function test_the_page_shows_skill_rows_and_the_explanation_instead_of_the_old_bars(): void
    {
        $html = $this->resultPage($this->scored([
            'primary_weakness' => 3, 'words_per_minute' => 55.0,
            'miscues' => $this->counts(['correct' => 15, 'long_pause' => 2]),
            'all_scores' => [1 => 0, 2 => 112.2, 3 => 30.7, 4 => 0],
        ]));

        $this->assertStringContainsString('Skill Scores', $html);
        $this->assertStringContainsString('Sign of a problem', $html);
        $this->assertStringContainsString("model's pick", $html);
        $this->assertStringContainsString('Why Oral Reading Fluency?', $html);
        $this->assertStringContainsString('What the other skills show', $html);

        // The raw rule-based numbers (112.2, 30.7) are no longer drawn as if they were percentages.
        $this->assertStringNotContainsString('112.2', $html);
    }

    public function test_results_analysed_before_the_finer_breakdown_still_explain_themselves(): void
    {
        $assessment = $this->scored(['primary_weakness' => 2, 'accuracy_rate' => 70.0, 'reading_level' => 'frustration', 'substitutions' => 4, 'omissions' => 2]);

        // Strip what newer analyses add, as an old stored result would look.
        $legacy = collect($assessment->result->ml_analysis_json)->except(['miscues', 'provenance'])->all();
        $assessment->result->update(['ml_analysis_json' => $legacy, 'substitutions' => 4, 'omissions' => 2]);

        $html = $this->resultPage($assessment->fresh());

        $this->assertStringContainsString('Skill Scores', $html);
        // The heading names which level it explains; this one has no comprehension test.
        $this->assertStringContainsString('Why this word reading level?', $html);
    }

    // -- Level tiles: each number labelled by what produced it ---------

    /** Gives an assessment a comprehension test with `$correct` of `$total` right. */
    private function withComprehension(Assessment $assessment, int $correct, int $total): Assessment
    {
        $assessment->update(['assessment_type' => Assessment::TYPE_COMPREHENSION]);

        foreach (range(1, $total) as $i) {
            ComprehensionQuestion::create([
                'material_id' => $assessment->material_id, 'question' => "Q{$i}?", 'question_type' => 'literal',
                'correct_answer' => 'Right', 'option_a' => 'Right', 'option_b' => 'Wrong',
                'option_c' => 'No', 'option_d' => 'Nope', 'sort_order' => $i,
            ]);
        }

        $ids = $assessment->material->comprehensionQuestions()->pluck('id');
        $answers = [];
        foreach ($ids as $i => $id) {
            $answers[$id] = $i < $correct ? 'A' : 'B';
        }
        app(ComprehensionService::class)->record($assessment, $answers);

        return $assessment->fresh(['result', 'verdict', 'comprehensionAnswers', 'learner', 'material']);
    }

    public function test_word_reading_alone_is_not_called_a_reading_level(): void
    {
        $html = $this->resultPage($this->scored([
            'accuracy_rate' => 91.5, 'reading_level' => 'instructional',
        ]));

        $this->assertStringContainsString('Word Reading Level', $html);
        $this->assertStringNotContainsString('Comprehension Level', $html);
        // The bare label would claim comprehension was measured when it was not.
        $this->assertStringNotContainsString('>Reading Level<', $html);
    }

    public function test_both_halves_measured_gives_all_three_levels(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 34.0, 'reading_level' => 'frustration'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            1, 5
        );

        $html = $this->resultPage($assessment);

        $this->assertStringContainsString('Word Reading Level', $html);
        $this->assertStringContainsString('Comprehension Level', $html);
        $this->assertStringContainsString('>Reading Level<', $html);
    }

    public function test_each_level_follows_its_own_phil_iri_scale(): void
    {
        // 98% word reading is Independent; 60% comprehension is Instructional.
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            3, 5
        );

        $levels = $assessment->readingLevels();

        $this->assertSame('independent', $levels['word']['level']);
        $this->assertSame('instructional', $levels['comprehension']['level']);
        // The passage as a whole reads at the weaker of the two.
        $this->assertSame('instructional', $levels['overall']['level']);
    }

    public function test_a_teacher_set_level_outranks_the_calculated_one(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 34.0, 'reading_level' => 'frustration'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            1, 5
        );

        AssessmentVerdict::create([
            'assessment_id' => $assessment->id,
            'decision' => AssessmentVerdict::DECISION_OVERRIDDEN,
            'final_reading_level' => 'instructional',
            'reason' => 'The recording cut out; the child read far more than this.',
            'decided_by' => $this->teacher->id,
            'decided_at' => now(),
        ]);

        $levels = $assessment->fresh(['result', 'verdict', 'comprehensionAnswers'])->readingLevels();

        $this->assertSame('instructional', $levels['overall']['level']);
        $this->assertTrue($levels['overall']['from_teacher']);
    }

    // -- The arithmetic behind the headline number ---------------------

    public function test_the_accuracy_sum_is_shown_not_just_the_percentage(): void
    {
        $assessment = $this->scored([
            'accuracy_rate' => 34.0, 'reading_level' => 'frustration', 'total_words' => 47,
            'miscues' => $this->counts(['correct' => 16, 'not_reached' => 31]),
        ]);

        $html = $this->resultPage($assessment);

        // 16 of 47 words is where 34% comes from; a bare "34%" invites the question.
        $this->assertStringContainsString('16 / 47 x 100 = 34%', $html);
    }

    public function test_unreached_words_are_explained_as_part_of_the_denominator(): void
    {
        $html = $this->resultPage($this->scored([
            'accuracy_rate' => 34.0, 'reading_level' => 'frustration', 'total_words' => 47,
            'miscues' => $this->counts(['correct' => 16, 'not_reached' => 31]),
        ]));

        $this->assertStringContainsString('31 of the 47 words were never reached', $html);
        $this->assertStringContainsString('only 16 were actually attempted', $html);
    }

    public function test_the_comprehension_sum_is_shown_too(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 34.0, 'reading_level' => 'frustration'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            1, 5
        );

        $this->assertStringContainsString('1 / 5 x 100 = 20%', $this->resultPage($assessment));
    }

    public function test_a_re_scored_result_shows_the_teachers_own_count(): void
    {
        $assessment = $this->scored([
            'accuracy_rate' => 34.0, 'reading_level' => 'frustration', 'total_words' => 47,
            'miscues' => $this->counts(['correct' => 16, 'not_reached' => 31]),
        ]);

        AssessmentVerdict::create([
            'assessment_id' => $assessment->id,
            'decision' => AssessmentVerdict::DECISION_OVERRIDDEN,
            'manual_scoring' => true,
            'words_read' => 20,
            'final_accuracy_rate' => 90.0,
            'final_reading_level' => 'instructional',
            'reason' => 'Re-counted by hand against the recording.',
            'decided_by' => $this->teacher->id,
            'decided_at' => now(),
        ]);

        $html = $this->resultPage($assessment->fresh(['result', 'verdict']));

        $this->assertStringContainsString('18 / 20 x 100 = 90%', $html);
        $this->assertStringContainsString('Counted by the teacher during re-scoring.', $html);
    }

    // -- Phil-IRI miscue names -----------------------------------------

    public function test_the_key_uses_phil_iri_miscue_names(): void
    {
        $html = $this->resultPage($this->scored([
            'word_comparison' => $this->comparisonRows(),
            'miscues' => $this->counts([
                'correct' => 3, 'mispronunciation' => 1, 'omission' => 1,
                'not_reached' => 2, 'unclear' => 1,
            ]),
        ]));

        $key = $this->between($html, 'comparison-key', 'ORIGINAL PASSAGE');

        $this->assertStringContainsString('Mispronunciation', $key);
        $this->assertStringContainsString('Omission', $key);

        // The old plain-English labels were not Phil-IRI terms.
        $this->assertStringNotContainsString('Mispronounced', $key);
        $this->assertStringNotContainsString('Skipped', $key);
    }

    public function test_plain_english_is_kept_as_the_hint_behind_each_term(): void
    {
        $html = $this->resultPage($this->scored([
            'word_comparison' => $this->comparisonRows(),
            'miscues' => $this->counts(['correct' => 3, 'omission' => 1, 'not_reached' => 2]),
        ]));

        // The official term labels it; the explanation is still a hover away.
        $this->assertStringContainsString('Left the word out', $html);
    }

    public function test_terms_the_engine_cannot_detect_are_not_listed(): void
    {
        $html = $this->resultPage($this->scored([
            'word_comparison' => $this->comparisonRows(),
            'miscues' => $this->counts(['correct' => 3, 'omission' => 1, 'not_reached' => 2]),
        ]));

        // A permanent zero would read as "the child made none of these".
        $this->assertStringNotContainsString('Transposition', $html);
        $this->assertStringNotContainsString('Reversal', $html);
    }

    public function test_non_phil_iri_markers_keep_their_plain_wording(): void
    {
        $html = $this->resultPage($this->scored([
            'word_comparison' => $this->comparisonRows(),
            'miscues' => $this->counts(['correct' => 3, 'not_reached' => 2, 'unclear' => 1]),
        ]));

        $key = $this->between($html, 'comparison-key', 'ORIGINAL PASSAGE');

        $this->assertStringContainsString('Not reached', $key);
        $this->assertStringContainsString('Unsure how it sounded', $key);
    }

    // -- Weakness card keeps every piece of its content -----------------

    public function test_the_weakness_card_still_shows_everything_after_the_relayout(): void
    {
        $assessment = $this->scored([
            'accuracy_rate' => 62.0, 'reading_level' => 'frustration', 'primary_weakness' => 2,
            'confidence_score' => 0.44,
            'miscues' => $this->counts(['correct' => 9, 'mispronunciation' => 4, 'substitution' => 2]),
        ]);

        $html = $this->resultPage($assessment);

        $this->assertStringContainsString('Weakness Classification', $html);
        $this->assertStringContainsString('Skill Scores', $html);
        $this->assertStringContainsString('the model', $html);
        $this->assertStringContainsString('What the other skills show', $html);

        // All four skills are still named.
        foreach (['Phonemic Awareness', 'Decoding Accuracy', 'Oral Reading Fluency', 'Reading Comprehension'] as $skill) {
            $this->assertStringContainsString($skill, $html);
        }

        // Confidence moved to the header but is still reported.
        $this->assertStringContainsString('44% confidence', $html);
    }

    // -- The card explains the level the page actually presents ---------

    public function test_the_card_explains_word_reading_when_that_is_all_there_is(): void
    {
        $html = $this->resultPage($this->scored([
            'accuracy_rate' => 91.5, 'reading_level' => 'instructional',
        ]));

        $this->assertStringContainsString('Why this word reading level?', $html);
        $this->assertStringNotContainsString('How the two halves combine', $html);
    }

    public function test_the_card_and_the_tile_never_disagree(): void
    {
        // Decodes the passage well, understands almost none of it.
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            1, 5
        );

        $interpretation = app(ReadingInterpretationService::class)->for($assessment);
        $tile = $assessment->readingLevels()['overall'];

        // The card used to answer "Independent" beside a "Frustration" tile.
        $this->assertSame($tile['level'], $interpretation['level']);
        $this->assertSame('Frustration', $interpretation['label']);
        $this->assertSame('Why this reading level?', $interpretation['scope']);
    }

    public function test_the_card_names_the_half_that_decided_the_level(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            1, 5
        );

        $decision = app(ReadingInterpretationService::class)->for($assessment)['decision'];

        $this->assertSame('comprehension', $decision['decided_by']);
        $this->assertStringContainsString('Word reading was Independent (98%)', $decision['text']);
        $this->assertStringContainsString('comprehension at 20% is Frustration', $decision['text']);
    }

    public function test_weak_decoding_is_named_when_it_is_the_lower_half(): void
    {
        // Understands what little was read, but decoding collapsed.
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 55.0, 'reading_level' => 'frustration'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            5, 5
        );

        $decision = app(ReadingInterpretationService::class)->for($assessment)['decision'];

        $this->assertSame('word', $decision['decided_by']);
        $this->assertStringContainsString('Comprehension was Independent (100%)', $decision['text']);
    }

    public function test_the_comprehension_scale_is_shown_with_its_own_bands(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            3, 5
        );

        $html = $this->resultPage($assessment);

        $this->assertStringContainsString('How the two halves combine', $html);
        // Comprehension uses 59-79 / 80+, not the word-reading 90 / 97 bands.
        $this->assertStringContainsString('80% and above', $html);
        $this->assertStringContainsString('59–79%', $html);
    }

    public function test_the_rule_footer_covers_both_scales_when_both_were_measured(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            3, 5
        );

        $rule = app(ReadingInterpretationService::class)->for($assessment)['rule'];

        $this->assertStringContainsString('Phil-IRI word reading', $rule);
        $this->assertStringContainsString('Comprehension', $rule);
    }

    // -- Where a teacher's verdict lands --------------------------------

    public function test_an_override_sets_the_word_level_when_that_is_the_only_level(): void
    {
        // 98% is Independent on the numbers; the teacher knows better.
        $assessment = $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent']);

        AssessmentVerdict::create([
            'assessment_id' => $assessment->id,
            'decision' => AssessmentVerdict::DECISION_OVERRIDDEN,
            'final_reading_level' => 'instructional',
            'reason' => 'Read accurately but word by word, without understanding.',
            'decided_by' => $this->teacher->id,
            'decided_at' => now(),
        ]);

        $levels = $assessment->fresh(['result', 'verdict'])->readingLevels();

        // With no comprehension half there is no combined tile to carry the
        // verdict, so it belongs on the only level there is.
        $this->assertSame('instructional', $levels['word']['level']);
        $this->assertTrue($levels['word']['from_teacher']);
    }

    public function test_an_override_does_not_rewrite_the_evidence_behind_it(): void
    {
        $assessment = $this->withComprehension(
            $this->scored(['accuracy_rate' => 98.0, 'reading_level' => 'independent'],
                ['type' => ReadingMaterial::TYPE_COMPREHENSION]),
            1, 5
        );

        AssessmentVerdict::create([
            'assessment_id' => $assessment->id,
            'decision' => AssessmentVerdict::DECISION_OVERRIDDEN,
            'final_reading_level' => 'instructional',
            'reason' => 'The comprehension questions were read to the child badly.',
            'decided_by' => $this->teacher->id,
            'decided_at' => now(),
        ]);

        $levels = $assessment->fresh(['result', 'verdict', 'comprehensionAnswers'])->readingLevels();

        // The teacher set the overall level; the two halves still report what
        // was actually measured, which is what makes the override auditable.
        $this->assertSame('instructional', $levels['overall']['level']);
        $this->assertSame('independent', $levels['word']['level']);
        $this->assertSame('frustration', $levels['comprehension']['level']);
    }

    // ── How to read this result ───────────────────────────────────────

    public function test_the_page_explains_the_process_and_numbers_with_this_learners_figures(): void
    {
        $html = $this->resultPage($this->scored([
            'accuracy_rate' => 93.3, 'words_per_minute' => 88.0, 'reading_level' => 'instructional',
        ]));

        $this->assertStringContainsString('How to read this result', $html);
        $this->assertStringContainsString('How the result was produced', $html);
        $this->assertStringContainsString('What each number means', $html);
        $this->assertStringContainsString('93.3%', $html);
        // Grade 4 benchmark comes from config, not from the copy.
        $this->assertStringContainsString('<strong>' . config('bigkas.wpm_benchmarks.4.min') . '</strong>', $html);
    }

    public function test_the_faq_is_present_and_says_the_ai_does_not_decide_the_level(): void
    {
        $html = $this->resultPage($this->scored());

        $this->assertStringContainsString('Frequently asked questions', $html);
        $this->assertStringContainsString('Is the AI deciding the learner', $html);
        $this->assertStringContainsString('only suggests which skill looks weakest', $html);
    }

    public function test_the_comprehension_row_appears_only_when_questions_were_answered(): void
    {
        $html = $this->resultPage($this->scored());

        $this->assertStringNotContainsString('Questions answered correctly.', $html);
    }
}
