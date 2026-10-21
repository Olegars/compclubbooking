<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StopRknDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/tools/stoprkn.exe')->assertRedirect(route('login'));
        $this->get('/tools/stoprkn.apk')->assertRedirect(route('login'));
        $this->get('/tools/rockid.apk')->assertRedirect(route('login'));
    }

    public function test_player_can_download_windows_installer_and_android_apk(): void
    {
        $exe = storage_path('app/tools/stopRKN-setup.exe');
        $apk = storage_path('app/tools/stopRKN.apk');
        if (! is_file($exe) || ! is_file($apk)) {
            $this->markTestSkipped('stopRKN files are missing from storage/app/tools');
        }

        $user = User::create([
            'name' => 'Nova',
            'phone' => '+7999'.random_int(1000000, 9999999),
            'email' => 'player.'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->get('/tools/stoprkn.exe')
            ->assertOk()
            ->assertHeader('content-type', 'application/octet-stream')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('stopRKN-setup.exe');

        $this->actingAs($user)
            ->get('/tools/stoprkn.apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertDownload('stopRKN.apk');

        $rock = storage_path('app/tools/rockID.apk');
        if (is_file($rock)) {
            $this->actingAs($user)
                ->get('/tools/rockid.apk')
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.android.package-archive')
                ->assertHeader('x-content-type-options', 'nosniff')
                ->assertDownload('rockID.apk');
        }
    }
}
