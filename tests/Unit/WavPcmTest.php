<?php

namespace Tests\Unit;

use App\Services\AiAssistant\WavPcm;
use PHPUnit\Framework\TestCase;

class WavPcmTest extends TestCase
{
    public function test_extracts_pcm_and_rate_from_shell_wav(): void
    {
        $pcm = str_repeat("\x00\x01", 80);
        $wav = $this->pcmWav($pcm, 16000, 1);

        $out = WavPcm::extract($wav);

        $this->assertSame($pcm, $out['pcm']);
        $this->assertSame(16000, $out['sample_rate']);
        $this->assertSame(1, $out['channels']);
        $this->assertSame(16000, WavPcm::speechKitRate($out['sample_rate']));
    }

    public function test_raw_bytes_treated_as_pcm(): void
    {
        $raw = 'not-a-wav';
        $out = WavPcm::extract($raw);

        $this->assertSame($raw, $out['pcm']);
        $this->assertSame(16000, $out['sample_rate']);
    }

    private function pcmWav(string $pcm, int $rate, int $channels): string
    {
        $bits = 16;
        $byteRate = $rate * $channels * $bits / 8;
        $blockAlign = $channels * $bits / 8;
        $dataSize = strlen($pcm);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels)
            .pack('V', $rate).pack('V', $byteRate).pack('v', $blockAlign).pack('v', $bits)
            .'data'.pack('V', $dataSize).$pcm;
    }
}
