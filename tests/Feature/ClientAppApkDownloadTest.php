<?php

namespace Tests\Feature;

use App\Support\ApkManifest;
use Tests\TestCase;

class ClientAppApkDownloadTest extends TestCase
{
    public function test_apk_is_downloadable_with_installer_mime(): void
    {
        $path = storage_path('app/apk/sector0451.apk');
        if (! is_file($path)) {
            $this->markTestSkipped('APK file is missing from storage/app/apk');
        }

        $this->get('/app.apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('sector0451.apk');
    }

    public function test_apk_manifest_json_is_public(): void
    {
        $path = storage_path('app/apk/sector0451.apk');
        if (! is_file($path)) {
            $this->markTestSkipped('APK file is missing from storage/app/apk');
        }

        $manifest = ApkManifest::read($path);
        $this->assertNotNull($manifest);

        config([
            'client_app.version_code' => 99,
            'client_app.version_name' => '9.9.9',
        ]);

        $this->get('/app.json')
            ->assertOk()
            ->assertJsonPath('version_code', $manifest['version_code'])
            ->assertJsonPath('version_name', $manifest['version_name'])
            ->assertJsonStructure(['version_code', 'version_name', 'apk_url', 'size']);
    }
}
