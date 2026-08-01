<?php

namespace App\Services\AiAssistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhisperSpeechToText
{
    public function transcribe(UploadedFile $audio): string
    {
        $key = (string) config('ai_assistant.openai.api_key');
        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан (нужен для Whisper STT).');
        }

        $base = (string) config('ai_assistant.openai.base_url');
        $model = (string) config('ai_assistant.openai.stt_model', 'whisper-1');
        $timeout = (float) config('ai_assistant.http_timeout', 60);

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->attach(
                'file',
                file_get_contents($audio->getRealPath()),
                $audio->getClientOriginalName() ?: 'audio.webm'
            )
            ->post($base.'/audio/transcriptions', [
                'model' => $model,
                'language' => 'ru',
                'response_format' => 'json',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'STT failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $text = trim((string) ($response->json('text') ?? ''));
        if ($text === '') {
            throw new RuntimeException('STT вернул пустой текст.');
        }

        return $text;
    }
}
