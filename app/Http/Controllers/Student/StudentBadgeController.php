<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Learner;
use Illuminate\Http\Request;

class StudentBadgeController extends Controller
{
    /**
     * Badge showcase - all badges (earned + locked).
     */
    public function index(Request $request)
    {
        $learner = $request->attributes->get('learner');

        $allBadges = Badge::active()->orderBy('sort_order')->get();
        $earnedIds = $learner->badges()->pluck('badges.id')->toArray();

        // Group by category
        $badgesByCategory = $allBadges->groupBy('category');

        return view('student.badges.index', compact('learner', 'badgesByCategory', 'earnedIds'));
    }

    /**
     * Class leaderboard.
     */
    public function leaderboard(Request $request)
    {
        $learner = $request->attributes->get('learner');

        // Get classmates (same class_id)
        $classmates = Learner::where('class_id', $learner->class_id)
            ->where('is_active', true)
            ->orderByDesc('total_xp')
            ->get()
            ->map(function ($l) use ($learner) {
                return [
                    'id' => $l->id,
                    'name' => $l->first_name . ' ' . substr($l->last_name, 0, 1) . '.',
                    'xp' => $l->total_xp,
                    'streak' => $l->current_streak,
                    'badges' => $l->getBadgeCount(),
                    'is_me' => $l->id === $learner->id,
                ];
            });

        $myRank = $classmates->search(fn($c) => $c['is_me']) + 1;

        return view('student.badges.leaderboard', compact('learner', 'classmates', 'myRank'));
    }
}
