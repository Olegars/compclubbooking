<?php

return [
    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),

    'max_audio_kb' => (int) env('AI_ASSISTANT_MAX_AUDIO_KB', 5120),
    'max_reply_chars' => (int) env('AI_ASSISTANT_MAX_REPLY_CHARS', 420),
    'rate_limit_per_minute' => (int) env('AI_ASSISTANT_RATE_LIMIT', 8),
    'http_timeout' => (float) env('AI_ASSISTANT_HTTP_TIMEOUT', 60),

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => rtrim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
    ],

    // Whisper STT + OpenAI TTS (можно тот же ключ)
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'stt_model' => env('AI_STT_MODEL', 'whisper-1'),
        'tts_model' => env('AI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('AI_TTS_VOICE', 'nova'),
    ],
];
