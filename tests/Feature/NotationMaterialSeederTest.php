<?php

namespace Tests\Feature;

use App\Models\ComprehensionQuestion;
use App\Models\ReadingMaterial;
use Database\Seeders\NotationMaterialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The notation passages: five for word reading and five for comprehension in
 * each language, every one of them carrying text that used to be scored as a
 * miscue. TextNormalizationTest covers the scoring; this covers the data.
 */
class NotationMaterialSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_word_reading_and_five_comprehension_passages_per_language(): void
    {
        $this->seed(NotationMaterialSeeder::class);

        foreach (['en', 'fil'] as $language) {
            $set = ReadingMaterial::where('language', $language);

            $this->assertSame(5, (clone $set)->where('type', 'oral_reading')->count(), "$language word reading");
            $this->assertSame(5, (clone $set)->where('type', 'comprehension')->count(), "$language comprehension");
        }
    }

    public function test_every_grade_3_to_6_is_covered_for_both_types_and_languages(): void
    {
        $this->seed(NotationMaterialSeeder::class);

        foreach ([3, 4, 5, 6] as $grade) {
            foreach (['en', 'fil'] as $language) {
                foreach (['oral_reading', 'comprehension'] as $type) {
                    $this->assertGreaterThanOrEqual(
                        1,
                        ReadingMaterial::where('grade_level', $grade)->where('language', $language)->where('type', $type)->count(),
                        "G$grade $language $type"
                    );
                }
            }
        }
    }

    public function test_passages_are_short_and_comprehension_has_four_valid_questions(): void
    {
        $this->seed(NotationMaterialSeeder::class);

        foreach (ReadingMaterial::all() as $m) {
            $this->assertLessThanOrEqual(NotationMaterialSeeder::MAX_WORDS, NotationMaterialSeeder::countWords($m->content), $m->title);
            $this->assertSame(NotationMaterialSeeder::countWords($m->content), $m->word_count, $m->title);
            $this->assertStringContainsString('notation test', (string) $m->source, $m->title);

            if ($m->type !== 'comprehension') {
                $this->assertCount(0, $m->comprehensionQuestions, $m->title);

                continue;
            }

            $questions = $m->comprehensionQuestions;
            $this->assertCount(4, $questions, $m->title);

            foreach ($questions as $q) {
                $options = [$q->option_a, $q->option_b, $q->option_c, $q->option_d];
                $this->assertCount(4, array_unique($options), $q->question);
                $this->assertContains($q->correct_answer, $options, $q->question);
            }
        }
    }

    /**
     * Each passage carries at least one of the hazards it claims, in the text
     * itself - so a passage cannot quietly lose the thing it exists to test.
     */
    public function test_each_passage_really_contains_the_notation_it_claims(): void
    {
        $markers = [
            'numerals' => '/\d/u',
            // letter-letter ("sari-sari") or letter-digit ("ika-21")
            'hyphen' => '/[\p{L}\p{N}]-[\p{L}\p{N}]/u',
            'curly apostrophe' => "/\u{2019}/u",
            'non-breaking space' => "/\u{00A0}/u",
            'look-alike' => '/[\x{0400}-\x{04FF}]/u',
        ];

        foreach ((new NotationMaterialSeeder())->passages() as $p) {
            $found = array_keys(array_filter(
                $markers,
                fn ($pattern) => (bool) preg_match($pattern, $p['content'])
            ));

            $this->assertNotEmpty($found, "{$p['title']} contains none of the notation it is for");

            foreach ($markers as $name => $pattern) {
                if (str_contains($p['hazards'], $name)) {
                    $this->assertContains($name, $found, "{$p['title']} claims {$name} but has none");
                }
            }
        }
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $this->seed(NotationMaterialSeeder::class);
        $materials = ReadingMaterial::count();
        $questions = ComprehensionQuestion::count();

        $this->seed(NotationMaterialSeeder::class);

        $this->assertSame($materials, ReadingMaterial::count());
        $this->assertSame($questions, ComprehensionQuestion::count());
    }
}
