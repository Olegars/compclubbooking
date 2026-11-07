<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GlmTextToSpeech
{
    /**
     * @return array{mime:string,binary:string}
     */
    public function synthesize(string $text, AiAssistantSetting $settings, ?string $voice = null): array
    {
        $key = $settings->resolvedGlmApiKey();
        if ($key === '') {
            throw new RuntimeException('GLM API-ключ не задан (нужен для озвучки).');
        }

        $text = trim($text);
        if (mb_strlen($text) > 1000) {
            $text = rtrim(mb_substr($text, 0, 999)).'…';
        }
        if ($text === '') {
            throw new RuntimeException('Нечего озвучивать.');
        }

        $resolvedVoice = AiAssistantSetting::sanitizeTtsVoice('glm', $voice)
            ?? $settings->resolvedGlmTtsVoice();

        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $response = Http::timeout($timeout)
            ->withToken($key)
            ->withHeaders(['Accept' => 'audio/wav'])
            ->post($settings->resolvedGlmBaseUrl().'/audio/speech', [
                'model' => $settings->resolvedGlmTtsModel(),
                'input' => $text,
                'voice' => $resolvedVoice,
                'response_format' => 'wav',
                'stream' => false,
            ]);

        $binary = $response->body();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! $response->successful() || str_contains($mime, 'json') || str_starts_with(ltrim($binary), '{')) {
            $message = trim((string) ($response->json('error.message') ?: $response->json('message') ?: $binary));
            if (mb_strlen($message) > 300) {
                $message = mb_substr($message, 0, 300).'…';
            }
            throw new RuntimeException('GLM TTS HTTP '.$response->status().($message !== '' ? ' '.$message : ''));
        }

        if ($binary === '' || strlen($binary) > 15 * 1024 * 1024) {
            throw new RuntimeException('GLM TTS вернул пустое аудио.');
        }

        return [
            'mime' => str_starts_with($binary, 'RIFF') ? 'audio/wav' : ($mime !== '' ? $mime : 'audio/wav'),
            'binary' => $binary,
        ];
    }
}
