<?php

namespace Tests\Feature;

use App\Services\ReadingAnalyzerService;
use Database\Seeders\NotationMaterialSeeder;
use Tests\TestCase;

/**
 * A child who read the passage correctly must score 100%, whatever notation the
 * passage happens to use and whatever form the speech engine writes it in.
 *
 * Before this, the comparison lowercased the text and deleted every character
 * that was not a letter or digit - without putting a space in its place and
 * without touching digits. So a correct reading lost marks whenever the two
 * sides were written differently:
 *
 *   "Twenty-first century" read as "21st century"  -> substitution
 *   "20 pesos" read as "twenty pesos"              -> substitution
 *   "sari-sari" read as two words                  -> insertion + substitution
 *   a non-breaking space out of Word               -> two words glued into one
 *   a Cyrillic look-alike letter in the passage    -> "mispronunciation" of a
 *                                                     word that looks identical
 *
 * The errors are deducted from accuracy, from WPM (which counts correct words
 * only) and from the features sent to the model, so they could move a child's
 * reading level. Every case below is a reading with no miscues in it.
 */
class TextNormalizationTest extends TestCase
{
    /** Score a passage against what the engine wrote. */
    private function score(string $passage, string $heard, string $language = 'en'): array
    {
        $words = [];
        $t = 0.0;

        foreach (preg_split('/\s+/', trim($heard), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $words[] = ['word' => $w, 'start' => round($t, 2), 'end' => round($t + 0.4, 2), 'confidence' => 0.95];
            $t += 0.45;
        }

        return app(ReadingAnalyzerService::class)->analyze(
            ['text' => $heard, 'words' => $words, 'duration' => $t, 'language' => $language],
            $passage,
            $t,
            $language
        );
    }

    private function assertPerfect(string $passage, string $heard, string $language = 'en'): void
    {
        $analysis = $this->score($passage, $heard, $language);

        $miscues = array_values(array_filter(
            $analysis['word_comparison'] ?? [],
            fn ($w) => ($w['miscue'] ?? $w['status']) !== 'correct'
        ));

        $this->assertSame([], array_map(
            fn ($w) => ($w['miscue'] ?? $w['status']) . ': ' . var_export($w['reference'], true) . ' / ' . var_export($w['spoken'], true),
            $miscues
        ), 'a correct reading must produce no miscues');

        $this->assertSame(100.0, (float) $analysis['accuracy_rate']);
        $this->assertSame(0, $analysis['error_count']);
    }

    // ── Look-alike and invisible characters ───────────────────────────

    public function test_capitals_never_mattered_and_still_do_not(): void
    {
        $this->assertPerfect('Ang bata ay masaya', 'ang bata ay masaya', 'fil');
        $this->assertPerfect('Si Ana ay Masaya', 'si ana ay masaya', 'fil');
    }

    public function test_a_cyrillic_look_alike_letter_in_the_passage_is_not_a_mispronunciation(): void
    {
        // U+0410 instead of "A": the two words are identical on screen.
        $this->assertPerfect("\u{0410}ng bata ay masaya", 'Ang bata ay masaya', 'fil');
        $this->assertPerfect("\u{0430}fter the storm", 'after the storm');
    }

    public function test_a_non_breaking_space_does_not_glue_two_words_together(): void
    {
        $this->assertPerfect("children read the\u{00A0}marker", 'children read the marker');
    }

    public function test_a_curly_apostrophe_matches_a_straight_one(): void
    {
        $this->assertPerfect("the town\u{2019}s old marker", "the town's old marker");
        $this->assertPerfect("ang dalawampu\u{2019}t isang puno", "ang dalawampu't isang puno", 'fil');
    }

    public function test_a_soft_hyphen_and_zero_width_space_are_ignored(): void
    {
        $this->assertPerfect("the ma\u{00AD}chine helps", 'the machine helps');
        $this->assertPerfect("the ma\u{200B}chine helps", 'the machine helps');
    }

    // ── Hyphenated compounds ──────────────────────────────────────────

    public function test_a_hyphenated_compound_may_be_written_as_one_word_or_two(): void
    {
        $this->assertPerfect('our sari-sari store', 'our sari-sari store', 'fil');
        $this->assertPerfect('our sari-sari store', 'our sari sari store', 'fil');
        $this->assertPerfect('si Ella ay mag-aaral', 'si Ella ay mag aaral', 'fil');
        $this->assertPerfect('a well-known marker', 'a well known marker');
    }

    public function test_an_en_dash_is_a_word_break_too(): void
    {
        $this->assertPerfect("twenty\u{2013}first century", 'twenty first century');
    }

    // ── Numerals ──────────────────────────────────────────────────────

    public function test_the_reported_case_twenty_first_century_read_as_21st(): void
    {
        $this->assertPerfect('The twenty-first century began', 'The 21st century began');
        $this->assertPerfect('The 21st century began', 'The twenty-first century began');
    }

    public function test_a_number_written_as_digits_may_be_read_as_words(): void
    {
        $this->assertPerfect('He had twenty pesos', 'He had 20 pesos');
        $this->assertPerfect('He had 20 pesos', 'He had twenty pesos');
        $this->assertPerfect('We counted 48 trees', 'We counted forty-eight trees');
        $this->assertPerfect('In 30 days', 'In thirty days');
        $this->assertPerfect('the last 100 meters', 'the last one hundred meters');
    }

    public function test_an_ordinal_in_digits_may_be_read_as_words(): void
    {
        $this->assertPerfect('on the 21st of June', 'on the twenty-first of June');
        $this->assertPerfect('the 3rd group to finish', 'the third group to finish');
        $this->assertPerfect('the 1st bell', 'the first bell');
    }

    public function test_a_year_is_read_as_a_number(): void
    {
        $this->assertPerfect('In 2019, a storm came', 'In two thousand nineteen, a storm came');
    }

    public function test_filipino_numbers(): void
    {
        $this->assertPerfect('May 10 piso si Ben', 'May sampung piso si Ben', 'fil');
        $this->assertPerfect('ng 2 pahina', 'ng dalawang pahina', 'fil');
        $this->assertPerfect('May 21 punong mangga', "May dalawampu't isang punong mangga", 'fil');
        $this->assertPerfect('Nasa ika-21 na siglo', "Nasa ika-dalawampu't isa na siglo", 'fil');
    }

    public function test_currency_and_percent_marks_do_not_block_a_number(): void
    {
        $this->assertPerfect('it cost 50% of 20 pesos', 'it cost fifty percent of twenty pesos');
    }

    // ── Guard rails: a real miscue is still a miscue ───────────────────

    public function test_a_wrong_number_is_still_an_error(): void
    {
        $analysis = $this->score('He had 20 pesos', 'He had thirty pesos');

        $this->assertSame(1, $analysis['error_count']);
        $this->assertLessThan(100, (float) $analysis['accuracy_rate']);
    }

    public function test_a_wrong_word_is_still_an_error(): void
    {
        $analysis = $this->score('the brown dog ran', 'the brown cat ran');

        $this->assertSame(1, $analysis['error_count']);
    }

    public function test_an_omitted_word_is_still_an_error(): void
    {
        $analysis = $this->score('a well-known stone marker', 'a well-known marker');

        $this->assertSame(1, $analysis['error_count']);
        $this->assertSame(
            'omission',
            collect($analysis['word_comparison'])->firstWhere('reference', 'stone')['miscue'] ?? null
        );
    }

    public function test_a_long_digit_string_is_left_alone_rather_than_spelled_out(): void
    {
        // Not a quantity anyone reads as a number; both sides keep it as-is.
        $this->assertPerfect('call 09171234567 now', 'call 09171234567 now');
    }

    // ── The seeded passages ───────────────────────────────────────────

    /**
     * Every passage in the seeder must normalize to clean word tokens: no
     * digits, no look-alike letters and no leftover punctuation. If one of
     * these fails, that passage would be scored against a reading of it.
     */
    public function test_every_seeded_passage_normalizes_to_plain_words(): void
    {
        foreach ((new NotationMaterialSeeder())->passages() as $p) {
            $tokens = app(ReadingAnalyzerService::class)->normalizedTokens($p['content'], $p['language']);

            foreach ($tokens as $token) {
                $this->assertMatchesRegularExpression(
                    '/^[a-z\x{00F1}]+$/u',
                    $token,
                    "{$p['title']}: token " . var_export($token, true) . ' is not a plain lowercase word'
                );
            }

            $this->assertNotEmpty($tokens, "{$p['title']} produced no tokens");
        }
    }

    /** What the speech engine would plausibly write for a correct reading. */
    public static function seededReadings(): array
    {
        return [
            'en oral, numerals + hyphen' => ['Coins for Pan de Sal', 'en',
                'Our sari sari store opens at six in the morning. Aling Nena sells pan de sal for three pesos each. My brother and I bring twenty pesos every Monday. We buy six pieces and share them on the way to school.'],
            'en oral, ordinals' => ['The 21st of June', 'en',
                'Our class planted trees on the twenty-first of June. We were the third group to finish. Each pupil carried two small seedlings to the open field. Our teacher counted forty-eight trees in all. She said they will give shade by the time we reach Grade six.'],
            'en oral, curly apostrophe + NBSP' => ["The Town's Old Marker", 'en',
                "Our town's plaza has a well known stone marker. It honors a brave farmer who warned the people before a flood. Long ago travellers rested in its shade. Today, children read the marker after school and learn his story."],
            'en oral, the reported case' => ['The Twenty-First Century', 'en',
                'We live in the 21st century. Machines help farmers plant and harvest. Mobile phones carry news across the country in seconds. Still, our teacher reminds us that reading remains the most useful skill. A pupil who reads well can learn almost anything.'],
            'en oral, look-alike letter' => ['A Ten-Minute Habit', 'en',
                'A ten minute habit can change a whole school year. Read one page after supper, then another the next night. In thirty days you will have finished a short book. Reading is not a race, so keep a slow and steady pace.'],
            'fil oral, look-alike letter at the start' => ['Ang Tindahan ni Lola', 'fil',
                'Ang tindahan ni Lola ay bukas tuwing umaga. May tinapay siyang tatlong piso at kendi na isang piso. Tuwing Sabado, binibigyan niya kami ng sampung piso. Masaya kaming bumibili ng sari sari niyang paninda.'],
            'fil oral, hyphenated compounds' => ['Masipag na Mag-aaral', 'fil',
                'Si Ella ay mag aaral sa Baitang apat. Tuwing hapon, nagbabasa siya ng dalawang pahina ng kuwento. Tuwang tuwa ang kaniyang ina sa kaniyang sipag. Sa loob ng tatlumpung araw, natapos niya ang isang maliit na aklat.'],
            'fil comp, numerals' => ['Ang 10 Piso ni Ben', 'fil',
                'May sampung piso si Ben na ibinigay ng kaniyang lolo. Pumunta siya sa sari sari store upang bumili ng tinapay. Nakita niyang umiiyak ang kaniyang kaibigan dahil nawala ang baon nito. Ibinahagi ni Ben ang tinapay. Ngumiti silang dalawa.'],
            'en comp, year + look-alike' => ['Twenty-First Century Farmers', 'en',
                'Twenty first century farmers read the weather on a phone. After a warning, they harvest early and save the crop. In two thousand nineteen, a storm ruined the rice of our village. Today the same warning arrives three days ahead, and the harvest is saved.'],
        ];
    }

    /**
     * A correct reading of a seeded passage, in the notation a speech engine
     * would produce, scores 100%.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('seededReadings')]
    public function test_a_seeded_passage_read_correctly_scores_full_marks(string $title, string $language, string $heard): void
    {
        $passage = collect((new NotationMaterialSeeder())->passages())->firstWhere('title', $title);

        $this->assertNotNull($passage, "no seeded passage titled {$title}");

        $this->assertPerfect($passage['content'], $heard, $language);
    }
}
