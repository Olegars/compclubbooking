<?php

namespace Tests\Feature;

use Tests\TestCase;

class BossAppApkDownloadTest extends TestCase
{
    public function test_apk_is_downloadable_with_installer_mime(): void
    {
        $path = storage_path('app/apk/boss0451.apk');
        if (! is_file($path)) {
            $this->markTestSkipped('APK file is missing from storage/app/apk');
        }

        $this->get('/boss-app.apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('boss0451.apk');
    }

    public function test_apk_manifest_json_is_public(): void
    {
        $path = storage_path('app/apk/boss0451.apk');
        if (! is_file($path)) {
            $this->markTestSkipped('APK file is missing from storage/app/apk');
        }

        $this->get('/boss-app.json')
            ->assertOk()
            ->assertJsonStructure(['version_code', 'version_name', 'apk_url', 'size'])
            ->assertJsonPath('apk_url', url('/boss-app.apk'));
    }
}
