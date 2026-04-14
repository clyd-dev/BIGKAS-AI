<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MLClassificationService;
use App\Services\ReadingAnalyzerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MLApiController extends Controller
{
    public function __construct(
        protected MLClassificationService $mlService,
        protected ReadingAnalyzerService $analyzerService,
    ) {}

    /**
     * Classify reading weakness from assessment features
     */
    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'accuracy_rate' => 'required|numeric',
            'words_per_minute' => 'required|numeric',
            'error_count' => 'required|numeric',
            'substitution_count' => 'nullable|integer',
            'omission_count' => 'nullable|integer',
            'insertion_count' => 'nullable|integer',
            'self_correction_count' => 'nullable|integer',
        ]);

        $features = [
            'accuracy_rate' => (float) $validated['accuracy_rate'],
            'words_per_minute' => (float) $validated['words_per_minute'],
            'error_count' => (int) $validated['error_count'],
            'substitution_count' => (int) ($validated['substitution_count'] ?? 0),
            'omission_count' => (int) ($validated['omission_count'] ?? 0),
            'insertion_count' => (int) ($validated['insertion_count'] ?? 0),
            'self_correction_count' => (int) ($validated['self_correction_count'] ?? 0),
        ];

        $result = $this->mlService->classify($features);

        $weaknessLabels = config('bigkas.weakness_categories', [
            1 => 'Phonemic Awareness',
            2 => 'Decoding Accuracy',
            3 => 'Oral Reading Fluency',
            4 => 'Reading Comprehension',
        ]);

        return $this->success([
            'primary_weakness' => $result['primary_weakness'] ?? null,
            'weakness_label' => $weaknessLabels[$result['primary_weakness'] ?? 0] ?? 'Unknown',
            'confidence' => $result['confidence'] ?? null,
            'probabilities' => $result['probabilities'] ?? null,
            'method' => $result['method'] ?? 'unknown',
            'features_used' => $features,
        ], 'Classification completed');
    }

    /**
     * Full text analysis (compare original vs transcribed)
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_text' => 'required|string',
            'transcribed_text' => 'required|string',
            'duration' => 'nullable|numeric',
        ]);

        $duration = (float) ($validated['duration'] ?? 60);

        $analysis = $this->analyzerService->analyze(
            ['text' => $validated['transcribed_text'], 'words' => []],
            $validated['original_text'],
            $duration
        );

        // Also run ML classification
        $classification = $this->mlService->classify([
            'accuracy_rate' => $analysis['accuracy_rate'],
            'words_per_minute' => $analysis['words_per_minute'],
            'error_count' => $analysis['error_count'],
            'substitution_count' => $analysis['substitutions'],
            'omission_count' => $analysis['omissions'],
            'insertion_count' => $analysis['insertions'],
            'self_correction_count' => $analysis['self_corrections'] ?? 0,
        ]);

        return $this->success([
            'analysis' => $analysis,
            'reading_level' => $analysis['reading_level'],
            'classification' => $classification,
        ], 'Analysis completed');
    }

    /**
     * ML service health check
     */
    public function health(): JsonResponse
    {
        $mlApiUrl = config('services.ml_api.url', 'http://localhost:5000');
        $healthy = false;
        $mlStatus = 'unreachable';
        $mode = 'rule_based_fallback';

        try {
            $response = Http::timeout(5)->connectTimeout(3)->get($mlApiUrl . '/health');

            if ($response->ok()) {
                $healthy = true;
                $mlStatus = 'healthy';
                $mode = 'ml_model';
            }
        } catch (\Exception $e) {
            $mlStatus = 'error: ' . $e->getMessage();
        }

        $hasApiKey = ! empty(config('services.openai.api_key', ''));

        return $this->success([
            'status' => 'ok',
            'ml_api' => [
                'url' => $mlApiUrl,
                'status' => $mlStatus,
                'healthy' => $healthy,
                'mode' => $mode,
            ],
            'speech_to_text' => [
                'provider' => 'OpenAI Whisper',
                'api_key_configured' => $hasApiKey,
                'mode' => $hasApiKey ? 'api' : 'mock',
            ],
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'timestamp' => now()->toIso8601String(),
        ]);
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
