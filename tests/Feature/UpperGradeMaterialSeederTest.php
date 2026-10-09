<?php

namespace Tests\Feature;

use App\Models\ComprehensionQuestion;
use App\Models\ReadingMaterial;
use Database\Seeders\UpperGradeMaterialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpperGradeMaterialSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_grade_3_to_6_gets_word_reading_and_comprehension_in_both_languages(): void
    {
        $this->seed(UpperGradeMaterialSeeder::class);

        foreach ([3, 4, 5, 6] as $grade) {
            foreach (['en', 'fil'] as $language) {
                $set = ReadingMaterial::where('grade_level', $grade)->where('language', $language);
                $this->assertSame(2, (clone $set)->where('type', 'oral_reading')->count(), "G$grade $language word reading");
                $this->assertSame(2, (clone $set)->where('type', 'comprehension')->count(), "G$grade $language comprehension");
            }
        }
    }

    public function test_no_passage_is_longer_than_fifty_words_and_comprehension_has_four_valid_questions(): void
    {
        $this->seed(UpperGradeMaterialSeeder::class);

        foreach (ReadingMaterial::all() as $m) {
            $this->assertLessThanOrEqual(50, UpperGradeMaterialSeeder::countWords($m->content), $m->title);
            $this->assertSame(UpperGradeMaterialSeeder::countWords($m->content), $m->word_count, $m->title);

            if ($m->type === 'comprehension') {
                $qs = $m->comprehensionQuestions;
                $this->assertCount(4, $qs, $m->title);
                foreach ($qs as $q) {
                    $options = [$q->option_a, $q->option_b, $q->option_c, $q->option_d];
                    $this->assertCount(4, array_unique($options), $q->question);
                    $this->assertContains($q->correct_answer, $options, $q->question);
                }
            }
        }
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $this->seed(UpperGradeMaterialSeeder::class);
        $materials = ReadingMaterial::count();
        $questions = ComprehensionQuestion::count();

        $this->seed(UpperGradeMaterialSeeder::class);

        $this->assertSame($materials, ReadingMaterial::count());
        $this->assertSame($questions, ComprehensionQuestion::count());
    }
}
