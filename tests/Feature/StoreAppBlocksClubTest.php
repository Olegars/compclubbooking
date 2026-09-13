<?php

namespace Tests\Feature;

use Tests\TestCase;

class StoreAppBlocksClubTest extends TestCase
{
    public function test_store_apk_cannot_open_public_site(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubStore/1.0',
        ])->get('/')->assertRedirect('/store/login');

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubStore/1.0',
        ])->get('/booking')->assertRedirect('/store/login');
    }

    public function test_store_apk_cannot_open_club_admin(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubStore/1.0',
        ])->get('/admin/login')->assertRedirect('/store/login');

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubStore/1.0',
        ])->get('/admin/dashboard')->assertRedirect('/store/login');
    }

    public function test_store_apk_can_open_store_login(): void
    {
        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 CompClubStore/1.0',
        ])->get('/store/login')->assertOk();
    }
}
