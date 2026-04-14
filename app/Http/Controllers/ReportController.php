<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Models\SchoolClass;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            $learners = Learner::all();
        } else {
            $learners = $user->learners;
        }

        $distribution = [
            'independent' => $learners->where('reading_level', 'independent')->count(),
            'instructional' => $learners->where('reading_level', 'instructional')->count(),
            'frustration' => $learners->where('reading_level', 'frustration')->count(),
            'not_assessed' => $learners->whereNull('reading_level')->count(),
        ];

        return view('reports.index', compact('distribution', 'learners'));
    }

    public function learnerReport(Learner $learner)
    {
        $assessmentResults = $learner->getAssessmentResults();
        $progressData = $learner->getProgressData();
        $interventionLogs = $learner->interventionLogs()->with(['intervention', 'assigner'])->latest()->get();
        $stats = $learner->getStats();
        $skillBreakdown = $learner->getSkillBreakdown();

        return view('reports.learner', compact('learner', 'assessmentResults', 'progressData', 'interventionLogs', 'stats', 'skillBreakdown'));
    }

    public function classReport(SchoolClass $class)
    {
        $class->load(['teacher', 'school', 'learners']);
        $learners = $class->learners()->with(['assessments.result'])->get();

        $learnersWithResults = $learners->map(function ($learner) {
            $latestResult = AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $learner->id))
                ->latest()->first();

            return [
                'learner' => $learner,
                'latest_result' => $latestResult,
            ];
        });

        return view('reports.class', compact('class', 'learnersWithResults'));
    }

    public function printReport(Learner $learner)
    {
        $assessmentResults = $learner->getAssessmentResults();
        $stats = $learner->getStats();
        $skillBreakdown = $learner->getSkillBreakdown();

        return view('reports.print', compact('learner', 'assessmentResults', 'stats', 'skillBreakdown'));
    }

    public function downloadPdf(Learner $learner)
    {
        // TODO: Implement with DOMPDF/TCPDF
        return redirect()->route('reports.print', $learner);
    }
}
