<?php

namespace App\Services\AiAssistant;

use RuntimeException;

class WavPcm
{
    /**
     * @return array{pcm: string, sample_rate: int, channels: int}
     */
    public static function extract(string $bytes): array
    {
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE') {
            return self::fromWav($bytes);
        }

        return ['pcm' => $bytes, 'sample_rate' => 16000, 'channels' => 1];
    }

    /**
     * SpeechKit v1 lpcm: 8000 / 16000 / 48000.
     */
    public static function speechKitRate(int $rate): int
    {
        foreach ([8000, 16000, 48000] as $allowed) {
            if (abs($rate - $allowed) <= 50) {
                return $allowed;
            }
        }

        if ($rate <= 12000) {
            return 8000;
        }
        if ($rate <= 32000) {
            return 16000;
        }

        return 48000;
    }

    /**
     * @return array{pcm: string, sample_rate: int, channels: int}
     */
    private static function fromWav(string $bytes): array
    {
        $len = strlen($bytes);
        $offset = 12;
        $sampleRate = 16000;
        $channels = 1;
        $pcm = '';

        while ($offset + 8 <= $len) {
            $id = substr($bytes, $offset, 4);
            $chunkSize = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0;
            $dataStart = $offset + 8;
            if ($dataStart > $len) {
                break;
            }

            if ($id === 'fmt ' && $chunkSize >= 16) {
                $fmt = unpack('vformat/vchannels/Vrate', substr($bytes, $dataStart, 16));
                $channels = (int) ($fmt['channels'] ?? 1);
                $sampleRate = (int) ($fmt['rate'] ?? 16000);
            } elseif ($id === 'data') {
                $take = min((int) $chunkSize, $len - $dataStart);
                $pcm = substr($bytes, $dataStart, $take);
                break;
            }

            $offset = $dataStart + (int) $chunkSize;
            if ($chunkSize % 2 === 1) {
                $offset++;
            }
        }

        if ($pcm === '') {
            throw new RuntimeException('WAV без PCM-данных.');
        }

        return [
            'pcm' => $pcm,
            'sample_rate' => $sampleRate > 0 ? $sampleRate : 16000,
            'channels' => $channels > 0 ? $channels : 1,
        ];
    }
}
