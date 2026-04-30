<?php

namespace App\Http\Controllers\Parent;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Intervention;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Models\Message;
use Illuminate\Http\Request;

class ParentDashboardController extends Controller
{
    /**
     * Parent dashboard — overview of all linked children.
     */
    public function index()
    {
        $user = auth()->user();
        $learners = $user->learners()->orderBy('last_name')->get();

        // Aggregate stats
        $learnerIds = $learners->pluck('id');
        $totalAssessments = $learnerIds->isEmpty() ? 0 : Assessment::whereIn('learner_id', $learnerIds)->count();
        $pendingInterventions = $learnerIds->isEmpty() ? 0 :
            InterventionLog::whereIn('learner_id', $learnerIds)->where('status', 'pending')->count();
        $unreadMessages = Message::where('receiver_id', $user->id)->whereNull('read_at')->whereNull('parent_message_id')->count();

        $stats = [
            'total_children'       => $learners->count(),
            'total_assessments'    => $totalAssessments,
            'pending_interventions' => $pendingInterventions,
            'unread_messages'      => $unreadMessages,
        ];

        // Recent assessments across all children
        $recentAssessments = $learnerIds->isEmpty() ? collect() :
            Assessment::whereIn('learner_id', $learnerIds)
                ->with(['learner', 'material', 'result'])
                ->latest()
                ->limit(5)
                ->get();

        // Pending home interventions
        $pendingLogs = $learnerIds->isEmpty() ? collect() :
            InterventionLog::whereIn('learner_id', $learnerIds)
                ->whereIn('status', ['pending', 'in_progress'])
                ->with(['intervention', 'learner'])
                ->latest()
                ->limit(5)
                ->get();

        return view('parent.dashboard', compact('learners', 'stats', 'recentAssessments', 'pendingLogs'));
    }

    /**
     * Feature 2: Learner's Reading Profile — detailed view of a child.
     */
    public function learnerProfile(Learner $learner)
    {
        $this->authorizeParent($learner);

        $stats = $learner->getStats();
        $stats['avg_accuracy'] = $stats['average_accuracy'] ?? 0;

        // Avg WPM
        $progressData = $learner->getProgressData();
        $stats['avg_wpm'] = $progressData->avg('words_per_minute') ?? 0;

        // Skill breakdown
        $rawSkill = $learner->getSkillBreakdown();
        $skillScores = [
            'phonemic'      => $rawSkill['phonemic_awareness'] ?? 0,
            'decoding'      => $rawSkill['decoding'] ?? 0,
            'fluency'       => $rawSkill['fluency'] ?? 0,
            'comprehension' => $rawSkill['comprehension'] ?? 0,
        ];

        // Progress chart data
        $progressDates    = $progressData->map(fn($r) => optional($r->assessment)->created_at?->format('M d') ?? '')->values()->toArray();
        $progressAccuracy = $progressData->pluck('accuracy_rate')->map(fn($v) => round((float)$v, 1))->values()->toArray();
        $progressWpm      = $progressData->pluck('words_per_minute')->map(fn($v) => (int)$v)->values()->toArray();

        // Reading level distribution badge
        $levelInfo = $learner->getReadingLevelInfo();

        // Recent practice sessions
        $recentPractice = $learner->practiceSessions()->latest()->limit(5)->get();

        // Intervention history
        $interventionLogs = $learner->interventionLogs()
            ->with(['intervention', 'assigner'])
            ->latest()
            ->limit(10)
            ->get();

        return view('parent.learner-profile', compact(
            'learner', 'stats', 'skillScores', 'levelInfo',
            'progressDates', 'progressAccuracy', 'progressWpm',
            'recentPractice', 'interventionLogs'
        ));
    }

    /**
     * Feature 3: Assessment Results — all results for a child.
     */
    public function assessmentResults(Learner $learner)
    {
        $this->authorizeParent($learner);

        $assessments = $learner->assessments()
            ->with(['material', 'result', 'assessor'])
            ->latest()
            ->paginate(15);

        $stats = $learner->getStats();
        $stats['avg_accuracy'] = $stats['average_accuracy'] ?? 0;
        $progressData = $learner->getProgressData();
        $stats['avg_wpm'] = $progressData->avg('words_per_minute') ?? 0;

        return view('parent.assessment-results', compact('learner', 'assessments', 'stats'));
    }

    /**
     * Feature 3b: Single assessment result detail.
     */
    public function assessmentDetail(Learner $learner, Assessment $assessment)
    {
        $this->authorizeParent($learner);
        abort_unless($assessment->learner_id === $learner->id, 403);

        $assessment->load(['material', 'result', 'assessor']);
        $result = $assessment->result;

        $comparison = $result?->getComparisonWithPrevious();
        $errorBreakdown = $result?->getErrorBreakdown();
        $readingLevelInfo = $result?->getReadingLevelInfo();

        return view('parent.assessment-detail', compact(
            'learner', 'assessment', 'result',
            'comparison', 'errorBreakdown', 'readingLevelInfo'
        ));
    }

    /**
     * Feature 1: Home Intervention Activities — interventions assigned to a child.
     */
    public function interventions(Learner $learner)
    {
        $this->authorizeParent($learner);

        $logs = $learner->interventionLogs()
            ->with(['intervention', 'assigner'])
            ->latest()
            ->paginate(20);

        // Stats
        $totalAssigned = $learner->interventionLogs()->count();
        $completed     = $learner->interventionLogs()->where('status', 'completed')->count();
        $pending       = $learner->interventionLogs()->where('status', 'pending')->count();
        $inProgress    = $learner->interventionLogs()->where('status', 'in_progress')->count();

        $interventionStats = compact('totalAssigned', 'completed', 'pending', 'inProgress');

        return view('parent.interventions', compact('learner', 'logs', 'interventionStats'));
    }

    /**
     * Feature 1b: Update intervention log status (parent can start/complete home activities).
     */
    public function updateIntervention(Request $request, Learner $learner, InterventionLog $interventionLog)
    {
        $this->authorizeParent($learner);
        abort_unless($interventionLog->learner_id === $learner->id, 403);

        $action = $request->input('action');

        match ($action) {
            'start'    => $interventionLog->start(),
            'complete' => $interventionLog->complete(
                $request->input('effectiveness_rating'),
                $request->input('notes')
            ),
            default    => null,
        };

        return back()->with('success', 'Activity status updated.');
    }

    /**
     * Ensure the authenticated parent is linked to this learner.
     */
    private function authorizeParent(Learner $learner): void
    {
        $user = auth()->user();
        $linked = $learner->users()->where('users.id', $user->id)->exists();
        abort_unless($linked, 403, 'You are not linked to this learner.');
    }
}
