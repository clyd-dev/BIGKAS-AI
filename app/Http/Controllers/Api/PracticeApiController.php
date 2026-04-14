<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PracticeSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PracticeApiController extends Controller
{
    /**
     * List available practice activities
     */
    public function activities(): JsonResponse
    {
        $activities = [
            [
                'id' => 'phonemic_awareness',
                'name' => 'Phonemic Awareness',
                'description' => 'Practice identifying and manipulating individual sounds in words.',
                'icon' => 'ear',
                'categories' => [
                    'sound_matching' => 'Sound Matching',
                    'rhyming' => 'Rhyming Words',
                    'syllable_counting' => 'Syllable Counting',
                    'phoneme_blending' => 'Phoneme Blending',
                ],
            ],
            [
                'id' => 'sight_words',
                'name' => 'Sight Words',
                'description' => 'Practice recognizing common sight words by grade level.',
                'icon' => 'eye',
                'categories' => [
                    'pre_primer' => 'Pre-Primer',
                    'primer' => 'Primer',
                    'grade_1' => 'Grade 1',
                    'grade_2' => 'Grade 2',
                    'grade_3' => 'Grade 3',
                ],
            ],
            [
                'id' => 'guided_reading',
                'name' => 'Guided Reading',
                'description' => 'Practice reading passages with adjustable font size and pacing.',
                'icon' => 'book',
                'categories' => [
                    'narrative' => 'Narrative',
                    'informational' => 'Informational',
                    'literary' => 'Literary',
                    'poetry' => 'Poetry',
                ],
            ],
            [
                'id' => 'fluency',
                'name' => 'Oral Reading Fluency',
                'description' => 'Practice reading aloud to improve speed and expression.',
                'icon' => 'mic',
                'categories' => [
                    'timed_reading' => 'Timed Reading',
                    'repeated_reading' => 'Repeated Reading',
                    'echo_reading' => 'Echo Reading',
                ],
            ],
        ];

        return $this->success(['activities' => $activities]);
    }

    /**
     * Start a practice session
     */
    public function startSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'activity_type' => 'required|string',
            'activity_category' => 'nullable|string',
            'language' => 'nullable|string',
        ]);

        $session = PracticeSession::create([
            'learner_id' => $validated['learner_id'],
            'activity_type' => $validated['activity_type'],
            'activity_category' => $validated['activity_category'] ?? null,
            'language' => $validated['language'] ?? 'english',
            'status' => 'in_progress',
            'started_at' => now(),
            'started_by' => $request->user()->id,
        ]);

        return $this->success([
            'session_id' => $session->id,
            'activity_type' => $session->activity_type,
            'status' => 'in_progress',
        ], 'Practice session started', 201);
    }

    /**
     * Complete a practice session
     */
    public function completeSession(Request $request, PracticeSession $practiceSession): JsonResponse
    {
        $validated = $request->validate([
            'score' => 'nullable|numeric|min:0|max:100',
            'items_completed' => 'nullable|integer|min:0',
            'items_correct' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $practiceSession->status = 'completed';
        $practiceSession->completed_at = now();
        $practiceSession->score = $validated['score'] ?? null;
        $practiceSession->items_completed = $validated['items_completed'] ?? null;
        $practiceSession->items_correct = $validated['items_correct'] ?? null;
        $practiceSession->notes = $validated['notes'] ?? null;

        // Calculate duration
        if ($practiceSession->started_at) {
            $practiceSession->duration_seconds = now()->diffInSeconds($practiceSession->started_at);
        }

        $practiceSession->save();

        return $this->success([
            'session_id' => $practiceSession->id,
            'status' => 'completed',
            'score' => $practiceSession->score,
            'duration_seconds' => $practiceSession->duration_seconds,
        ], 'Practice session completed');
    }

    // ----- JSON helper methods -----

    protected function success(array $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
