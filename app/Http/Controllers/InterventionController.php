<?php

namespace App\Http\Controllers;

use App\Support\Directory;

use App\Models\Intervention;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Models\ActivityLog;
use App\Notifications\NewInterventionAssigned;
use Illuminate\Http\Request;
use App\Traits\AuthorizesLearnerAccess;

class InterventionController extends Controller
{
    use AuthorizesLearnerAccess;
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

        return view('interventions.index', compact('interventions', 'weaknessCategories'));
    }

    public function create()
    {
        $weaknessCategories = config('bigkas.weakness_categories', []);
        return view('interventions.create', compact('weaknessCategories'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateIntervention($request);

        $intervention = Intervention::create(array_merge($validated, [
            'for_teacher' => $request->has('for_teacher'),
            'for_parent' => $request->has('for_parent'),
            'effectiveness_score' => 5.0,
        ]));

        ActivityLog::log('create_intervention', "Created intervention: {$intervention->name}", 'intervention', $intervention->id);

        return redirect()->route('interventions.show', $intervention)
            ->with('success', "Intervention \"{$intervention->name}\" created.");
    }

    public function edit(Intervention $intervention)
    {
        $weaknessCategories = config('bigkas.weakness_categories', []);
        return view('interventions.edit', compact('intervention', 'weaknessCategories'));
    }

    public function update(Request $request, Intervention $intervention)
    {
        $validated = $this->validateIntervention($request);

        $intervention->update(array_merge($validated, [
            'for_teacher' => $request->has('for_teacher'),
            'for_parent' => $request->has('for_parent'),
        ]));

        ActivityLog::log('update_intervention', "Updated intervention: {$intervention->name}", 'intervention', $intervention->id);

        return redirect()->route('interventions.show', $intervention)
            ->with('success', 'Intervention updated.');
    }

    public function destroy(Intervention $intervention)
    {
        $intervention->update(['is_active' => false]);

        ActivityLog::log('deactivate_intervention', "Deactivated intervention: {$intervention->name}", 'intervention', $intervention->id);

        return redirect()->route('interventions.index')
            ->with('success', "Intervention \"{$intervention->name}\" deactivated.");
    }

    private function validateIntervention(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'target_weakness' => 'required|integer|in:1,2,3,4',
            'activity_type' => 'required|in:game,drill,reading,writing,audio,visual',
            'materials_needed' => 'nullable|string',
            'instructions' => 'required|string',
            'grade_level_min' => 'required|integer|min:3|max:6',
            'grade_level_max' => 'required|integer|min:3|max:6|gte:grade_level_min',
            'estimated_duration' => 'required|integer|min:1|max:240',
        ]);
    }

    public function show(Intervention $intervention)
    {
        $stats = $intervention->getStats();

        // Learners for the assign form (scoped to teacher)
        $user = auth()->user();
        $learners = Directory::sortLearners($user->isAdmin() ? Learner::get() : $user->learners()->get());

        // Recent assignments of this intervention
        $recentLogs = InterventionLog::where('intervention_id', $intervention->id)
            ->with(['learner', 'assigner'])
            ->latest()
            ->limit(10)
            ->get();

        return view('interventions.show', compact('intervention', 'stats', 'learners', 'recentLogs'));
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

        // Notify linked parent(s)
        $log->load('learner.users', 'intervention');
        $parents = $log->learner->users->filter(fn ($u) => $u->pivot->relationship === 'parent');
        foreach ($parents as $parent) {
            $parent->notify(new NewInterventionAssigned($log));
        }

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

        ActivityLog::log('update_intervention_log', "Updated intervention status to {$interventionLog->status} for learner #{$interventionLog->learner_id}", 'intervention_log', $interventionLog->id);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => "Intervention {$action}ed."]);
        }

        return back()->with('success', "Intervention status updated.");
    }

    public function learnerInterventions(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);
        $logs = $learner->interventionLogs()
            ->with(['intervention', 'assigner'])
            ->latest()
            ->paginate(20);

        return view('interventions.learner', compact('learner', 'logs'));
    }
}

