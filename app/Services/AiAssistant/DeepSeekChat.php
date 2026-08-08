<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekChat
{
    /**
     * @param  array{game_title:?string,player_name:?string,club_name:?string,club_id?:?int}  $context
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
        $settings = AiAssistantSetting::forClub(isset($context['club_id']) ? (int) $context['club_id'] : null);

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.7,
                'max_tokens' => 220,
                'messages' => [
                    ['role' => 'system', 'content' => $settings->resolveCompanionPrompt($context, $maxChars)],
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
     *   favorite_games:array<int, array{id:int,title:string,launch_count:int}>,
     *   club_id?:?int
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
        $settings = AiAssistantSetting::forClub(isset($context['club_id']) ? (int) $context['club_id'] : null);

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.8,
                'max_tokens' => 160,
                'messages' => [
                    ['role' => 'system', 'content' => $settings->resolveGreetingPrompt($context, $maxChars)],
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
}
