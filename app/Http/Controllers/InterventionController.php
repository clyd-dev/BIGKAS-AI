<?php

namespace App\Http\Controllers;

use App\Models\Intervention;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class InterventionController extends Controller
{
    public function index(Request $request)
    {
        $query = Intervention::active();

        if ($request->filled('weakness')) {
            $query->forWeakness($request->weakness);
        }
        if ($request->filled('type')) {
            $query->where('activity_type', $request->type);
        }

        $interventions = $query->orderByDesc('effectiveness_score')->paginate(20);
        $weaknessCategories = config('bigkas.weakness_categories', []);

        return view('intervention.index', compact('interventions', 'weaknessCategories'));
    }

    public function show(Intervention $intervention)
    {
        $stats = $intervention->getStats();
        return view('intervention.show', compact('intervention', 'stats'));
    }

    public function assign(Request $request)
    {
        $request->validate([
            'intervention_id' => 'required|exists:interventions,id',
            'learner_id' => 'required|exists:learners,id',
            'assessment_result_id' => 'nullable|exists:assessment_results,id',
        ]);

        $log = InterventionLog::create([
            'intervention_id' => $request->intervention_id,
            'learner_id' => $request->learner_id,
            'assigned_by' => auth()->id(),
            'assessment_result_id' => $request->assessment_result_id,
            'status' => 'pending',
        ]);

        ActivityLog::log('assign_intervention', "Assigned intervention to learner #{$request->learner_id}", 'intervention_log', $log->id);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Intervention assigned.']);
        }

        return back()->with('success', 'Intervention assigned successfully.');
    }

    public function updateLog(Request $request, InterventionLog $interventionLog)
    {
        $action = $request->input('action');

        match ($action) {
            'start' => $interventionLog->start(),
            'complete' => $interventionLog->complete(
                $request->input('effectiveness_rating'),
                $request->input('notes')
            ),
            'skip' => $interventionLog->skip($request->input('reason')),
            default => null,
        };

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => "Intervention {$action}ed."]);
        }

        return back()->with('success', "Intervention status updated.");
    }

    public function learnerInterventions(Learner $learner)
    {
        $logs = $learner->interventionLogs()
            ->with(['intervention', 'assigner'])
            ->latest()
            ->paginate(20);

        return view('intervention.learner', compact('learner', 'logs'));
    }
}
