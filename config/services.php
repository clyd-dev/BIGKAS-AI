<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // OpenAI API Configuration (for Whisper Speech-to-Text)
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'api_url' => 'https://api.openai.com/v1',
        'model' => 'whisper-1',
        'timeout' => 60,
    ],

    // Whisper STT Mode Configuration
    // Set WHISPER_USE_LOCAL=true in .env to use local faster-whisper via the Python Flask service
    // Set WHISPER_USE_LOCAL=false to use the OpenAI cloud API (requires OPENAI_API_KEY)
    'whisper' => [
        'use_local' => env('WHISPER_USE_LOCAL', true),

        // How long to wait for local CPU transcription. The recorder stops at
        // 3 minutes of audio and faster-whisper 'small' on a small VPS runs
        // slower than real time, so this has to allow for several minutes.
        // It must stay BELOW php-fpm's request_terminate_timeout and nginx's
        // fastcgi_read_timeout, so this is the limit that fires first and the
        // teacher gets a real message instead of a dead gateway.
        'timeout' => (int) env('WHISPER_TIMEOUT', 300),

        // The placeholder transcript ("I have a dog his name is Max...") is a
        // development aid. Scoring it would store an assessment that describes
        // nothing, so it is refused unless this is explicitly allowed. Null
        // means "local and testing only" (see SpeechToTextService).
        'allow_mock' => env('STT_ALLOW_MOCK'),
    ],

    // Google Cloud Speech-to-Text (alternative)
    'google_speech' => [
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS', ''),
        'project_id' => env('GOOGLE_PROJECT_ID', ''),
        'languages' => [
            'en' => 'en-PH',
            'fil' => 'fil-PH',
            'hil' => 'fil-PH',
        ],
    ],

    // Python ML API Service
    'ml_api' => [
        'url' => env('ML_API_URL', 'http://localhost:5000'),
        'enabled' => env('ML_API_ENABLED', true),
        'timeout' => 30,
        'endpoints' => [
            'classify' => '/api/classify',
            'analyze' => '/api/analyze',
            'health' => '/api/health',
        ],
    ],

];
