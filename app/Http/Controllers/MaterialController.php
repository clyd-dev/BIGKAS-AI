<?php

namespace App\Http\Controllers;

use App\Models\ReadingMaterial;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $query = ReadingMaterial::active();

        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }
        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $materials = $query->orderBy('grade_level')->orderBy('title')->paginate(20);
        $gradeLevels = config('bigkas.grade_levels', []);

        return view('materials.index', compact('materials', 'gradeLevels'));
    }

    /**
     * JSON options for the teacher assessment wizard.
     *
     * Chain: grade (from learner) → language (en/fil) → assessment type →
     * material (last). Returns exact-grade matches plus an adjacent-grade
     * (±1) fallback group when the exact set is empty.
     *
     * Assessment type filters by comprehension-question availability:
     * oral_reading = all materials; comprehension/combined = only
     * materials that have comprehension questions.
     */
    public function options(Request $request)
    {
        $validated = $request->validate([
            'grade_level' => 'required|integer|min:1|max:12',
            'language' => 'required|in:en,fil',
            'assessment_type' => 'required|in:oral_reading,comprehension,combined',
            'with_fallback' => 'nullable|boolean',
        ]);

        $needsQuestions = in_array($validated['assessment_type'], ['comprehension', 'combined'], true);

        $buildQuery = function () use ($validated, $needsQuestions) {
            $query = ReadingMaterial::active()
                ->where('language', $validated['language'])
                ->withCount('comprehensionQuestions');

            if ($needsQuestions) {
                $query->whereHas('comprehensionQuestions');
            }

            return $query;
        };

        $shape = fn (ReadingMaterial $material) => [
            'id' => $material->id,
            'title' => $material->title,
            'grade_level' => $material->grade_level,
            'grade_level_name' => $material->getGradeLevelName(),
            'language' => $material->language,
            'language_name' => $material->getLanguageName(),
            'word_count' => $material->word_count,
            'category' => $material->category,
            'difficulty' => $material->difficulty,
            'has_questions' => ($material->comprehension_questions_count ?? 0) > 0,
        ];

        $exact = $buildQuery()
            ->where('grade_level', $validated['grade_level'])
            ->orderBy('title')
            ->get()
            ->map($shape)
            ->values();

        $fallback = [];
        if ($request->boolean('with_fallback', true) && $exact->isEmpty()) {
            $adjacent = array_values(array_filter(
                [$validated['grade_level'] - 1, $validated['grade_level'] + 1],
                fn ($grade) => $grade >= 1 && $grade <= 12
            ));

            $fallback = $buildQuery()
                ->whereIn('grade_level', $adjacent)
                ->orderBy('grade_level')
                ->orderBy('title')
                ->get()
                ->map($shape)
                ->values();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'exact' => $exact,
                'fallback' => $fallback,
                'grade_level' => (int) $validated['grade_level'],
                'language' => $validated['language'],
                'assessment_type' => $validated['assessment_type'],
            ],
        ]);
    }

    public function create()
    {
        $gradeLevels = config('bigkas.grade_levels', []);
        return view('materials.create', compact('gradeLevels'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'language' => 'required|in:en,fil,hil',
            'grade_level' => 'required|integer|min:1|max:12',
            'difficulty' => 'required|in:easy,medium,hard',
            'category' => 'required|in:narrative,expository,poetry,dialogue',
        ]);

        $material = ReadingMaterial::create(array_merge($request->only([
            'title', 'content', 'language', 'grade_level', 'difficulty', 'category', 'source', 'genre',
        ]), [
            'word_count' => count(preg_split('/\s+/', trim($request->content), -1, PREG_SPLIT_NO_EMPTY)),
            'created_by' => auth()->id(),
        ]));

        ActivityLog::log('create_material', "Created reading material: {$material->title}", 'reading_material', $material->id);

        return redirect()->route('materials.show', $material)
            ->with('success', 'Reading material created successfully.');
    }

    public function show(ReadingMaterial $material)
    {
        $material->load('comprehensionQuestions');
        $usageStats = $material->getUsageStats();

        return view('materials.show', compact('material', 'usageStats'));
    }

    public function edit(ReadingMaterial $material)
    {
        $gradeLevels = config('bigkas.grade_levels', []);
        return view('materials.edit', compact('material', 'gradeLevels'));
    }

    public function update(Request $request, ReadingMaterial $material)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'language' => 'required|in:en,fil,hil',
            'grade_level' => 'required|integer|min:1|max:12',
            'difficulty' => 'required|in:easy,medium,hard',
            'category' => 'required|in:narrative,expository,poetry,dialogue',
        ]);

        $material->update(array_merge($request->only([
            'title', 'content', 'language', 'grade_level', 'difficulty', 'category', 'source', 'genre',
        ]), [
            'word_count' => count(preg_split('/\s+/', trim($request->content), -1, PREG_SPLIT_NO_EMPTY)),
        ]));

        ActivityLog::log('update_material', "Updated reading material: {$material->title}", 'reading_material', $material->id);

        return redirect()->route('materials.show', $material)
            ->with('success', 'Reading material updated successfully.');
    }

    public function destroy(ReadingMaterial $material)
    {
        $material->update(['is_active' => false]);

        ActivityLog::log('delete_material', "Deactivated reading material: {$material->title}", 'reading_material', $material->id);

        return redirect()->route('materials.index')
            ->with('success', "Material \"{$material->title}\" has been deactivated.");
    }
}
