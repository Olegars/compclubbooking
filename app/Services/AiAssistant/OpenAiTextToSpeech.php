<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiTextToSpeech
{
    /**
     * @param  array{api_key?:string,base_url?:string,model?:string}|null  $credentials
     * @return array{mime:string,binary:string}
     */
    public function synthesize(string $text, ?string $voice = null, ?array $credentials = null): array
    {
        $key = trim((string) ($credentials['api_key'] ?? config('ai_assistant.openai.api_key', '')));
        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY не задан (нужен для TTS).');
        }

        $base = rtrim(trim((string) ($credentials['base_url'] ?? config('ai_assistant.openai.base_url', 'https://api.openai.com/v1'))), '/');
        $model = trim((string) ($credentials['model'] ?? config('ai_assistant.openai.tts_model', 'tts-1')));
        $resolvedVoice = $voice !== null && trim($voice) !== ''
            ? strtolower(trim($voice))
            : (string) config('ai_assistant.openai.tts_voice', 'nova');
        $timeout = (float) config('ai_assistant.http_timeout', 60);

        $response = Http::timeout($timeout)
            ->withToken($key)
            ->withHeaders(['Accept' => 'audio/mpeg'])
            ->post($base.'/audio/speech', [
                'model' => $model,
                'voice' => $resolvedVoice,
                'input' => $text,
                'response_format' => 'mp3',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'TTS failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new RuntimeException('TTS вернул пустое аудио.');
        }

        return [
            'mime' => 'audio/mpeg',
            'binary' => $binary,
        ];
    }
}
