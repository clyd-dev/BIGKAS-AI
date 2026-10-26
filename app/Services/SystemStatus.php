<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Health of the two AI parts of BIGKAS-AI, for the admin dashboard:
 *   - the ML classifier (Flask service, random-forest model that labels a reader's weakness)
 *   - speech-to-text (Whisper: local faster-whisper in the same Flask service, or the OpenAI cloud API)
 *
 * Each service gets one of four states: online, degraded, offline, disabled.
 * Results are cached for a short time so the dashboard never hammers the services.
 */
class SystemStatus
{
    private const CACHE_KEY = 'system-status';
    private const CACHE_SECONDS = 30;

    /**
     * The service answers /api/health out of the same request queue it
     * transcribes in, and a transcription runs for minutes, so a probe sent
     * while a reading is being scored can wait seconds for its answer. At 3s
     * these two cards read "Offline" for a service that was up with both
     * models loaded. This page is read by one admin, not by every teacher, so
     * it waits for a right answer rather than hurrying to a wrong one.
     */
    private const PROBE_TIMEOUT = 10;

    public static function all(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => self::collect());
    }

    private static function collect(): array
    {
        $flask = self::probeFlask();

        return [
            'checked_at' => now()->toIso8601String(),
            'services'   => [self::classifier($flask), self::speech($flask)],
            'backlog'    => self::backlog(),
        ];
    }

    /**
     * Recordings saved but not scored, waiting for the engine (see RescoreQueue).
     * Given-up ones failed on their own, not for want of the engine, and need a
     * person.
     */
    private static function backlog(): array
    {
        $queue = app(RescoreQueue::class);
        $waiting = $queue->waiting();
        $oldest = (clone $waiting)->oldest('updated_at')->value('updated_at');

        return [
            'waiting'  => (clone $waiting)->count(),
            'given_up' => $queue->givenUp()->count(),
            'oldest'   => $oldest?->toIso8601String(),
        ];
    }

    /** Ask the Flask service how it is. Needed by both the classifier and local Whisper. */
    private static function probeFlask(): array
    {
        $needed = config('services.ml_api.enabled') || config('services.whisper.use_local');
        $url    = rtrim((string) config('services.ml_api.url'), '/');

        if (! $needed) {
            return ['probed' => false, 'url' => $url];
        }

        try {
            $response = Http::timeout(self::PROBE_TIMEOUT)->get($url . '/api/health');

            return $response->successful()
                ? ['probed' => true, 'up' => true, 'url' => $url, 'data' => (array) $response->json()]
                : ['probed' => true, 'up' => false, 'url' => $url, 'error' => "answered HTTP {$response->status()}"];
        } catch (\Throwable $e) {
            return ['probed' => true, 'up' => false, 'url' => $url, 'error' => 'could not be reached'];
        }
    }

    private static function classifier(array $flask): array
    {
        $base = ['key' => 'ml', 'label' => 'AI / ML classifier', 'icon' => 'bi-cpu', 'mode' => 'Random-forest weakness classifier'];

        if (! config('services.ml_api.enabled')) {
            return $base + ['state' => 'disabled', 'headline' => 'Turned off',
                'detail' => 'Turned off (ML_API_ENABLED=false). Readings are measured and saved, but no weakness is classified.'];
        }
        if (! ($flask['up'] ?? false)) {
            return $base + ['state' => 'offline', 'headline' => 'Offline',
                'detail' => "The ML service at {$flask['url']} " . ($flask['error'] ?? 'could not be reached') . '. Recordings are still saved, but no weakness is classified until it is started.'];
        }
        if (! ($flask['data']['classifier_loaded'] ?? false)) {
            return $base + ['state' => 'degraded', 'headline' => 'Running without a model',
                'detail' => 'The service is up but no trained model is loaded, so no weakness is classified. Nothing stands in for the model.'];
        }

        return $base + ['state' => 'online', 'headline' => 'Online',
            'detail' => 'Model ' . ($flask['data']['model_version'] ?? 'unknown') . ' is loaded.'];
    }

    private static function speech(array $flask): array
    {
        $base = ['key' => 'whisper', 'label' => 'Speech-to-text (Whisper)', 'icon' => 'bi-mic'];

        if (config('services.whisper.use_local')) {
            $base += ['mode' => 'Local faster-whisper'];

            if (! ($flask['up'] ?? false)) {
                return $base + ['state' => 'offline', 'headline' => 'Offline',
                    'detail' => "Local Whisper runs inside the ML service at {$flask['url']}, which " . ($flask['error'] ?? 'could not be reached') . '. Recorded readings cannot be scored until it is started; they are saved and scored automatically once it is back.'];
            }
            if (! ($flask['data']['whisper_loaded'] ?? false)) {
                return $base + ['state' => 'degraded', 'headline' => 'Model not loaded',
                    'detail' => 'The service is up but the Whisper model is not loaded yet, so transcription will fail or be slow on first use.'];
            }

            return $base + ['state' => 'online', 'headline' => 'Online', 'detail' => 'The Whisper model is loaded and ready.'];
        }

        // OpenAI cloud mode
        $base += ['mode' => 'OpenAI cloud (whisper-1)'];
        $check = (new SpeechToTextService())->healthCheck();

        return match ($check['status'] ?? 'error') {
            'ok'      => $base + ['state' => 'online', 'headline' => 'Online', 'detail' => 'The OpenAI speech API is reachable.'],
            'warning' => $base + ['state' => 'degraded', 'headline' => 'No API key', 'detail' => 'WHISPER_USE_LOCAL is off but OPENAI_API_KEY is not set.'],
            default   => $base + ['state' => 'offline', 'headline' => 'Offline', 'detail' => 'The OpenAI speech API could not be reached.'],
        };
    }
}
