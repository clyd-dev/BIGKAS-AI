<?php

namespace App\Http\Controllers;

use App\Models\ReadingMaterial;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = ReadingMaterial::active();

        // Teachers only ever see materials for their own assigned grade —
        // the grade filter isn't shown to them at all (nothing to pick).
        $lockedGrade = null;
        if (!$user->isAdmin()) {
            $lockedGrade = $user->taughtClasses()->first()?->grade_level;
            $query->where('grade_level', $lockedGrade ?? -1);
        } elseif ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }

        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $materials = $query->orderBy('grade_level')->orderBy('title')->paginate(20)->withQueryString();

        return view('materials.index', compact('materials', 'lockedGrade'));
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
        $user = auth()->user();
        $lockedClass = $user->isAdmin() ? null : $user->taughtClasses()->first();

        return view('materials.create', compact('lockedClass'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        $rules = $this->materialRules($user);

        $validated = $request->validate($rules);

        if ($user->isAdmin()) {
            $gradeLevel = $validated['grade_level'];
        } else {
            $lockedClass = $user->taughtClasses()->first();
            abort_if(!$lockedClass, 403, 'You are not assigned to a class/section yet.');
            $gradeLevel = $lockedClass->grade_level;
        }

        $material = ReadingMaterial::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'language' => $validated['language'],
            'difficulty' => $validated['difficulty'],
            'type' => $validated['type'],
            'grade_level' => $gradeLevel,
            'source' => $validated['source'] ?? null,
            'word_count' => count(preg_split('/\s+/', trim($validated['content']), -1, PREG_SPLIT_NO_EMPTY)),
            'created_by' => $user->id,
        ]);

        $this->syncQuestions($material, $validated);

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
        $user = auth()->user();
        $lockedClass = $user->isAdmin() ? null : $user->taughtClasses()->first();

        return view('materials.edit', compact('material', 'lockedClass'));
    }

    public function update(Request $request, ReadingMaterial $material)
    {
        $user = auth()->user();

        $validated = $request->validate($this->materialRules($user));

        // Teachers can't move a material to a different grade — it stays
        // wherever it already is.
        $gradeLevel = $user->isAdmin() ? $validated['grade_level'] : $material->grade_level;

        $material->update([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'language' => $validated['language'],
            'difficulty' => $validated['difficulty'],
            'type' => $validated['type'],
            'grade_level' => $gradeLevel,
            'source' => $validated['source'] ?? null,
            'word_count' => count(preg_split('/\s+/', trim($validated['content']), -1, PREG_SPLIT_NO_EMPTY)),
        ]);

        $this->syncQuestions($material, $validated);

        ActivityLog::log('update_material', "Updated reading material: {$material->title}", 'reading_material', $material->id);

        return redirect()->route('materials.show', $material)
            ->with('success', 'Reading material updated successfully.');
    }

    private function materialRules($user): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'language' => 'required|in:en,fil',
            'difficulty' => 'required|in:easy,medium,hard',
            'type' => 'required|in:oral_reading,comprehension',
            'source' => 'nullable|string|max:255',
            'questions' => 'required_if:type,comprehension|array|min:1',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.question_type' => 'nullable|in:literal,inferential',
            'questions.*.option_a' => 'required_with:questions|string|max:255',
            'questions.*.option_b' => 'required_with:questions|string|max:255',
            'questions.*.option_c' => 'required_with:questions|string|max:255',
            'questions.*.option_d' => 'required_with:questions|string|max:255',
            'questions.*.correct_option' => 'required_with:questions|in:A,B,C,D',
        ];

        if ($user->isAdmin()) {
            $rules['grade_level'] = 'required|integer|min:3|max:6';
        }

        return $rules;
    }

    /**
     * Write the posted question set onto the material.
     *
     * Existing rows are updated in place by position so their IDs survive an
     * edit; extra rows are created and removed ones deleted. Past assessment
     * answers keep their own text snapshots, so editing questions here never
     * rewrites what an earlier learner was actually asked.
     */
    private function syncQuestions(ReadingMaterial $material, array $validated): void
    {
        if (($validated['type'] ?? null) !== ReadingMaterial::TYPE_COMPREHENSION) {
            $material->comprehensionQuestions()->delete();
            return;
        }

        $existing = $material->comprehensionQuestions()->orderBy('sort_order')->get();

        foreach (array_values($validated['questions'] ?? []) as $i => $q) {
            $options = [
                'A' => $q['option_a'],
                'B' => $q['option_b'],
                'C' => $q['option_c'],
                'D' => $q['option_d'],
            ];

            $attributes = [
                'question' => $q['question'],
                'question_type' => $q['question_type'] ?? 'literal',
                'correct_answer' => $options[$q['correct_option']],
                'option_a' => $q['option_a'],
                'option_b' => $q['option_b'],
                'option_c' => $q['option_c'],
                'option_d' => $q['option_d'],
                'sort_order' => $i + 1,
            ];

            if ($row = $existing->get($i)) {
                $row->update($attributes);
            } else {
                $material->comprehensionQuestions()->create($attributes);
            }
        }

        // Drop any rows the teacher removed from the form.
        $keep = count($validated['questions'] ?? []);
        $existing->slice($keep)->each(fn ($row) => $row->delete());
    }

    public function destroy(ReadingMaterial $material)
    {
        $material->update(['is_active' => false]);

        ActivityLog::log('delete_material', "Deactivated reading material: {$material->title}", 'reading_material', $material->id);

        return redirect()->route('materials.index')
            ->with('success', "Material \"{$material->title}\" has been deactivated.");
    }
}
