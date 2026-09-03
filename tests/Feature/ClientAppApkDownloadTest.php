<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientAppApkDownloadTest extends TestCase
{
    public function test_apk_is_downloadable(): void
    {
        $path = (string) env('APP_APK_PATH', 'C:\\Qt\\compclubApp\\app-debug.apk');
        if (! is_file($path)) {
            $this->markTestSkipped('APK file is missing');
        }

        $this->get('/app.apk')
            ->assertOk()
            ->assertDownload('sector0451.apk');
    }
}
