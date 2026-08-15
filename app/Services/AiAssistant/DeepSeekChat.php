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
        $settings = AiAssistantSetting::forClub(isset($context['club_id']) ? (int) $context['club_id'] : null);
        $key = $settings->resolvedLlmApiKey();
        if ($key === '') {
            throw new RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $base = $settings->resolvedLlmBaseUrl();
        $model = $settings->resolvedLlmModel();
        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $maxChars = $settings->resolvedMaxReplyChars();

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
     * Cheap live check from admin: one short completion, no STT/TTS.
     *
     * @return array{ok:true, reply:string, model:string, base_url:string, provider:string}
     */
    public function probe(?int $clubId = null): array
    {
        $settings = AiAssistantSetting::forClub($clubId);
        $key = $settings->resolvedLlmApiKey();
        if ($key === '') {
            throw new RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $base = $settings->resolvedLlmBaseUrl();
        $model = $settings->resolvedLlmModel();
        $timeout = min(20.0, (float) config('ai_assistant.http_timeout', 60));

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => 16,
                'messages' => [
                    ['role' => 'system', 'content' => 'Ответь одним словом: ок'],
                    ['role' => 'user', 'content' => 'пинг'],
                ],
            ]);

        if (! $response->successful()) {
            $body = trim($response->body());
            if (mb_strlen($body) > 400) {
                $body = mb_substr($body, 0, 400).'…';
            }

            throw new RuntimeException('LLM failed: HTTP '.$response->status().' '.$body);
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($text === '') {
            throw new RuntimeException('LLM вернул пустой ответ.');
        }

        return [
            'ok' => true,
            'reply' => $text,
            'model' => $model,
            'base_url' => $base,
            'provider' => $settings->resolvedLlmProvider(),
        ];
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
        $settings = AiAssistantSetting::forClub(isset($context['club_id']) ? (int) $context['club_id'] : null);
        $key = $settings->resolvedLlmApiKey();
        if ($key === '') {
            throw new RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $base = $settings->resolvedLlmBaseUrl();
        $model = $settings->resolvedLlmModel();
        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $maxChars = min(280, $settings->resolvedMaxReplyChars());

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
