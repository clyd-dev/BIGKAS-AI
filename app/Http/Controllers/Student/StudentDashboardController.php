<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AssessmentSession;
use App\Models\Badge;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Services\BadgeService;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function index(Request $request)
    {
        $learner = $request->attributes->get('learner');

        // Check for active assessment session
        $activeSession = $learner->getActiveSession();

        // Recent badges (last 5)
        $recentBadges = $learner->badges()->limit(5)->get();
        $totalBadges = $learner->getBadgeCount();
        $allBadgesCount = Badge::active()->count();

        // Pending activities (approved interventions)
        $pendingActivities = InterventionLog::where('learner_id', $learner->id)
            ->where('status', 'pending')
            ->with('intervention')
            ->latest()
            ->limit(5)
            ->get();

        // Stats
        $stats = $learner->getStats();

        // Check & award any new badges
        app(BadgeService::class)->checkAndAward($learner);

        return view('student.dashboard', compact(
            'learner',
            'activeSession',
            'recentBadges',
            'totalBadges',
            'allBadgesCount',
            'pendingActivities',
            'stats'
        ));
    }
}
