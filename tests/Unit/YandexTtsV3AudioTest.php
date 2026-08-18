<?php

namespace Tests\Unit;

use App\Services\AiAssistant\YandexTextToSpeech;
use PHPUnit\Framework\TestCase;

class YandexTtsV3AudioTest extends TestCase
{
    public function test_decodes_wrapped_result_chunk(): void
    {
        $mp3 = 'ID3voice';
        $body = json_encode([
            'result' => [
                'audioChunk' => ['data' => base64_encode($mp3)],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame($mp3, YandexTextToSpeech::decodeV3Audio($body));
    }

    public function test_concatenates_ndjson_chunks(): void
    {
        $body = json_encode(['audioChunk' => ['data' => base64_encode('AA')]], JSON_THROW_ON_ERROR)
            ."\n"
            .json_encode(['result' => ['audioChunk' => ['data' => base64_encode('BB')]]], JSON_THROW_ON_ERROR);

        $this->assertSame('AABB', YandexTextToSpeech::decodeV3Audio($body));
    }
}
