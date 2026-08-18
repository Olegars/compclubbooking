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
     * Моно 16-bit LE PCM на 16 kHz — то, что ждёт STT v1.
     *
     * @return array{pcm: string, sample_rate: int, channels: int, peak: int}
     */
    public static function toSpeechKitLpcm(string $bytes): array
    {
        $wav = self::extract($bytes);
        $pcm = $wav['pcm'];
        $channels = max(1, (int) $wav['channels']);
        $rate = max(1, (int) $wav['sample_rate']);

        if ($channels > 1) {
            $pcm = self::downmixMono($pcm, $channels);
        }

        $target = 16000;
        if (abs($rate - $target) > 50) {
            $pcm = self::resampleInt16($pcm, $rate, $target);
            $rate = $target;
        } else {
            $rate = 16000;
        }

        return [
            'pcm' => $pcm,
            'sample_rate' => $rate,
            'channels' => 1,
            'peak' => self::peakAbs($pcm),
        ];
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

    private static function readS16le(string $pcm, int $index): int
    {
        $u = unpack('v', substr($pcm, $index * 2, 2))[1] ?? 0;

        return $u >= 32768 ? $u - 65536 : $u;
    }

    private static function packS16le(int $sample): string
    {
        $sample = max(-32768, min(32767, $sample));

        return pack('v', $sample & 0xFFFF);
    }

    private static function peakAbs(string $pcm): int
    {
        $n = intdiv(strlen($pcm), 2);
        $peak = 0;
        for ($i = 0; $i < $n; $i++) {
            $peak = max($peak, abs(self::readS16le($pcm, $i)));
        }

        return $peak;
    }

    private static function downmixMono(string $pcm, int $channels): string
    {
        $channels = max(2, $channels);
        $frames = intdiv(intdiv(strlen($pcm), 2), $channels);
        $out = '';
        for ($i = 0; $i < $frames; $i++) {
            $sum = 0;
            for ($c = 0; $c < $channels; $c++) {
                $sum += self::readS16le($pcm, $i * $channels + $c);
            }
            $out .= self::packS16le((int) round($sum / $channels));
        }

        return $out;
    }

    private static function resampleInt16(string $pcm, int $fromRate, int $toRate): string
    {
        $n = intdiv(strlen($pcm), 2);
        if ($n < 2 || $fromRate <= 0 || $toRate <= 0 || $fromRate === $toRate) {
            return $pcm;
        }

        $outFrames = max(1, (int) round($n * ($toRate / $fromRate)));
        $out = '';
        $ratio = $fromRate / $toRate;
        for ($i = 0; $i < $outFrames; $i++) {
            $src = $i * $ratio;
            $i0 = (int) floor($src);
            $i1 = min($n - 1, $i0 + 1);
            $t = $src - $i0;
            $s0 = self::readS16le($pcm, $i0);
            $s1 = self::readS16le($pcm, $i1);
            $out .= self::packS16le((int) round($s0 + ($s1 - $s0) * $t));
        }

        return $out;
    }
}
