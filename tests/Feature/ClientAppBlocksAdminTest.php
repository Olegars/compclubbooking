<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientAppBlocksAdminTest extends TestCase
{
    public function test_client_apk_cannot_open_admin(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubClient/1.0',
        ])->get('/admin/login')->assertRedirect('/');

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubClient/1.0',
        ])->get('/admin/dashboard')->assertRedirect('/');
    }

    public function test_browser_can_open_admin_login(): void
    {
        $this->get('/admin/login')->assertOk();
    }
}
