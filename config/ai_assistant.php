<?php

return [
    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),

    'max_audio_kb' => (int) env('AI_ASSISTANT_MAX_AUDIO_KB', 5120),
    'max_reply_chars' => (int) env('AI_ASSISTANT_MAX_REPLY_CHARS', 420),
    'rate_limit_per_minute' => (int) env('AI_ASSISTANT_RATE_LIMIT', 8),
    'http_timeout' => (float) env('AI_ASSISTANT_HTTP_TIMEOUT', 60),
    'vision_timeout' => (float) env('AI_ASSISTANT_VISION_TIMEOUT', 90),
    'image_timeout' => (float) env('AI_ASSISTANT_IMAGE_TIMEOUT', 120),
    'avatar_dir' => env('CLUB_AVATAR_DIR'),

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => rtrim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
        'vision_model' => env('DEEPSEEK_VISION_MODEL', 'deepseek-flash'),
    ],

    // Речь по умолчанию: Yandex SpeechKit (из РФ стабильнее OpenAI)
    'speech_provider' => env('AI_SPEECH_PROVIDER', 'yandex'),

    'yandex' => [
        'api_key' => env('YANDEX_SPEECHKIT_API_KEY'),
        'folder_id' => env('YANDEX_SPEECHKIT_FOLDER_ID'),
        'stt_url' => rtrim((string) env('YANDEX_STT_URL', 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'), '/'),
        'tts_url' => rtrim((string) env('YANDEX_TTS_URL', 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize'), '/'),
        'tts_v3_url' => rtrim((string) env('YANDEX_TTS_V3_URL', 'https://tts.api.cloud.yandex.net/tts/v3/utteranceSynthesis'), '/'),
        'tts_voice' => env('YANDEX_TTS_VOICE', 'alena'),
        'lang' => env('YANDEX_SPEECH_LANG', 'ru-RU'),
        'billing_iam_token' => env('YANDEX_BILLING_IAM_TOKEN'),
        'billing_account_id' => env('YANDEX_BILLING_ACCOUNT_ID'),
    ],
    'alibaba_billing' => [
        'access_key_id' => env('ALIBABA_ACCESS_KEY_ID'),
        'access_key_secret' => env('ALIBABA_ACCESS_KEY_SECRET'),
        'endpoint' => rtrim((string) env('ALIBABA_BILLING_ENDPOINT', ''), '/'),
    ],

    // Опциональный fallback, если SpeechKit недоступен
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'stt_model' => env('AI_STT_MODEL', 'whisper-1'),
        'tts_model' => env('AI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('AI_TTS_VOICE', 'nova'),
    ],

    /*
     * Стилизация аватара. Qwen Image Edit рисует один кадр по фото и образцу.
     * DeepSeek картинки не рисует. Очередь ComfyUI в этой галке не используется.
     */
    'avatar' => [
        'relay_token' => (string) (
            env('AVATAR_RELAY_TOKEN')
            ?: env('VIDEO_MARKER_RELAY_TOKEN')
            ?: env('CLUB_WOL_RELAY_TOKEN', '')
        ),
        'stale_seconds' => (int) env('AVATAR_STYLIZE_STALE_SECONDS', 120),
        'qwen' => [
            'api_key' => env('QWEN_API_KEY', env('DASHSCOPE_API_KEY')),
            'base_url' => rtrim((string) env('QWEN_IMAGE_BASE_URL', 'https://dashscope-intl.aliyuncs.com/api/v1'), '/'),
            'model' => env('QWEN_IMAGE_MODEL', 'qwen-image-2.0-pro'),
            'prompt_extend' => filter_var(env('QWEN_IMAGE_PROMPT_EXTEND', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'comfyui' => [
            'url' => rtrim((string) env('COMFYUI_URL', ''), '/'),
            'workflow' => env('COMFYUI_WORKFLOW', ''),
            'checkpoint' => env('COMFYUI_CHECKPOINT', 'v1-5-pruned-emaonly.safetensors'),
            'timeout' => (float) env('COMFYUI_TIMEOUT', 90),
        ],
    ],
];
