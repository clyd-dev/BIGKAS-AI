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
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
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

        // Names and emails are encrypted: search and sort happen in PHP.
        $found = $query->get();
        if ($request->filled('search')) {
            $found = $found->filter(fn (User $u) => Directory::userMatches($u, $request->search));
        }
        $users = Directory::paginate(Directory::sortUsers($found), 20);

        // All classes for dropdowns
        $allClasses = SchoolClass::with('teacher')
            ->orderBy('grade_level')->orderBy('section')->get();

        // Map teacher_id → their assigned class for the Grade & Section column
        $teacherClasses = SchoolClass::whereNotNull('teacher_id')
            ->orderBy('grade_level')->orderBy('section')
            ->get()
            ->keyBy('teacher_id');

        return view('admin.users', compact('users', 'allClasses', 'teacherClasses'));
    }

    public function updateUserRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|in:admin,teacher,parent,student']);
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
            'role'     => 'required|in:admin,teacher,parent,student',
            'password' => 'required|string|min:8|confirmed|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
            'class_id' => 'nullable|exists:classes,id',
        ]);

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

        // If a class was chosen, assign this user as its teacher
        if ($request->filled('class_id')) {
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
            'role'     => 'required|in:admin,teacher,parent,student',
            'class_id' => 'nullable|exists:classes,id',
        ]);

        $oldRole = $user->role;

        // role is guarded — explicit assignment only.
        $user->role = $request->role;
        $user->save();

        // Remove this user from any class they were previously assigned as teacher
        SchoolClass::where('teacher_id', $user->id)->update(['teacher_id' => null]);

        // Assign to new class if provided
        if ($request->filled('class_id')) {
            SchoolClass::where('id', $request->class_id)
                ->update(['teacher_id' => $user->id]);
        }

        $changes = [];
        if ($oldRole !== $request->role) {
            $changes[] = "role {$oldRole}→{$request->role}";
        }
        if ($request->filled('class_id')) {
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
        $school   = $this->currentSchool();
        $classes  = SchoolClass::with(['teacher', 'learners'])
            ->withCount('learners')
            ->orderBy('grade_level')
            ->orderBy('section')
            ->get();
        $teachers = Directory::sortUsers(User::where('role', 'teacher')->get());

        return view('admin.schools', compact('school', 'classes', 'teachers'));
    }

    public function storeClass(Request $request)
    {
        $request->validate([
            'grade_level' => 'required|integer|min:1|max:6',
            'section'     => 'required|string|max:100',
            'teacher_id'  => 'nullable|exists:users,id',
            'school_year' => 'nullable|string|max:20',
        ]);

        $school = $this->currentSchool();

        SchoolClass::create([
            'school_id'   => $school->id,
            'grade_level' => $request->grade_level,
            'section'     => $request->section,
            'teacher_id'  => $request->teacher_id ?: null,
            'school_year' => $request->school_year,
            'is_active'   => true,
        ]);

        ActivityLog::log('admin_create_class', "Added Grade {$request->grade_level} – {$request->section}");

        return back()->with('success', "Grade {$request->grade_level} – {$request->section} added successfully.");
    }

    public function updateClass(Request $request, SchoolClass $schoolClass)
    {
        $request->validate([
            'grade_level' => 'required|integer|min:1|max:6',
            'section'     => 'required|string|max:100',
            'teacher_id'  => 'nullable|exists:users,id',
            'school_year' => 'nullable|string|max:20',
        ]);

        $schoolClass->update([
            'grade_level' => $request->grade_level,
            'section'     => $request->section,
            'teacher_id'  => $request->teacher_id ?: null,
            'school_year' => $request->school_year,
        ]);

        ActivityLog::log('admin_update_class', "Updated class: Grade {$request->grade_level} – {$request->section}", 'class', $schoolClass->id);

        return back()->with('success', "Section updated successfully.");
    }

    public function deleteClass(SchoolClass $schoolClass)
    {
        $label = "Grade {$schoolClass->grade_level} – {$schoolClass->section}";
        $schoolClass->delete();
        ActivityLog::log('admin_delete_class', "Deleted class: {$label}");
        return back()->with('success', "{$label} deleted.");
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
            ->withCount('learners')
            ->orderBy('grade_level')
            ->orderBy('section')
            ->get();

        return view('admin.classes', compact('classes'));
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
