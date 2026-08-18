<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexTextToSpeech
{
    /** Классика REST v1. Остальные (Марина, Даша, …) только в API v3. */
    public const V1_VOICES = ['alena', 'filipp', 'jane', 'omazh', 'zahar', 'ermil'];

    /** emotion в v1; у Филиппа амплуа нет — с ним 400. */
    public const V1_EMOTION_VOICES = ['alena', 'jane', 'omazh', 'zahar', 'ermil'];

    /**
     * @param  array{api_key?:string,folder_id?:string,url?:string,v3_url?:string,lang?:string}|null  $credentials
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

        if (in_array($resolvedVoice, self::V1_VOICES, true)) {
            return $this->synthesizeV1($text, $resolvedVoice, $key, $credentials);
        }

        return $this->synthesizeV3($text, $resolvedVoice, $key, $credentials);
    }

    /**
     * @param  array{folder_id?:string,url?:string,lang?:string}|null  $credentials
     * @return array{mime:string,binary:string}
     */
    private function synthesizeV1(string $text, string $voice, string $key, ?array $credentials): array
    {
        $payload = [
            'text' => $text,
            'lang' => (string) ($credentials['lang'] ?? config('ai_assistant.yandex.lang', 'ru-RU')),
            'voice' => $voice,
            'format' => 'mp3',
        ];
        $folder = trim((string) ($credentials['folder_id'] ?? config('ai_assistant.yandex.folder_id', '')));
        if ($folder !== '') {
            $payload['folderId'] = $folder;
        }
        if (in_array($voice, self::V1_EMOTION_VOICES, true)) {
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

    /**
     * @param  array{folder_id?:string,v3_url?:string}|null  $credentials
     * @return array{mime:string,binary:string}
     */
    private function synthesizeV3(string $text, string $voice, string $key, ?array $credentials): array
    {
        $url = rtrim(trim((string) ($credentials['v3_url'] ?? config('ai_assistant.yandex.tts_v3_url', 'https://tts.api.cloud.yandex.net/tts/v3/utteranceSynthesis'))), '/');
        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $folder = trim((string) ($credentials['folder_id'] ?? config('ai_assistant.yandex.folder_id', '')));

        $headers = ['Authorization' => 'Api-Key '.$key];
        if ($folder !== '') {
            $headers['x-folder-id'] = $folder;
        }

        $payload = [
            'text' => $text,
            'hints' => [
                ['voice' => $voice],
            ],
            'outputAudioSpec' => [
                'containerAudio' => [
                    'containerAudioType' => 'MP3',
                ],
            ],
            'unsafeMode' => true,
        ];

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders($headers)
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Yandex TTS v3 failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $binary = self::decodeV3Audio($response->body());
        if ($binary === '') {
            throw new RuntimeException('Yandex TTS v3 вернул пустое аудио.');
        }

        return [
            'mime' => 'audio/mpeg',
            'binary' => $binary,
        ];
    }

    public static function decodeV3Audio(string $body): string
    {
        $chunks = [];
        $json = json_decode($body, true);
        if (is_array($json)) {
            self::collectAudioChunks($json, $chunks);
        } else {
            foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (is_array($row)) {
                    self::collectAudioChunks($row, $chunks);
                }
            }
        }

        $binary = '';
        foreach ($chunks as $b64) {
            $decoded = base64_decode($b64, true);
            if ($decoded !== false) {
                $binary .= $decoded;
            }
        }

        return $binary;
    }

    /**
     * @param  array<mixed>  $json
     * @param  list<string>  $chunks
     */
    private static function collectAudioChunks(array $json, array &$chunks): void
    {
        if (array_is_list($json)) {
            foreach ($json as $item) {
                if (is_array($item)) {
                    self::collectAudioChunks($item, $chunks);
                }
            }

            return;
        }

        if (isset($json['result']) && is_array($json['result']) && array_is_list($json['result'])) {
            self::collectAudioChunks($json['result'], $chunks);

            return;
        }

        $data = $json['result']['audioChunk']['data']
            ?? $json['audioChunk']['data']
            ?? null;
        if (is_string($data) && $data !== '') {
            $chunks[] = $data;
        }
    }
}
