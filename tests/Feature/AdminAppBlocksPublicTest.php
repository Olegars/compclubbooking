<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAppBlocksPublicTest extends TestCase
{
    public function test_admin_apk_cannot_open_public_site(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubAdmin/1.0',
        ])->get('/')->assertRedirect('/admin/login');

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubAdmin/1.0',
        ])->get('/booking')->assertRedirect('/admin/login');
    }

    public function test_admin_apk_can_open_admin_login(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubAdmin/1.0',
        ])->get('/admin/login')->assertOk();
    }

    public function test_admin_apk_can_open_store_login(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubAdmin/1.0',
        ])->get('/store/login')->assertOk();
    }

    public function test_client_apk_still_cannot_open_admin(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubClient/1.0',
        ])->get('/admin/login')->assertRedirect('/');
    }
}
