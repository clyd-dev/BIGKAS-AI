<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Support\Facades\Auth;

use App\Models\User;

class LearnerController extends Controller
{
    use AuthorizesLearnerAccess;

    public function index(Request $request)
    {
        $user  = auth()->user();

        if ($user->isAdmin()) {
            $query = Learner::with('schoolClass')->orderBy('last_name');
        } elseif ($user->isTeacher()) {
            $classIds = $user->taughtClasses()->pluck('id');
            $query = Learner::with('schoolClass')
                ->where(function($q) use ($classIds, $user) {
                    $q->whereIn('class_id', $classIds)
                      ->orWhereHas('users', function($uq) use ($user) {
                          $uq->where('users.id', $user->id);
                      });
                })
                ->orderBy('last_name');
        } else {
            // Parent
            $query = $user->learners()->with('schoolClass')->orderBy('last_name');
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('first_name', 'like', "%{$s}%")
                ->orWhere('last_name',  'like', "%{$s}%")
                ->orWhere('lrn',        'like', "%{$s}%")
            );
        }

        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->filled('reading_level')) {
            $query->where('reading_level', $request->reading_level);
        }

        $learners   = $query->get();
        
        if ($user->isAdmin()) {
            $allClasses = SchoolClass::orderBy('grade_level')->orderBy('section')->get();
        } elseif ($user->isTeacher()) {
            $allClasses = $user->taughtClasses()->orderBy('grade_level')->orderBy('section')->get();
        } else {
            $allClasses = collect();
        }

        return view('learners.index', compact('learners', 'allClasses'));
    }

    public function create()
    {
        $user = auth()->user();
        if ($user->isAdmin()) {
            $allClasses = SchoolClass::orderBy('grade_level')->orderBy('section')->get();
        } else {
            $allClasses = $user->taughtClasses()->orderBy('grade_level')->orderBy('section')->get();
        }
        
        $schoolName  = School::orderBy('id')->value('name') ?? 'Old Sagay Elementary School';
        $parents     = User::where('role', 'parent')->orderBy('name')->get();

        return view('learners.create', compact('allClasses', 'schoolName', 'parents'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'first_name'  => 'required|string|min:2',
            'last_name'   => 'required|string|min:2',
            'grade_level' => 'required|integer|min:1|max:12',
            'lrn'         => 'nullable|string|max:20|unique:learners,lrn',
            'birth_date'  => 'nullable|date',
            'gender'      => 'nullable|in:male,female',
            'class_id'    => 'nullable|exists:classes,id',
            'parent_ids'  => 'nullable|array',
            'parent_ids.*'=> 'exists:users,id',
        ]);

        // Derive grade_level from the chosen class if provided.
        $classId    = $request->class_id ?: null;
        $gradeLevel = $request->grade_level;
        if ($classId) {
            $class      = SchoolClass::find($classId);
            $gradeLevel = $class?->grade_level ?? $gradeLevel;
        }

        $learner = Learner::create([
            'lrn'         => $request->lrn ?: null,
            'first_name'  => $request->first_name,
            'last_name'   => $request->last_name,
            'middle_name' => $request->middle_name,
            'birth_date'  => $request->birth_date ?: null,
            'gender'      => $request->gender,
            'grade_level' => $gradeLevel ?? $request->grade_level,
            'school_id'   => School::orderBy('id')->value('id'),
            'class_id'    => $classId ?? null,
            'notes'       => $request->notes,
        ]);

        $relationship = auth()->user()->isTeacher() ? 'teacher' : 'parent';
        $learner->users()->attach(auth()->id(), ['relationship' => $relationship]);

        // Attach selected parents
        if ($request->filled('parent_ids')) {
            foreach ($request->parent_ids as $parentId) {
                $learner->users()->attach($parentId, ['relationship' => 'parent']);
            }
        }

        ActivityLog::log('create_learner', "Added learner: {$learner->getFullName()}", 'learner', $learner->id);

        return redirect()->route('learners.show', $learner)
            ->with('success', "Learner \"{$learner->getFullName()}\" has been added.");
    }

    public function show(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        $learner->load(['school', 'schoolClass', 'users']);
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
        $this->authorizeLearnerAccess($learner);

        $learner->load(['schoolClass', 'users' => function($q) {
            $q->where('role', 'parent');
        }]);
        
        $user = auth()->user();
        if ($user->isAdmin()) {
            $allClasses = SchoolClass::orderBy('grade_level')->orderBy('section')->get();
        } else {
            // Also include the learner's current class even if not taught by this teacher, 
            // though the teacher wouldn't have access unless they taught it or added it.
            $allClasses = $user->taughtClasses()->orderBy('grade_level')->orderBy('section')->get();
            if ($learner->class_id && !$allClasses->contains('id', $learner->class_id)) {
                $allClasses->push($learner->schoolClass);
            }
        }
        
        $schoolName  = School::orderBy('id')->value('name') ?? 'Old Sagay Elementary School';
        $parents     = User::where('role', 'parent')->orderBy('name')->get();

        return view('learners.edit', compact('learner', 'allClasses', 'schoolName', 'parents'));
    }

    public function update(Request $request, Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        $request->validate([
            'first_name'  => 'required|string|min:2',
            'last_name'   => 'required|string|min:2',
            'grade_level' => 'required|integer|min:1|max:12',
            'lrn'         => 'nullable|string|max:20|unique:learners,lrn,' . $learner->id,
            'class_id'    => 'nullable|exists:classes,id',
        ]);

        // If a class is assigned, derive grade_level from the class record.
        $gradeLevel = $request->grade_level;
        $classId    = $request->class_id ?: null;
        if ($classId) {
            $class      = SchoolClass::find($classId);
            $gradeLevel = $class?->grade_level ?? $gradeLevel;
        }

        $learner->update([
            'lrn'         => $request->lrn ?: null,
            'first_name'  => $request->first_name,
            'last_name'   => $request->last_name,
            'middle_name' => $request->middle_name,
            'birth_date'  => $request->birth_date ?: null,
            'gender'      => $request->gender,
            'grade_level' => $gradeLevel,
            'class_id'    => $classId,
            'notes'       => $request->notes,
        ]);

        // Handle parent assignments
        $existingParents = $learner->users()->where('role', 'parent')->pluck('users.id')->toArray();
        $newParents = $request->parent_ids ?? [];
        
        $toAttach = array_diff($newParents, $existingParents);
        $toDetach = array_diff($existingParents, $newParents);
        
        if (!empty($toDetach)) {
            $learner->users()->detach($toDetach);
        }
        if (!empty($toAttach)) {
            foreach ($toAttach as $parentId) {
                $learner->users()->attach($parentId, ['relationship' => 'parent']);
            }
        }

        ActivityLog::log('update_learner', "Updated learner: {$learner->first_name} {$learner->last_name}", 'learner', $learner->id);

        return redirect()->route('learners.show', $learner)
            ->with('success', 'Learner updated successfully.');
    }

    public function destroy(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        $name = $learner->getFullName();
        $learner->delete();

        ActivityLog::log('delete_learner', "Deleted learner: {$name}", 'learner', $learner->id);

        return redirect()->route('learners.index')
            ->with('success', "Learner \"{$name}\" has been removed.");
    }

    public function progress(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

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
