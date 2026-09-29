<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MLClassificationService
{
    protected string $apiUrl;
    protected int $timeout;
    protected bool $enabled;

    public function __construct()
    {
        $config = config('services.ml_api', []);
        $this->apiUrl = $config['url'] ?? 'http://127.0.0.1:5000'; // Default to local Python Flask
        $this->timeout = $config['timeout'] ?? 5; // Reduced timeout so it falls back quickly if down
        $this->enabled = (bool) ($config['enabled'] ?? true);
    }

    public function classify(array $features): array
    {
        if (!$this->enabled) {
            return $this->fallbackClassification($features);
        }

        try {
            $response = $this->makeRequest('/api/classify', $features);

            return [
                'primary' => $response['primary_weakness'] ?? null,
                'secondary' => $response['secondary_weakness'] ?? null,
                'confidence' => $response['confidence'] ?? 0.8,
                'all_scores' => $response['scores'] ?? [],
            ];
        } catch (\Exception $e) {
            // IF PYTHON IS DOWN, USE THE FALLBACK!
            Log::warning('ML Microservice offline or failed, using Rule-Based Fallback. Error: ' . $e->getMessage());
            return $this->fallbackClassification($features);
        }
    }

    /**
     * Rule-Based Fallback (from your capstone architecture proposal)
     * This acts as the safety net if the Python AI is offline.
     *
     * Updated to use the 12-feature rate-based keys that match the
     * ml_features array from ReadingAnalyzerService::analyze().
     * This way, classify() receives the same array whether it routes
     * to Flask or falls back to rules — no key translation needed.
     */
    protected function fallbackClassification(array $features): array
    {
        $accuracy = $features['accuracy_rate'] ?? 0;
        $wpm = $features['words_per_minute'] ?? 0;
        $fluency = $features['fluency_score'] ?? 5;
        $subRate = $features['substitution_rate'] ?? 0;
        $omRate = $features['omission_rate'] ?? 0;
        $phoneticRate = $features['phonetic_error_rate'] ?? 0;
        $vowelRate = $features['vowel_error_rate'] ?? 0;
        $blendRate = $features['blend_error_rate'] ?? 0;
        $pauseFreq = $features['pause_frequency'] ?? 0;

        $primary = 'Instructional (Mixed)';

        if ($accuracy >= 95 && $wpm >= 60 && $fluency >= 8) {
            $primary = '0';
        } elseif (($phoneticRate + $vowelRate + $blendRate) > 0.5 && $accuracy < 85) {
            // More than half of all errors are phonemic in nature
            $primary = '1';
        } elseif ($subRate > 0.1 && $accuracy < 90) {
            // High substitution rate + low accuracy = decoding problem
            $primary = '2';
        } elseif ($accuracy >= 90 && ($wpm < 80 || $fluency < 6 || $pauseFreq > 0.1)) {
            // Can decode but reads slowly/haltingly
            $primary = '3';
        } elseif ($omRate > 0.1 && $accuracy >= 85) {
            // Skipping words despite being able to decode = possible comprehension issue
            $primary = '4';
        }

        return [
            'primary' => $primary,
            'secondary' => null,
            'confidence' => 0.5, // 50% confidence because it's a hard-coded fallback rule, not ML
            'all_scores' => [],
            'is_fallback' => true // Flag so the dashboard knows it was rule-based
        ];
    }

    public function analyze(array $features): array
    {
        if (!$this->enabled) {
            throw new \Exception('ML service is disabled');
        }
        return $this->makeRequest('/api/analyze', $features);
    }

    public function healthCheck(): array
    {
        if (!$this->enabled) {
            return ['status' => 'disabled', 'message' => 'ML service is disabled in configuration'];
        }

        try {
            $response = Http::timeout(5)->get($this->apiUrl . '/api/health');

            if ($response->successful()) {
                return [
                    'status' => 'ok',
                    'message' => 'ML service is running',
                    'model_version' => $response->json('model_version', 'unknown'),
                ];
            }

            return ['status' => 'error', 'message' => "Service returned HTTP {$response->status()}"];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function makeRequest(string $endpoint, array $data): array
    {
        $response = Http::timeout($this->timeout)
            ->post($this->apiUrl . $endpoint, $data);

        if (!$response->successful()) {
            throw new \Exception("ML API error: HTTP {$response->status()}");
        }

        return $response->json();
    }

    public function submitTrainingData(array $assessmentData, int $correctLabel): bool
    {
        if (!$this->enabled) return false;

        try {
            $this->makeRequest('/api/training-data', [
                'features' => $assessmentData,
                'label' => $correctLabel,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to submit training data: " . $e->getMessage());
            return false;
        }
    }

    public function getModelInfo(): array
    {
        try {
            $response = Http::timeout(5)->get($this->apiUrl . '/api/model-info');
            return $response->successful() ? $response->json() : [];
        } catch (\Exception $e) {
            return [];
        }
    }
}