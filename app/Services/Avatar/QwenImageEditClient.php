<?php

namespace App\Services\Avatar;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QwenImageEditClient
{
    public function configured(): bool
    {
        return $this->apiKey() !== '' && $this->baseUrl() !== '';
    }

    /**
     * Один кадр: фото гостя и клубный образец как референс стиля.
     */
    public function stylize(string $photoBytes, string $sampleBytes, string $prompt, string $negative): ?string
    {
        if (! $this->configured() || $photoBytes === '' || $sampleBytes === '' || trim($prompt) === '') {
            return null;
        }

        $timeout = (float) config('ai_assistant.image_timeout', 120);
        $url = $this->baseUrl().'/services/aigc/multimodal-generation/generation';
        $payload = [
            'model' => $this->model(),
            'input' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['image' => $this->dataUri($photoBytes)],
                        ['image' => $this->dataUri($sampleBytes)],
                        ['text' => $prompt],
                    ],
                ]],
            ],
            'parameters' => [
                'n' => 1,
                'negative_prompt' => mb_substr($negative, 0, 500),
                'prompt_extend' => (bool) config('ai_assistant.avatar.qwen.prompt_extend', false),
                'watermark' => false,
                'size' => '1024*1024',
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->withToken($this->apiKey())
                ->acceptJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Qwen avatar stylize failed', [
                'status' => $response->status(),
                'code' => $response->json('code'),
                'message' => $response->json('message'),
            ]);

            return null;
        }

        $image = $this->firstImage(data_get($response->json(), 'output.choices.0.message.content'));
        if ($image === null) {
            Log::warning('Qwen avatar stylize returned no image', [
                'request_id' => $response->json('request_id'),
            ]);

            return null;
        }

        return $this->decodeImage($image, $timeout);
    }

    private function apiKey(): string
    {
        return trim((string) config('ai_assistant.avatar.qwen.api_key', ''));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai_assistant.avatar.qwen.base_url', ''), '/');
    }

    private function model(): string
    {
        $model = trim((string) config('ai_assistant.avatar.qwen.model', 'qwen-image-2.0-pro'));

        return $model !== '' ? $model : 'qwen-image-2.0-pro';
    }

    private function dataUri(string $bytes): string
    {
        $mime = 'image/png';
        if (str_starts_with($bytes, "\xff\xd8\xff")) {
            $mime = 'image/jpeg';
        } elseif (str_starts_with($bytes, 'GIF8')) {
            $mime = 'image/gif';
        } elseif (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function firstImage(mixed $content): ?string
    {
        if (! is_array($content)) {
            return null;
        }
        foreach ($content as $part) {
            if (is_array($part) && is_string($part['image'] ?? null) && $part['image'] !== '') {
                return $part['image'];
            }
        }

        return null;
    }

    private function decodeImage(string $image, float $timeout): ?string
    {
        if (str_starts_with($image, 'data:')) {
            $comma = strpos($image, ',');
            $payload = $comma === false ? '' : substr($image, $comma + 1);
            $bytes = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);

            return is_string($bytes) && $this->looksLikeImage($bytes) ? $bytes : null;
        }

        if (! str_starts_with($image, 'https://') || $this->blockedHost($image)) {
            return null;
        }

        try {
            $response = Http::timeout(min(30, $timeout))
                ->withHeaders(['Accept' => 'image/png,image/*'])
                ->get($image);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if (strlen($bytes) > 15 * 1024 * 1024 || ! $this->looksLikeImage($bytes)) {
            return null;
        }

        return $bytes;
    }

    private function blockedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return true;
        }
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function looksLikeImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG")
            || str_starts_with($bytes, "\xff\xd8\xff")
            || str_starts_with($bytes, 'GIF8')
            || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    }
}
