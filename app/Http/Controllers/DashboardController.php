<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
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

        // Teacher / Parent dashboard
        $learners = $user->learners()->orderBy('last_name')->get();
        $stats = $user->getStats();
        $recentAssessments = Assessment::forUser($user->id)
            ->with(['learner', 'material', 'result'])
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard.index', compact('stats', 'learners', 'recentAssessments'));
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
