<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantSetting extends Model
{
    protected $fillable = [
        'club_id',
        'is_enabled',
        'llm_provider',
        'llm_api_key',
        'llm_base_url',
        'llm_model',
        'speech_provider',
        'yandex_api_key',
        'yandex_folder_id',
        'openai_api_key',
        'openai_base_url',
        'stt_model',
        'tts_model',
        'tts_voice',
        'max_reply_chars',
        'companion_prompt',
        'greeting_prompt',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'llm_api_key' => 'encrypted',
        'yandex_api_key' => 'encrypted',
        'openai_api_key' => 'encrypted',
        'max_reply_chars' => 'integer',
    ];

    protected $hidden = [
        'llm_api_key',
        'yandex_api_key',
        'openai_api_key',
    ];

    public const OPENAI_VOICES = [
        'alloy' => 'Alloy',
        'echo' => 'Echo',
        'fable' => 'Fable',
        'onyx' => 'Onyx',
        'nova' => 'Nova',
        'shimmer' => 'Shimmer',
    ];

    /** @deprecated use OPENAI_VOICES / voicesFor() */
    public const VOICES = self::OPENAI_VOICES;

    public const YANDEX_VOICES = [
        'alena' => 'Алёна',
        'filipp' => 'Филипп',
        'marina' => 'Марина',
        'alexander' => 'Александр',
        'jane' => 'Джейн',
        'zahar' => 'Захар',
        'dasha' => 'Даша',
        'julia' => 'Юлия',
        'lera' => 'Лера',
        'masha' => 'Маша',
        'kirill' => 'Кирилл',
        'anton' => 'Антон',
    ];

    public const SPEECH_PROVIDERS = [
        'yandex' => 'Yandex SpeechKit',
        'openai' => 'OpenAI (Whisper + TTS)',
    ];

    public const LLM_PROVIDERS = [
        'deepseek' => 'DeepSeek',
        'openai' => 'OpenAI',
    ];

    public const LLM_PRESETS = [
        'deepseek' => [
            'base_url' => 'https://api.deepseek.com',
            'model' => 'deepseek-v4-flash',
        ],
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
        ],
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public static function forClub(?int $clubId = null): self
    {
        $clubId = $clubId ?: (int) Club::query()->value('id');

        return static::query()->firstOrCreate(
            ['club_id' => $clubId],
            [
                'is_enabled' => true,
                'llm_provider' => 'deepseek',
                'speech_provider' => 'yandex',
                'tts_voice' => (string) config('ai_assistant.yandex.tts_voice', 'alena'),
                'companion_prompt' => null,
                'greeting_prompt' => null,
            ]
        );
    }

    /**
     * Почему Shell/приветствие не стартуют. null = можно вызывать.
     * Админские ping LLM / TTS этот метод не используют.
     */
    public static function denyReason(?int $clubId = null): ?string
    {
        $settings = static::forClub($clubId);

        if (! $settings->is_enabled) {
            return 'Выключен тумблером в админке';
        }

        if ($settings->resolvedLlmApiKey() === '') {
            return 'Нет ключа LLM (DeepSeek/OpenAI)';
        }

        if (! $settings->hasSpeechCredentials()) {
            return $settings->resolvedSpeechProvider() === 'openai'
                ? 'Нет ключа OpenAI для речи'
                : 'Нет ключа Yandex SpeechKit';
        }

        return null;
    }

    public static function isReady(?int $clubId = null): bool
    {
        return static::denyReason($clubId) === null;
    }

    public function resolvedLlmProvider(): string
    {
        $provider = strtolower(trim((string) ($this->llm_provider ?: 'deepseek')));

        return array_key_exists($provider, self::LLM_PROVIDERS) ? $provider : 'deepseek';
    }

    public function resolvedLlmApiKey(): string
    {
        $fromDb = trim((string) ($this->llm_api_key ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        $provider = $this->resolvedLlmProvider();
        if ($provider === 'openai') {
            $fromOpenAiDb = trim((string) ($this->openai_api_key ?? ''));
            if ($fromOpenAiDb !== '') {
                return $fromOpenAiDb;
            }

            return trim((string) config('ai_assistant.openai.api_key', ''));
        }

        return trim((string) config('ai_assistant.deepseek.api_key', ''));
    }

    public function resolvedLlmBaseUrl(): string
    {
        $fromDb = self::normalizeLlmBaseUrl((string) ($this->llm_base_url ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        $provider = $this->resolvedLlmProvider();
        if ($provider === 'openai') {
            $env = self::normalizeLlmBaseUrl((string) config('ai_assistant.openai.base_url', ''));
            if ($env !== '') {
                return $env;
            }
        } else {
            $env = self::normalizeLlmBaseUrl((string) config('ai_assistant.deepseek.base_url', ''));
            if ($env !== '') {
                return $env;
            }
        }

        return self::LLM_PRESETS[$provider]['base_url'] ?? self::LLM_PRESETS['deepseek']['base_url'];
    }

    /** Убрать пробелы, хвостовые слэши и опечатки вроде https://api.deepseek.com. */
    public static function normalizeLlmBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Точка перед концом хоста / после TLD — частая опечатка
        $url = preg_replace('#(https?://[^/\s]+)\.+(?=/|$)#', '$1', $url) ?? $url;

        return rtrim($url, '/');
    }

    public function resolvedLlmModel(): string
    {
        $fromDb = trim((string) ($this->llm_model ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        $provider = $this->resolvedLlmProvider();
        if ($provider === 'openai') {
            // OpenAI chat model is not in config as deepseek.model — use preset / optional env later
            return self::LLM_PRESETS['openai']['model'];
        }

        $env = trim((string) config('ai_assistant.deepseek.model', ''));
        if ($env !== '') {
            return $env;
        }

        return self::LLM_PRESETS['deepseek']['model'];
    }

    public function resolvedOpenAiApiKey(): string
    {
        $fromDb = trim((string) ($this->openai_api_key ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('ai_assistant.openai.api_key', ''));
    }

    public function resolvedOpenAiBaseUrl(): string
    {
        $fromDb = rtrim(trim((string) ($this->openai_base_url ?? '')), '/');
        if ($fromDb !== '') {
            return $fromDb;
        }

        $env = rtrim(trim((string) config('ai_assistant.openai.base_url', '')), '/');

        return $env !== '' ? $env : 'https://api.openai.com/v1';
    }

    public function resolvedSttModel(): string
    {
        $fromDb = trim((string) ($this->stt_model ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return (string) config('ai_assistant.openai.stt_model', 'whisper-1');
    }

    public function resolvedTtsModel(): string
    {
        $fromDb = trim((string) ($this->tts_model ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return (string) config('ai_assistant.openai.tts_model', 'tts-1');
    }

    public function resolvedSpeechProvider(): string
    {
        $provider = strtolower(trim((string) ($this->speech_provider ?: config('ai_assistant.speech_provider', 'yandex'))));

        return array_key_exists($provider, self::SPEECH_PROVIDERS) ? $provider : 'yandex';
    }

    /**
     * @return array<string, string>
     */
    public static function voicesFor(string $provider): array
    {
        return $provider === 'openai' ? self::OPENAI_VOICES : self::YANDEX_VOICES;
    }

    public function resolvedYandexApiKey(): string
    {
        $fromDb = trim((string) ($this->yandex_api_key ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('ai_assistant.yandex.api_key', ''));
    }

    public function resolvedYandexFolderId(): string
    {
        $fromDb = trim((string) ($this->yandex_folder_id ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('ai_assistant.yandex.folder_id', ''));
    }

    public function yandexKeySource(): string
    {
        return trim((string) ($this->yandex_api_key ?? '')) !== '' ? 'db' : 'env';
    }

    public function resolvedTtsVoice(): string
    {
        $voices = self::voicesFor($this->resolvedSpeechProvider());
        $voice = strtolower(trim((string) $this->tts_voice));
        if ($voice !== '' && array_key_exists($voice, $voices)) {
            return $voice;
        }

        if ($this->resolvedSpeechProvider() === 'openai') {
            $fallback = strtolower(trim((string) config('ai_assistant.openai.tts_voice', 'nova')));

            return array_key_exists($fallback, $voices) ? $fallback : 'nova';
        }

        $fallback = strtolower(trim((string) config('ai_assistant.yandex.tts_voice', 'alena')));

        return array_key_exists($fallback, $voices) ? $fallback : 'alena';
    }

    public function resolvedMaxReplyChars(): int
    {
        if ($this->max_reply_chars !== null && (int) $this->max_reply_chars > 0) {
            return (int) $this->max_reply_chars;
        }

        return max(80, (int) config('ai_assistant.max_reply_chars', 420));
    }

    public function hasSpeechCredentials(): bool
    {
        if ($this->resolvedSpeechProvider() === 'openai') {
            return $this->resolvedOpenAiApiKey() !== '';
        }

        return $this->resolvedYandexApiKey() !== '';
    }

    public function hasCredentials(): bool
    {
        return $this->resolvedLlmApiKey() !== ''
            && $this->hasSpeechCredentials();
    }

    public function llmKeySource(): string
    {
        return trim((string) ($this->llm_api_key ?? '')) !== '' ? 'db' : 'env';
    }

    public function openAiKeySource(): string
    {
        return trim((string) ($this->openai_api_key ?? '')) !== '' ? 'db' : 'env';
    }

    /**
     * @param  array{game_title:?string,player_name:?string,club_name:?string}  $context
     */
    public function resolveCompanionPrompt(array $context, int $maxChars): string
    {
        $template = trim((string) ($this->companion_prompt ?? ''));
        if ($template === '') {
            $template = self::defaultCompanionPromptTemplate();
        }

        $game = $context['game_title'] ?: 'неизвестно (игра может быть не запущена)';
        $player = $context['player_name'] ?: 'игрок';
        $club = $context['club_name'] ?: 'компьютерный клуб';

        return strtr($template, [
            '{{club}}' => $club,
            '{{player}}' => $player,
            '{{game}}' => $game,
            '{{max_chars}}' => (string) $maxChars,
        ]);
    }

    /**
     * @param  array{
     *   player_name:?string,
     *   club_name:?string,
     *   pc_name:?string,
     *   time_remaining:?string,
     *   is_first_visit:bool,
     *   visit_count_completed:int,
     *   favorite_games:array<int, array{id:int,title:string,launch_count:int}>
     * }  $context
     */
    public function resolveGreetingPrompt(array $context, int $maxChars): string
    {
        $template = trim((string) ($this->greeting_prompt ?? ''));
        if ($template === '') {
            $template = self::defaultGreetingPromptTemplate();
        }

        $player = $context['player_name'] ?: 'игрок';
        $club = $context['club_name'] ?: 'компьютерный клуб';
        $pc = $context['pc_name'] ?: 'ПК';
        $time = $context['time_remaining'] ?: 'неизвестно';
        $first = ! empty($context['is_first_visit']);
        $visits = (int) ($context['visit_count_completed'] ?? 0);

        $games = $context['favorite_games'] ?? [];
        $gameLines = [];
        foreach (array_slice($games, 0, 5) as $g) {
            $title = (string) ($g['title'] ?? '');
            $count = (int) ($g['launch_count'] ?? 0);
            if ($title === '') {
                continue;
            }
            $gameLines[] = $count > 0 ? "{$title} ({$count})" : $title;
        }
        $gamesText = $gameLines ? implode(', ', $gameLines) : 'предпочтения пока неизвестны';

        $visitLine = $first
            ? 'Это похоже на первый визит (или прошлых сессий не было).'
            : "Игрок уже бывал в клубе; завершённых визитов: {$visits}.";

        return strtr($template, [
            '{{club}}' => $club,
            '{{player}}' => $player,
            '{{pc}}' => $pc,
            '{{time}}' => $time,
            '{{visit_line}}' => $visitLine,
            '{{games}}' => $gamesText,
            '{{max_chars}}' => (string) $maxChars,
        ]);
    }

    public static function defaultCompanionPromptTemplate(): string
    {
        return <<<'PROMPT'
Ты голосовой компаньон за ПК в «{{club}}». Тебя вызывают по F1 во время сессии, ответ сразу озвучивается в наушники.

Игрок: {{player}}
Сейчас запущена / актуальна игра: {{game}}

Сам по смыслу реплики выбери тон — отдельный классификатор не нужен:
- вопрос по игре / механике / прохождению — коротко и по делу; опирайся на название игры выше;
- болтовня, поддержка, «по душам» — по-человечески, тепло, без морали и без роли психотерапевта;
- про клуб (бронь, деньги, админ) — не выдумывай факты; скажи позвать администратора или посмотреть в Shell.

Жёсткие правила:
- ответ на русском, разговорный;
- 1–3 коротких предложения, максимум ~{{max_chars}} символов;
- без markdown, списков, эмодзи, кавычек-ёлочек для оформления;
- без преамбул вроде «Конечно!» и «Как ИИ я…»;
- не спойлери сюжет, если прямо не просят.
PROMPT;
    }

    public static function defaultGreetingPromptTemplate(): string
    {
        return <<<'PROMPT'
Ты голос станции в «{{club}}». Сейчас игрок только авторизовался на «{{pc}}», приветствие озвучивается в колонки.

Игрок: {{player}}
Остаток сессии: {{time}}
{{visit_line}}
Любимые / частые игры: {{games}}

Задача: тёплое короткое приветствие по-русски.
- если первый визит — поприветствуй в клубе, без занудства;
- если не первый — можно мягко узнать по любимым играм или просто сказать «с возвращением»;
- можно коротко упомянуть одну игру из списка, если она есть;
- не выдумывай акции, цены, правила и факты клуба;
- 1–2 предложения, максимум ~{{max_chars}} символов;
- без markdown, списков, эмодзи, кавычек-ёлочек;
- без «Конечно!» и «Как ИИ…».
PROMPT;
    }

    public function toAdminArray(): array
    {
        $provider = $this->resolvedLlmProvider();

        return [
            'id' => $this->id,
            'club_id' => $this->club_id,
            'is_enabled' => (bool) $this->is_enabled,
            'llm_provider' => $provider,
            'llm_base_url' => $this->llm_base_url ?: '',
            'llm_model' => $this->llm_model ?: '',
            'has_llm_api_key' => trim((string) ($this->llm_api_key ?? '')) !== '',
            'llm_key_source' => $this->llmKeySource(),
            'openai_base_url' => $this->openai_base_url ?: '',
            'stt_model' => $this->stt_model ?: '',
            'tts_model' => $this->tts_model ?: '',
            'has_openai_api_key' => trim((string) ($this->openai_api_key ?? '')) !== '',
            'openai_key_source' => $this->openAiKeySource(),
            'speech_provider' => $this->resolvedSpeechProvider(),
            'yandex_folder_id' => $this->yandex_folder_id ?: '',
            'has_yandex_api_key' => trim((string) ($this->yandex_api_key ?? '')) !== '',
            'yandex_key_source' => $this->yandexKeySource(),
            'has_llm_credentials' => $this->resolvedLlmApiKey() !== '',
            'has_openai_credentials' => $this->resolvedOpenAiApiKey() !== '',
            'has_speech_credentials' => $this->hasSpeechCredentials(),
            'tts_voice' => $this->resolvedTtsVoice(),
            'max_reply_chars' => $this->max_reply_chars ?: (int) config('ai_assistant.max_reply_chars', 420),
            'companion_prompt' => $this->companion_prompt ?: self::defaultCompanionPromptTemplate(),
            'greeting_prompt' => $this->greeting_prompt ?: self::defaultGreetingPromptTemplate(),
            'using_default_companion' => trim((string) ($this->companion_prompt ?? '')) === '',
            'using_default_greeting' => trim((string) ($this->greeting_prompt ?? '')) === '',
            'credentials_ok' => $this->hasCredentials(),
            'llm_preset_base_url' => self::LLM_PRESETS[$provider]['base_url'] ?? '',
            'llm_preset_model' => self::LLM_PRESETS[$provider]['model'] ?? '',
        ];
    }
}
