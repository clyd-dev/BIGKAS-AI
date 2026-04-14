<?php

namespace Database\Seeders;

use App\Models\ComprehensionQuestion;
use Illuminate\Database\Seeder;

class ComprehensionQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            // For "My Pet Dog" (material_id=1)
            ['material_id' => 1, 'question' => 'What is the name of the dog?', 'question_type' => 'literal', 'correct_answer' => 'Max', 'option_a' => 'Max', 'option_b' => 'Rex', 'option_c' => 'Buddy', 'option_d' => 'Spot', 'sort_order' => 1],
            ['material_id' => 1, 'question' => 'What color is the dog?', 'question_type' => 'literal', 'correct_answer' => 'Brown', 'option_a' => 'White', 'option_b' => 'Brown', 'option_c' => 'Black', 'option_d' => 'Yellow', 'sort_order' => 2],
            ['material_id' => 1, 'question' => 'Where does the dog run?', 'question_type' => 'literal', 'correct_answer' => 'In the park', 'option_a' => 'In the house', 'option_b' => 'In the park', 'option_c' => 'In the school', 'option_d' => 'In the garden', 'sort_order' => 3],
            ['material_id' => 1, 'question' => 'What game do they play?', 'question_type' => 'literal', 'correct_answer' => 'Catch', 'option_a' => 'Hide and seek', 'option_b' => 'Tag', 'option_c' => 'Catch', 'option_d' => 'Running', 'sort_order' => 4],
            ['material_id' => 1, 'question' => 'How does the narrator feel about Max?', 'question_type' => 'inferential', 'correct_answer' => 'The narrator loves Max', 'option_a' => 'Angry', 'option_b' => 'Sad', 'option_c' => 'The narrator loves Max', 'option_d' => 'Scared', 'sort_order' => 5],

            // For "Ang Pusa Ko" (material_id=12 — 11 English + 1st Filipino)
            ['material_id' => 12, 'question' => 'Ano ang pangalan ng pusa?', 'question_type' => 'literal', 'correct_answer' => 'Muning', 'option_a' => 'Muning', 'option_b' => 'Mingming', 'option_c' => 'Pusang', 'option_d' => 'Kuting', 'sort_order' => 1],
            ['material_id' => 12, 'question' => 'Ano ang kulay ng pusa?', 'question_type' => 'literal', 'correct_answer' => 'Puti', 'option_a' => 'Itim', 'option_b' => 'Puti', 'option_c' => 'Kayumanggi', 'option_d' => 'Pula', 'sort_order' => 2],
            ['material_id' => 12, 'question' => 'Saan natutulog si Muning?', 'question_type' => 'literal', 'correct_answer' => 'Sa ilalim ng mesa', 'option_a' => 'Sa kama', 'option_b' => 'Sa ilalim ng mesa', 'option_c' => 'Sa labas', 'option_d' => 'Sa upuan', 'sort_order' => 3],
        ];

        foreach ($questions as $question) {
            ComprehensionQuestion::create($question);
        }
    }
}
