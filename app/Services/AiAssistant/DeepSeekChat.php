<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use App\Models\Club;
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
     * Короткий текстовый completion (ники и прочие не голосовые задачи).
     */
    public function complete(
        string $system,
        string $user,
        float $temperature = 0.9,
        int $maxTokens = 48,
        ?int $clubId = null,
        ?float $timeout = null,
    ): string {
        $settings = $this->settingsForOptionalClub($clubId);
        $key = $settings->resolvedLlmApiKey();
        if ($key === '') {
            throw new RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $base = $settings->resolvedLlmBaseUrl();
        $model = $settings->resolvedLlmModel();
        $timeout ??= min(8.0, (float) config('ai_assistant.http_timeout', 60));

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', $this->chatPayload(
                $model,
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                temperature: $temperature,
                maxTokens: $maxTokens,
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

        return $text;
    }

    /**
     * JSON-ответ по тексту и/или картинке (накладные, OCR).
     *
     * @param  string|list<array<string, mixed>>  $user
     * @return array<string, mixed>
     */
    public function completeJson(
        string $system,
        string|array $user,
        ?int $clubId = null,
        float $temperature = 0.1,
        int $maxTokens = 4000,
        ?float $timeout = null,
        bool $vision = false,
    ): array {
        $settings = $this->settingsForOptionalClub($clubId);
        $key = $settings->resolvedLlmApiKey();
        if ($key === '') {
            throw new RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $base = $settings->resolvedLlmBaseUrl();
        $model = $vision ? $this->resolvedVisionModel($settings) : $settings->resolvedLlmModel();
        $timeout ??= $vision
            ? (float) config('ai_assistant.vision_timeout', 90)
            : (float) config('ai_assistant.http_timeout', 60);

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', $this->chatPayload(
                $model,
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                temperature: $temperature,
                maxTokens: $maxTokens,
                jsonObject: true,
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

        return $this->decodeJsonObject($text);
    }

    /**
     * Клубный аватар: фото игрока + образец дефолтного аватара → картинка от модели.
     */
    public function stylizeAvatar(string $photoDataUrl, string $sampleDataUrl, ?int $clubId = null): string
    {
        $settings = $this->settingsForOptionalClub($clubId);
        $key = $settings->resolvedLlmApiKey();
        if ($key === '') {
            throw new RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $base = $settings->resolvedLlmBaseUrl();
        $model = $this->resolvedVisionModel($settings);
        $timeout = (float) config('ai_assistant.image_timeout', 120);
        $prompt = $this->avatarStylePrompt();

        $fromImages = $this->tryImageGeneration($base, $key, $model, $prompt, $photoDataUrl, $sampleDataUrl, $timeout);
        if ($fromImages !== null) {
            return $fromImages;
        }

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', $this->chatPayload(
                $model,
                [
                    ['role' => 'system', 'content' => $this->avatarStyleSystemPrompt()],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $photoDataUrl, 'detail' => 'high'],
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $sampleDataUrl, 'detail' => 'high'],
                            ],
                        ],
                    ],
                ],
                temperature: 0.4,
                maxTokens: 4096,
            ));

        if (! $response->successful()) {
            $body = trim($response->body());
            if (mb_strlen($body) > 400) {
                $body = mb_substr($body, 0, 400).'…';
            }

            throw new RuntimeException('DeepSeek не стилизовал аватар: HTTP '.$response->status().' '.$body);
        }

        $bytes = $this->extractImageBytes($response->json());
        if ($bytes === null) {
            throw new RuntimeException('DeepSeek не вернул изображение. Попробуйте без стилизации.');
        }

        return $bytes;
    }

    public function resolvedVisionModel(?AiAssistantSetting $settings = null): string
    {
        $settings ??= $this->settingsForOptionalClub(null);
        if ($settings->resolvedLlmProvider() === 'openai') {
            $model = trim($settings->resolvedLlmModel());

            return $model !== '' ? $model : 'gpt-4o-mini';
        }

        $fromConfig = trim((string) config('ai_assistant.deepseek.vision_model', ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        $configured = strtolower($settings->resolvedLlmModel());
        if (str_contains($configured, 'flash') || str_contains($configured, 'vision')) {
            return 'deepseek-flash';
        }

        return 'deepseek-flash';
    }

    /**
     * Без клуба в БД не создаём ai_assistant_settings с club_id=0 (FK).
     */
    private function settingsForOptionalClub(?int $clubId): AiAssistantSetting
    {
        $id = $clubId ?: (int) Club::query()->value('id');
        if ($id > 0) {
            return AiAssistantSetting::forClub($id);
        }

        return new AiAssistantSetting([
            'llm_provider' => 'deepseek',
            'is_enabled' => true,
        ]);
    }

    /**
     * @param  list<array{role:string,content:mixed}>  $messages
     * @return array<string, mixed>
     */
    private function chatPayload(
        string $model,
        array $messages,
        float $temperature,
        int $maxTokens,
        bool $jsonObject = false,
    ): array {
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

        if ($jsonObject) {
            $payload['response_format'] = ['type' => 'json_object'];
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

        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $bits = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $bits[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $bits[] = (string) $part['text'];
                }
            }
            $content = implode('', $bits);
        }

        $text = trim((string) $content);
        if ($text !== '') {
            return $text;
        }

        // fallback если thinking всё же включён и ответ только в reasoning
        return trim((string) ($message['reasoning_content'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/us', $text, $m)) {
            $text = $m[1];
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('LLM вернул не JSON.');
        }

        return $decoded;
    }

    private function avatarStylePrompt(): string
    {
        return <<<'PROMPT'
Первое изображение — фото игрока. Второе — образец клубного аватара.
Перерисуй человека с первого фото в точности в визуальном стиле второго образца: cyberpunk digital illustration, неоновые зелёные схемы на лице, тёмный фон, портрет анфас, та же линия и свет. Сохрани личность, форму лица, волосы и выражение. Без текста и водяных знаков. Верни только готовое изображение аватара.
PROMPT;
    }

    private function avatarStyleSystemPrompt(): string
    {
        return 'Ты художник клубных аватаров. По фото игрока и образцу стиля верни готовое изображение (PNG), не описание.';
    }

    private function tryImageGeneration(
        string $base,
        string $key,
        string $model,
        string $prompt,
        string $photoDataUrl,
        string $sampleDataUrl,
        float $timeout,
    ): ?string {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => '512x512',
            'response_format' => 'b64_json',
            'images' => [
                ['image_url' => ['url' => $photoDataUrl]],
                ['image_url' => ['url' => $sampleDataUrl]],
            ],
        ];
        if ($this->isDeepSeekModel($model)) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        try {
            $response = Http::timeout($timeout)
                ->withToken($key)
                ->acceptJson()
                ->post($base.'/images/generations', $payload);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->extractImageBytes($response->json());
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractImageBytes(?array $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $b64 = data_get($json, 'data.0.b64_json');
        if (is_string($b64) && $b64 !== '') {
            $decoded = $this->decodeBase64Image($b64);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $url = data_get($json, 'data.0.url');
        if (is_string($url) && $url !== '') {
            $fromUrl = $this->bytesFromUrlOrData($url);
            if ($fromUrl !== null) {
                return $fromUrl;
            }
        }

        $message = data_get($json, 'choices.0.message', []);
        if (is_array($message)) {
            $fromMessage = $this->extractImageFromContent($message['content'] ?? null)
                ?? $this->extractImageFromContent($message['images'] ?? null);
            if ($fromMessage !== null) {
                return $fromMessage;
            }
        }

        $text = $this->extractMessageText($json);
        if ($text !== '') {
            $fromText = $this->extractImageFromContent($text);
            if ($fromText !== null) {
                return $fromText;
            }
        }

        return null;
    }

    private function extractImageFromContent(mixed $content): ?string
    {
        if (is_string($content)) {
            if (preg_match('/data:image\/[a-zA-Z0-9.+-]+;base64,([A-Za-z0-9+\/=]+)/', $content, $m)) {
                $decoded = $this->decodeBase64Image($m[1]);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
            if (preg_match('/https?:\/\/[^\s"\']+/', $content, $m)) {
                $fromUrl = $this->bytesFromUrlOrData($m[0]);
                if ($fromUrl !== null) {
                    return $fromUrl;
                }
            }
            $asJson = json_decode($content, true);
            if (is_array($asJson)) {
                foreach (['image_base64', 'b64_json', 'image'] as $field) {
                    if (! empty($asJson[$field]) && is_string($asJson[$field])) {
                        $decoded = $this->decodeBase64Image($asJson[$field]);
                        if ($decoded !== null) {
                            return $decoded;
                        }
                    }
                }
            }

            return null;
        }

        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $part) {
            if (is_string($part)) {
                $found = $this->extractImageFromContent($part);
                if ($found !== null) {
                    return $found;
                }
                continue;
            }
            if (! is_array($part)) {
                continue;
            }
            $candidates = [
                $part['b64_json'] ?? null,
                $part['image_base64'] ?? null,
                $part['url'] ?? null,
                $part['data'] ?? null,
                data_get($part, 'image_url.url'),
            ];
            foreach ($candidates as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $found = str_starts_with($value, 'http') || str_starts_with($value, 'data:')
                    ? $this->bytesFromUrlOrData($value)
                    : $this->decodeBase64Image($value);
                if ($found !== null) {
                    return $found;
                }
            }
            if (isset($part['text'])) {
                $found = $this->extractImageFromContent($part['text']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function bytesFromUrlOrData(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.+)$/s', $url, $m)) {
            return $this->decodeBase64Image($m[1]);
        }
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return $this->decodeBase64Image($url);
        }

        try {
            $response = Http::timeout(30)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $bytes = $response->body();

        return $this->looksLikeImage($bytes) ? $bytes : null;
    }

    private function decodeBase64Image(string $raw): ?string
    {
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        if (str_starts_with($raw, 'data:')) {
            $comma = strpos($raw, ',');
            $raw = $comma === false ? $raw : substr($raw, $comma + 1);
        }
        $decoded = base64_decode($raw, true);
        if (! is_string($decoded) || $decoded === '' || ! $this->looksLikeImage($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function looksLikeImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG")
            || str_starts_with($bytes, "\xff\xd8\xff")
            || str_starts_with($bytes, 'GIF8')
            || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    }
}
