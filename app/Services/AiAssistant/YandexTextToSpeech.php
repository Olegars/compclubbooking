<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexTextToSpeech
{
    /**
     * @param  array{api_key?:string,folder_id?:string,url?:string,lang?:string}|null  $credentials
     * @return array{mime:string,binary:string}
     */
    public function synthesize(string $text, ?string $voice = null, ?array $credentials = null): array
    {
        $key = trim((string) ($credentials['api_key'] ?? config('ai_assistant.yandex.api_key', '')));
        if ($key === '') {
            throw new RuntimeException('Yandex SpeechKit ключ не задан (нужен для озвучки).');
        }

        $resolvedVoice = $voice !== null && trim($voice) !== ''
            ? strtolower(trim($voice))
            : (string) config('ai_assistant.yandex.tts_voice', 'alena');

        $payload = [
            'text' => $text,
            'lang' => (string) ($credentials['lang'] ?? config('ai_assistant.yandex.lang', 'ru-RU')),
            'voice' => $resolvedVoice,
            'format' => 'mp3',
        ];
        $folder = trim((string) ($credentials['folder_id'] ?? config('ai_assistant.yandex.folder_id', '')));
        if ($folder !== '') {
            $payload['folderId'] = $folder;
        }
        if (in_array($resolvedVoice, ['alena', 'filipp', 'jane', 'omazh', 'zahar', 'ermil'], true)) {
            $payload['emotion'] = 'good';
        }

        $url = rtrim(trim((string) ($credentials['url'] ?? config('ai_assistant.yandex.tts_url', 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize'))), '/');
        $timeout = (float) config('ai_assistant.http_timeout', 60);

        $response = Http::timeout($timeout)
            ->asForm()
            ->withHeaders(['Authorization' => 'Api-Key '.$key])
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Yandex TTS failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new RuntimeException('Yandex TTS вернул пустое аудио.');
        }

        return [
            'mime' => 'audio/mpeg',
            'binary' => $binary,
        ];
    }
}
