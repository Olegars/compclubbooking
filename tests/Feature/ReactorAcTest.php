<?php

namespace Tests\Feature;

use App\Models\AcBan;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LanBountyEvent;
use App\Models\User;
use App\Services\ClubFeatureService;
use App\Services\ReactorAc\AcGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReactorAcTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'AC Club', 'slug' => 'ac-club']);
        $this->user = User::create([
            'name' => 'Домашний',
            'phone' => '79005550011',
            'email' => 'ac@club.test',
            'password' => 'password',
        ]);
        config(['reactor_ac.server_secret' => 'dedic-secret']);
    }

    public function test_phase_zero_is_off_until_the_club_turns_it_on(): void
    {
        $features = app(ClubFeatureService::class);
        $this->assertFalse($features->enabled($this->club->id, 'reactor_ac'));
        $this->assertTrue($features->enabled($this->club->id, 'qr_login'));
        $this->assertSame('off', app(AcGate::class)->mode($this->club->id));

        $this->postJson('/api/ac/login', [
            'phone' => '79005550011',
            'password' => 'password',
        ])->assertStatus(409)->assertJsonPath('reason', 'mode_off');

        $this->withHeader('X-Reactor-Ac-Secret', 'dedic-secret')
            ->postJson('/api/ac/server/validate', [
                'steam_id' => '76561198000000001',
                'token' => '',
            ])
            ->assertOk()
            ->assertJsonPath('allow', true)
            ->assertJsonPath('reason', 'mode_off');

        $this->getJson('/ac.json')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('stack', 'CounterStrikeSharp');

        $this->get('/ac/download')->assertRedirect();
        $this->actingAs($this->user)->get('/ac/download')->assertOk();

        $admin = $this->makeAdmin('supervisor');
        $this->actingAs($admin, 'admin')
            ->get('/admin/config/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'documents',
                fn ($rows) => collect($rows)->contains(fn ($row) => ($row['kind'] ?? '') === 'reactor_ac' && ($row['is_system'] ?? false) === true)
            ));

        $this->actingAs($admin, 'admin')
            ->get('/admin/fair-play')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/FairPlay')->where('mode', 'off'));
    }

    public function test_telemetry_logs_integrity_and_does_not_issue_a_token(): void
    {
        $this->enable('telemetry');
        $token = $this->login();

        $this->withToken($token)->postJson('/api/ac/heartbeat', [
            'steam_id' => '76561198000000001',
            'hwid' => str_repeat('ab', 32),
            'testsigning' => true,
            'vm' => false,
            'integrity_ok' => true,
        ])->assertOk()->assertJsonPath('integrity_ok', false);

        $this->withToken($token)->postJson('/api/ac/connect-token', [
            'match_id' => 'm-1',
        ])->assertStatus(409)->assertJsonPath('reason', 'telemetry');
    }

    public function test_gate_token_station_reload_and_ban_scope(): void
    {
        $this->enable('gate');
        $steam = '76561198000000001';
        $token = $this->login();
        $this->beat($token, $steam);

        $issued = $this->withToken($token)->postJson('/api/ac/connect-token', [
            'match_id' => 'm-1',
        ])->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{10}$/', $issued['token']);

        $this->server('validate', ['steam_id' => $steam, 'token' => 'OTHERTOKEN'])
            ->assertOk()
            ->assertJsonPath('reason', 'deny_unknown');

        $this->server('validate', ['steam_id' => '76561198000000002', 'token' => $issued['token']])
            ->assertOk()
            ->assertJsonPath('reason', 'deny_steam');

        $this->server('validate', ['steam_id' => $steam, 'token' => $issued['token']])
            ->assertOk()
            ->assertJsonPath('allow', true)
            ->assertJsonPath('reason', 'consumed');

        $this->server('validate', ['steam_id' => $steam, 'token' => $issued['token']])
            ->assertOk()
            ->assertJsonPath('reason', 'token_already_used');

        $this->server('active-sessions', ['match_id' => 'm-1'])
            ->assertOk()
            ->assertJsonPath('sessions.0.steam_id', $steam);

        $this->server('validate-session', ['steam_id' => $steam, 'match_id' => 'm-1'])
            ->assertOk()
            ->assertJsonPath('reason', 'session');

        $this->server('heartbeat-check', ['steam_ids' => [$steam]])
            ->assertOk()
            ->assertJsonPath('players.0.allow', true);

        $session = \App\Models\AcSession::query()->where('kind', 'home')->first();
        $session->update(['last_heartbeat_at' => now()->subMinutes(5)]);
        $this->server('heartbeat-check', ['steam_ids' => [$steam]])
            ->assertOk()
            ->assertJsonPath('players.0.reason', 'deny_stale');

        $this->beat($token, $steam);
        $again = $this->withToken($token)->postJson('/api/ac/connect-token', [
            'match_id' => 'm-1',
        ])->assertOk()->json('token');

        app(AcGate::class)->ban($this->user, AcBan::SCOPE_MATCH_MAKING, 'чит', 7, null);
        $this->assertNull(app(AcGate::class)->fullBanMessage($this->user));
        $this->server('validate', ['steam_id' => $steam, 'token' => $again])
            ->assertOk()
            ->assertJsonPath('reason', 'deny_banned');

        $ban = AcBan::query()->first();
        app(AcGate::class)->pardon($ban);
        $fresh = $this->withToken($token)->postJson('/api/ac/connect-token', [
            'match_id' => 'm-2',
        ])->assertOk()->json('token');
        $this->server('validate', ['steam_id' => $steam, 'token' => $fresh])
            ->assertOk()
            ->assertJsonPath('reason', 'consumed');

        app(AcGate::class)->ban($this->user, AcBan::SCOPE_FULL, 'клуб', 0, null);
        $this->assertNotNull(app(AcGate::class)->fullBanMessage($this->user));

        [$pc, $booking] = $this->seat('192.168.20.15', $steam);
        $this->server('validate-station', [
            'client_ip' => '192.168.20.16',
            'steam_id' => $steam,
            'match_id' => 'hall',
        ])->assertOk()->assertJsonPath('reason', 'deny_no_session');

        $this->server('validate-station', [
            'client_ip' => '192.168.20.15',
            'steam_id' => '76561198000000009',
            'match_id' => 'hall',
        ])->assertOk()->assertJsonPath('reason', 'deny_steam');

        AcBan::query()->where('scope', AcBan::SCOPE_FULL)->update(['pardoned_at' => now()]);
        $this->server('validate-station', [
            'client_ip' => '192.168.20.15',
            'steam_id' => $steam,
            'match_id' => 'hall',
        ])->assertOk()->assertJsonPath('reason', 'station');

        $booking->update(['status' => 'completed']);
        $pc->update(['last_seen_at' => now()]);
        $this->server('validate-station', [
            'client_ip' => '192.168.20.15',
            'steam_id' => $steam,
            'match_id' => 'hall',
        ])->assertOk()->assertJsonPath('reason', 'deny_no_session');
    }

    private function enable(string $mode): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'reactor_ac', true, ['mode' => $mode]);
    }

    private function login(): string
    {
        return $this->postJson('/api/ac/login', [
            'phone' => '79005550011',
            'password' => 'password',
        ])->assertOk()->json('token');
    }

    private function beat(string $token, string $steam): void
    {
        $this->withToken($token)->postJson('/api/ac/heartbeat', [
            'steam_id' => $steam,
            'hwid' => str_repeat('cd', 32),
            'testsigning' => false,
            'vm' => false,
            'integrity_ok' => true,
        ])->assertOk()->assertJsonPath('integrity_ok', true);
    }

    private function server(string $path, array $body)
    {
        return $this->withHeader('X-Reactor-Ac-Secret', 'dedic-secret')
            ->postJson('/api/ac/server/'.$path, $body);
    }

    /**
     * @return array{0: Computer, 1: Booking}
     */
    private function seat(string $ip, string $steam): array
    {
        $pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-AC',
            'status' => 'busy',
            'kind' => 'pc',
            'lan_ip' => $ip,
            'last_seen_at' => now(),
        ]);
        $start = CarbonImmutable::now()->subMinutes(10);
        $local = $start->timezone(config('app.timezone'));
        $booking = Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => 2,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'pin_code' => '2222',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'actual_started_at' => $start,
        ]);
        LanBountyEvent::query()->create([
            'club_id' => $this->club->id,
            'computer_id' => $pc->id,
            'user_id' => $this->user->id,
            'booking_id' => $booking->id,
            'steam_id' => $steam,
            'event' => 'kill',
            'game' => 'cs2',
            'occurred_at' => now(),
        ]);

        return [$pc, $booking];
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => $role,
            'email' => $role.'.ac@club.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);
    }
}
