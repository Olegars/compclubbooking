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

    // Речь по умолчанию: Yandex SpeechKit (из РФ стабильнее OpenAI)
    'speech_provider' => env('AI_SPEECH_PROVIDER', 'yandex'),

    'yandex' => [
        'api_key' => env('YANDEX_SPEECHKIT_API_KEY'),
        'folder_id' => env('YANDEX_SPEECHKIT_FOLDER_ID'),
        'stt_url' => rtrim((string) env('YANDEX_STT_URL', 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'), '/'),
        'tts_url' => rtrim((string) env('YANDEX_TTS_URL', 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize'), '/'),
        'tts_voice' => env('YANDEX_TTS_VOICE', 'alena'),
        'lang' => env('YANDEX_SPEECH_LANG', 'ru-RU'),
    ],

    // Опциональный fallback, если SpeechKit недоступен
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'stt_model' => env('AI_STT_MODEL', 'whisper-1'),
        'tts_model' => env('AI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('AI_TTS_VOICE', 'nova'),
    ],
];
