<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MLClassificationService
{
    protected string $apiUrl;
    protected int $timeout;
    protected bool $enabled;

    public function __construct()
    {
        $config = config('services.ml_api', []);
        $this->apiUrl = $config['url'] ?? 'http://localhost:5000';
        $this->timeout = $config['timeout'] ?? 30;
        $this->enabled = (bool) ($config['enabled'] ?? true);
    }

    public function classify(array $features): array
    {
        if (!$this->enabled) {
            throw new \Exception('ML service is disabled');
        }

        $response = $this->makeRequest('/api/classify', $features);

        return [
            'primary' => $response['primary_weakness'] ?? null,
            'secondary' => $response['secondary_weakness'] ?? null,
            'confidence' => $response['confidence'] ?? 0.5,
            'all_scores' => $response['scores'] ?? [],
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
            logger()->error("Failed to submit training data: " . $e->getMessage());
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
