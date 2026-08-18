<?php

namespace Tests\Unit;

use App\Services\AiAssistant\YandexCloudAuth;
use App\Services\AiAssistant\YandexTextToSpeech;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexCloudAuthTest extends TestCase
{
    public function test_api_key_does_not_attach_folder(): void
    {
        $this->assertSame('', YandexCloudAuth::folderIdForRequest('AQVNxxx', 'b1gfolder'));
        $this->assertSame('b1gfolder', YandexCloudAuth::folderIdForRequest('t1.iamtoken', 'b1gfolder'));
        $this->assertSame('Api-Key AQVNxxx', YandexCloudAuth::authorizationHeader('AQVNxxx'));
        $this->assertSame('Bearer t1.abc', YandexCloudAuth::authorizationHeader('t1.abc'));
    }

    public function test_v3_omits_folder_header_for_service_account_key(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => ['audioChunk' => ['data' => base64_encode('ID3')]],
            ], 200),
        ]);

        (new YandexTextToSpeech())->synthesize('Привет', 'anton', [
            'api_key' => 'AQVNtest',
            'folder_id' => 'b1gm9d91io1jit1e0504',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/tts/v3/utteranceSynthesis')
                && $request->hasHeader('Authorization', 'Api-Key AQVNtest')
                && ! $request->hasHeader('x-folder-id');
        });
    }

    public function test_permission_denied_on_folder_is_readable(): void
    {
        Http::fake([
            '*' => Http::response([
                'error_code' => 'UNAUTHORIZED',
                'error_message' => 'rpc error: code = PermissionDenied desc = Permission to [resource-manager.folder b1gxxx] denied',
            ], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('оставьте Folder ID в админке пустым');

        (new YandexTextToSpeech())->synthesize('Привет', 'alena', [
            'api_key' => 'AQVNtest',
            'folder_id' => 'b1gxxx',
        ]);
    }
}
