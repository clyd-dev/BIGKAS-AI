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

    /**
     * Translate a prediction into the integer weakness id stored on
     * assessment_results. The service may answer with either the class number
     * or its label, and the rule-based fallback has labels of its own.
     */
    public static function mapWeaknessToId(mixed $predicted): ?int
    {
        $map = [
            '0' => 0,
            'Independent Reader' => 0,
            'Independent Reader / No Weakness' => 0,
            'None (Independent)' => 0,
            '1' => 1,
            'Phonemic Awareness' => 1,
            '2' => 2,
            'Decoding Accuracy' => 2,
            'Instructional (Mixed)' => 2,
            '3' => 3,
            'Oral Reading Fluency' => 3,
            '4' => 4,
            'Comprehension' => 4,
            'Reading Comprehension' => 4,
        ];

        return $map[(string) $predicted] ?? null;
    }

    /**
     * The weakness id to store for a classification.
     *
     * A prediction is only kept when the measured evidence backs it up, so the
     * stored weakness can never contradict the per-skill rows the teacher is
     * shown. Two reasons this matters:
     *
     *  - The three pattern features are shares of a reader's errors, so one
     *    vowel-ish slip reads as a maximal phonics signal and the classifier
     *    (and the older hand-written rules) answered "Phonemic Awareness" for
     *    readers whose real trouble was decoding or pace.
     *  - Comprehension is never measured by the classifier at all; without
     *    comprehension questions there is nothing behind such a prediction.
     *
     * When the top class is unsupported, the next most likely supported class
     * is used; if the evidence supports nothing, no weakness is stored.
     *
     * @param array<int, string> $statuses from WeaknessEvidence::statuses()
     * @param int|null           $fallbackId used when the classifier gave no usable answer
     */
    public static function resolveWeaknessId(array $classification, array $statuses, ?int $fallbackId = null): ?int
    {
        $id = self::mapWeaknessToId($classification['primary'] ?? '') ?? $fallbackId;

        // 0 means "no weakness found", which needs no supporting evidence.
        if ($id === null || $id === 0 || WeaknessEvidence::showsProblem($statuses[$id] ?? null)) {
            return $id;
        }

        // all_scores are probabilities in class order 0-4 (RF classes_).
        $scores = $classification['all_scores'] ?? [];

        if (count($scores) === 5) {
            arsort($scores);

            foreach (array_keys($scores) as $candidate) {
                $candidate = (int) $candidate;

                if ($candidate === 0 || WeaknessEvidence::showsProblem($statuses[$candidate] ?? null)) {
                    return $candidate;
                }
            }
        }

        // The classifier offered nothing the evidence backs, so go with the
        // clearest measured problem instead of inventing one.
        return WeaknessEvidence::clearestProblem($statuses);
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

        // Order matters. The phonemic test comes after the two that read real
        // magnitudes (accuracy, substitutions, pace), because phonetic/vowel/
        // blend are shares of this reader's own errors: one slip bucketed as a
        // vowel confusion makes them 1.0. Tested first, as they used to be, they
        // labelled every inaccurate reader "Phonemic Awareness". The share must
        // now be overwhelming, and WeaknessEvidence still has to back the answer
        // before it is stored against a learner.
        if ($accuracy >= 95 && $wpm >= 60 && $fluency >= 8) {
            $primary = '0';
        } elseif ($subRate > 0.1 && $accuracy < 90) {
            // High substitution rate + low accuracy = decoding problem
            $primary = '2';
        } elseif ($accuracy >= 90 && ($wpm < 80 || $fluency < 6 || $pauseFreq > 0.1)) {
            // Can decode but reads slowly/haltingly
            $primary = '3';
        } elseif (($phoneticRate + $vowelRate + $blendRate) > 0.8 && $accuracy < 85) {
            // Nearly every error this reader made sounds like the target word
            $primary = '1';
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