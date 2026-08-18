<?php

namespace App\Services\AiAssistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexSpeechToText
{
    /**
     * @param  array{api_key?:string,folder_id?:string,url?:string,lang?:string}|null  $credentials
     */
    public function transcribe(UploadedFile $audio, ?array $credentials = null): string
    {
        $key = trim((string) ($credentials['api_key'] ?? config('ai_assistant.yandex.api_key', '')));
        if ($key === '') {
            throw new RuntimeException('Yandex SpeechKit ключ не задан (нужен для распознавания речи).');
        }

        $path = $audio->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('Не удалось прочитать аудиофайл.');
        }

        $bytes = (string) file_get_contents($path);
        $wav = WavPcm::extract($bytes);
        $rate = WavPcm::speechKitRate((int) $wav['sample_rate']);

        $query = [
            'lang' => (string) ($credentials['lang'] ?? config('ai_assistant.yandex.lang', 'ru-RU')),
            'format' => 'lpcm',
            'sampleRateHertz' => (string) $rate,
            'topic' => 'general',
        ];
        $folder = trim((string) ($credentials['folder_id'] ?? config('ai_assistant.yandex.folder_id', '')));
        if ($folder !== '') {
            $query['folderId'] = $folder;
        }

        $url = rtrim(trim((string) ($credentials['url'] ?? config('ai_assistant.yandex.stt_url', 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'))), '/');
        $timeout = (float) config('ai_assistant.http_timeout', 60);

        $response = Http::timeout($timeout)
            ->withHeaders(['Authorization' => 'Api-Key '.$key])
            ->withBody($wav['pcm'], 'application/octet-stream')
            ->post($url.'?'.http_build_query($query));

        if (! $response->successful()) {
            throw new RuntimeException(
                'Yandex STT failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $text = trim((string) ($response->json('result') ?? ''));
        if ($text === '') {
            throw new RuntimeException('Yandex STT вернул пустой текст.');
        }

        return $text;
    }
}
