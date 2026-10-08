<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\School;
use App\Support\Directory;
use App\Models\SchoolClass;
use App\Models\Learner;
use App\Models\Assessment;
use App\Models\Intervention;
use App\Models\ReadingMaterial;
use App\Models\SystemSetting;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    /** JSON health of the ML classifier and Whisper, for the dashboard's status card. */
    public function systemStatus(Request $request)
    {
        return response()->json(
            \App\Services\SystemStatus::all($request->boolean('fresh'))
        )->header('Cache-Control', 'no-store');
    }

    /** The Admin Panel was merged into the dashboard; keep the old URL working. */
    public function index()
    {
        return redirect()->route('dashboard');
    }

    public function users(Request $request)
    {
        $query = User::with('school');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }
        if ($request->boolean('unassigned')) {
            // Teachers who have no grade & section yet.
            $query->where('role', 'teacher')->whereDoesntHave('taughtClasses');
        }

        // Names and emails are encrypted: search and sort happen in PHP.
        $found = $query->get();
        if ($request->filled('search')) {
            $found = $found->filter(fn (User $u) => Directory::userMatches($u, $request->search));
        }
        $users = Directory::paginate(Directory::sortUsers($found), 10);

        // All classes for dropdowns
        $allClasses = SchoolClass::with('teacher')
            ->orderBy('grade_level')->orderBy('section')->get();

        // Map teacher_id → their assigned class for the Grade & Section column
        $teacherClasses = SchoolClass::whereNotNull('teacher_id')
            ->orderBy('grade_level')->orderBy('section')
            ->get()
            ->keyBy('teacher_id');

        $pendingTeachers = User::where('role', 'teacher')->where('is_active', true)->whereDoesntHave('taughtClasses')->count();

        return view('admin.users', compact('users', 'allClasses', 'teacherClasses', 'pendingTeachers'));
    }

    public function updateUserRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|in:admin,teacher,parent']);
        $oldRole = $user->role;
        // role is guarded — explicit assignment only.
        $user->role = $request->role;
        $user->save();

        ActivityLog::log('admin_update_role', "Changed role of {$user->name} from {$oldRole} to {$request->role}", 'user', $user->id);

        return back()->with('success', "Role updated for {$user->name}.");
    }

    public function activateUser(User $user)
    {
        // is_active is guarded — explicit assignment only.
        $user->is_active = true;
        $user->save();
        ActivityLog::log('admin_activate_user', "Activated user {$user->name} ({$user->role})", 'user', $user->id);
        return back()->with('success', "{$user->name} has been activated.");
    }

    public function deactivateUser(User $user)
    {
        // An admin must not lock themselves out.
        if ($user->id === auth()->id()) {
            return back()->with('error', "You can't deactivate your own account.");
        }

        // is_active is guarded — explicit assignment only.
        $user->is_active = false;
        $user->save();
        ActivityLog::log('admin_deactivate_user', "Deactivated user {$user->name} ({$user->role})", 'user', $user->id);
        return back()->with('success', "{$user->name} has been deactivated.");
    }

    public function resetUserPassword(User $user)
    {
        $tempPassword = 'Bigkas@123';
        $user->update(['password' => Hash::make($tempPassword)]);
        ActivityLog::log('admin_reset_password', "Reset password for {$user->name} ({$user->role})", 'user', $user->id);
        return back()->with('success', "Password reset for {$user->name}. Temporary password: {$tempPassword}");
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'email', \App\Rules\UniqueBlindIndex::email()],
            'role'     => 'required|in:admin,teacher,parent',
            'password' => \App\Support\PasswordPolicy::rules(),
            'class_id' => 'nullable|exists:classes,id',
        ], \App\Support\PasswordPolicy::messages());

        if ($request->role === 'teacher' && ($taken = $this->classTakenMessage($request->class_id, null))) {
            throw ValidationException::withMessages(['class_id' => $taken]);
        }

        // Auto-set school_id from the chosen class
        $school = School::orderBy('id')->first();

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'school_id' => $school?->id,
        ]);

        // role and is_active are guarded — explicit assignment only.
        $user->role = $request->role;
        $user->is_active = true;
        $user->save();

        // Admin-created accounts skip code verification — auto-verified.
        $user->email_verified_at = now();
        $user->save();

        // Only a teacher is given a grade & section.
        if ($request->role === 'teacher' && $request->filled('class_id')) {
            SchoolClass::where('id', $request->class_id)
                ->update(['teacher_id' => $user->id]);
        }

        ActivityLog::log('admin_create_user', "Created {$request->role} user: {$request->name} ({$request->email})", 'user', $user->id);

        return redirect()->route('admin.users')
            ->with('success', "User {$request->name} created successfully.");
    }

    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'role'     => 'required|in:admin,teacher,parent',
            'class_id' => 'nullable|exists:classes,id',
        ]);

        if ($request->role === 'teacher' && ($taken = $this->classTakenMessage($request->class_id, $user))) {
            return back()->withInput()->with('error', $taken);
        }

        $oldRole = $user->role;

        // role is guarded — explicit assignment only.
        $user->role = $request->role;
        $user->save();

        // Remove this user from any class they were previously assigned as teacher
        SchoolClass::where('teacher_id', $user->id)->update(['teacher_id' => null]);

        // Assign to new class if provided (only teachers have a grade & section)
        $assignClass = $request->role === 'teacher' && $request->filled('class_id');
        if ($assignClass) {
            SchoolClass::where('id', $request->class_id)
                ->update(['teacher_id' => $user->id]);
        }

        $changes = [];
        if ($oldRole !== $request->role) {
            $changes[] = "role {$oldRole}→{$request->role}";
        }
        if ($assignClass) {
            $cls = SchoolClass::find($request->class_id);
            $changes[] = "assigned to Grade {$cls->grade_level} – {$cls->section}";
        } else {
            $changes[] = 'removed from class';
        }

        ActivityLog::log('admin_update_user', "Updated {$user->name}: " . implode(', ', $changes), 'user', $user->id);

        return back()->with('success', "{$user->name} updated successfully.");
    }

    /**
     * The app is single-school. A fresh deploy seeds content only (no SchoolSeeder),
     * so create a blank default school on first use instead of leaving the page empty
     * and failing classes.school_id (NOT NULL).
     */
    private function currentSchool(): School
    {
        return School::orderBy('id')->first()
            ?? School::create(['name' => 'Old Sagay Elementary School']);
    }

    public function schools()
    {
        $school = $this->currentSchool();
        $stats  = [
            'sections' => SchoolClass::count(),
            'teachers' => User::where('role', 'teacher')->count(),
            'learners' => Learner::count(),
        ];

        return view('admin.schools', compact('school', 'stats'));
    }

    public function storeClass(Request $request)
    {
        $request->validate($this->classRules());

        if ($problem = $this->classProblem($request, null)) {
            return back()->withInput()->with('error', $problem);
        }

        $school = $this->currentSchool();

        SchoolClass::create([
            'school_id'   => $school->id,
            'grade_level' => $request->grade_level,
            'section'     => trim($request->section),
            'teacher_id'  => $request->teacher_id ?: null,
            'school_year' => $this->schoolYearFrom($request),
            'is_active'   => true,
        ]);

        ActivityLog::log('admin_create_class', "Added Grade {$request->grade_level} – {$request->section}");

        return redirect()->route('admin.classes')->with('success', "Grade {$request->grade_level} – {$request->section} added.");
    }

    public function updateClass(Request $request, SchoolClass $schoolClass)
    {
        $request->validate($this->classRules());

        if ($problem = $this->classProblem($request, $schoolClass)) {
            return back()->withInput()->with('error', $problem);
        }

        $schoolClass->update([
            'grade_level' => $request->grade_level,
            'section'     => trim($request->section),
            'teacher_id'  => $request->teacher_id ?: null,
            'school_year' => $this->schoolYearFrom($request),
        ]);

        ActivityLog::log('admin_update_class', "Updated class: Grade {$request->grade_level} – {$request->section}", 'class', $schoolClass->id);

        return redirect()->route('admin.classes')->with('success', 'Section updated.');
    }

    /** Danger zone: the principal must type the section name to delete it. */
    public function deleteClass(Request $request, SchoolClass $schoolClass)
    {
        $request->validate(['confirm' => 'required|string']);

        if (mb_strtolower(trim($request->confirm)) !== mb_strtolower(trim($schoolClass->section))) {
            return back()->withInput()->with('error', 'The name you typed does not match this section, so nothing was deleted.');
        }

        $label    = "Grade {$schoolClass->grade_level} – {$schoolClass->section}";
        $learners = $schoolClass->learners()->count();
        $reports  = $schoolClass->reports()->count();
        $schoolClass->delete();

        ActivityLog::log('admin_delete_class', "Deleted class: {$label} ({$learners} learners left without a section, {$reports} teacher reports removed)");

        return redirect()->route('admin.classes')->with('success', "{$label} deleted."
            . ($learners ? " {$learners} learner" . ($learners === 1 ? ' is' : 's are') . ' now without a section.' : ''));
    }

    private function classRules(): array
    {
        return [
            'grade_level' => 'required|integer|min:1|max:6',
            'section'     => 'required|string|max:100',
            'teacher_id'  => 'nullable|exists:users,id',
            'school_year' => 'nullable|string|max:20',
        ];
    }

    private function schoolYearFrom(Request $request): string
    {
        return trim((string) $request->school_year) ?: $this->defaultSchoolYear();
    }

    /** The school year most classes are in, or the current Philippine school year (starts in June). */
    private function defaultSchoolYear(): string
    {
        return SchoolClass::orderByDesc('school_year')->value('school_year')
            ?? (now()->month >= 6 ? now()->year . '-' . (now()->year + 1) : (now()->year - 1) . '-' . now()->year);
    }

    /** Business-rule check for adding/editing a section. Returns a message, or null when it is fine. */
    private function classProblem(Request $request, ?SchoolClass $current): ?string
    {
        $grade   = (int) $request->grade_level;
        $section = trim($request->section);
        $year    = $this->schoolYearFrom($request);

        $duplicate = SchoolClass::where('grade_level', $grade)
            ->whereRaw('LOWER(section) = ?', [mb_strtolower($section)])
            ->where('school_year', $year)
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->exists();
        if ($duplicate) {
            return "Grade {$grade} – {$section} already exists for S.Y. {$year}.";
        }

        if ($request->filled('teacher_id')) {
            $teacher = User::find($request->teacher_id);
            if (! $teacher || $teacher->role !== 'teacher') {
                return 'The adviser must be a teacher.';
            }
            $other = SchoolClass::where('teacher_id', $teacher->id)
                ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
                ->first();
            if ($other) {
                return "{$teacher->name} already advises Grade {$other->grade_level} – {$other->section}. A teacher can only have one section.";
            }
        }

        return null;
    }

    /** A section can only have one teacher. Message when {classId} already belongs to someone other than $forUser. */
    private function classTakenMessage(?int $classId, ?User $forUser): ?string
    {
        if (! $classId) {
            return null;
        }
        $class = SchoolClass::with('teacher')->find($classId);
        if ($class && $class->teacher_id && (! $forUser || $class->teacher_id !== $forUser->id)) {
            return "Grade {$class->grade_level} – {$class->section} already has a teacher ({$class->teacher?->name}). A section can only have one teacher.";
        }

        return null;
    }

    public function storeSchool(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'school_id_number' => 'nullable|string|max:50|unique:schools,school_id_number',
        ]);

        $school = School::create($request->only(['name', 'school_id_number', 'address', 'district', 'division', 'region', 'contact_number', 'email', 'principal_name']));

        ActivityLog::log('admin_create_school', "Created school: {$school->name}", 'school', $school->id);

        return back()->with('success', 'School added successfully.');
    }

    public function updateSchool(Request $request, School $school)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'school_id_number' => 'nullable|string|max:50|unique:schools,school_id_number,' . $school->id,
            'email'            => 'nullable|email|max:255',
        ]);
        $school->update($request->only(['name', 'school_id_number', 'address', 'district', 'division', 'region', 'contact_number', 'email', 'principal_name']));

        ActivityLog::log('admin_update_school', "Updated school: {$school->name}", 'school', $school->id);

        return back()->with('success', 'School updated.');
    }

    public function deleteSchool(School $school)
    {
        $school->update(['is_active' => false]);
        ActivityLog::log('admin_deactivate_school', "Deactivated school: {$school->name}", 'school', $school->id);
        return back()->with('success', 'School deactivated.');
    }

    public function classesOverview(Request $request)
    {
        $classes = SchoolClass::with(['teacher', 'learners'])
            ->withCount(['learners', 'reports'])
            ->orderBy('grade_level')
            ->orderBy('section')
            ->get();

        $teachers    = Directory::sortUsers(User::where('role', 'teacher')->where('is_active', true)->get());
        $advising    = $classes->whereNotNull('teacher_id')->keyBy('teacher_id');   // teacher id => the section they advise
        $school      = $this->currentSchool();
        $defaultYear = $this->defaultSchoolYear();

        return view('admin.classes', compact('classes', 'teachers', 'advising', 'school', 'defaultYear'));
    }

    public function activityLogs(Request $request)
    {
        $query = ActivityLog::with('user');

        if ($request->filled('role')) {
            if ($request->role === 'learner') {
                $query->where('subject_type', 'learner');
            } else {
                $query->whereHas('user', function($q) use ($request) {
                    $q->where('role', $request->role);
                });
            }
        }

        $logs = $query->latest()->paginate(50);
        
        return view('admin.logs', compact('logs'));
    }

    public function interventions()
    {
        $interventions = Intervention::orderBy('target_weakness')->orderByDesc('effectiveness_score')->paginate(20);
        $weaknessCategories = config('bigkas.weakness_categories', []);

        return view('admin.interventions', compact('interventions', 'weaknessCategories'));
    }

    public function materials()
    {
        $materials = ReadingMaterial::orderBy('grade_level')->orderBy('title')->paginate(20);
        return view('admin.materials', compact('materials'));
    }

    public function settings()
    {
        $settings = [
            'app_name' => SystemSetting::getValue('app_name', 'BIGKAS-AI'),
            'max_audio_mb' => SystemSetting::getValue('max_audio_mb', 20),
            'independent_threshold' => SystemSetting::getValue('independent_threshold', 97),
            'instructional_threshold' => SystemSetting::getValue('instructional_threshold', 90),
            'ml_enabled' => SystemSetting::getValue('ml_enabled', true),
            'auto_recommend' => SystemSetting::getValue('auto_recommend', true),
        ];

        return view('admin.settings', compact('settings'));
    }

    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            'settings.app_name' => 'required|string|max:100',
            'settings.max_audio_mb' => 'required|integer|min:1|max:100',
            'settings.independent_threshold' => 'required|integer|min:90|max:100',
            'settings.instructional_threshold' => 'required|integer|min:80|max:100|lt:settings.independent_threshold',
            'settings.ml_enabled' => 'sometimes|boolean',
            'settings.auto_recommend' => 'sometimes|boolean',
        ]);

        $types = [
            'app_name' => 'string',
            'max_audio_mb' => 'number',
            'independent_threshold' => 'number',
            'instructional_threshold' => 'number',
            'ml_enabled' => 'boolean',
            'auto_recommend' => 'boolean',
        ];

        $changedKeys = [];
        foreach ($validated['settings'] as $key => $value) {
            SystemSetting::setValue($key, $value, $types[$key]);
            $changedKeys[] = $key;
        }

        // Unchecked boxes submit nothing — store explicit false, never stale.
        foreach (['ml_enabled', 'auto_recommend'] as $key) {
            if (! array_key_exists($key, $validated['settings'])) {
                SystemSetting::setValue($key, '0', 'boolean');
                $changedKeys[] = $key;
            }
        }

        ActivityLog::log('admin_update_settings', 'Updated system settings: ' . implode(', ', $changedKeys), 'system_setting', null);

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Phil-IRI Forms hub (principal / school head, read-only): the official forms and where each one lives,
     * plus a paginated per-section progress table.
     */
    public function philIri(Request $request)
    {
        $schoolYears = SchoolClass::distinct()->orderByDesc('school_year')->pluck('school_year');
        $schoolYear  = $request->input('school_year', $schoolYears->first());
        $grade       = $request->input('grade_level');

        $sections = SchoolClass::with('teacher:id,name')
            ->where('is_active', true)
            ->whereBetween('grade_level', [3, 6])
            ->when($schoolYear, fn ($q) => $q->where('school_year', $schoolYear))
            ->when($grade, fn ($q) => $q->where('grade_level', $grade))
            ->withCount([
                'learners',
                'learners as gst_count'       => fn ($q) => $q->whereHas('gstResults', fn ($g) => $g->where('school_year', $schoolYear)),
                'learners as assessed_count'  => fn ($q) => $q->whereHas('assessments', fn ($a) => $a->where('status', 'completed')),
                'learners as independent_count'   => fn ($q) => $q->where('reading_level', 'independent'),
                'learners as instructional_count' => fn ($q) => $q->where('reading_level', 'instructional'),
                'learners as frustration_count'   => fn ($q) => $q->where('reading_level', 'frustration'),
            ])
            ->orderBy('grade_level')->orderBy('section')
            ->paginate(10)->withQueryString();

        // Latest teacher report per section (any period) for the status column.
        $reportStatus = \App\Models\ClassReport::whereIn('class_id', $sections->pluck('id'))
            ->where('school_year', $schoolYear)
            ->orderBy('submitted_at')
            ->get()->groupBy('class_id')->map(fn ($g) => $g->last()->status);

        $school = School::orderBy('id')->first();

        return view('admin.phil-iri', compact('sections', 'reportStatus', 'schoolYears', 'schoolYear', 'grade', 'school'));
    }
}
