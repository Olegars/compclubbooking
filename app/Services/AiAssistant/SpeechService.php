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
        private readonly QwenSpeechToText $qwenStt,
        private readonly QwenTextToSpeech $qwenTts,
    ) {
    }

    public function transcribe(UploadedFile $audio, AiAssistantSetting $settings): string
    {
        if ($settings->resolvedVoiceSpeechProvider() === 'qwen') {
            return $this->qwenStt->transcribe($audio, $settings);
        }

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
        $provider = $settings->resolvedVoiceSpeechProvider();
        $voice = AiAssistantSetting::sanitizeTtsVoice($provider, $voice)
            ?? ($provider === 'qwen' ? $settings->resolvedQwenTtsVoice() : $settings->resolvedTtsVoice());

        if ($provider === 'qwen') {
            return $this->qwenTts->synthesize($text, $settings, $voice);
        }

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
