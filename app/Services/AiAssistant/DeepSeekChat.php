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
}
