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
            $learners = Learner::all();
            $classes  = SchoolClass::with(['school', 'teacher'])->withCount('learners')->get();
        } else {
            $learners = $user->learners;
            $classes  = SchoolClass::where('teacher_id', $user->id)
                ->with('school')
                ->withCount('learners')
                ->get();
        }

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
        $assessments = $learner->assessments()->with(['material', 'result'])->latest()->get();

        // Map skill breakdown keys to match view (phonemic, decoding, fluency, comprehension)
        $rawSkill = $learner->getSkillBreakdown();
        $skillScores = [
            'phonemic'      => $rawSkill['phonemic_awareness'] ?? 0,
            'decoding'      => $rawSkill['decoding'] ?? 0,
            'fluency'       => $rawSkill['fluency'] ?? 0,
            'comprehension' => $rawSkill['comprehension'] ?? 0,
        ];

        return view('reports.learner', compact(
            'learner', 'assessmentResults', 'progressData', 'interventionLogs',
            'stats', 'skillScores', 'assessments',
            'progressDates', 'progressAccuracy', 'progressWpm'
        ));
    }

    public function classReport(SchoolClass $class)
    {
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

