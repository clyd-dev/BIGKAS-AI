<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Support\Directory;
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
            $query = Learner::with('schoolClass');
        } elseif ($user->isTeacher()) {
            $classIds = $user->taughtClasses()->pluck('id');
            $query = Learner::with('schoolClass')
                ->where(function($q) use ($classIds, $user) {
                    $q->whereIn('class_id', $classIds)
                      ->orWhereHas('users', function($uq) use ($user) {
                          $uq->where('users.id', $user->id);
                      });
                });
        } else {
            // Parent
            $query = $user->learners()->with('schoolClass');
        }

        // Per-section summary reflects the user's full scope, ignoring the filters below.
        $sectionStats = (clone $query)->reorder()
            ->selectRaw('class_id, reading_level, COUNT(*) as total')
            ->groupBy('class_id', 'reading_level')
            ->get()
            ->groupBy('class_id');

        if ($request->filled('search')) {
            // Names and LRNs are encrypted, so the match is made in PHP on this user's own learners.
            $query->whereIn('learners.id', Directory::matchingLearnerIds($query, $request->search) ?: [0]);
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

        $portalBase = clone $query; // same filters, before the main list is paginated
        $learners = Directory::paginate(Directory::sortLearners($query->get()), 10);

        if ($user->isAdmin()) {
            $allClasses = SchoolClass::orderBy('grade_level')->orderBy('section')->get();
        } elseif ($user->isTeacher()) {
            $allClasses = $user->taughtClasses()->orderBy('grade_level')->orderBy('section')->get();
        } else {
            $allClasses = collect();
        }

        // Teachers get the student-portal container (activity + badges) on the same page.
        $portalLearners = $badges = null;
        $portalTab = $request->input('portal_tab') === 'badges' ? 'badges' : 'activity';
        if ($user->isTeacher()) {
            $portalAll = $portalBase->withCount('badges')->get()
                ->sort(fn ($a, $b) => [-$a->total_xp, Directory::key($a->last_name), Directory::key($a->first_name), $a->id]
                    <=> [-$b->total_xp, Directory::key($b->last_name), Directory::key($b->first_name), $b->id])
                ->values();
            $portalLearners = Directory::paginate($portalAll, 10, 'portal_page');

            $badges = \App\Models\Badge::withCount('learners')
                ->orderBy('sort_order')->orderBy('id')
                ->paginate(10, ['*'], 'badge_page')->withQueryString();
        }

        return view('learners.index', compact('learners', 'allClasses', 'sectionStats', 'portalLearners', 'badges', 'portalTab'));
    }

    public function create()
    {
        $user = auth()->user();
        $lockedClass = null;

        if ($user->isAdmin()) {
            $allClasses = SchoolClass::orderBy('grade_level')->orderBy('section')->get();
        } else {
            // Teachers are each assigned to exactly one class/section — lock the
            // create form to it instead of letting them pick a grade/section.
            $lockedClass = $user->taughtClasses()->first();
            $allClasses  = $lockedClass ? collect([$lockedClass]) : collect();
        }

        $schoolName  = School::orderBy('id')->value('name') ?? 'Old Sagay Elementary School';
        $parents     = Directory::sortUsers(User::where('role', 'parent')->get());

        return view('learners.create', compact('allClasses', 'lockedClass', 'schoolName', 'parents'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        $rules = [
            'first_name'  => 'required|string|min:2',
            'last_name'   => 'required|string|min:2',
            'lrn'         => ['nullable', 'string', 'max:20', \App\Rules\UniqueBlindIndex::lrn()],
            'birth_date'  => 'nullable|date',
            'gender'      => 'nullable|in:male,female',
            'parent_ids'  => 'nullable|array',
            'parent_ids.*'=> 'exists:users,id',
        ];

        // Admins choose grade/section explicitly. Teachers are locked to their
        // own assigned class — grade_level/class_id are forced below, not trusted from the request.
        if ($user->isAdmin()) {
            $rules['grade_level'] = 'required|integer|min:1|max:12';
            $rules['class_id']    = 'nullable|exists:classes,id';
        }

        $request->validate($rules);

        if ($user->isAdmin()) {
            // Derive grade_level from the chosen class if provided.
            $classId    = $request->class_id ?: null;
            $gradeLevel = $request->grade_level;
            if ($classId) {
                $class      = SchoolClass::find($classId);
                $gradeLevel = $class?->grade_level ?? $gradeLevel;
            }
        } else {
            $lockedClass = $user->taughtClasses()->first();
            abort_if(!$lockedClass, 403, 'You are not assigned to a class/section yet.');
            $classId    = $lockedClass->id;
            $gradeLevel = $lockedClass->grade_level;
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

        // The teacher who adds the learner issues the student-portal PIN (stored encrypted, visible to the teacher).
        $pin = Learner::generatePin();
        $learner->pin = $pin;
        $learner->pin_created_at = now();
        $learner->save();

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
        $parents     = Directory::sortUsers(User::where('role', 'parent')->get());

        return view('learners.edit', compact('learner', 'allClasses', 'schoolName', 'parents'));
    }

    public function update(Request $request, Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        $request->validate([
            'first_name'  => 'required|string|min:2',
            'last_name'   => 'required|string|min:2',
            'grade_level' => 'required|integer|min:1|max:12',
            'lrn'         => ['nullable', 'string', 'max:20', \App\Rules\UniqueBlindIndex::lrn($learner->id)],
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

    /** Reset a learner's student-portal XP and streak (teacher action). */
    public function resetXp(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        // total_xp / streaks are guarded: explicit assignment only.
        $learner->total_xp = 0;
        $learner->current_streak = 0;
        $learner->longest_streak = 0;
        $learner->last_activity_date = null;
        $learner->save();

        ActivityLog::log('reset_learner_xp', "Reset XP and streak for learner: {$learner->getFullName()}", 'learner', $learner->id);

        return redirect(route('learners.index', ['portal_tab' => 'activity']) . '#student-portal')
            ->with('success', "XP and streak reset for {$learner->getFullName()}.");
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
