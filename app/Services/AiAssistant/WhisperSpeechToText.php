<?php

namespace App\Services\AiAssistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhisperSpeechToText
{
    /**
     * @param  array{api_key?:string,base_url?:string,model?:string}|null  $credentials
     */
    public function transcribe(UploadedFile $audio, ?array $credentials = null): string
    {
        $key = trim((string) ($credentials['api_key'] ?? config('ai_assistant.openai.api_key', '')));
        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан (нужен для Whisper STT).');
        }

        $base = rtrim(trim((string) ($credentials['base_url'] ?? config('ai_assistant.openai.base_url', 'https://api.openai.com/v1'))), '/');
        $model = trim((string) ($credentials['model'] ?? config('ai_assistant.openai.stt_model', 'whisper-1')));
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
