<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Models\School;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class LearnerController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $learners = $user->isAdmin()
            ? Learner::with('school')->orderBy('last_name')->get()
            : $user->learners()->orderBy('last_name')->get();

        return view('learners.index', compact('learners'));
    }

    public function create()
    {
        $gradeLevels = config('bigkas.grade_levels', []);
        $schools = School::active()->orderBy('name')->get();

        return view('learners.create', compact('gradeLevels', 'schools'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|min:2',
            'last_name' => 'required|string|min:2',
            'grade_level' => 'required|integer|min:1|max:12',
            'lrn' => 'nullable|string|max:20|unique:learners,lrn',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
        ]);

        $learner = Learner::create([
            'lrn' => $request->lrn ?: null,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'birth_date' => $request->birth_date ?: null,
            'gender' => $request->gender,
            'grade_level' => $request->grade_level,
            'school_id' => $request->school_id ?? auth()->user()->school_id,
            'class_id' => $request->class_id,
            'mother_tongue' => $request->mother_tongue ?? 'Hiligaynon',
            'notes' => $request->notes,
        ]);

        $relationship = auth()->user()->isTeacher() ? 'teacher' : 'parent';
        $learner->users()->attach(auth()->id(), ['relationship' => $relationship]);

        ActivityLog::log('create_learner', "Added learner: {$learner->getFullName()}", 'learner', $learner->id);

        return redirect()->route('learners.show', $learner)
            ->with('success', "Learner \"{$learner->getFullName()}\" has been added.");
    }

    public function show(Learner $learner)
    {
        $learner->load(['school', 'schoolClass']);
        $assessments = $learner->assessments()->with(['material', 'result'])->latest()->limit(10)->get();
        $stats = $learner->getStats();
        $pendingInterventions = $learner->interventionLogs()
            ->where('status', 'pending')
            ->with('intervention')
            ->latest()
            ->get();
        $skillBreakdown = $learner->getSkillBreakdown();

        return view('learners.show', compact('learner', 'assessments', 'stats', 'pendingInterventions', 'skillBreakdown'));
    }

    public function edit(Learner $learner)
    {
        $gradeLevels = config('bigkas.grade_levels', []);
        $schools = School::active()->orderBy('name')->get();

        return view('learners.edit', compact('learner', 'gradeLevels', 'schools'));
    }

    public function update(Request $request, Learner $learner)
    {
        $request->validate([
            'first_name' => 'required|string|min:2',
            'last_name' => 'required|string|min:2',
            'grade_level' => 'required|integer|min:1|max:12',
            'lrn' => 'nullable|string|max:20|unique:learners,lrn,' . $learner->id,
        ]);

        $learner->update([
            'lrn' => $request->lrn ?: null,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'middle_name' => $request->middle_name,
            'birth_date' => $request->birth_date ?: null,
            'gender' => $request->gender,
            'grade_level' => $request->grade_level,
            'mother_tongue' => $request->mother_tongue ?? 'Hiligaynon',
            'notes' => $request->notes,
        ]);

        return redirect()->route('learners.show', $learner)
            ->with('success', 'Learner updated successfully.');
    }

    public function destroy(Learner $learner)
    {
        $name = $learner->getFullName();
        $learner->delete();

        return redirect()->route('learners.index')
            ->with('success', "Learner \"{$name}\" has been removed.");
    }

    public function progress(Learner $learner)
    {
        $progressData = $learner->getProgressData();
        $assessmentResults = $learner->getAssessmentResults();
        $interventionLogs = $learner->interventionLogs()
            ->with(['intervention', 'assigner'])
            ->latest()
            ->get();
        $stats = $learner->getStats();
        $skillBreakdown = $learner->getSkillBreakdown();

        return view('learners.progress', compact('learner', 'progressData', 'assessmentResults', 'interventionLogs', 'stats', 'skillBreakdown'));
    }
}
