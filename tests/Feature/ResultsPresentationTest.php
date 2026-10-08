<?php

namespace Tests\Feature;

use App\Models\Assessment;
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
        $this->assertStringContainsString('Why this level?', $html);
    }
}
