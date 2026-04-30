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
        $learners = $user->learners()->orderBy('last_name')->get();
        $baseStats = $user->getStats();

        // Augment stats with avg_accuracy and avg_wpm across all teacher's learners
        $learnerIds = $learners->pluck('id');
        $avgAccuracy = $learnerIds->isEmpty() ? 0 :
            AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->avg('accuracy_rate') ?? 0;
        $avgWpm = $learnerIds->isEmpty() ? 0 :
            AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->avg('words_per_minute') ?? 0;

        $stats = array_merge($baseStats, [
            'avg_accuracy' => round((float) $avgAccuracy, 1),
            'avg_wpm'      => round((float) $avgWpm, 1),
        ]);

        // Reading level distribution for chart
        $distribution = [
            'independent'  => $learners->where('reading_level', 'independent')->count(),
            'instructional' => $learners->where('reading_level', 'instructional')->count(),
            'frustration'  => $learners->where('reading_level', 'frustration')->count(),
            'not_assessed' => $learners->whereNull('reading_level')->count(),
        ];

        $recentAssessments = Assessment::forUser($user->id)
            ->with(['learner', 'material', 'result'])
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard.index', compact('stats', 'learners', 'recentAssessments', 'distribution'));
    }

    private function adminDashboard($user)
    {
        $stats = [
            'total_users' => \App\Models\User::count(),
            'total_learners' => Learner::count(),
            'total_assessments' => Assessment::count(),
            'frustration_learners' => Learner::where('reading_level', 'frustration')->count(),
        ];

        $recentAssessments = Assessment::with(['learner', 'material', 'result', 'assessor'])
            ->latest()->limit(10)->get();

        return view('dashboard.admin', compact('stats', 'recentAssessments'));
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
