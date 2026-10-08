<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Models\SchoolClass;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use Illuminate\Http\Request;
use App\Traits\AuthorizesLearnerAccess;

class ReportController extends Controller
{
    use AuthorizesLearnerAccess;
    public function index()
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return $this->adminOverview();
        }

        $learners = $user->accessibleLearnersQuery()->get();

        if ($user->isParent()) {
            $latestByLearner = AssessmentResult::select('assessment_results.*', 'assessments.learner_id as owner_id')
                ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
                ->whereIn('assessments.learner_id', $learners->pluck('id'))
                ->with('assessment')
                ->orderByDesc('assessments.created_at')
                ->get()
                ->groupBy('owner_id')
                ->map->first();

            return view('parent.reports', compact('learners', 'latestByLearner'));
        }

        $classes  = SchoolClass::where('teacher_id', $user->id)
            ->with('school')
            ->withCount('learners')
            ->get();

        $learnerIds = $learners->pluck('id');

        $distribution = [
            'independent'  => $learners->where('reading_level', 'independent')->count(),
            'instructional' => $learners->where('reading_level', 'instructional')->count(),
            'frustration'  => $learners->where('reading_level', 'frustration')->count(),
            'not_assessed' => $learners->whereNull('reading_level')->count(),
        ];

        $assessed   = $learners->whereNotNull('reading_level')->count();
        $avgAccuracy = $learnerIds->isEmpty() ? 0 :
            AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->avg('accuracy_rate') ?? 0;
        $avgWpm = $learnerIds->isEmpty() ? 0 :
            AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->avg('words_per_minute') ?? 0;

        $stats = [
            'total_learners' => $learners->count(),
            'assessed'       => $assessed,
            'avg_accuracy'   => round((float) $avgAccuracy, 1),
            'avg_wpm'        => round((float) $avgWpm, 1),
        ];

        return view('reports.index', compact('distribution', 'learners', 'stats', 'classes'));
    }

    /**
     * Admin (principal): school-wide summary plus one row per section, instead of
     * a flat list of every learner.
     */
    private function adminOverview()
    {
        $levels = ['independent', 'instructional', 'frustration'];
        $total  = Learner::count();
        $distribution = [];
        foreach ($levels as $level) {
            $distribution[$level] = Learner::where('reading_level', $level)->count();
        }
        $distribution['not_assessed'] = Learner::whereNull('reading_level')->count();

        $stats = [
            'total_learners' => $total,
            'assessed'       => $total - $distribution['not_assessed'],
            'avg_accuracy'   => round((float) (AssessmentResult::avg('accuracy_rate') ?? 0), 1),
            'avg_wpm'        => round((float) (AssessmentResult::avg('words_per_minute') ?? 0), 1),
        ];

        $counts = ['learners'];
        foreach ($levels as $level) {
            $counts["learners as {$level}_count"] = fn ($q) => $q->where('reading_level', $level);
        }
        $counts['learners as assessed_count'] = fn ($q) => $q->whereNotNull('reading_level');

        $sections = SchoolClass::with('teacher')
            ->withCount($counts)
            ->orderBy('grade_level')->orderBy('section')
            ->paginate(10)
            ->withQueryString();

        $pendingReports = \App\Models\ClassReport::where('status', \App\Models\ClassReport::STATUS_SUBMITTED)->count();

        return view('reports.admin-index', compact('stats', 'distribution', 'sections', 'pendingReports'));
    }

    public function learnerReport(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);
        $assessmentResults = $learner->getAssessmentResults();
        $progressData      = $learner->getProgressData();
        $interventionLogs  = $learner->interventionLogs()->with(['intervention', 'assigner'])->latest()->get();
        $stats             = $learner->getStats();

        // Rename avg_accuracy key to match view expectation ($stats['avg_accuracy'])
        $stats['avg_accuracy'] = $stats['average_accuracy'] ?? 0;
        $stats['avg_wpm']      = $progressData->avg('words_per_minute') ?? 0;

        // Extract chart arrays from progress data (ordered chronologically)
        $progressDates    = $progressData->map(fn($r) => optional($r->assessment)->created_at?->format('M d') ?? '')->values()->toArray();
        $progressAccuracy = $progressData->pluck('accuracy_rate')->map(fn($v) => round((float)$v, 1))->values()->toArray();
        $progressWpm      = $progressData->pluck('words_per_minute')->map(fn($v) => (int)$v)->values()->toArray();

        // Pass assessments (Assessment models with result) for the history table
        $assessments = $learner->assessments()->with(['material', 'result'])->latest()->paginate(10)->withQueryString();

        // Map skill breakdown keys to match view (phonemic, decoding, fluency, comprehension)
        $rawSkill = $learner->getSkillBreakdown();
        $skillScores = [
            'phonemic'      => $rawSkill['phonemic_awareness'] ?? 0,
            'decoding'      => $rawSkill['decoding'] ?? 0,
            'fluency'       => $rawSkill['fluency'] ?? 0,
            'comprehension' => $rawSkill['comprehension'] ?? 0,
        ];

        // Parents get a plain-language, phone-friendly version of the report,
        // with the option to send it to the class teacher / admin.
        if (auth()->user()->isParent()) {
            $latestResult     = $progressData->last();
            $comparison       = $latestResult?->getComparisonWithPrevious();
            $reportRecipients = $learner->reportRecipients();

            return view('parent.report', compact(
                'learner', 'stats', 'skillScores', 'assessments',
                'latestResult', 'comparison', 'reportRecipients'
            ));
        }

        return view('reports.learner', compact(
            'learner', 'assessmentResults', 'progressData', 'interventionLogs',
            'stats', 'skillScores', 'assessments',
            'progressDates', 'progressAccuracy', 'progressWpm'
        ));
    }

    public function classReport(SchoolClass $class)
    {
        abort_unless(auth()->user()->isAdmin() || $class->teacher_id === auth()->id(), 403, 'This is not your class.');

        $class->load(['teacher', 'school']);
        $classLearners = $class->learners()->get();
        $learnerIds    = $classLearners->pluck('id');

        // Build flat learner rows for the view table
        $learners = $classLearners->map(function ($learner) {
            $latestResult = AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $learner->id))
                ->latest()->first();
            return [
                'id'            => $learner->id,
                'name'          => $learner->getFullName(),
                'reading_level' => $latestResult?->reading_level ?? $learner->reading_level,
                'accuracy_rate' => $latestResult ? number_format($latestResult->accuracy_rate, 1) : null,
                'words_per_minute' => $latestResult?->words_per_minute,
            ];
        });

        // Aggregate stats
        $totalLearners = $classLearners->count();
        $assessed      = $classLearners->whereNotNull('reading_level')->count();
        $avgAccuracy   = $learnerIds->isEmpty() ? 0 :
            AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->avg('accuracy_rate') ?? 0;
        $avgWpm = $learnerIds->isEmpty() ? 0 :
            AssessmentResult::whereHas('assessment', fn($q) => $q->whereIn('learner_id', $learnerIds))
                ->avg('words_per_minute') ?? 0;

        $avgAccuracy = round((float) $avgAccuracy, 1);
        $avgWpm      = round((float) $avgWpm, 1);

        $distribution = [
            'independent'  => $classLearners->where('reading_level', 'independent')->count(),
            'instructional' => $classLearners->where('reading_level', 'instructional')->count(),
            'frustration'  => $classLearners->where('reading_level', 'frustration')->count(),
            'not_assessed' => $classLearners->whereNull('reading_level')->count(),
        ];

        return view('reports.class', compact(
            'class', 'learners', 'totalLearners', 'assessed',
            'avgAccuracy', 'avgWpm', 'distribution'
        ));
    }

    public function printReport(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);
        $assessmentResults = $learner->getAssessmentResults();
        $stats = $learner->getStats();
        $skillBreakdown = $learner->getSkillBreakdown();

        return view('reports.print', compact('learner', 'assessmentResults', 'stats', 'skillBreakdown'));
    }

    public function downloadPdf(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);
        // TODO: Implement with DOMPDF/TCPDF
        return redirect()->route('reports.print', $learner);
    }
}

