<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class QwenTextToSpeech
{
    /**
     * @return array{mime:string,binary:string}
     */
    public function synthesize(string $text, AiAssistantSetting $settings, ?string $voice = null): array
    {
        $key = $settings->resolvedQwenApiKey();
        if ($key === '') {
            throw new RuntimeException('Qwen API-ключ не задан (нужен для озвучки).');
        }

        $text = trim($text);
        if (mb_strlen($text) > 600) {
            $text = rtrim(mb_substr($text, 0, 599)).'…';
        }
        if ($text === '') {
            throw new RuntimeException('Нечего озвучивать.');
        }

        $resolvedVoice = AiAssistantSetting::sanitizeTtsVoice('qwen', $voice)
            ?? $settings->resolvedQwenTtsVoice();

        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($settings->resolvedQwenGenerationUrl(), [
                'model' => $settings->resolvedQwenTtsModel(),
                'input' => [
                    'text' => $text,
                    'voice' => $resolvedVoice,
                    'language_type' => (string) config('ai_assistant.qwen.tts_language', 'Russian'),
                ],
            ]);

        if (! $response->successful() || $this->dashScopeError($response)) {
            throw new RuntimeException($this->fail('Qwen TTS', $response));
        }

        $inline = trim((string) $response->json('output.audio.data'));
        if ($inline !== '') {
            $binary = base64_decode($inline, true);
            if (is_string($binary) && $binary !== '') {
                return [
                    'mime' => $this->mimeFor($binary, ''),
                    'binary' => $binary,
                ];
            }
        }

        $url = trim((string) $response->json('output.audio.url'));
        if ($url === '' || $this->blockedHost($url)) {
            throw new RuntimeException('Qwen TTS не вернул аудио.');
        }

        try {
            $audio = Http::timeout(min(30, $timeout))
                ->withHeaders(['Accept' => 'audio/wav,audio/mpeg,audio/*'])
                ->get($url);
        } catch (\Throwable $e) {
            report($e);
            throw new RuntimeException('Qwen TTS: не удалось скачать озвучку.');
        }

        if (! $audio->successful()) {
            throw new RuntimeException($this->fail('Qwen TTS audio', $audio));
        }

        $binary = $audio->body();
        if ($binary === '' || strlen($binary) > 15 * 1024 * 1024) {
            throw new RuntimeException('Qwen TTS вернул пустое аудио.');
        }

        $mime = strtolower(trim(explode(';', (string) $audio->header('Content-Type'))[0]));

        return [
            'mime' => $this->mimeFor($binary, $mime !== '' ? $mime : $url),
            'binary' => $binary,
        ];
    }

    private function mimeFor(string $binary, string $hint): string
    {
        if (str_starts_with($binary, 'RIFF')) {
            return 'audio/wav';
        }
        if (str_starts_with($binary, 'ID3') || str_starts_with($binary, "\xff\xfb")) {
            return 'audio/mpeg';
        }
        $hint = strtolower($hint);
        if (str_contains($hint, 'wav')) {
            return 'audio/wav';
        }
        if (str_contains($hint, 'mpeg') || str_contains($hint, 'mp3')) {
            return 'audio/mpeg';
        }

        return 'audio/wav';
    }

    private function blockedHost(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return true;
        }
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function dashScopeError(Response $response): bool
    {
        $code = $response->json('code');

        return is_string($code) && $code !== '' && $code !== '200';
    }

    private function fail(string $what, Response $response): string
    {
        $message = trim((string) ($response->json('message') ?: $response->body()));
        if (mb_strlen($message) > 300) {
            $message = mb_substr($message, 0, 300).'…';
        }

        return $what.' HTTP '.$response->status().($message !== '' ? ' '.$message : '');
    }
}
