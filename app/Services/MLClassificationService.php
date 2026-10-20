<?php

namespace App\Services;

use App\Models\AssessmentResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only author of a weakness label.
 *
 * When the model cannot answer, nothing answers in its place: the result is
 * saved with its measurements and an explicit "unavailable" status, and the
 * teacher is told. A rule standing in for the model was a guess that looked
 * like a result, and it fed a child's interventions and practice.
 */
class MLClassificationService
{
    protected string $apiUrl;
    protected int $timeout;
    protected bool $enabled;

    public function __construct()
    {
        $config = config('services.ml_api', []);
        $this->apiUrl = $config['url'] ?? 'http://127.0.0.1:5000'; // Default to local Python Flask
        $this->timeout = $config['timeout'] ?? 5; // Short, so an offline service is noticed quickly
        $this->enabled = (bool) ($config['enabled'] ?? true);
    }

    /**
     * Translate a prediction into the integer weakness id stored on
     * assessment_results. The service may answer with either the class number
     * or its label.
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
     * from the MODEL'S OWN ranking is used. If none of its choices is backed, no
     * weakness is stored. Nothing else may choose a label: not the analyzer's
     * rule, and not a "clearest problem" pick. Those were authored by rules and
     * then stamped as the model's.
     *
     * @param array<int, string> $statuses from WeaknessEvidence::statuses()
     */
    public static function resolveWeaknessId(array $classification, array $statuses): ?int
    {
        $id = self::mapWeaknessToId($classification['primary'] ?? '');

        if ($id === null) {
            return null;
        }

        // 0 means "no weakness found", which needs no supporting evidence.
        if ($id === 0 || WeaknessEvidence::showsProblem($statuses[$id] ?? null)) {
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

        return null;
    }

    /** Did the model actually answer? Absent the flag, a named class means yes. */
    protected static function answered(array $classification): bool
    {
        return array_key_exists('available', $classification)
            ? (bool) $classification['available']
            : ($classification['primary'] ?? null) !== null;
    }

    /**
     * Everything a save path needs from a classification, in one place so the
     * web, API and practice routes cannot drift apart.
     *
     * `classifier_output` keeps what the model actually said beside what was
     * stored, so a label adjusted by the measurements can always be audited.
     *
     * @param array<int, string> $statuses from WeaknessEvidence::statuses()
     * @return array{primary_weakness: ?int, classification_status: string, confidence_score: ?float, classifier_output: array}
     */
    public static function resolve(array $classification, array $statuses): array
    {
        $answered = self::answered($classification);

        $output = [
            'available' => $answered,
            'primary' => $classification['primary'] ?? null,
            'confidence' => $classification['confidence'] ?? null,
            'all_scores' => $classification['all_scores'] ?? [],
            'model_version' => $classification['model_version'] ?? null,
            'reason' => $answered ? null : ($classification['reason'] ?? 'unavailable'),
        ];

        if (! $answered) {
            return [
                'primary_weakness' => null,
                'classification_status' => AssessmentResult::STATUS_UNAVAILABLE,
                // A confidence nobody measured is worse than none.
                'confidence_score' => null,
                'classifier_output' => $output,
            ];
        }

        $id = self::resolveWeaknessId($classification, $statuses);

        if ($id === null) {
            return [
                'primary_weakness' => null,
                'classification_status' => AssessmentResult::STATUS_MODEL_UNSUPPORTED,
                'confidence_score' => null,
                'classifier_output' => $output,
            ];
        }

        // The model's confidence belongs to the class it picked. If the stored
        // label is its next choice, report that class's own probability.
        $confidence = isset($classification['confidence']) ? (float) $classification['confidence'] : null;
        $pick = self::mapWeaknessToId($classification['primary'] ?? '');
        $scores = $classification['all_scores'] ?? [];

        if ($pick !== $id && isset($scores[$id])) {
            $confidence = (float) $scores[$id];
        }

        return [
            'primary_weakness' => $id,
            'classification_status' => AssessmentResult::STATUS_CLASSIFIED,
            'confidence_score' => $confidence,
            'classifier_output' => $output,
        ];
    }

    public function classify(array $features): array
    {
        if (! $this->enabled) {
            return self::unavailable('disabled');
        }

        try {
            $response = $this->makeRequest('/api/classify', $features);

            return [
                'available' => true,
                'primary' => $response['primary_weakness'] ?? null,
                'secondary' => $response['secondary_weakness'] ?? null,
                'confidence' => $response['confidence'] ?? null,
                'all_scores' => $response['scores'] ?? [],
                // The version the service answering this request reports. Read
                // from the answer, never from a metadata file on disk: that file
                // outlives the model and would keep naming a version nothing ran.
                'model_version' => $response['model_version'] ?? $this->serviceModelVersion(),
            ];
        } catch (ConnectionException $e) {
            Log::warning('ML service unreachable; no weakness will be classified. ' . $e->getMessage());

            return self::unavailable('unreachable');
        } catch (\Exception $e) {
            // Flask answers 503 when it is up but the model file is not loaded.
            $reason = $e->getCode() === 503 ? 'model_not_loaded' : 'error';
            Log::warning("ML service could not classify ({$reason}); no weakness will be classified. " . $e->getMessage());

            return self::unavailable($reason);
        }
    }

    /** The shape of a classification that never happened. */
    protected static function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'primary' => null,
            'secondary' => null,
            'confidence' => null,
            'all_scores' => [],
            'model_version' => null,
            'reason' => $reason,
        ];
    }

    /**
     * What produced this classification, for the stored result.
     *
     * Every path that saves a result calls this, so a weakness can always be
     * traced to the model, or to the fact that it could not answer. A teacher
     * deciding about a child should never have to guess.
     *
     * Static and pure: it reads only the classification it is handed, so every
     * caller records the same thing and a stubbed service cannot change it.
     *
     * @return array{classifier: string, model_version: ?string}
     */
    public static function classifierProvenance(array $classification): array
    {
        $answered = self::answered($classification);

        return [
            'classifier' => $answered ? 'ml' : 'unavailable',
            // Only a model that answered has a version to report.
            'model_version' => $answered ? ($classification['model_version'] ?? null) : null,
        ];
    }

    /**
     * The version the running service reports, asked only when a classification
     * came back without one. Returns null rather than guessing from disk.
     */
    protected function serviceModelVersion(): ?string
    {
        try {
            $response = Http::timeout($this->timeout)->get($this->apiUrl . '/api/health');

            if (! $response->successful() || ! $response->json('classifier_loaded')) {
                return null;
            }

            return $response->json('model_version') ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Is the analysis service able to classify right now? Cached for a few
     * seconds so a page that asks does not hold up the teacher.
     *
     * @return array{state: string, ok: bool, message: string, version: ?string}
     */
    public function serviceStatus(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('ml.service.status');
        }

        return Cache::remember('ml.service.status', 10, fn () => $this->probeService());
    }

    protected function probeService(): array
    {
        // Transcription can also run through a cloud key, independent of Flask.
        $cloudStt = ! empty(config('services.openai.api_key'));

        if (! $this->enabled) {
            return $this->status('disabled', false, 'The analysis service is turned off in settings, so weakness classification will not run.', null, $cloudStt);
        }

        try {
            $response = Http::timeout(3)->get($this->apiUrl . '/api/health');
        } catch (\Exception $e) {
            return $cloudStt
                ? $this->status('unreachable', false, 'The analysis service is not running. You can still record, but weakness classification will be unavailable until it is back.', null, true)
                : $this->status('unreachable', false, 'The analysis service is not running, so recordings cannot be scored and no weakness can be classified until it is back. You can still record: the recording is saved and can be analyzed later.', null, false);
        }

        if (! $response->successful()) {
            return $this->status('error', false, 'The analysis service is not responding properly. Scoring and weakness classification may be unavailable.', null, $cloudStt);
        }

        $version = $response->json('model_version') ?: null;

        // The speech engine and the classifier load separately inside the same
        // service, so one can be down while the other is up.
        $sttOk = (bool) $response->json('whisper_loaded') || $cloudStt;
        $classifierOk = (bool) $response->json('classifier_loaded');

        if (! $sttOk && ! $classifierOk) {
            return $this->status('stt_missing', false, 'The speech engine and the model are not loaded, so recordings cannot be scored until the service is fixed. You can still record: the recording is saved and can be analyzed later.', $version, false);
        }

        if (! $sttOk) {
            return $this->status('stt_missing', false, 'The speech engine is not loaded, so a recording cannot be scored until it is back. You can still record: the recording is saved and can be analyzed later.', $version, false);
        }

        if (! $classifierOk) {
            return $this->status('model_missing', false, 'The analysis service is running but no model is loaded. Results will be saved without a weakness classification.', $version, true);
        }

        if ($response->json('model_stale')) {
            return $this->status('stale', true, 'The model is running, but its file is missing from the server. It will stop working the next time the service restarts.', $version, true);
        }

        return $this->status('ready', true, 'The analysis service is running.', $version, true);
    }

    private function status(string $state, bool $ok, string $message, ?string $version = null, bool $sttOk = true): array
    {
        return ['state' => $state, 'ok' => $ok, 'stt_ok' => $sttOk, 'message' => $message, 'version' => $version];
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
            throw new \RuntimeException("ML API error: HTTP {$response->status()}", $response->status());
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