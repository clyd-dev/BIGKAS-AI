<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Teachers manage the achievement badges children earn in the student portal (managed from the Learners page). */
class BadgeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'required|string|max:500',
            'icon'           => 'required|string|max:10',
            'category'       => ['required', Rule::in(Badge::CATEGORIES)],
            'xp_reward'      => 'required|integer|min:0|max:10000',
            'criteria_type'  => ['required', Rule::in(array_keys(Badge::RULES))],
            'criteria_value' => 'nullable|integer|min:1|max:100000',
        ]);

        $rule = Badge::RULES[$data['criteria_type']];
        $criteria = ['type' => $data['criteria_type']];
        if ($rule[1]) {
            if (empty($data['criteria_value'])) {
                return back()->withInput()->withErrors(['criteria_value' => 'Enter how many are needed for this rule.']);
            }
            $criteria['value'] = (int) $data['criteria_value'];
        }

        $badge = Badge::create([
            'name'        => $data['name'],
            'slug'        => $this->uniqueSlug($data['name']),
            'description' => $data['description'],
            'icon'        => $data['icon'],
            'color'       => '#6C63FF',
            'category'    => $data['category'],
            'xp_reward'   => $data['xp_reward'],
            'criteria'    => $criteria,
            'sort_order'  => (Badge::max('sort_order') ?? 0) + 1,
            'is_active'   => true,
        ]);

        ActivityLog::log('create_badge', "Created badge: {$badge->name}", 'badge', $badge->id);

        return $this->back("Badge \"{$badge->name}\" added.");
    }

    public function update(Request $request, Badge $badge)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'required|string|max:500',
            'icon'        => 'required|string|max:10',
            'xp_reward'   => 'required|integer|min:0|max:10000',
        ]);

        $badge->update($data);
        ActivityLog::log('update_badge', "Updated badge: {$badge->name}", 'badge', $badge->id);

        return $this->back('Badge updated.');
    }

    public function toggle(Badge $badge)
    {
        $badge->update(['is_active' => ! $badge->is_active]);
        $state = $badge->is_active ? 'activated' : 'deactivated';
        ActivityLog::log('toggle_badge', "Badge \"{$badge->name}\" {$state}", 'badge', $badge->id);

        return $this->back("Badge \"{$badge->name}\" {$state}.");
    }

    private function back(string $message)
    {
        return redirect(route('learners.index', ['portal_tab' => 'badges']) . '#student-portal')->with('success', $message);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'badge';
        $slug = $base;
        for ($i = 2; Badge::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
