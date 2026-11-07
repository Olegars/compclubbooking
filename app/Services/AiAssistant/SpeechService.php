<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Http\UploadedFile;

class SpeechService
{
    public function __construct(
        private readonly YandexSpeechToText $yandexStt,
        private readonly YandexTextToSpeech $yandexTts,
        private readonly QwenSpeechToText $qwenStt,
        private readonly QwenTextToSpeech $qwenTts,
        private readonly GlmSpeechToText $glmStt,
        private readonly GlmTextToSpeech $glmTts,
    ) {
    }

    public function transcribe(UploadedFile $audio, AiAssistantSetting $settings): string
    {
        if ($settings->resolvedVoiceSpeechProvider() === 'qwen') {
            return $this->qwenStt->transcribe($audio, $settings);
        }

        if ($settings->resolvedVoiceSpeechProvider() === 'glm') {
            return $this->glmStt->transcribe($audio, $settings);
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
        $provider = $settings->resolvedVoiceSpeechProvider();
        $voice = AiAssistantSetting::sanitizeTtsVoice($provider, $voice)
            ?? match ($provider) {
                'qwen' => $settings->resolvedQwenTtsVoice(),
                'glm' => $settings->resolvedGlmTtsVoice(),
                default => $settings->resolvedQuestionTtsVoice(),
            };

        if ($provider === 'qwen') {
            return $this->qwenTts->synthesize($text, $settings, $voice);
        }

        if ($provider === 'glm') {
            return $this->glmTts->synthesize($text, $settings, $voice);
        }

        return $this->yandexTts->synthesize($text, $voice, [
            'api_key' => $settings->resolvedYandexApiKey(),
            'folder_id' => $settings->resolvedYandexFolderId(),
        ]);
    }
}
