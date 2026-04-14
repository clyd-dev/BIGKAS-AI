<?php

namespace App\Http\Controllers;

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
            'total_users' => User::count(),
            'total_teachers' => User::where('role', 'teacher')->count(),
            'total_parents' => User::where('role', 'parent')->count(),
            'total_students' => User::where('role', 'student')->count(),
            'total_learners' => Learner::count(),
            'total_assessments' => Assessment::count(),
            'total_schools' => School::count(),
            'frustration_learners' => Learner::where('reading_level', 'frustration')->count(),
            'instructional_learners' => Learner::where('reading_level', 'instructional')->count(),
            'independent_learners' => Learner::where('reading_level', 'independent')->count(),
        ];

        $recentActivity = ActivityLog::with('user')->latest()->limit(20)->get();

        return view('admin.index', compact('stats', 'recentActivity'));
    }

    public function users(Request $request)
    {
        $query = User::with('school');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $users = $query->orderBy('name')->paginate(20);

        return view('admin.users', compact('users'));
    }

    public function updateUserRole(Request $request, User $user)
    {
        $request->validate(['role' => 'required|in:admin,teacher,parent,student']);
        $user->update(['role' => $request->role]);

        return back()->with('success', "Role updated for {$user->name}.");
    }

    public function activateUser(User $user)
    {
        $user->update(['is_active' => true]);
        return back()->with('success', "{$user->name} has been activated.");
    }

    public function deactivateUser(User $user)
    {
        $user->update(['is_active' => false]);
        return back()->with('success', "{$user->name} has been deactivated.");
    }

    public function resetUserPassword(User $user)
    {
        $user->update(['password' => 'admin123']);
        return back()->with('success', "Password reset for {$user->name}. Temporary: admin123");
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

        School::create($request->only(['name', 'school_id_number', 'address', 'district', 'division', 'region', 'contact_number', 'email', 'principal_name']));

        return back()->with('success', 'School added successfully.');
    }

    public function updateSchool(Request $request, School $school)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $school->update($request->only(['name', 'school_id_number', 'address', 'district', 'division', 'region', 'contact_number', 'email', 'principal_name']));

        return back()->with('success', 'School updated.');
    }

    public function deleteSchool(School $school)
    {
        $school->update(['is_active' => false]);
        return back()->with('success', 'School deactivated.');
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

        Intervention::create($request->only([
            'name', 'description', 'target_weakness', 'activity_type', 'materials_needed',
            'instructions', 'for_teacher', 'for_parent', 'grade_level_min', 'grade_level_max',
            'estimated_duration', 'effectiveness_score',
        ]));

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

        return back()->with('success', 'Intervention updated.');
    }

    public function deleteIntervention(Intervention $intervention)
    {
        $intervention->update(['is_active' => false]);
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
        foreach ($request->except('_token') as $key => $value) {
            SystemSetting::setValue($key, $value);
        }

        return back()->with('success', 'Settings saved.');
    }
}
