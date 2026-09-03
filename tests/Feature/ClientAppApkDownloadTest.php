<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientAppApkDownloadTest extends TestCase
{
    public function test_apk_is_downloadable(): void
    {
        $path = public_path('app.apk');
        if (! is_file($path)) {
            $this->markTestSkipped('APK file is missing from public/');
        }

        $this->get('/app.apk')
            ->assertOk()
            ->assertDownload('sector0451.apk');
    }
}
