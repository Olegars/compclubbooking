<?php

namespace App\Services\Avatar;

use Illuminate\Support\Facades\Http;

class HuggingFaceAvatarClient
{
    public function refine(string $imageBytes, string $prompt, string $negative = ''): ?string
    {
        $token = trim((string) config('ai_assistant.avatar.huggingface.token', ''));
        if ($token === '' || $imageBytes === '') {
            return null;
        }

        $model = trim((string) config('ai_assistant.avatar.huggingface.model', 'Qwen/Qwen-Image-Edit'));
        $provider = trim((string) config('ai_assistant.avatar.huggingface.provider', 'hf-inference'));
        $base = rtrim((string) config('ai_assistant.avatar.huggingface.base_url', 'https://router.huggingface.co'), '/');
        $timeout = (float) config('ai_assistant.image_timeout', 120);

        $payload = [
            'inputs' => base64_encode($imageBytes),
            'parameters' => [
                'prompt' => $prompt,
                'negative_prompt' => $negative !== '' ? $negative : 'photo, selfie, blurry, extra face, watermark, low quality',
                'guidance_scale' => 4.5,
                'num_inference_steps' => 8,
                'target_size' => ['width' => 512, 'height' => 512],
            ],
        ];

        foreach ($this->endpoints($base, $provider, $model) as $url) {
            $bytes = $this->postImage($url, $token, $payload, $timeout);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function endpoints(string $base, string $provider, string $model): array
    {
        $model = ltrim($model, '/');
        $provider = trim($provider) !== '' ? trim($provider) : 'hf-inference';
        $urls = [];
        if ($provider !== 'auto') {
            $urls[] = $base.'/'.$provider.'/models/'.$model;
        }
        $urls[] = $base.'/hf-inference/models/'.$model;
        $urls[] = 'https://api-inference.huggingface.co/models/'.$model;

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postImage(string $url, string $token, array $payload, float $timeout): ?string
    {
        $attempts = 0;
        while ($attempts < 2) {
            $attempts++;
            try {
                $response = Http::timeout($timeout)
                    ->withToken($token)
                    ->accept('image/png')
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $payload);
            } catch (\Throwable) {
                return null;
            }

            if ($response->status() === 503 && $attempts === 1 && ! app()->runningUnitTests()) {
                usleep(1_500_000);

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($this->looksLikeImage($body)) {
                return $body;
            }

            $json = json_decode($body, true);
            if (! is_array($json)) {
                return null;
            }
            $b64 = data_get($json, 'image')
                ?? data_get($json, 'data.0.b64_json')
                ?? data_get($json, 'data.0.image');
            if (is_string($b64) && $b64 !== '') {
                $decoded = base64_decode(preg_replace('/\s+/', '', $b64) ?? $b64, true);
                if (is_string($decoded) && $this->looksLikeImage($decoded)) {
                    return $decoded;
                }
            }

            return null;
        }

        return null;
    }

    private function looksLikeImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG")
            || str_starts_with($bytes, "\xff\xd8\xff")
            || str_starts_with($bytes, 'GIF8')
            || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    }
}
