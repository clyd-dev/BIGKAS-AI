<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\User;
use App\Models\School;
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

        return view('admin.index', compact('stats', 'recentActivity', 'chartData'));
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

        $users   = $query->orderBy('name')->paginate(20);
        $schools = School::orderBy('name')->get();

        return view('admin.users', compact('users', 'schools'));
    }

    public function updateUserRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|in:admin,teacher,parent,student']);
        $oldRole = $user->role;
        $user->update(['role' => $request->role]);

        ActivityLog::log('admin_update_role', "Changed role of {$user->name} from {$oldRole} to {$request->role}", 'user', $user->id);

        return back()->with('success', "Role updated for {$user->name}.");
    }

    public function activateUser(User $user)
    {
        $user->update(['is_active' => true]);
        ActivityLog::log('admin_activate_user', "Activated user {$user->name} ({$user->role})", 'user', $user->id);
        return back()->with('success', "{$user->name} has been activated.");
    }

    public function deactivateUser(User $user)
    {
        $user->update(['is_active' => false]);
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
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email',
            'role'      => 'required|in:admin,teacher,parent,student',
            'password'  => 'required|string|min:8|confirmed',
            'school_id' => 'nullable|exists:schools,id',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'role'      => $request->role,
            'password'  => Hash::make($request->password),
            'school_id' => $request->school_id,
            'is_active' => true,
        ]);

        ActivityLog::log('admin_create_user', "Created {$request->role} user: {$request->name} ({$request->email})", 'user', $user->id);

        return redirect()->route('admin.users')
            ->with('success', "User {$request->name} created successfully.");
    }

    public function schools()
    {
        $schools = School::withCount(['users', 'learners'])->orderBy('name')->get();
        return view('admin.schools', compact('schools'));
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
        $query = \App\Models\SchoolClass::with(['teacher', 'school'])
            ->withCount('learners');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('teacher', function($q) use ($s) {
                $q->where('name', 'like', "%{$s}%");
            })->orWhere('section', 'like', "%{$s}%");
        }

        $classes = $query->orderBy('grade_level')->orderBy('section')->paginate(15);

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
        $query = Learner::with(['schoolClass', 'school', 'badges'])
            ->withCount('badges');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('first_name', 'like', "%{$s}%")
                ->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('lrn', 'like', "%{$s}%")
            );
        }

        if ($request->filled('school_id')) {
            $query->where('school_id', $request->school_id);
        }

        $learners = $query->orderByDesc('total_xp')->paginate(25);
        $schools  = School::orderBy('name')->get();

        return view('admin.learner-portal', compact('learners', 'schools'));
    }

    public function generateLearnerPin(Learner $learner)
    {
        $pin = Learner::generatePin();
        $learner->update(['pin' => $pin]);

        ActivityLog::log('admin_generate_pin', "Generated new PIN for learner: {$learner->getFullName()}", 'learner', $learner->id);

        return back()->with('success', "PIN for {$learner->getFullName()}: {$pin}");
    }

    public function resetLearnerXp(Learner $learner)
    {
        $learner->update([
            'total_xp'        => 0,
            'current_streak'  => 0,
            'longest_streak'  => 0,
            'last_activity_date' => null,
        ]);

        ActivityLog::log('admin_reset_learner_xp', "Reset XP and streak for learner: {$learner->getFullName()}", 'learner', $learner->id);

        return back()->with('success', "XP and streak reset for {$learner->getFullName()}.");
    }
}
