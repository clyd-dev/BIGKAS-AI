<?php

namespace App\Services;

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

        if (empty($this->apiKey)) {
            return $this->mockTranscribe($audioPath, $language);
        }

        return $this->callWhisperApi($audioPath, $language);
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

        return $this->formatWhisperResponse($response->json());
    }

    protected function formatWhisperResponse(array $data): array
    {
        $words = [];
        if (isset($data['words'])) {
            foreach ($data['words'] as $word) {
                $words[] = [
                    'word' => $word['word'],
                    'start' => $word['start'],
                    'end' => $word['end'],
                    'confidence' => 1.0,
                ];
            }
        }

        return [
            'text' => $data['text'] ?? '',
            'words' => $words,
            'language' => $data['language'] ?? 'en',
            'duration' => $data['duration'] ?? 0,
            'segments' => $data['segments'] ?? [],
            'raw_response' => $data,
        ];
    }

    protected function mockTranscribe(string $audioPath, string $language): array
    {
        $fileSize = filesize($audioPath);
        $estimatedDuration = max(10, $fileSize / 16000);

        $mockTexts = [
            'en' => 'I have a dog his name is Max Max is brown he has a long tail Max likes to run he runs in the park I play with Max we play catch Max catches the ball he brings it back to me I love my dog Max',
            'fil' => 'Ako ay may pusa ang pangalan niya ay Muning si Muning ay puti mahaba ang buntot niya mahilig siyang matulog natutulog siya sa ilalim ng mesa pinapakain ko siya binibigyan ko siya ng gatas mahal ko ang pusa ko',
            'hil' => 'May ido ako ang ngalan niya Max si Max kayumanggi mahaba ang ikog niya gusto ni Max magdalagan nagadalagan siya sa park nagahambal ako kay Max nagahampang kami catch ginakuha ni Max ang bola ginabalik niya ini sa akon palangga ko ang ido ko',
        ];

        $text = $mockTexts[$language] ?? $mockTexts['en'];
        $words = explode(' ', $text);
        $wordData = [];
        $timePerWord = $estimatedDuration / count($words);
        $currentTime = 0;

        foreach ($words as $word) {
            $wordData[] = [
                'word' => $word,
                'start' => round($currentTime, 2),
                'end' => round($currentTime + $timePerWord, 2),
                'confidence' => rand(85, 99) / 100,
            ];
            $currentTime += $timePerWord;
        }

        return [
            'text' => $text,
            'words' => $wordData,
            'language' => $language,
            'duration' => round($estimatedDuration, 2),
            'segments' => [],
            'is_mock' => true,
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
            return ['status' => 'warning', 'message' => 'API key not configured (using mock mode)', 'mock_mode' => true];
        }
        try {
            $response = Http::timeout(10)->withToken($this->apiKey)->get($this->apiUrl . '/models');
            return $response->successful()
                ? ['status' => 'ok', 'message' => 'OpenAI API is reachable', 'mock_mode' => false]
                : ['status' => 'error', 'message' => "API returned status {$response->status()}", 'mock_mode' => false];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'mock_mode' => false];
        }
    }
}
