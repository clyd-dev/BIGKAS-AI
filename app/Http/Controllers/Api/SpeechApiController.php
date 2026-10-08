<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpeechApiController extends Controller
{
    public function __construct(
        protected SpeechToTextService $sttService,
    ) {}

    /**
     * Transcribe uploaded audio
     */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:webm,wav,mp3,ogg,m4a|max:20480',
            'language' => 'nullable|string',
        ]);

        $language = $request->input('language', 'english');

        // Store temporarily
        $file = $request->file('audio');
        $filename = 'stt_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('audio', $filename, 'public');
        $fullPath = storage_path('app/public/' . $path);

        // Transcribe
        try {
            $result = $this->sttService->transcribe($fullPath, $language);
        } catch (\App\Exceptions\SpeechServiceUnavailable $e) {
            \Illuminate\Support\Facades\Log::error('Speech API: ' . $e->getMessage());

            return $this->error($e->forTeacher(), 503);
        }

        if (! ($result['success'] ?? false)) {
            return $this->error('Transcription failed: ' . ($result['error'] ?? 'Unknown error'), 500);
        }

        return $this->success([
            'text' => $result['text'],
            'language' => $language,
            'duration' => $result['duration'] ?? null,
            'confidence' => $result['confidence'] ?? null,
            'mode' => $result['mode'] ?? 'api',
        ], 'Transcription completed');
    }

    /**
     * Get supported languages
     */
    public function languages(): JsonResponse
    {
        $languages = [
            [
                'code' => 'english',
                'name' => 'English',
                'whisper_code' => 'en',
                'supported' => true,
            ],
            [
                'code' => 'filipino',
                'name' => 'Filipino (Tagalog)',
                'whisper_code' => 'tl',
                'supported' => true,
            ],
            ];

        return $this->success(['languages' => $languages]);
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
