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

        // What the card is offered on. A session is never completed anywhere in
        // this application, so getActiveSession() goes on returning one for ever
        // and the card used to stand there after the reading was read, sent and
        // scored - tapping it bounced off "No pending assessment found." The
        // reading itself is the honest test, and it is the same lookup the
        // child's own poll uses, so the two can never disagree.
        $pendingAssessment = StudentAssessmentController::pendingFor($learner);

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
            'pendingAssessment',
            'recentBadges',
            'totalBadges',
            'allBadgesCount',
            'pendingActivities',
            'stats'
        ));
    }
}
