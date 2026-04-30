<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\InterventionLog;
use App\Models\Learner;
use App\Models\PracticeSession;
use App\Models\ReadingMaterial;
use App\Services\BadgeService;
use Illuminate\Http\Request;

class StudentActivityController extends Controller
{
    /**
     * Show approved activities (teacher-assigned interventions).
     */
    public function index(Request $request)
    {
        $learner = $request->attributes->get('learner');

        $pendingActivities = InterventionLog::where('learner_id', $learner->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with('intervention')
            ->latest()
            ->get();

        $completedActivities = InterventionLog::where('learner_id', $learner->id)
            ->where('status', 'completed')
            ->with('intervention')
            ->latest()
            ->limit(10)
            ->get();

        return view('student.activities.index', compact('learner', 'pendingActivities', 'completedActivities'));
    }

    /**
     * Show a specific activity.
     */
    public function show(Request $request, InterventionLog $log)
    {
        $learner = $request->attributes->get('learner');

        if ($log->learner_id !== $learner->id) {
            abort(403);
        }

        $log->load('intervention');

        return view('student.activities.show', compact('learner', 'log'));
    }

    /**
     * Mark an activity as started.
     */
    public function start(Request $request, InterventionLog $log)
    {
        $learner = $request->attributes->get('learner');

        if ($log->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($log->status === 'pending') {
            $log->start();
        }

        return response()->json(['status' => 'in_progress']);
    }

    /**
     * Mark an activity as completed.
     */
    public function complete(Request $request, InterventionLog $log)
    {
        $learner = $request->attributes->get('learner');

        if ($log->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $log->complete(
            $request->input('effectiveness_rating'),
            $request->input('notes')
        );

        // Award XP and check badges
        $learner->addXp(15);
        $learner->recordActivity();
        app(BadgeService::class)->checkAndAward($learner);

        return response()->json([
            'status' => 'completed',
            'xp_earned' => 15,
            'message' => 'Activity completed! +15 XP',
        ]);
    }

    /**
     * Flash card practice.
     */
    public function flashCards(Request $request)
    {
        $learner = $request->attributes->get('learner');

        // Get materials at learner's grade level
        $materials = ReadingMaterial::where('grade_level', '<=', $learner->grade_level)
            ->orderBy('difficulty')
            ->orderBy('grade_level')
            ->limit(20)
            ->get();

        // Build word cards from materials
        $cards = collect();
        foreach ($materials as $material) {
            $words = preg_split('/\s+/', $material->content);
            // Pick unique words 4+ chars (more challenging for flash cards)
            $filtered = collect($words)
                ->map(fn($w) => strtolower(preg_replace('/[^a-zA-ZáéíóúñÑ\-]/', '', $w)))
                ->filter(fn($w) => mb_strlen($w) >= 4)
                ->unique()
                ->values();
            $cards = $cards->merge($filtered);
        }

        $cards = $cards->unique()->shuffle()->take(30)->values();

        return view('student.activities.flashcards', compact('learner', 'cards'));
    }

    /**
     * Save flash card practice session result.
     */
    public function saveFlashCardResult(Request $request)
    {
        $learner = $request->attributes->get('learner');

        $request->validate([
            'total_cards' => 'required|integer|min:1',
            'correct_cards' => 'required|integer|min:0',
            'duration_seconds' => 'required|integer|min:1',
        ]);

        PracticeSession::create([
            'learner_id' => $learner->id,
            'session_type' => 'sight_words',
            'time_spent' => $request->duration_seconds,
            'score' => round(($request->correct_cards / $request->total_cards) * 100, 1),
            'details' => [
                'type' => 'flashcard',
                'total_cards' => $request->total_cards,
                'correct_cards' => $request->correct_cards,
                'accuracy' => round(($request->correct_cards / $request->total_cards) * 100, 1),
            ],
            'completed_at' => now(),
        ]);

        $xp = $request->correct_cards * 2;
        $learner->addXp($xp);
        $learner->recordActivity();
        app(BadgeService::class)->checkAndAward($learner);

        return response()->json([
            'success' => true,
            'xp_earned' => $xp,
            'message' => "Practice complete! +{$xp} XP",
        ]);
    }

    /**
     * Guided reading practice.
     */
    public function guidedReading(Request $request)
    {
        $learner = $request->attributes->get('learner');

        $materials = ReadingMaterial::where('grade_level', '<=', $learner->grade_level)
            ->orderByDesc('grade_level')
            ->orderBy('difficulty')
            ->limit(10)
            ->get();

        return view('student.activities.guided-reading', compact('learner', 'materials'));
    }
}
