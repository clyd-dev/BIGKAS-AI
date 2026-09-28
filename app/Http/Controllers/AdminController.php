<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\User;
use App\Models\School;
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
    public function index()
    {
        $stats = [
            'total_users'            => User::count(),
            'total_teachers'         => User::where('role', 'teacher')->count(),
            'total_parents'          => User::where('role', 'parent')->count(),
            'total_students'         => User::where('role', 'student')->count(),
            'total_learners'         => Learner::count(),
            'total_assessments'      => Assessment::count(),
            'total_schools'          => School::count(),
            'total_interventions'    => Intervention::where('is_active', true)->count(),
            'total_materials'        => ReadingMaterial::where('is_active', true)->count(),
            'frustration_learners'   => Learner::where('reading_level', 'frustration')->count(),
            'instructional_learners' => Learner::where('reading_level', 'instructional')->count(),
            'independent_learners'   => Learner::where('reading_level', 'independent')->count(),
        ];

        // Fetch breakdown of reading weaknesses (ML Classification Results)
        // 1=Phonemic, 2=Decoding, 3=Fluency, 4=Comprehension
        $weaknessDistribution = \App\Models\AssessmentResult::selectRaw('primary_weakness, COUNT(*) as count')
            ->whereNotNull('primary_weakness')
            ->groupBy('primary_weakness')
            ->pluck('count', 'primary_weakness')
            ->toArray();

        // Map IDs to labels for Chart.js
        $weaknessLabels = [];
        $weaknessData = [];
        $categories = config('bigkas.weakness_categories', [
            1 => ['name' => 'Phonemic Awareness'],
            2 => ['name' => 'Decoding'],
            3 => ['name' => 'Fluency'],
            4 => ['name' => 'Comprehension']
        ]);

        foreach ($categories as $id => $cat) {
            $weaknessLabels[] = $cat['name'];
            $weaknessData[] = $weaknessDistribution[$id] ?? 0;
        }

        // Fetch Assessments per month for line chart (current year)
        $assessmentsPerMonth = Assessment::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->whereYear('created_at', date('Y'))
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        $monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthData = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthData[] = $assessmentsPerMonth[$i] ?? 0;
        }

        $chartData = [
            'weaknesses' => [
                'labels' => $weaknessLabels,
                'data' => $weaknessData,
            ],
            'assessments' => [
                'labels' => $monthLabels,
                'data' => $monthData,
            ]
        ];

        $recentActivity = ActivityLog::with('user')->latest()->limit(20)->get();

        $schoolName = School::orderBy('id')->value('name') ?? 'Old Sagay Elementary School';

        return view('admin.index', compact('stats', 'recentActivity', 'chartData', 'schoolName'));
    }

    public function users(Request $request)
    {
        $query = User::with('school');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            );
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $users = $query->orderBy('name')->paginate(20);

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
            'email'    => 'required|email|unique:users,email',
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

    public function schools()
    {
        $school   = School::orderBy('id')->first();
        $classes  = SchoolClass::with(['teacher', 'learners'])
            ->withCount('learners')
            ->orderBy('grade_level')
            ->orderBy('section')
            ->get();
        $teachers = User::where('role', 'teacher')->orderBy('name')->get();

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

        $school = School::orderBy('id')->first();

        SchoolClass::create([
            'school_id'   => $school?->id,
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
        $request->validate(['name' => 'required|string|max:255']);
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

    public function storeIntervention(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'target_weakness' => 'required|integer|in:1,2,3,4',
            'instructions' => 'required|string',
        ]);

        $intervention = Intervention::create($request->only([
            'name', 'description', 'target_weakness', 'activity_type', 'materials_needed',
            'instructions', 'for_teacher', 'for_parent', 'grade_level_min', 'grade_level_max',
            'estimated_duration', 'effectiveness_score',
        ]));

        ActivityLog::log('admin_create_intervention', "Created intervention: {$intervention->name} (weakness: {$request->target_weakness})", 'intervention', $intervention->id);

        return back()->with('success', 'Intervention added.');
    }

    public function updateIntervention(Request $request, Intervention $intervention)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $intervention->update($request->only([
            'name', 'description', 'target_weakness', 'activity_type', 'materials_needed',
            'instructions', 'for_teacher', 'for_parent', 'grade_level_min', 'grade_level_max',
            'estimated_duration', 'effectiveness_score',
        ]));

        ActivityLog::log('admin_update_intervention', "Updated intervention: {$intervention->name}", 'intervention', $intervention->id);

        return back()->with('success', 'Intervention updated.');
    }

    public function deleteIntervention(Intervention $intervention)
    {
        $intervention->update(['is_active' => false]);
        ActivityLog::log('admin_deactivate_intervention', "Deactivated intervention: {$intervention->name}", 'intervention', $intervention->id);
        return back()->with('success', 'Intervention deactivated.');
    }

    public function materials()
    {
        $materials = ReadingMaterial::orderBy('grade_level')->orderBy('title')->paginate(20);
        return view('admin.materials', compact('materials'));
    }

    public function settings()
    {
        $settings = SystemSetting::all()->keyBy('setting_key');
        return view('admin.settings', compact('settings'));
    }

    public function saveSettings(Request $request)
    {
        $changedKeys = [];
        foreach ($request->except('_token') as $key => $value) {
            SystemSetting::setValue($key, $value);
            $changedKeys[] = $key;
        }

        ActivityLog::log('admin_update_settings', 'Updated system settings: ' . implode(', ', $changedKeys), 'system_setting', null);

        return back()->with('success', 'Settings saved.');
    }

    // ── Badge Management ──

    public function badges()
    {
        $badges = Badge::orderBy('sort_order')->get();
        return view('admin.badges', compact('badges'));
    }

    public function storeBadge(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'required|string|max:100|unique:badges,slug',
            'description' => 'required|string',
            'icon'        => 'required|string|max:10',
            'category'    => 'required|string|max:100',
            'xp_reward'   => 'required|integer|min:0',
        ]);

        $badge = Badge::create([
            'name'        => $request->name,
            'slug'        => $request->slug,
            'description' => $request->description,
            'icon'        => $request->icon,
            'color'       => $request->color ?? '#6C63FF',
            'category'    => $request->category,
            'xp_reward'   => $request->xp_reward,
            'criteria'    => json_decode($request->criteria ?? '{}', true),
            'sort_order'  => $request->sort_order ?? 99,
            'is_active'   => true,
        ]);

        ActivityLog::log('admin_create_badge', "Created badge: {$badge->name} (category: {$badge->category})", 'badge', $badge->id);

        return back()->with('success', 'Badge created.');
    }

    public function updateBadge(Request $request, Badge $badge)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'required|string',
            'xp_reward'   => 'required|integer|min:0',
        ]);

        $badge->update([
            'name'        => $request->name,
            'description' => $request->description,
            'icon'        => $request->icon ?? $badge->icon,
            'color'       => $request->color ?? $badge->color,
            'xp_reward'   => $request->xp_reward,
            'sort_order'  => $request->sort_order ?? $badge->sort_order,
        ]);

        ActivityLog::log('admin_update_badge', "Updated badge: {$badge->name}", 'badge', $badge->id);

        return back()->with('success', 'Badge updated.');
    }

    public function toggleBadge(Badge $badge)
    {
        $badge->update(['is_active' => !$badge->is_active]);
        $state = $badge->is_active ? 'activated' : 'deactivated';
        ActivityLog::log('admin_toggle_badge', "Badge \"{$badge->name}\" {$state}", 'badge', $badge->id);
        return back()->with('success', "Badge \"{$badge->name}\" {$state}.");
    }

    // ── Learner Portal Oversight ──

    public function learnerPortal(Request $request)
    {
        $query = Learner::with(['schoolClass', 'badges'])
            ->withCount('badges');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('first_name', 'like', "%{$s}%")
                ->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('lrn', 'like', "%{$s}%")
            );
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        $learners   = $query->orderByDesc('total_xp')->paginate(25);
        $allClasses = SchoolClass::with('teacher')
            ->orderBy('grade_level')
            ->orderBy('section')
            ->get();

        return view('admin.learner-portal', compact('learners', 'allClasses'));
    }

    public function generateLearnerPin(Learner $learner)
    {
        $pin = Learner::generatePin();
        // pin is guarded — explicit assignment only (hashed cast still applies).
        $learner->pin = $pin;
        $learner->save();

        ActivityLog::log('admin_generate_pin', "Generated new PIN for learner: {$learner->getFullName()}", 'learner', $learner->id);

        return back()->with('success', "PIN for {$learner->getFullName()}: {$pin}");
    }

    public function resetLearnerXp(Learner $learner)
    {
        // total_xp / streaks are guarded — explicit assignment only.
        $learner->total_xp = 0;
        $learner->current_streak = 0;
        $learner->longest_streak = 0;
        $learner->last_activity_date = null;
        $learner->save();

        ActivityLog::log('admin_reset_learner_xp', "Reset XP and streak for learner: {$learner->getFullName()}", 'learner', $learner->id);

        return back()->with('success', "XP and streak reset for {$learner->getFullName()}.");
    }

    /**
     * Phil-IRI Reading Profile — Form 4 (school matrix) + Form 3A (learner detail).
     */
    public function philIri(Request $request)
    {
        $schoolYear = $request->get('school_year');
        $gradeFilter = $request->get('grade_level');

        // Get available school years from classes
        $schoolYears = SchoolClass::distinct()->orderByDesc('school_year')->pluck('school_year');
        if (!$schoolYear && $schoolYears->isNotEmpty()) {
            $schoolYear = $schoolYears->first();
        }

        // Build classes query scoped to school year
        $classesQuery = SchoolClass::with(['teacher:id,name', 'learners' => function ($q) {
            $q->where('is_active', true)->orderBy('last_name');
        }, 'learners.assessments' => function ($q) {
            $q->where('status', 'completed')->with('result')->latest();
        }])
            ->where('is_active', true)
            ->orderBy('grade_level')
            ->orderBy('section');

        if ($schoolYear) {
            $classesQuery->where('school_year', $schoolYear);
        }
        if ($gradeFilter) {
            $classesQuery->where('grade_level', $gradeFilter);
        }

        $classes = $classesQuery->get();

        // ── Build Form 4 matrix data ──
        $form4 = [];
        $totals = ['frustration' => 0, 'instructional' => 0, 'independent' => 0, 'not_assessed' => 0, 'total' => 0];
        $gradeTotals = [];

        // ── Build Form 3A learner detail data ──
        $form3a = [];

        foreach ($classes as $class) {
            $gradeKey = $class->grade_level;
            $sectionKey = $class->id;

            if (!isset($gradeTotals[$gradeKey])) {
                $gradeTotals[$gradeKey] = ['frustration' => 0, 'instructional' => 0, 'independent' => 0, 'not_assessed' => 0, 'total' => 0];
            }

            $classData = [
                'class_id'    => $class->id,
                'grade_level' => $gradeKey,
                'section'     => $class->section,
                'teacher'     => $class->teacher?->name ?? 'Unassigned',
                'frustration' => 0,
                'instructional' => 0,
                'independent' => 0,
                'not_assessed' => 0,
                'total'       => 0,
            ];

            $classLearners = [];

            foreach ($class->learners as $learner) {
                $latestAssessment = $learner->assessments->first(); // already sorted by latest
                $result = $latestAssessment?->result;

                $readingLevel = $result?->reading_level ?? null;
                $levelKey = $readingLevel ?? 'not_assessed';

                $classData[$levelKey]++;
                $classData['total']++;
                $gradeTotals[$gradeKey][$levelKey]++;
                $gradeTotals[$gradeKey]['total']++;
                $totals[$levelKey]++;
                $totals['total']++;

                $classLearners[] = [
                    'id'           => $learner->id,
                    'lrn'          => $learner->lrn,
                    'full_name'    => $learner->full_name,
                    'gender'       => $learner->gender,
                    'reading_level' => $readingLevel,
                    'accuracy'     => $result?->accuracy_rate,
                    'wpm'          => $result?->words_per_minute,
                    'errors'       => $result?->error_count,
                    'fluency'      => $result?->fluency_score,
                    'comprehension' => $result?->comprehension_score,
                    'primary_weakness' => $result?->primary_weakness,
                    'assessed_at'  => $latestAssessment?->assessed_at,
                ];
            }

            $form4[] = $classData;
            $form3a[$sectionKey] = [
                'class'    => $classData,
                'learners' => $classLearners,
            ];
        }

        $schoolName = School::first()?->name ?? 'Old Sagay Elementary School';

        ActivityLog::log('admin_view_phil_iri', 'Viewed Phil-IRI Reading Profile', 'system', null);

        return view('admin.phil-iri', compact(
            'form4', 'form3a', 'totals', 'gradeTotals',
            'schoolYears', 'schoolYear', 'gradeFilter',
            'schoolName'
        ));
    }
}
