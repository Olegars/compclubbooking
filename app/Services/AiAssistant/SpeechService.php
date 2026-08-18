<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Http\UploadedFile;

class SpeechService
{
    public function __construct(
        private readonly YandexSpeechToText $yandexStt,
        private readonly YandexTextToSpeech $yandexTts,
        private readonly WhisperSpeechToText $openaiStt,
        private readonly OpenAiTextToSpeech $openaiTts,
    ) {
    }

    public function transcribe(UploadedFile $audio, AiAssistantSetting $settings): string
    {
        if ($settings->resolvedSpeechProvider() === 'openai') {
            return $this->openaiStt->transcribe($audio, [
                'api_key' => $settings->resolvedOpenAiApiKey(),
                'base_url' => $settings->resolvedOpenAiBaseUrl(),
                'model' => $settings->resolvedSttModel(),
            ]);
        }

        return $this->yandexStt->transcribe($audio, [
            'api_key' => $settings->resolvedYandexApiKey(),
            'folder_id' => $settings->resolvedYandexFolderId(),
        ]);
    }

    /**
     * @return array{mime:string,binary:string}
     */
    public function synthesize(string $text, AiAssistantSetting $settings, ?string $voice = null): array
    {
        $voice = AiAssistantSetting::sanitizeTtsVoice($settings->resolvedSpeechProvider(), $voice)
            ?? $settings->resolvedTtsVoice();

        if ($settings->resolvedSpeechProvider() === 'openai') {
            return $this->openaiTts->synthesize($text, $voice, [
                'api_key' => $settings->resolvedOpenAiApiKey(),
                'base_url' => $settings->resolvedOpenAiBaseUrl(),
                'model' => $settings->resolvedTtsModel(),
            ]);
        }

        return $this->yandexTts->synthesize($text, $voice, [
            'api_key' => $settings->resolvedYandexApiKey(),
            'folder_id' => $settings->resolvedYandexFolderId(),
        ]);
    }
}
