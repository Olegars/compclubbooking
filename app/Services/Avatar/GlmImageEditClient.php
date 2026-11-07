<?php

namespace App\Services\Avatar;

use App\Models\AiAssistantSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GlmImageEditClient
{
    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Один кадр: фото гостя и клубный образец как референс стиля.
     */
    public function stylize(string $photoBytes, string $sampleBytes, string $prompt, string $negative): ?string
    {
        $prompt = trim($prompt);
        if (! $this->configured() || $photoBytes === '' || $sampleBytes === '' || $prompt === '') {
            return null;
        }
        $negative = trim($negative);
        if ($negative !== '') {
            $prompt .= ' Avoid: '.mb_substr($negative, 0, 300);
        }

        $timeout = (float) config('ai_assistant.image_timeout', 120);
        $payload = [
            'model' => $this->model(),
            'prompt' => $prompt,
            'size' => '1280x1280',
            'quality' => 'hd',
            'images' => [
                $this->dataUri($photoBytes),
                $this->dataUri($sampleBytes),
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->withToken($this->apiKey())
                ->acceptJson()
                ->post($this->baseUrl().'/images/generations', $payload);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('GLM avatar stylize failed', [
                'status' => $response->status(),
                'message' => $response->json('message') ?: $response->json('error.message'),
            ]);

            return null;
        }

        $image = trim((string) data_get($response->json(), 'data.0.url'));
        if ($image === '') {
            Log::warning('GLM avatar stylize returned no image');

            return null;
        }

        return $this->decodeImage($image, $timeout);
    }

    private function apiKey(): string
    {
        return AiAssistantSetting::forClub(null)->resolvedGlmApiKey();
    }

    private function baseUrl(): string
    {
        return AiAssistantSetting::forClub(null)->resolvedGlmBaseUrl();
    }

    private function model(): string
    {
        $model = trim((string) config('ai_assistant.avatar.glm.model', 'glm-image'));

        return $model !== '' ? $model : 'glm-image';
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
