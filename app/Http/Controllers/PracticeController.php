<?php

namespace App\Http\Controllers;

use App\Models\PracticeSession;
use App\Models\ReadingMaterial;
use App\Models\Learner;
use Illuminate\Http\Request;

class PracticeController extends Controller
{
    public function index()
    {
        return view('practice.index');
    }

    public function phonemic()
    {
        $activities = [
            ['name' => 'Sound Matching', 'description' => 'Match pictures with the same beginning sound', 'icon' => 'volume-up'],
            ['name' => 'Syllable Counting', 'description' => 'Clap and count syllables in words', 'icon' => 'hand-paper'],
            ['name' => 'Rhyming Words', 'description' => 'Find words that rhyme', 'icon' => 'music'],
            ['name' => 'Sound Blending', 'description' => 'Blend sounds to form words', 'icon' => 'puzzle-piece'],
        ];

        return view('practice.phonemic', compact('activities'));
    }

    public function sightWords()
    {
        $wordsByGrade = [
            1 => ['the', 'and', 'a', 'to', 'said', 'in', 'he', 'it', 'of', 'was', 'she', 'for', 'that', 'is', 'his', 'but', 'they', 'my', 'are', 'with'],
            2 => ['would', 'make', 'like', 'him', 'has', 'her', 'some', 'then', 'could', 'them', 'very', 'when', 'what', 'your', 'been', 'their', 'its', 'over', 'just', 'also'],
            3 => ['about', 'never', 'going', 'before', 'always', 'because', 'around', 'should', 'better', 'between', 'different', 'important', 'another', 'together', 'through'],
        ];

        return view('practice.sight-words', compact('wordsByGrade'));
    }

    public function guidedReading(Request $request)
    {
        $query = ReadingMaterial::active();

        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }
        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }

        $materials = $query->orderBy('grade_level')->orderBy('difficulty')->paginate(12);

        return view('practice.guided-reading', compact('materials'));
    }

    public function complete(Request $request)
    {
        $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'session_type' => 'required|in:phonemic,sight_words,guided_reading,comprehension',
            'score' => 'nullable|numeric|min:0|max:100',
            'time_spent' => 'required|integer|min:0',
        ]);

        $session = PracticeSession::create([
            'learner_id' => $request->learner_id,
            'material_id' => $request->material_id,
            'session_type' => $request->session_type,
            'score' => $request->score,
            'time_spent' => $request->time_spent,
            'details' => $request->details,
            'completed_at' => now(),
            'created_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'session_id' => $session->id]);
        }

        return back()->with('success', 'Practice session recorded.');
    }
}
