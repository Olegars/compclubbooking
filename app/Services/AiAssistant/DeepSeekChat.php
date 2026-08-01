<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekChat
{
    /**
     * @param  array{game_title:?string,player_name:?string,club_name:?string}  $context
     */
    public function reply(string $userText, array $context = []): string
    {
        $key = (string) config('ai_assistant.deepseek.api_key');
        if ($key === '') {
            throw new RuntimeException('DEEPSEEK_API_KEY не задан.');
        }

        $base = (string) config('ai_assistant.deepseek.base_url');
        $model = (string) config('ai_assistant.deepseek.model', 'deepseek-chat');
        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $maxChars = (int) config('ai_assistant.max_reply_chars', 420);

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.7,
                'max_tokens' => 220,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($context, $maxChars)],
                    ['role' => 'user', 'content' => $userText],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'LLM failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($text === '') {
            throw new RuntimeException('LLM вернул пустой ответ.');
        }

        if (mb_strlen($text) > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
        }

        return $text;
    }

    /**
     * Personalized spoken greeting after Shell login (no STT).
     *
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
    public function greet(array $context): string
    {
        $key = (string) config('ai_assistant.deepseek.api_key');
        if ($key === '') {
            throw new RuntimeException('DEEPSEEK_API_KEY не задан.');
        }

        $base = (string) config('ai_assistant.deepseek.base_url');
        $model = (string) config('ai_assistant.deepseek.model', 'deepseek-chat');
        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $maxChars = min(280, (int) config('ai_assistant.max_reply_chars', 420));

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.8,
                'max_tokens' => 160,
                'messages' => [
                    ['role' => 'system', 'content' => $this->greetingSystemPrompt($context, $maxChars)],
                    ['role' => 'user', 'content' => 'Сгенерируй короткое голосовое приветствие для этого игрока прямо сейчас.'],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'LLM greeting failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($text === '') {
            throw new RuntimeException('LLM вернул пустое приветствие.');
        }

        if (mb_strlen($text) > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
        }

        return $text;
    }

    /**
     * @param  array{game_title:?string,player_name:?string,club_name:?string}  $context
     */
    private function systemPrompt(array $context, int $maxChars): string
    {
        $game = $context['game_title'] ?: 'неизвестно (игра может быть не запущена)';
        $player = $context['player_name'] ?: 'игрок';
        $club = $context['club_name'] ?: 'компьютерный клуб';

        return <<<PROMPT
Ты голосовой компаньон за ПК в «{$club}». Тебя вызывают по F1 во время сессии, ответ сразу озвучивается в наушники.

Игрок: {$player}
Сейчас запущена / актуальна игра: {$game}

Сам по смыслу реплики выбери тон — отдельный классификатор не нужен:
- вопрос по игре / механике / прохождению — коротко и по делу; опирайся на название игры выше;
- болтовня, поддержка, «по душам» — по-человечески, тепло, без морали и без роли психотерапевта;
- про клуб (бронь, деньги, админ) — не выдумывай факты; скажи позвать администратора или посмотреть в Shell.

Жёсткие правила:
- ответ на русском, разговорный;
- 1–3 коротких предложения, максимум ~{$maxChars} символов;
- без markdown, списков, эмодзи, кавычек-ёлочек для оформления;
- без преамбул вроде «Конечно!» и «Как ИИ я…»;
- не спойлери сюжет, если прямо не просят.
PROMPT;
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
    private function greetingSystemPrompt(array $context, int $maxChars): string
    {
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

        return <<<PROMPT
Ты голос станции в «{$club}». Сейчас игрок только авторизовался на «{$pc}», приветствие озвучивается в колонки.

Игрок: {$player}
Остаток сессии: {$time}
{$visitLine}
Любимые / частые игры: {$gamesText}

Задача: тёплое короткое приветствие по-русски.
- если первый визит — поприветствуй в клубе, без занудства;
- если не первый — можно мягко узнать по любимым играм или просто сказать «с возвращением»;
- можно коротко упомянуть одну игру из списка, если она есть;
- не выдумывай акции, цены, правила и факты клуба;
- 1–2 предложения, максимум ~{$maxChars} символов;
- без markdown, списков, эмодзи, кавычек-ёлочек;
- без «Конечно!» и «Как ИИ…».
PROMPT;
    }
}
