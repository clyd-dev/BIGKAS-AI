<?php

namespace Tests\Feature;

use App\Services\ReadingAnalyzerService;
use Tests\TestCase;

class MiscueDetectionTest extends TestCase
{
    private const PASSAGE = 'The brown dog ran across the wide green field and barked at the little bird.';

    /** Build a transcription with evenly spaced word timings, like the speech service returns. */
    private function transcription(string $text, array $gapsBefore = [], array $confidence = []): array
    {
        $words = [];
        $clock = 0.0;

        foreach (preg_split('/\s+/', trim($text)) as $i => $word) {
            $clock += $gapsBefore[$i] ?? 0.1;
            $words[] = [
                'word' => $word,
                'start' => round($clock, 2),
                'end' => round($clock + 0.3, 2),
                'confidence' => $confidence[$i] ?? 0.95,
            ];
            $clock += 0.3;
        }

        return ['text' => $text, 'words' => $words, 'duration' => $clock];
    }

    private function analyze(string $spoken, array $gaps = [], array $confidence = [], array $extra = []): array
    {
        $transcription = array_merge($this->transcription($spoken, $gaps, $confidence), $extra);

        return app(ReadingAnalyzerService::class)->analyze($transcription, self::PASSAGE, 30);
    }

    private function miscueOf(array $analysis, string $spokenWord): ?string
    {
        foreach ($analysis['word_comparison'] as $entry) {
            if ($entry['spoken'] === $spokenWord && $entry['status'] !== 'correct') {
                return $entry['miscue'];
            }
        }

        return null;
    }

    public function test_a_perfect_reading_has_no_miscues(): void
    {
        $analysis = $this->analyze(self::PASSAGE);

        $this->assertSame(0, $analysis['error_count']);
        $this->assertSame(15, $analysis['miscues']['correct']);
        $this->assertSame(100.0, $analysis['accuracy_rate']);
    }

    public function test_self_correction_is_detected_and_not_counted_as_an_error(): void
    {
        // "feed... field": a wrong attempt, then the right word.
        $analysis = $this->analyze('The brown dog ran across the wide green feed field and barked at the little bird.');

        $this->assertSame('self_correction', $this->miscueOf($analysis, 'feed'));
        $this->assertSame(1, $analysis['self_corrections']);
        $this->assertSame(0, $analysis['insertions']);
        $this->assertSame(0, $analysis['error_count'], 'A fixed mistake is not a miscue.');
        $this->assertSame(100.0, $analysis['accuracy_rate']);
    }

    public function test_repeated_word_is_a_repetition_not_an_insertion(): void
    {
        $analysis = $this->analyze('The brown dog dog ran across the wide green field and barked at the little bird.');

        $this->assertSame(1, $analysis['repetitions']);
        $this->assertSame(0, $analysis['insertions'], 'Repetitions must not be double-counted as insertions.');
    }

    public function test_false_start_counts_as_a_repetition(): void
    {
        // "bar- barked"
        $analysis = $this->analyze('The brown dog ran across the wide green field and bar barked at the little bird.');

        $this->assertSame('repetition', $this->miscueOf($analysis, 'bar'));
        $this->assertSame(1, $analysis['repetitions']);
    }

    public function test_reread_phrase_is_repetition_not_off_passage_talk(): void
    {
        $analysis = $this->analyze('The brown dog ran across the wide green field across the wide green field and barked at the little bird.');

        $this->assertSame(5, $analysis['repetitions']);
        $this->assertSame(0, $analysis['miscues']['off_passage']);
    }

    public function test_sound_alike_replacement_is_a_mispronunciation(): void
    {
        // "ran" read as "run"
        $analysis = $this->analyze('The brown dog run across the wide green field and barked at the little bird.');

        $this->assertSame('mispronunciation', $this->miscueOf($analysis, 'run'));
        $this->assertSame(1, $analysis['miscues']['mispronunciation']);
        $this->assertSame(0, $analysis['miscues']['substitution']);
        // The combined column still carries it, as the training data expects.
        $this->assertSame(1, $analysis['substitutions']);
    }

    public function test_unrelated_replacement_is_a_substitution(): void
    {
        // "field" read as "house"
        $analysis = $this->analyze('The brown dog ran across the wide green house and barked at the little bird.');

        $this->assertSame('substitution', $this->miscueOf($analysis, 'house'));
        $this->assertSame(1, $analysis['miscues']['substitution']);
        $this->assertSame(0, $analysis['miscues']['mispronunciation']);
    }

    public function test_omission_and_insertion_are_detected(): void
    {
        // "green" skipped, "very" added
        $analysis = $this->analyze('The brown dog ran across the wide field and barked at the very little bird.');

        $this->assertSame(1, $analysis['omissions']);
        $this->assertSame(1, $analysis['insertions']);
        $this->assertSame('insertion', $this->miscueOf($analysis, 'very'));
    }

    public function test_words_after_the_reading_stopped_are_not_reached_rather_than_skipped(): void
    {
        // Skips "green" mid-passage, then stops after "field".
        $analysis = $this->analyze('The brown dog ran across the wide field');

        $this->assertSame(1, $analysis['miscues']['omission'], 'A word left out mid-sentence is a skip.');
        $this->assertSame(6, $analysis['miscues']['not_reached'], 'Words never attempted are a different thing.');

        // Unread is unread: both still count against the total, so accuracy is unchanged.
        $this->assertSame(7, $analysis['omissions']);
        $this->assertSame(53.3, $analysis['accuracy_rate']);

        $byWord = collect($analysis['word_comparison'])->keyBy('reference');
        $this->assertSame('omission', $byWord['green']['miscue']);
        $this->assertSame('not_reached', $byWord['bird']['miscue']);
    }

    public function test_repeated_phrase_in_the_passage_is_matched_where_the_reader_actually_was(): void
    {
        // "to school" occurs twice. A child who stops after the first one has
        // not skipped the middle of the passage and read its last two words.
        $passage = 'I walk to school with my mother. I see my friends. I love going to school.';
        $analysis = app(ReadingAnalyzerService::class)->analyze(
            $this->transcription('I walk to school'), $passage, 10
        );

        $this->assertSame(0, $analysis['miscues']['omission']);
        $this->assertSame(12, $analysis['miscues']['not_reached']);

        $first = array_slice($analysis['word_comparison'], 0, 4);
        $this->assertSame(['correct', 'correct', 'correct', 'correct'], array_column($first, 'miscue'));
    }

    public function test_alignment_keeps_as_many_correct_words_as_possible(): void
    {
        // Same number of edits either way; the reading with more matched words wins.
        $analysis = app(ReadingAnalyzerService::class)->analyze(
            $this->transcription('the cat sat on a the mat'), 'the cat sat on the mat', 10
        );

        $this->assertSame(6, $analysis['miscues']['correct']);
        $this->assertSame(100.0, $analysis['accuracy_rate']);
    }

    public function test_dropping_only_the_final_word_is_still_a_skip(): void
    {
        $analysis = $this->analyze('The brown dog ran across the wide green field and barked at the little');

        $this->assertSame(1, $analysis['miscues']['omission']);
        $this->assertSame(0, $analysis['miscues']['not_reached']);
    }

    public function test_pauses_are_located_and_graded(): void
    {
        // 1.5s before word 4 ("ran"), 4s before word 9 ("field")
        $analysis = $this->analyze(self::PASSAGE, [3 => 1.5, 8 => 4.0]);

        $this->assertSame(1, $analysis['miscues']['hesitation']);
        $this->assertSame(1, $analysis['miscues']['long_pause']);

        $byWord = collect($analysis['word_comparison'])->keyBy('reference');
        $this->assertSame('hesitation', $byWord['ran']['pause_kind']);
        $this->assertSame('long_pause', $byWord['field']['pause_kind']);
        $this->assertEqualsWithDelta(4.0, $byWord['field']['pause_before'], 0.01);
    }

    public function test_talk_before_reading_is_off_passage_not_insertions(): void
    {
        $analysis = $this->analyze('teacher can I start now ' . self::PASSAGE);

        $this->assertSame(5, $analysis['miscues']['off_passage']);
        $this->assertSame(0, $analysis['insertions']);
        $this->assertSame(0, $analysis['error_count']);
        $this->assertSame('teacher can i start now', $analysis['non_reading']['off_passage'][0]['text']);
        $this->assertSame(100.0, $analysis['accuracy_rate']);
    }

    public function test_sound_tags_are_reported_as_sounds_not_scored_as_words(): void
    {
        $analysis = $this->analyze('The brown dog [coughing] ran across the wide green field and barked at the little bird.');

        $this->assertSame(0, $analysis['insertions']);
        $this->assertSame(0, $analysis['error_count']);
        $this->assertSame('coughing', $analysis['non_reading']['sounds'][0]['label']);
    }

    public function test_unclear_words_are_flagged(): void
    {
        $analysis = $this->analyze(self::PASSAGE, [], [2 => 0.31]);

        $this->assertSame(1, $analysis['miscues']['unclear']);
    }

    public function test_segments_the_engine_doubted_are_surfaced(): void
    {
        $analysis = $this->analyze(self::PASSAGE, [], [], [
            'segments' => [
                ['text' => 'The brown dog', 'start' => 0, 'end' => 2, 'no_speech_prob' => 0.02],
                ['text' => 'thank you', 'start' => 9, 'end' => 11, 'no_speech_prob' => 0.81],
            ],
            'audio_quality' => ['measured' => true, 'rating' => 'noisy', 'snr_db' => 6.2],
        ]);

        $this->assertCount(1, $analysis['non_reading']['unreliable_segments']);
        $this->assertSame('thank you', $analysis['non_reading']['unreliable_segments'][0]['text']);
        $this->assertSame('noisy', $analysis['audio_quality']['rating']);
    }

    public function test_ml_feature_vector_is_unchanged_in_shape(): void
    {
        $analysis = $this->analyze('The brown dog dog run across the wide field and barked at the little bird.');

        $this->assertSame([
            'accuracy_rate', 'words_per_minute', 'fluency_score',
            'substitution_rate', 'omission_rate', 'insertion_rate',
            'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
            'self_correction_rate', 'pause_frequency', 'prosody_score',
        ], array_keys($analysis['ml_features']));
    }

    public function test_every_entry_keeps_the_original_four_way_status(): void
    {
        $analysis = $this->analyze('teacher can I start now The brown dog dog run across the wide feed field and barked at the bird.');

        foreach ($analysis['word_comparison'] as $entry) {
            $this->assertContains($entry['status'], ['correct', 'substitution', 'omission', 'insertion']);
            $this->assertArrayHasKey('miscue', $entry);
        }
    }
}
