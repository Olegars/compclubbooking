<?php

namespace App\Services\AiAssistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class YandexSpeechToText
{
    /**
     * @param  array{api_key?:string,folder_id?:string,url?:string,lang?:string}|null  $credentials
     */
    public function transcribe(UploadedFile $audio, ?array $credentials = null): string
    {
        $key = YandexCloudAuth::normalizeKey(
            (string) ($credentials['api_key'] ?? config('ai_assistant.yandex.api_key', ''))
        );
        if ($key === '') {
            throw new RuntimeException('Yandex SpeechKit ключ не задан (нужен для распознавания речи).');
        }

        $path = $audio->getRealPath();
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('Не удалось прочитать аудиофайл.');
        }

        $bytes = (string) file_get_contents($path);
        $wav = WavPcm::toSpeechKitLpcm($bytes);

        if ($wav['peak'] < 80) {
            throw new RuntimeException('Микрофон молчит (в записи тишина). Проверьте устройство ввода гарнитуры.');
        }

        Log::info('yandex_stt_clip', [
            'pcm_bytes' => strlen($wav['pcm']),
            'sample_rate' => $wav['sample_rate'],
            'peak' => $wav['peak'],
        ]);

        $query = [
            'lang' => (string) ($credentials['lang'] ?? config('ai_assistant.yandex.lang', 'ru-RU')),
            'format' => 'lpcm',
            'sampleRateHertz' => (string) $wav['sample_rate'],
            'topic' => 'general',
        ];

        $url = rtrim(trim((string) ($credentials['url'] ?? config('ai_assistant.yandex.stt_url', 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize'))), '/');
        $timeout = (float) config('ai_assistant.http_timeout', 60);

        $response = Http::timeout($timeout)
            ->withHeaders(['Authorization' => YandexCloudAuth::authorizationHeader($key)])
            ->withBody($wav['pcm'], 'application/octet-stream')
            ->post($url.'?'.http_build_query($query));

        if (! $response->successful()) {
            throw new RuntimeException(YandexCloudAuth::httpError('Yandex STT', $response));
        }

        $text = trim((string) ($response->json('result') ?? ''));
        if ($text === '') {
            throw new RuntimeException('Речь не распознана. Говорите ближе к микрофону гарнитуры, 1–2 секунды.');
        }

        return $text;
    }
}
