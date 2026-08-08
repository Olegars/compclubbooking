<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantSetting extends Model
{
    protected $fillable = [
        'club_id',
        'is_enabled',
        'tts_voice',
        'companion_prompt',
        'greeting_prompt',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public const VOICES = [
        'alloy' => 'Alloy',
        'echo' => 'Echo',
        'fable' => 'Fable',
        'onyx' => 'Onyx',
        'nova' => 'Nova',
        'shimmer' => 'Shimmer',
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
                'tts_voice' => (string) config('ai_assistant.openai.tts_voice', 'nova'),
                'companion_prompt' => null,
                'greeting_prompt' => null,
            ]
        );
    }

    public function resolvedTtsVoice(): string
    {
        $voice = strtolower(trim((string) $this->tts_voice));
        if ($voice === '' || ! array_key_exists($voice, self::VOICES)) {
            return (string) config('ai_assistant.openai.tts_voice', 'nova');
        }

        return $voice;
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
        return [
            'id' => $this->id,
            'club_id' => $this->club_id,
            'is_enabled' => (bool) $this->is_enabled,
            'tts_voice' => $this->resolvedTtsVoice(),
            'companion_prompt' => $this->companion_prompt ?: self::defaultCompanionPromptTemplate(),
            'greeting_prompt' => $this->greeting_prompt ?: self::defaultGreetingPromptTemplate(),
            'using_default_companion' => trim((string) ($this->companion_prompt ?? '')) === '',
            'using_default_greeting' => trim((string) ($this->greeting_prompt ?? '')) === '',
        ];
    }
}
