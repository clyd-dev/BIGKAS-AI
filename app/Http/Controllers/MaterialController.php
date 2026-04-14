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

        return redirect()->route('materials.show', $material)
            ->with('success', 'Reading material updated successfully.');
    }

    public function destroy(ReadingMaterial $material)
    {
        $material->update(['is_active' => false]);

        return redirect()->route('materials.index')
            ->with('success', "Material \"{$material->title}\" has been deactivated.");
    }
}
