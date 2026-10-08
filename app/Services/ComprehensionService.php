<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\ComprehensionQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the comprehension test taken after a learner finishes reading.
 *
 * Both entry points use this one method — the teacher panel (answers posted
 * alongside Analyze) and the student portal (answers posted with their audio) —
 * so a score can't be computed two different ways.
 */
class ComprehensionService
{
    /**
     * @param  array<int|string, string>  $answers  question id => chosen option letter (A-D)
     * @return float  percentage correct (0-100)
     */
    public function record(Assessment $assessment, array $answers): float
    {
        $questions = $assessment->material
            ? $assessment->material->comprehensionQuestions()->orderBy('sort_order')->get()
            : collect();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'This material has no comprehension questions.',
            ]);
        }

        $missing = $questions->reject(fn (ComprehensionQuestion $q) => filled($answers[$q->id] ?? null));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'Please answer all ' . $questions->count() . ' comprehension questions.',
            ]);
        }

        $correct = 0;

        DB::transaction(function () use ($assessment, $questions, $answers, &$correct) {
            // Re-taking the test replaces the previous attempt.
            $assessment->comprehensionAnswers()->delete();

            foreach ($questions as $question) {
                $letter = strtoupper(trim((string) $answers[$question->id]));
                $selectedText = $question->getOptionText($letter);
                $isCorrect = $selectedText !== null && $question->isCorrect($selectedText);

                if ($isCorrect) {
                    $correct++;
                }

                $assessment->comprehensionAnswers()->create([
                    'question_id' => $question->id,
                    'question_text' => $question->question,
                    'selected_option' => in_array($letter, ['A', 'B', 'C', 'D'], true) ? $letter : null,
                    'selected_text' => $selectedText,
                    'correct_text' => $question->correct_answer,
                    'is_correct' => $isCorrect,
                    'created_at' => now(),
                ]);
            }
        });

        return round(($correct / $questions->count()) * 100, 2);
    }
}
