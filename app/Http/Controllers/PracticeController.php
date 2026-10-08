<?php

namespace App\Http\Controllers;

use App\Support\Directory;

use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\ReadingMaterial;
use App\Models\Learner;
use App\Services\BadgeService;
use App\Services\MLClassificationService;
use App\Services\ReadingAnalyzerService;
use App\Services\SpeechToTextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PracticeController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $learnerIds = $user->isAdmin()
            ? Learner::pluck('id')
            : $user->accessibleLearnersQuery()->pluck('learners.id');

        $recentSessions = PracticeSession::whereIn('learner_id', $learnerIds)
            ->with('learner')
            ->latest()
            ->limit(15)
            ->get();

        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());

        return view('practice.index', compact('recentSessions', 'learners'));
    }

    public function items(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:sight_word,phonemic_sound,rhyme,syllable,blend',
            'grade_level' => 'required|integer|min:1|max:12',
            'language' => 'nullable|in:en,fil',
        ]);

        $query = PracticeItem::active()
            ->forType($validated['type'])
            ->forGrade($validated['grade_level']);

        if ($validated['language'] ?? null) {
            $query->forLanguage($validated['language']);
        }

        $items = $query->orderBy('content')->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function materials(Request $request)
    {
        $validated = $request->validate([
            'grade_level' => 'required|integer|min:1|max:12',
            'language' => 'nullable|in:en,fil',
        ]);

        $query = ReadingMaterial::active()
            ->forGrade($validated['grade_level']);

        if ($validated['language'] ?? null) {
            $query->where('language', $validated['language']);
        }

        $materials = $query->orderBy('title')->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'grade_level' => $m->grade_level,
                'language' => $m->language,
                'language_name' => $m->getLanguageName(),
                'word_count' => $m->word_count,
                'content' => $m->content,
            ]);

        return response()->json([
            'success' => true,
            'data' => $materials,
        ]);
    }

    public function phonemic()
    {
        $user = auth()->user();
        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());

        return view('practice.phonemic', compact('learners'));
    }

    public function sightWords()
    {
        $user = auth()->user();
        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());

        return view('practice.sight-words', compact('learners'));
    }

    public function guidedReading(Request $request)
    {
        $user = auth()->user();
        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());

        $query = ReadingMaterial::active();

        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }
        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }

        $materials = $query->orderBy('grade_level')->orderBy('difficulty')->paginate(12);

        return view('practice.guided-reading', compact('materials', 'learners'));
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'session_type' => 'required|in:phonemic,sight_words,guided_reading,comprehension',
            'score' => 'nullable|numeric|min:0|max:100',
            'time_spent' => 'required|integer|min:0',
            'material_id' => 'nullable|exists:reading_materials,id',
            'details' => 'nullable|array',
        ]);

        $learner = Learner::findOrFail($validated['learner_id']);

        $user = auth()->user();
        $accessibleIds = $user->isAdmin()
            ? Learner::pluck('id')
            : $user->accessibleLearnersQuery()->pluck('learners.id');

        if (! $accessibleIds->contains($learner->id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($validated['material_id'] ?? null) {
            $material = ReadingMaterial::active()->find($validated['material_id']);
            if (! $material) {
                return back()->withErrors(['material_id' => 'Invalid material.'])->withInput();
            }
        }

        $session = PracticeSession::create([
            'learner_id' => $validated['learner_id'],
            'material_id' => $validated['material_id'] ?? null,
            'session_type' => $validated['session_type'],
            'score' => $validated['score'],
            'time_spent' => $validated['time_spent'],
            'details' => $validated['details'] ?? null,
            'completed_at' => now(),
            'created_at' => now(),
        ]);

        $learner->addXp(10);
        $learner->recordActivity();

        $badgeService = app(BadgeService::class);
        $badgeService->checkAndAward($learner);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'session_id' => $session->id,
                'xp_earned' => 10,
            ]);
        }

        return back()->with('success', 'Practice session recorded. +10 XP earned!');
    }

    public function recommend(Learner $learner)
    {
        $user = auth()->user();
        $accessibleIds = $user->isAdmin()
            ? Learner::pluck('id')
            : $user->accessibleLearnersQuery()->pluck('learners.id');

        if (! $accessibleIds->contains($learner->id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $latestAssessment = $learner->assessments()->with('result')->latest()->first();
        $weaknessMap = [
            1 => 'phonemic',
            2 => 'sight_words',
            3 => 'guided_reading',
            4 => 'comprehension',
        ];

        $recommended = [];
        if ($latestAssessment && $latestAssessment->result && $latestAssessment->result->primary_weakness) {
            $type = $weaknessMap[$latestAssessment->result->primary_weakness] ?? 'phonemic';
            $recommended[] = [
                'type' => $type,
                'reason' => 'Based on latest assessment weakness',
                'priority' => 'high',
            ];
        }

        $allTypes = ['phonemic', 'sight_words', 'guided_reading', 'comprehension'];
        foreach ($allTypes as $type) {
            if (! in_array($type, array_column($recommended, 'type'))) {
                $recommended[] = [
                    'type' => $type,
                    'reason' => 'General practice',
                    'priority' => 'medium',
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $recommended]);
    }

    public function history(Learner $learner)
    {
        $user = auth()->user();
        $accessibleIds = $user->isAdmin()
            ? Learner::pluck('id')
            : $user->accessibleLearnersQuery()->pluck('learners.id');

        if (! $accessibleIds->contains($learner->id)) {
            abort(403);
        }

        $sessions = PracticeSession::where('learner_id', $learner->id)
            ->with('material')
            ->latest()
            ->paginate(20);

        $stats = [
            'total_sessions' => PracticeSession::where('learner_id', $learner->id)->count(),
            'avg_score' => PracticeSession::where('learner_id', $learner->id)->whereNotNull('score')->avg('score'),
            'total_time' => PracticeSession::where('learner_id', $learner->id)->sum('time_spent'),
            'by_type' => PracticeSession::where('learner_id', $learner->id)
                ->selectRaw('session_type, count(*) as count, avg(score) as avg_score')
                ->groupBy('session_type')
                ->get(),
        ];

        return view('practice.history', compact('sessions', 'stats', 'learner'));
    }

    public function analyzeGuidedReading(Request $request, Learner $learner, ReadingMaterial $material)
    {
        $user = auth()->user();
        $accessibleIds = $user->isAdmin()
            ? Learner::pluck('id')
            : $user->accessibleLearnersQuery()->pluck('learners.id');

        if (! $accessibleIds->contains($learner->id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'audio' => 'required|file|max:25600',
        ]);

        $file = $request->file('audio');
        $extension = $file->getClientOriginalExtension() ?: 'webm';
        $filename = "practice_{$learner->id}_{$material->id}_" . time() . ".{$extension}";
        $path = $file->storeAs('practice/audio', $filename, 'public');

        $sttService = app(SpeechToTextService::class);

        try {
            $transcription = $sttService->transcribe($file->getRealPath(), $material->language);
        } catch (\App\Exceptions\SpeechServiceUnavailable $e) {
            // Practice is not recorded as an assessment, but scoring the
            // placeholder transcript would still show the learner invented
            // feedback about words they never read.
            \Illuminate\Support\Facades\Log::error('Practice analysis: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => $e->forTeacher()], 503);
        }

        $analyzer = app(ReadingAnalyzerService::class);
        $analysis = $analyzer->analyze(
            $transcription,
            $material->content,
            $transcription['duration'] ?? 60
        );

        $mlService = app(MLClassificationService::class);
        $classification = $mlService->classify($analysis['ml_features']);

        // Practice asks no comprehension questions, so that skill has no evidence
        // behind it here; the rest is judged from this reading like anywhere else.
        $analysis['primary_weakness'] = MLClassificationService::resolveWeaknessId(
            $classification,
            \App\Services\WeaknessEvidence::statuses($analysis, $learner->grade_level, null),
            $analysis['primary_weakness'] ?? null
        );
        $analysis['confidence_score'] = $classification['confidence'] ?? $analysis['confidence_score'];

        return response()->json([
            'success' => true,
            'data' => [
                'accuracy_rate' => $analysis['accuracy_rate'],
                'words_per_minute' => $analysis['words_per_minute'],
                'fluency_score' => $analysis['fluency_score'],
                'reading_level' => $analysis['reading_level'],
                'primary_weakness' => $analysis['primary_weakness'],
                'confidence_score' => $analysis['confidence_score'],
                'error_count' => $analysis['error_count'],
                'substitutions' => $analysis['substitutions'],
                'omissions' => $analysis['omissions'],
                'insertions' => $analysis['insertions'],
                'skill_scores' => $analysis['skill_scores'] ?? [],
            ],
        ]);
    }
}
