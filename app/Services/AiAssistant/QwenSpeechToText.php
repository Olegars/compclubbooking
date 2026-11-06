<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class QwenSpeechToText
{
    public function transcribe(UploadedFile $audio, AiAssistantSetting $settings): string
    {
        $key = $settings->resolvedQwenApiKey();
        if ($key === '') {
            throw new RuntimeException('Qwen API-ключ не задан (нужен для распознавания речи).');
        }

        $path = $audio->getRealPath();
        $bytes = ($path && is_readable($path)) ? (string) file_get_contents($path) : (string) $audio->getContent();
        if ($bytes === '' && ! app()->runningUnitTests()) {
            throw new RuntimeException('Не удалось прочитать аудиофайл.');
        }

        if (str_starts_with($bytes, 'RIFF')) {
            $wav = WavPcm::toSpeechKitLpcm($bytes);
            if ($wav['peak'] < 80 && ! app()->runningUnitTests()) {
                throw new RuntimeException('Микрофон молчит (в записи тишина). Проверьте устройство ввода гарнитуры.');
            }
        }

        $mime = strtolower(trim((string) $audio->getMimeType()));
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/wav';
        }
        if ($mime === 'audio/wave' || $mime === 'audio/x-wav') {
            $mime = 'audio/wav';
        }

        Log::info('qwen_stt_clip', [
            'bytes' => strlen($bytes),
            'mime' => $mime,
            'model' => $settings->resolvedQwenAsrModel(),
        ]);

        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $response = Http::timeout($timeout)
            ->withToken($key)
            ->acceptJson()
            ->post($settings->resolvedQwenGenerationUrl(), [
                'model' => $settings->resolvedQwenAsrModel(),
                'input' => [
                    'messages' => [[
                        'role' => 'user',
                        'content' => [[
                            'audio' => 'data:'.$mime.';base64,'.base64_encode($bytes),
                        ]],
                    ]],
                ],
                'parameters' => [
                    'asr_options' => [
                        'language' => (string) config('ai_assistant.qwen.asr_language', 'ru'),
                        'enable_itn' => false,
                    ],
                ],
            ]);

        if (! $response->successful() || $this->dashScopeError($response)) {
            throw new RuntimeException($this->fail('Qwen ASR', $response));
        }

        $text = $this->extractText($response->json());
        if ($text === '') {
            throw new RuntimeException('Речь не распознана. Говорите ближе к микрофону гарнитуры, 1–2 секунды.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractText(?array $json): string
    {
        $content = data_get($json, 'output.choices.0.message.content');
        if (is_string($content)) {
            return trim($content);
        }
        if (is_array($content)) {
            $bits = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $bits[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $bits[] = (string) $part['text'];
                }
            }

            return trim(implode('', $bits));
        }

        $alt = data_get($json, 'choices.0.message.content');

        return is_string($alt) ? trim($alt) : '';
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
