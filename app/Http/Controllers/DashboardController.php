<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard($user);
        }

        if ($user->isStudent()) {
            return $this->studentDashboard($user);
        }

        if ($user->isParent()) {
            return redirect()->route('parent.dashboard');
        }

        // Teacher dashboard
        // 1. Scalability Fix: Don't fetch all learners at once for UI, use pagination
        $learnersQuery = $user->accessibleLearnersQuery();
        $learners = (clone $learnersQuery)->orderBy('last_name')->paginate(10);
        $learnerIds = (clone $learnersQuery)->pluck('learners.id');
        
        $baseStats = $user->getStats();

        // 2. Query Optimization: Fetch aggregates in a single query
        $avgAccuracy = 0;
        $avgWpm = 0;
        if ($learnerIds->isNotEmpty()) {
            $aggregates = AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->selectRaw('AVG(accuracy_rate) as avg_acc, AVG(words_per_minute) as avg_wpm')
                ->first();
            $avgAccuracy = $aggregates->avg_acc ?? 0;
            $avgWpm = $aggregates->avg_wpm ?? 0;
        }

        $stats = array_merge($baseStats, [
            'total_learners' => $learnerIds->count(),
            'avg_accuracy' => round((float) $avgAccuracy, 1),
            'avg_wpm'      => round((float) $avgWpm, 1),
        ]);

        // 3. Query Optimization: Group By for chart distribution (Avoid N+1 memory issues)
        $distributionData = (clone $learnersQuery)
            ->select('reading_level', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('reading_level')
            ->pluck('count', 'reading_level')
            ->toArray();

        $distribution = [
            'independent'  => $distributionData['independent'] ?? 0,
            'instructional' => $distributionData['instructional'] ?? 0,
            'frustration'  => $distributionData['frustration'] ?? 0,
            'not_assessed' => ($distributionData[''] ?? 0) + ($distributionData[null] ?? 0),
        ];

        $recentAssessments = Assessment::forUser($user)
            ->with(['learner', 'material', 'result'])
            ->latest()
            ->limit(5) // Reduced from 10 to keep dashboard clean
            ->get();

        // 4. Actionable Intelligence: At-Risk Students (Frustration level + ML Deficiencies)
        $atRiskLearners = (clone $learnersQuery)
            ->where('reading_level', 'frustration')
            ->with(['assessments' => function($query) {
                // Get the latest assessment result which contains the ML primary weakness
                $query->latest()->limit(1)->with('result');
            }])
            ->take(5)
            ->get();

        // 5. Actionable Intelligence: Pending Interventions for teacher's action center
        $pendingInterventions = \App\Models\InterventionLog::whereIn('learner_id', $learnerIds)
            ->where('status', 'pending')
            ->with(['learner', 'intervention'])
            ->latest()
            ->limit(5)
            ->get();

        return view('dashboard.index', compact(
            'stats', 'learners', 'recentAssessments', 'distribution', 'atRiskLearners', 'pendingInterventions'
        ));
    }

    private function adminDashboard($user)
    {
        $stats = [
            'total_users' => \App\Models\User::count(),
            'total_learners' => Learner::count(),
            'total_assessments' => Assessment::count(),
            'total_teachers' => \App\Models\User::where('role', 'teacher')->count(),
            'total_schools' => \App\Models\School::count(),
            'frustration_learners' => Learner::where('reading_level', 'frustration')->count(),
        ];

        $distributionData = Learner::select('reading_level', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('reading_level')
            ->pluck('count', 'reading_level')
            ->toArray();

        $distribution = [
            'independent'  => $distributionData['independent'] ?? 0,
            'instructional' => $distributionData['instructional'] ?? 0,
            'frustration'  => $distributionData['frustration'] ?? 0,
            'not_assessed' => ($distributionData[''] ?? 0) + ($distributionData[null] ?? 0),
        ];

        // Monthly Data (Last 6 months)
        $monthlyLabels = [];
        $monthlyCounts = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthlyLabels[] = $date->format('M');
            $monthlyCounts[] = Assessment::whereMonth('created_at', $date->month)
                ->whereYear('created_at', $date->year)
                ->count();
        }

        $recentAssessments = Assessment::with(['learner', 'material', 'result', 'assessor'])
            ->latest()->limit(10)->get();

        return view('dashboard.admin', compact('stats', 'recentAssessments', 'distribution', 'monthlyLabels', 'monthlyCounts'));
    }

    private function studentDashboard($user)
    {
        $learner = $user->linkedLearner();

        if (!$learner) {
            return view('dashboard.student-unlinked');
        }

        $stats = $learner->getStats();
        $recentAssessments = $learner->assessments()->with(['material', 'result'])->latest()->limit(5)->get();
        $skillBreakdown = $learner->getSkillBreakdown();
        $pendingInterventions = $learner->interventionLogs()
            ->where('status', 'pending')
            ->with('intervention')
            ->get();

        return view('dashboard.student', compact('learner', 'stats', 'recentAssessments', 'skillBreakdown', 'pendingInterventions'));
    }
}
