<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnerApiController extends Controller
{
    /**
     * List learners for authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Learner::query()->where('is_active', true);

        if ($user->role === 'teacher') {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        } elseif ($user->role === 'parent') {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }
        // admin sees all

        $learners = $query->orderBy('last_name')->orderBy('first_name')->get();

        return $this->success([
            'learners' => $learners->map(fn ($l) => $this->formatLearner($l))->toArray(),
        ]);
    }

    /**
     * Create a new learner
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|min:2',
            'last_name' => 'required|string|min:2',
            'middle_name' => 'nullable|string',
            'grade_level' => 'required|integer|min:1|max:6',
            'gender' => 'nullable|in:male,female',
            'birth_date' => 'nullable|date',
            'lrn' => 'nullable|string',
            'mother_tongue' => 'nullable|string',
            'school_id' => 'nullable|exists:schools,id',
            'class_id' => 'nullable|exists:classes,id',
        ]);

        $user = $request->user();

        $learner = Learner::create(array_merge($validated, [
            'school_id' => $validated['school_id'] ?? $user->school_id,
            'is_active' => true,
        ]));

        // Attach the creating user to the learner
        $learner->users()->attach($user->id, [
            'relationship' => $user->role === 'parent' ? 'parent' : 'teacher',
        ]);

        return $this->success([
            'learner' => $this->formatLearner($learner),
        ], 'Learner created successfully', 201);
    }

    /**
     * Show learner details
     */
    public function show(Learner $learner): JsonResponse
    {
        return $this->success([
            'learner' => $this->formatLearner($learner),
            'stats' => [
                'total_assessments' => $learner->assessments()->count(),
                'latest_reading_level' => $learner->reading_level,
            ],
        ]);
    }

    /**
     * Update learner
     */
    public function update(Request $request, Learner $learner): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|min:2',
            'last_name' => 'required|string|min:2',
            'middle_name' => 'nullable|string',
            'grade_level' => 'required|integer|min:1|max:6',
            'gender' => 'nullable|in:male,female',
            'birth_date' => 'nullable|date',
            'lrn' => 'nullable|string',
            'mother_tongue' => 'nullable|string',
        ]);

        $learner->update($validated);

        return $this->success([
            'learner' => $this->formatLearner($learner->fresh()),
        ], 'Learner updated successfully');
    }

    /**
     * Delete (deactivate) learner
     */
    public function destroy(Learner $learner): JsonResponse
    {
        $learner->update(['is_active' => false]);

        return $this->success([], 'Learner deactivated successfully');
    }

    /**
     * Get learner progress data
     */
    public function progress(Learner $learner): JsonResponse
    {
        $assessments = $learner->assessments()
            ->with('results')
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get();

        $progressData = $assessments->map(fn ($a) => [
            'date' => $a->created_at->toDateString(),
            'accuracy' => $a->results->first()?->accuracy_rate,
            'wpm' => $a->results->first()?->words_per_minute,
            'reading_level' => $a->results->first()?->reading_level,
        ]);

        return $this->success([
            'learner_id' => $learner->id,
            'progress' => $progressData,
        ]);
    }

    /**
     * Get learner's assessments
     */
    public function assessments(Learner $learner): JsonResponse
    {
        $results = $learner->assessments()
            ->with(['results', 'material'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'learner_id' => $learner->id,
            'assessments' => $results,
        ]);
    }

    /**
     * Format learner for API response
     */
    private function formatLearner(Learner $learner): array
    {
        return [
            'id' => $learner->id,
            'first_name' => $learner->first_name,
            'last_name' => $learner->last_name,
            'middle_name' => $learner->middle_name,
            'full_name' => $learner->full_name,
            'grade_level' => $learner->grade_level,
            'gender' => $learner->gender,
            'birth_date' => $learner->birth_date,
            'lrn' => $learner->lrn,
            'mother_tongue' => $learner->mother_tongue,
            'reading_level' => $learner->reading_level,
            'created_at' => $learner->created_at,
        ];
    }

    // ----- JSON helper methods -----

    protected function success(array $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];
        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
