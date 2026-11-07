<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GlmSpeechToText
{
    public function transcribe(UploadedFile $audio, AiAssistantSetting $settings): string
    {
        $key = $settings->resolvedGlmApiKey();
        if ($key === '') {
            throw new RuntimeException('GLM API-ключ не задан (нужен для распознавания речи).');
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

        $name = str_starts_with($bytes, 'RIFF')
            ? 'audio.wav'
            : ($audio->getClientOriginalName() ?: 'audio.wav');

        Log::info('glm_stt_clip', [
            'bytes' => strlen($bytes),
            'name' => $name,
            'model' => $settings->resolvedGlmAsrModel(),
        ]);

        $timeout = (float) config('ai_assistant.http_timeout', 60);
        $response = Http::timeout($timeout)
            ->withToken($key)
            ->attach('file', $bytes, $name)
            ->post($settings->resolvedGlmBaseUrl().'/audio/transcriptions', [
                'model' => $settings->resolvedGlmAsrModel(),
                'stream' => 'false',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->fail($response->status(), $response->body()));
        }

        $text = trim((string) ($response->json('text') ?? ''));
        if ($text === '') {
            throw new RuntimeException('Речь не распознана. Говорите ближе к микрофону гарнитуры, 1–2 секунды.');
        }

        return $text;
    }

    private function fail(int $status, string $body): string
    {
        $message = trim($body);
        if (mb_strlen($message) > 300) {
            $message = mb_substr($message, 0, 300).'…';
        }

        return 'GLM ASR HTTP '.$status.($message !== '' ? ' '.$message : '');
    }
}
