<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReadingMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialApiController extends Controller
{
    /**
     * List materials with optional filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = ReadingMaterial::where('is_active', true);

        if ($language = $request->input('language')) {
            $query->where('language', $language);
        }
        if ($grade = $request->input('grade_level')) {
            $query->where('grade_level', (int) $grade);
        }
        if ($difficulty = $request->input('difficulty')) {
            $query->where('difficulty', $difficulty);
        }

        $materials = $query->orderBy('grade_level')->orderBy('title')->get();

        return $this->success([
            'materials' => $materials->map(fn ($m) => $this->formatMaterial($m, false))->toArray(),
        ]);
    }

    /**
     * Show material details (includes full content)
     */
    public function show(ReadingMaterial $material): JsonResponse
    {
        return $this->success([
            'material' => $this->formatMaterial($material, true),
            'usage_stats' => [
                'times_used' => $material->assessments()->count(),
            ],
        ]);
    }

    /**
     * Create new material
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'teacher'])) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|min:3',
            'content' => 'required|string|min:10',
            'language' => 'required|string',
            'grade_level' => 'required|integer|min:1|max:6',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'category' => 'nullable|string',
            'source' => 'nullable|string',
        ]);

        $content = trim($validated['content']);
        $wordCount = count(preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY));

        $material = ReadingMaterial::create([
            'title' => $validated['title'],
            'content' => $content,
            'language' => $validated['language'],
            'grade_level' => $validated['grade_level'],
            'difficulty' => $validated['difficulty'] ?? 'medium',
            'category' => $validated['category'] ?? 'narrative',
            'word_count' => $wordCount,
            'source' => $validated['source'] ?? null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return $this->success([
            'material' => $this->formatMaterial($material, true),
        ], 'Material created successfully', 201);
    }

    /**
     * Update material
     */
    public function update(Request $request, ReadingMaterial $material): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'teacher'])) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|min:3',
            'content' => 'required|string|min:10',
            'language' => 'required|string',
            'grade_level' => 'required|integer|min:1|max:6',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'category' => 'nullable|string',
            'source' => 'nullable|string',
        ]);

        $content = trim($validated['content']);
        $wordCount = count(preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY));

        $material->update([
            'title' => $validated['title'],
            'content' => $content,
            'language' => $validated['language'],
            'grade_level' => $validated['grade_level'],
            'difficulty' => $validated['difficulty'] ?? 'medium',
            'category' => $validated['category'] ?? 'narrative',
            'word_count' => $wordCount,
            'source' => $validated['source'] ?? null,
        ]);

        return $this->success([
            'material' => $this->formatMaterial($material->fresh(), true),
        ], 'Material updated successfully');
    }

    /**
     * Delete (deactivate) material
     */
    public function destroy(Request $request, ReadingMaterial $material): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return $this->error('Unauthorized', 403);
        }

        $material->update(['is_active' => false]);

        return $this->success([], 'Material deactivated successfully');
    }

    /**
     * Format material for API response
     */
    private function formatMaterial(ReadingMaterial $m, bool $includeContent = false): array
    {
        $data = [
            'id' => $m->id,
            'title' => $m->title,
            'language' => $m->language,
            'grade_level' => $m->grade_level,
            'difficulty' => $m->difficulty,
            'category' => $m->category,
            'word_count' => $m->word_count,
            'source' => $m->source,
            'created_at' => $m->created_at,
        ];

        if ($includeContent) {
            $data['content'] = $m->content;
        }

        return $data;
    }

    // ----- JSON helper methods -----

    protected function success(array $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
