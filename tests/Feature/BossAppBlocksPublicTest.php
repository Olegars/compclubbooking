<?php

namespace Tests\Feature;

use Tests\TestCase;

class BossAppBlocksPublicTest extends TestCase
{
    public function test_boss_apk_cannot_open_public_site(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0',
        ])->get('/')->assertRedirect('/admin/login');

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0',
        ])->get('/booking')->assertRedirect('/admin/login');
    }

    public function test_boss_apk_can_open_admin_login(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0',
        ])->get('/admin/login')->assertOk();
    }

    public function test_boss_apk_can_open_store_login(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0',
        ])->get('/store/login')->assertOk();
    }
}
