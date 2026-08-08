<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WifiAccessSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WifiAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wifi_access.enabled' => true,
            'wifi_access.station_code' => 'club-test',
            'wifi_access.session_hours' => 6,
            'wifi_access.relay_token' => 'wifi-relay-secret',
        ]);

        $this->user = User::create([
            'name' => 'Wifi Guest',
            'phone' => '+79990001122',
            'email' => 'wifi@example.test',
            'password' => 'password',
        ]);
    }

    public function test_join_page_renders(): void
    {
        $this->get('/wifi/join?station=club-test&mac=AA:BB:CC:DD:EE:FF')
            ->assertOk()
            ->assertSee('Гостевой Wi-Fi');
    }

    public function test_authorize_queues_pending_session(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/wifi/authorize', [
                'station' => 'club-test',
                'mac' => 'aa-bb-cc-dd-ee-ff',
                'ip' => '10.10.0.50',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('session.status', 'pending');

        $this->assertDatabaseHas('wifi_access_sessions', [
            'user_id' => $this->user->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'status' => WifiAccessSession::STATUS_PENDING,
        ]);
    }

    public function test_rejects_wrong_station(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/wifi/authorize', [
                'station' => 'wrong',
                'mac' => 'AA:BB:CC:DD:EE:01',
            ])
            ->assertStatus(422);
    }

    public function test_mikrotik_grant_targets_and_applied(): void
    {
        $session = WifiAccessSession::create([
            'user_id' => $this->user->id,
            'phone' => $this->user->phone,
            'station_code' => 'club-test',
            'mac_address' => 'AA:BB:CC:DD:EE:10',
            'client_ip' => '10.10.0.10',
            'status' => WifiAccessSession::STATUS_PENDING,
            'authorized_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);

        $this->getJson('/api/wifi/grant-targets?token=wifi-relay-secret')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('grant.0.id', $session->id)
            ->assertJsonPath('macs_grant.0', 'AA:BB:CC:DD:EE:10');

        $this->postJson('/api/wifi/grant-applied', [
            'token' => 'wifi-relay-secret',
            'grant_ids' => [$session->id],
        ])->assertOk();

        $this->assertSame(
            WifiAccessSession::STATUS_GRANTED,
            $session->fresh()->status
        );
    }

    public function test_relay_rejects_bad_token(): void
    {
        $this->getJson('/api/wifi/grant-targets?token=bad')
            ->assertStatus(401);
    }
}
