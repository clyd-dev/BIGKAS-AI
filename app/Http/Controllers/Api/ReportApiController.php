<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Learner;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportApiController extends Controller
{
    /**
     * Get learner report data
     */
    public function learnerReport(Learner $learner): JsonResponse
    {
        $learner->load(['assessments.results', 'interventionLogs.intervention']);

        $assessmentResults = $learner->assessments
            ->where('status', 'completed')
            ->map(fn ($a) => [
                'id' => $a->id,
                'date' => $a->created_at->toDateString(),
                'accuracy' => $a->results->first()?->accuracy_rate,
                'wpm' => $a->results->first()?->words_per_minute,
                'reading_level' => $a->results->first()?->reading_level,
                'primary_weakness' => $a->results->first()?->primary_weakness,
            ]);

        $interventionLogs = $learner->interventionLogs->map(fn ($log) => [
            'id' => $log->id,
            'intervention_name' => $log->intervention?->name,
            'status' => $log->status,
            'assigned_at' => $log->assigned_at,
            'completed_at' => $log->completed_at,
        ]);

        return $this->success([
            'learner' => [
                'id' => $learner->id,
                'name' => $learner->full_name,
                'grade_level' => $learner->grade_level,
                'reading_level' => $learner->reading_level,
            ],
            'stats' => [
                'total_assessments' => $assessmentResults->count(),
                'avg_accuracy' => $assessmentResults->avg('accuracy') ?? 0,
                'avg_wpm' => $assessmentResults->avg('wpm') ?? 0,
            ],
            'assessment_results' => $assessmentResults->values(),
            'intervention_logs' => $interventionLogs->values(),
        ]);
    }

    /**
     * Get class report data
     */
    public function classReport(SchoolClass $schoolClass): JsonResponse
    {
        $learners = Learner::where('class_id', $schoolClass->id)
            ->with(['assessments' => fn ($q) => $q->where('status', 'completed')->latest()->with('results')])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $distribution = ['independent' => 0, 'instructional' => 0, 'frustration' => 0, 'not_assessed' => 0];
        $totalAccuracy = 0;
        $totalWpm = 0;
        $assessed = 0;

        $learnerData = $learners->map(function ($learner) use (&$distribution, &$totalAccuracy, &$totalWpm, &$assessed) {
            $latestAssessment = $learner->assessments->first();
            $latestResult = $latestAssessment?->results->first();

            $level = $latestResult?->reading_level;
            if ($level && isset($distribution[$level])) {
                $distribution[$level]++;
                $assessed++;
                $totalAccuracy += $latestResult->accuracy_rate ?? 0;
                $totalWpm += $latestResult->words_per_minute ?? 0;
            } else {
                $distribution['not_assessed']++;
            }

            return [
                'id' => $learner->id,
                'name' => $learner->full_name,
                'grade_level' => $learner->grade_level,
                'reading_level' => $level,
                'accuracy_rate' => $latestResult?->accuracy_rate,
                'words_per_minute' => $latestResult?->words_per_minute,
            ];
        });

        return $this->success([
            'class' => [
                'id' => $schoolClass->id,
                'name' => $schoolClass->name,
                'grade_level' => $schoolClass->grade_level,
            ],
            'total_learners' => $learners->count(),
            'assessed' => $assessed,
            'distribution' => $distribution,
            'avg_accuracy' => $assessed > 0 ? round($totalAccuracy / $assessed, 1) : 0,
            'avg_wpm' => $assessed > 0 ? round($totalWpm / $assessed, 0) : 0,
            'learners' => $learnerData,
        ]);
    }

    /**
     * Dashboard statistics
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Learner::query()->where('is_active', true);

        if ($user->role === 'teacher') {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        } elseif ($user->role === 'parent') {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        $learners = $query->get();

        $distribution = ['independent' => 0, 'instructional' => 0, 'frustration' => 0, 'not_assessed' => 0];
        foreach ($learners as $learner) {
            $level = $learner->reading_level ?? 'not_assessed';
            if (isset($distribution[$level])) {
                $distribution[$level]++;
            } else {
                $distribution['not_assessed']++;
            }
        }

        return $this->success([
            'stats' => [
                'total_learners' => $learners->count(),
                'assessed' => $learners->whereNotNull('reading_level')->count(),
            ],
            'distribution' => $distribution,
            'total_learners' => $learners->count(),
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
