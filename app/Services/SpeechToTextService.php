<?php

namespace App\Services;

use App\Exceptions\SpeechServiceUnavailable;
use Illuminate\Support\Facades\Http;

class SpeechToTextService
{
    protected string $apiKey;
    protected string $apiUrl;
    protected int $timeout;

    public function __construct()
    {
        $config = config('services.openai', []);
        $this->apiKey = $config['api_key'] ?? '';
        $this->apiUrl = $config['api_url'] ?? 'https://api.openai.com/v1';
        $this->timeout = $config['timeout'] ?? 60;
    }

    public function transcribe(string $audioPath, string $language = 'en'): array
    {
        if (!file_exists($audioPath)) {
            throw new \Exception("Audio file not found: {$audioPath}");
        }

        $lastError = null;

        // Priority 1: Local Whisper via Python Flask (self-hosted, no API cost)
        if (config('services.whisper.use_local', true)) {
            try {
                return $this->callLocalWhisper($audioPath, $language);
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                \Log::warning('Local Whisper failed, trying fallbacks: ' . $lastError);
                // Fall through to next option
            }
        }

        // Priority 2: OpenAI Cloud API (requires API key)
        if (!empty($this->apiKey)) {
            return $this->callWhisperApi($audioPath, $language);
        }

        // No engine answered. There is nothing to fall back to: a stand-in
        // transcript would be scored as if the child had read it, producing a
        // flawless result (100%, perfect pace, "independent") for a reading
        // that never happened. So nothing is scored, in every environment, and
        // the caller reports that the engine is down. The recording is already
        // saved, so analysing again once it is back is all it takes.
        \Log::error('No speech engine answered; nothing was scored.', [
            'audio' => basename($audioPath),
            'last_error' => $lastError,
        ]);

        throw SpeechServiceUnavailable::becauseNoEngineAnswered($lastError);
    }

    /**
     * Call the local faster-whisper model running inside the Python Flask microservice.
     * This sends the audio file to /api/transcribe on the same Flask server
     * that hosts the RF classifier, keeping everything self-hosted.
     */
    protected function callLocalWhisper(string $audioPath, string $language): array
    {
        $mlApiUrl = config('services.ml_api.url', 'http://127.0.0.1:5000');

        // Local CPU inference is slower than real time on a small VPS, so this
        // waits minutes, not seconds (see config/services.php whisper.timeout).
        $response = Http::timeout(config('services.whisper.timeout', 300))
            ->attach('audio', file_get_contents($audioPath), basename($audioPath))
            ->post($mlApiUrl . '/api/transcribe', [
                'language' => $language,
            ]);

        if (!$response->successful()) {
            throw new \Exception("Local Whisper error: HTTP {$response->status()} - {$response->body()}");
        }

        return $this->formatWhisperResponse($response->json(), 'local_whisper');
    }

    protected function callWhisperApi(string $audioPath, string $language): array
    {
        $languageMap = ['en' => 'en', 'fil' => 'tl', 'hil' => 'tl'];

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->attach('file', file_get_contents($audioPath), basename($audioPath))
            ->post($this->apiUrl . '/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => $languageMap[$language] ?? 'en',
                'response_format' => 'verbose_json',
            ]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown API error');
            throw new \Exception("API error ({$response->status()}): {$error}");
        }

        return $this->formatWhisperResponse($response->json(), 'openai_api');
    }

    protected function formatWhisperResponse(array $data, string $engine = 'unknown'): array
    {
        $words = [];
        if (isset($data['words'])) {
            foreach ($data['words'] as $word) {
                $words[] = [
                    'word' => $word['word'],
                    'start' => $word['start'],
                    'end' => $word['end'],
                    'confidence' => $word['confidence'] ?? 1.0,
                ];
            }
        }

        return [
            'text' => $data['text'] ?? '',
            'words' => $words,
            'language' => $data['language'] ?? 'en',
            'duration' => $data['duration'] ?? 0,
            'segments' => $data['segments'] ?? [],
            'engine' => $engine,
            // Measured from the waveform by the local service (speech vs
            // background, noise level, non-reading sounds). The cloud API
            // doesn't provide this, so it may be absent.
            'audio_quality' => $data['audio_quality'] ?? null,
            'verbatim_mode' => $data['verbatim_mode'] ?? false,
            'raw_response' => $data,
        ];
    }

    public function getSupportedLanguages(): array
    {
        return [
            'en' => ['code' => 'en', 'name' => 'English', 'whisper_code' => 'en'],
            'fil' => ['code' => 'fil', 'name' => 'Filipino (Tagalog)', 'whisper_code' => 'tl'],
            ];
    }

    public function validateAudioFile(string $audioPath): array
    {
        $errors = [];
        if (!file_exists($audioPath)) {
            return ['Audio file not found'];
        }
        if (filesize($audioPath) > 25 * 1024 * 1024) {
            $errors[] = 'Audio file too large (max 25MB)';
        }
        $ext = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm', 'ogg'])) {
            $errors[] = 'Unsupported audio format';
        }
        return $errors;
    }

    public function healthCheck(): array
    {
        if (empty($this->apiKey)) {
            return ['status' => 'warning', 'message' => 'No cloud speech API key is configured; transcription depends on the local engine.'];
        }
        try {
            $response = Http::timeout(10)->withToken($this->apiKey)->get($this->apiUrl . '/models');
            return $response->successful()
                ? ['status' => 'ok', 'message' => 'OpenAI API is reachable']
                : ['status' => 'error', 'message' => "API returned status {$response->status()}"];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
