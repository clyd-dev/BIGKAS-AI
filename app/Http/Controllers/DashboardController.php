<?php

namespace App\Http\Controllers;

use App\Support\Directory;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Services\ClassWatchlistService;
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
        $learners = Directory::paginate(Directory::sortLearners((clone $learnersQuery)->get()), 10);
        $learnerIds = (clone $learnersQuery)->pluck('learners.id');
        
        $baseStats = $user->getStats();

        // 2. Query Optimization: Fetch aggregates in a single query
        $avgAccuracy = 0;
        $avgWpm = 0;
        if ($learnerIds->isNotEmpty()) {
            $aggregates = AssessmentResult::measured()->whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
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

        // 4. Who needs attention, and why. Frustration level is only one of the
        //    signals; see ClassWatchlistService for the rest.
        $watchlist = app(ClassWatchlistService::class)->build($learnersQuery, 5);

        // 5. Action Center: every intervention still open, oldest activity first.
        //    In-progress logs belong here too - those are the ones being tracked.
        $openInterventions = InterventionLog::whereIn('learner_id', $learnerIds)
            ->whereIn('status', [InterventionLog::STATUS_PENDING, InterventionLog::STATUS_IN_PROGRESS])
            ->with(['learner', 'intervention'])
            ->orderByRaw('COALESCE(started_at, created_at) asc')
            ->limit(6)
            ->get();

        $interventionCounts = InterventionLog::whereIn('learner_id', $learnerIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('dashboard.index', compact(
            'stats', 'learners', 'recentAssessments', 'distribution',
            'watchlist', 'openInterventions', 'interventionCounts'
        ));
    }

    /**
     * The single admin (principal) page: key counts with links to manage each area,
     * what needs attention, school-wide reading weaknesses, and recent activity.
     */
    private function adminDashboard($user)
    {
        $stats = [
            'total_users'         => \App\Models\User::count(),
            'total_teachers'      => \App\Models\User::where('role', 'teacher')->count(),
            'total_learners'      => Learner::count(),
            'total_assessments'   => Assessment::count(),
            'total_materials'     => \App\Models\ReadingMaterial::where('is_active', true)->count(),
            'total_interventions' => \App\Models\Intervention::where('is_active', true)->count(),
        ];

        // School-wide weaknesses, read the way every other page reads them:
        // a teacher's verdict wins, invalid readings are left out, and a reading
        // the model could not classify is counted as awaiting, not as "none".
        $weaknessTotals = AssessmentResult::weaknessCounts();
        $counts = $weaknessTotals['counts'];
        $awaitingClassification = $weaknessTotals['awaiting'];

        $categories = config('bigkas.weakness_categories', [
            1 => ['name' => 'Phonemic Awareness'],
            2 => ['name' => 'Decoding'],
            3 => ['name' => 'Fluency'],
            4 => ['name' => 'Comprehension'],
        ]);
        $weaknessLabels = [];
        $weaknessData   = [];
        foreach ($categories as $id => $cat) {
            $weaknessLabels[] = $cat['name'];
            $weaknessData[]   = $counts[$id] ?? 0;
        }

        $progress = \App\Services\SchoolReadingProgress::summary();
        $pendingTeachers = \App\Models\User::where('role', 'teacher')->where('is_active', true)->whereDoesntHave('taughtClasses')->count();
        $pendingReports = \App\Models\ClassReport::where('status', \App\Models\ClassReport::STATUS_SUBMITTED)->count();

        $sectionsNoAdviser = \App\Models\SchoolClass::where('is_active', true)->whereNull('teacher_id')->count();
        $frustration       = Learner::where('reading_level', 'frustration')->count();
        $rescore           = app(\App\Services\RescoreQueue::class);
        $unscored          = $rescore->waiting()->count();
        $gaveUp            = $rescore->givenUp()->count();

        // Most urgent first. danger/warning = something is waiting on the principal; info = for awareness.
        $attention = collect([
            ['key' => 'teachers', 'severity' => 'danger', 'icon' => 'bi-person-exclamation',
                'title' => 'Teachers waiting for a grade & section', 'count' => $pendingTeachers,
                'detail' => "They can't add learners or conduct assessments until you assign one.",
                'url' => route('admin.users', ['role' => 'teacher', 'unassigned' => 1]), 'action' => 'Assign now'],
            ['key' => 'reports', 'severity' => 'warning', 'icon' => 'bi-inbox',
                'title' => 'Teacher reports waiting for review', 'count' => $pendingReports,
                'detail' => 'Submitted section reports are waiting for you to review or return.',
                'url' => route('reports.submissions.index'), 'action' => 'Review'],
            ['key' => 'sections', 'severity' => 'warning', 'icon' => 'bi-diagram-3',
                'title' => 'Sections without an adviser', 'count' => $sectionsNoAdviser,
                'detail' => 'No teacher is assigned, so nobody can add learners or assess in these sections.',
                'url' => route('admin.classes'), 'action' => 'Open Classrooms'],
            ['key' => 'frustration', 'severity' => 'info', 'icon' => 'bi-heart-pulse',
                'title' => 'Learners at Frustration level', 'count' => $frustration,
                'detail' => 'These learners need extra reading support. Check their interventions.',
                'url' => route('learners.index', ['reading_level' => 'frustration']), 'action' => 'View learners'],
            ['key' => 'unscored', 'severity' => 'warning', 'icon' => 'bi-hourglass-split',
                'title' => 'Recordings waiting to be scored', 'count' => $unscored,
                'detail' => 'Saved while the speech engine or model was down. They are scored automatically once it is back.',
                'url' => route('admin.system'), 'action' => 'See queue'],
            ['key' => 'unscorable', 'severity' => 'danger', 'icon' => 'bi-exclamation-octagon',
                'title' => 'Recordings that could not be scored', 'count' => $gaveUp,
                'detail' => 'Retried several times and still failing. The teacher can press Analyze, or the child can read again.',
                'url' => route('admin.system'), 'action' => 'Review'],
        ])->filter(fn ($a) => $a['count'] > 0)->values();

        $recentActivity = \App\Models\ActivityLog::with('user')->latest()->limit(10)->get();
        $school         = \App\Models\School::orderBy('id')->first();

        return view('dashboard.admin', compact('stats', 'weaknessLabels', 'weaknessData', 'awaitingClassification', 'pendingReports', 'pendingTeachers', 'progress', 'attention', 'recentActivity', 'school'));
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
