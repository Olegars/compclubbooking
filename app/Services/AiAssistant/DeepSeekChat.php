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
            ->post($base.'/chat/completions', $this->chatPayload(
                $model,
                [
                    ['role' => 'system', 'content' => $settings->resolveCompanionPrompt($context, $maxChars)],
                    ['role' => 'user', 'content' => $userText],
                ],
                temperature: 0.7,
                maxTokens: 220,
            ));

        if (! $response->successful()) {
            throw new RuntimeException(
                'LLM failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $text = $this->extractMessageText($response->json());
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
            ->post($base.'/chat/completions', $this->chatPayload(
                $model,
                [
                    ['role' => 'system', 'content' => 'Ответь одним словом: ок'],
                    ['role' => 'user', 'content' => 'пинг'],
                ],
                temperature: 0,
                maxTokens: 64,
            ));

        if (! $response->successful()) {
            $body = trim($response->body());
            if (mb_strlen($body) > 400) {
                $body = mb_substr($body, 0, 400).'…';
            }

            throw new RuntimeException('LLM failed: HTTP '.$response->status().' '.$body);
        }

        $text = $this->extractMessageText($response->json());
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
            ->post($base.'/chat/completions', $this->chatPayload(
                $model,
                [
                    ['role' => 'system', 'content' => $settings->resolveGreetingPrompt($context, $maxChars)],
                    ['role' => 'user', 'content' => 'Сгенерируй короткое голосовое приветствие для этого игрока прямо сейчас.'],
                ],
                temperature: 0.8,
                maxTokens: 160,
            ));

        if (! $response->successful()) {
            throw new RuntimeException(
                'LLM greeting failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $text = $this->extractMessageText($response->json());
        if ($text === '') {
            throw new RuntimeException('LLM вернул пустое приветствие.');
        }

        if (mb_strlen($text) > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
        }

        return $text;
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     * @return array<string, mixed>
     */
    private function chatPayload(string $model, array $messages, float $temperature, int $maxTokens): array
    {
        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        // DeepSeek V4: thinking включён по умолчанию и съедает max_tokens → content пустой
        if ($this->isDeepSeekModel($model)) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        return $payload;
    }

    private function isDeepSeekModel(string $model): bool
    {
        $m = strtolower($model);

        return str_contains($m, 'deepseek') || str_starts_with($m, 'deepseek-');
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractMessageText(?array $json): string
    {
        $message = data_get($json, 'choices.0.message', []);
        if (! is_array($message)) {
            return '';
        }

        $content = trim((string) ($message['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }

        // fallback если thinking всё же включён и ответ только в reasoning
        return trim((string) ($message['reasoning_content'] ?? ''));
    }
}
