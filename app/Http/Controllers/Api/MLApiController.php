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

        // No rule answers in the model's place, so an unavailable model is an
        // honest 503 rather than a result that looks like a classification.
        if (! ($result['available'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'The analysis model is not running, so no weakness was classified.',
                'data' => [
                    'classification_status' => 'unavailable',
                    'reason' => $result['reason'] ?? 'unavailable',
                ],
            ], 503);
        }

        $weaknessCategories = config('bigkas.weakness_categories', []);
        $primaryWeakness = MLClassificationService::mapWeaknessToId($result['primary'] ?? '');
        $weaknessInfo = $weaknessCategories[$primaryWeakness] ?? null;

        return $this->success([
            'primary_weakness' => $primaryWeakness,
            'weakness_label' => $weaknessInfo['name'] ?? 'Unknown',
            'confidence' => $result['confidence'] ?? null,
            'probabilities' => $result['all_scores'] ?? null,
            'model_version' => $result['model_version'] ?? null,
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

        // The service's own health endpoint, read once and cached for a few
        // seconds. This used to probe /health, which the service does not have,
        // so it always reported unreachable. There is no rule-based mode: when
        // the model cannot answer, nothing classifies.
        $status = $this->mlService->serviceStatus();
        $healthy = $status['ok'];
        $mlStatus = $status['state'];
        $mode = $status['ok'] ? 'ml_model' : 'unavailable';

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
                'provider' => $hasApiKey ? 'OpenAI Whisper API' : 'faster-whisper (local)',
                'api_key_configured' => $hasApiKey,
                // There is no placeholder mode: when no engine is available,
                // recordings are saved but not scored.
                'mode' => $status['stt_ok'] ? ($hasApiKey ? 'api' : 'local') : 'unavailable',
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
