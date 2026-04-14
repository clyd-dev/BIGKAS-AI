<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Services\InterventionRecommenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterventionApiController extends Controller
{
    /**
     * List all active interventions
     */
    public function index(Request $request): JsonResponse
    {
        $query = Intervention::where('is_active', true)
            ->orderBy('target_weakness')
            ->orderBy('name');

        if ($weakness = $request->input('target_weakness')) {
            $query->where('target_weakness', (int) $weakness);
        }

        $interventions = $query->get()->map(fn ($i) => [
            'id' => $i->id,
            'name' => $i->name,
            'description' => $i->description,
            'target_weakness' => $i->target_weakness,
            'type' => $i->type,
            'difficulty' => $i->difficulty,
            'duration_minutes' => $i->duration_minutes,
            'instructions' => $i->instructions,
        ]);

        return $this->success(['interventions' => $interventions]);
    }

    /**
     * Show intervention details
     */
    public function show(Intervention $intervention): JsonResponse
    {
        return $this->success([
            'intervention' => [
                'id' => $intervention->id,
                'name' => $intervention->name,
                'description' => $intervention->description,
                'instructions' => $intervention->instructions,
                'target_weakness' => $intervention->target_weakness,
                'type' => $intervention->type,
                'difficulty' => $intervention->difficulty,
                'duration_minutes' => $intervention->duration_minutes,
            ],
        ]);
    }

    /**
     * Assign intervention to learner (create log)
     */
    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'intervention_id' => 'required|exists:interventions,id',
            'assessment_id' => 'nullable|exists:assessments,id',
            'notes' => 'nullable|string',
        ]);

        $log = InterventionLog::create([
            'learner_id' => $validated['learner_id'],
            'intervention_id' => $validated['intervention_id'],
            'assessment_id' => $validated['assessment_id'] ?? null,
            'assigned_by' => $request->user()->id,
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'assigned_at' => now(),
        ]);

        return $this->success([
            'log_id' => $log->id,
            'status' => 'pending',
        ], 'Intervention assigned successfully', 201);
    }

    /**
     * Update intervention log status
     */
    public function updateLog(Request $request, InterventionLog $interventionLog): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,skipped',
            'notes' => 'nullable|string',
        ]);

        $interventionLog->status = $validated['status'];
        $interventionLog->notes = $validated['notes'] ?? $interventionLog->notes;

        if ($validated['status'] === 'completed') {
            $interventionLog->completed_at = now();
        }

        $interventionLog->save();

        return $this->success([
            'log_id' => $interventionLog->id,
            'status' => $interventionLog->status,
        ], 'Intervention log updated');
    }

    /**
     * Get recommendations for a specific assessment
     */
    public function recommendations(
        Assessment $assessment,
        InterventionRecommenderService $recommender
    ): JsonResponse {
        $result = $assessment->results()->latest()->first();

        if (! $result) {
            return $this->error('Assessment results not found', 404);
        }

        $learner = $assessment->learner;

        $recommendations = $recommender->recommend(
            $result->primary_weakness ?? 1,
            $result->reading_level ?? 'frustration',
            $learner->grade_level ?? 1
        );

        return $this->success([
            'assessment_id' => $assessment->id,
            'primary_weakness' => $result->primary_weakness,
            'reading_level' => $result->reading_level,
            'recommendations' => $recommendations,
        ]);
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
