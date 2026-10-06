<?php

namespace Tests\Feature;

use App\Mcp\McpActor;
use App\Mcp\McpServer;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\McpToken;
use App\Models\StoreBuiltPc;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreEstimate;
use App\Models\StoreSupplierCatalogProduct;
use App\Models\User;
use App\Services\ComputerPowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('framework/testing/mcp-actions.log');
        if (! is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0777, true);
        }
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
        config([
            'mcp.enabled' => true,
            'mcp.http_enabled' => true,
            'logging.channels.mcp.path' => $this->logPath,
        ]);

        $this->club = Club::create([
            'name' => 'MCP Club',
            'slug' => 'mcp-club-'.uniqid(),
        ]);
    }

    public function test_http_is_hidden_until_enabled_and_rejects_a_bad_token(): void
    {
        config(['mcp.http_enabled' => false]);

        $this->postJson('/mcp', $this->rpcBody('initialize'))->assertNotFound();

        config(['mcp.http_enabled' => true]);

        $this->postJson('/mcp', $this->rpcBody('initialize'))->assertUnauthorized();
        $this->withHeader('Origin', 'https://evil.example')
            ->withToken($this->tokenFor($this->admin('owner')))
            ->postJson('/mcp', $this->rpcBody('ping'))
            ->assertForbidden();
    }

    public function test_initialize_and_stdio_frame(): void
    {
        $plain = $this->tokenFor($this->admin('owner'));

        $this->rpc($plain, 'initialize', ['protocolVersion' => '2025-03-26'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', '0451-kosino')
            ->assertJsonPath('result.protocolVersion', '2025-03-26');

        $token = McpToken::query()->first();
        $actor = McpActor::fromToken($token);
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'])."\n");
        rewind($in);
        app(McpServer::class)->serveStream($in, $out, $actor);
        rewind($out);
        $raw = stream_get_contents($out);

        $this->assertStringContainsString('"id":7', $raw);
        $this->assertStringContainsString('"result":{}', $raw);
    }

    public function test_catalog_follows_role_boundaries(): void
    {
        $ownerTools = $this->toolNames($this->tokenFor($this->admin('owner')));
        $supervisorTools = $this->toolNames($this->tokenFor($this->admin('supervisor')));
        $internTools = $this->toolNames($this->tokenFor($this->admin('intern')));
        $manager = $this->tokenFor($this->admin('store_manager'));
        $assembler = $this->tokenFor($this->admin('assembler'));

        $this->assertContains('restart_computer_session', $ownerTools);
        $this->assertContains('preview_tax_usn', $ownerTools);
        $this->assertNotContains('restart_computer_session', $supervisorTools);
        $this->assertContains('trigger_wol', $supervisorTools);
        $this->assertContains('validate_ac_session', $supervisorTools);
        $this->assertSame([], $internTools);

        $managerTools = $this->toolNames($manager);
        $this->assertContains('create_store_estimate', $managerTools);
        $this->assertNotContains('restart_computer_session', $managerTools);
        $this->assertNotContains('create_store_estimate', $this->toolNames($assembler));

        $this->rpc($manager, 'resources/read', ['uri' => 'club://status/summary'])
            ->assertOk()
            ->assertJsonPath('error.code', -32001);

        $player = User::create([
            'name' => 'Гость',
            'phone' => '+79990000001',
            'email' => 'player-'.uniqid().'@mcp.test',
            'password' => 'password',
        ]);
        $playerToken = $this->tokenFor($player);
        $uris = collect($this->rpc($playerToken, 'resources/list')->json('result.resources'))->pluck('uri')->all();

        $this->assertSame(['player://me', 'player://club/public'], $uris);
        $this->assertSame([], $this->toolNames($playerToken));
        $me = json_decode($this->toolText($this->rpc($playerToken, 'resources/read', ['uri' => 'player://me'])->assertOk()), true);
        $this->assertSame('Гость', $me['name']);
        $this->assertStringNotContainsString('79990000001', json_encode($me));
    }

    public function test_release_waits_for_confirmation_and_is_owner_only(): void
    {
        $computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-12',
            'status' => 'busy',
        ]);
        $user = User::create([
            'name' => 'Stuck Guest',
            'phone' => '+79991112233',
            'email' => 'stuck-'.uniqid().'@mcp.test',
            'password' => 'password',
        ]);
        $booking = $this->activeBooking($user, $computer);
        $args = [
            'computer_id' => $computer->id,
            'reason' => 'Шелл убит без выхода',
        ];

        $preview = $this->rpc($this->tokenFor($this->admin('supervisor')), 'tools/call', [
            'name' => 'restart_computer_session',
            'arguments' => $args,
        ])->assertOk();
        $this->assertTrue($preview->json('result.isError'));
        $this->assertSame('active', $booking->fresh()->status);

        $owner = $this->tokenFor($this->admin('owner'));
        $first = $this->rpc($owner, 'tools/call', [
            'name' => 'restart_computer_session',
            'arguments' => $args,
        ])->assertOk();
        $this->assertFalse($first->json('result.isError'));
        $this->assertStringContainsString('needs_confirmation', $this->toolText($first));
        $this->assertSame('active', $booking->fresh()->status);

        $second = $this->rpc($owner, 'tools/call', [
            'name' => 'restart_computer_session',
            'arguments' => $args + ['confirm' => true],
        ])->assertOk();
        $this->assertFalse($second->json('result.isError'));
        $this->assertSame('completed', $booking->fresh()->status);

        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('restart_computer_session', $log);
        $this->assertStringNotContainsString('79991112233', $log);
    }

    public function test_wake_hold_survives_power_sync(): void
    {
        $computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-08',
            'status' => 'available',
            'hwid' => 'HWID-'.uniqid(),
            'mac_address' => 'AA:BB:CC:DD:EE:08',
            'power_desired' => 'off',
            'power_state' => 'off',
        ]);
        $args = ['computer_id' => $computer->id];
        $token = $this->tokenFor($this->admin('owner'));

        $this->rpc($token, 'tools/call', ['name' => 'trigger_wol', 'arguments' => $args])->assertOk();
        $this->assertNull($computer->fresh()->wol_hold_until);

        $done = $this->rpc($token, 'tools/call', [
            'name' => 'trigger_wol',
            'arguments' => $args + ['confirm' => true],
        ])->assertOk();
        $this->assertFalse($done->json('result.isError'));
        $this->assertNotNull($computer->fresh()->wol_hold_until);

        app(ComputerPowerService::class)->syncFor($computer->id);

        $this->assertSame('on', $computer->fresh()->power_desired);
    }

    public function test_store_estimate_masks_phone_and_assembler_cannot_see_cost_or_foreign_build(): void
    {
        StoreSupplierCatalogProduct::create([
            'sku' => 99001,
            'name' => 'Ryzen 5',
            'price' => 100,
            'rrp' => 150,
        ]);
        StoreClient::create([
            'club_id' => $this->club->id,
            'name' => 'Покупатель',
            'phone' => '+79990001122',
        ]);
        StoreComponent::create([
            'club_id' => $this->club->id,
            'name' => 'DDR5',
            'type' => 'ram',
            'status' => 'in_stock',
            'purchase_price' => 12345.67,
            'serials' => ['SN-KEEP'],
        ]);

        $manager = $this->tokenFor($this->admin('store_manager'));
        $args = [
            'title' => 'Сборка',
            'client_phone' => '+7 (999) 000-11-22',
            'components' => [['sku' => 99001, 'qty' => 1]],
        ];
        $preview = $this->rpc($manager, 'tools/call', [
            'name' => 'create_store_estimate',
            'arguments' => $args,
        ])->assertOk();
        $this->assertSame(0, StoreEstimate::query()->count());
        $this->assertStringNotContainsString('79990001122', $this->toolText($preview));
        $this->assertStringContainsString('***1122', $this->toolText($preview));

        $created = $this->rpc($manager, 'tools/call', [
            'name' => 'create_store_estimate',
            'arguments' => $args + ['confirm' => true],
        ])->assertOk();
        $this->assertFalse($created->json('result.isError'));
        $this->assertSame(1, StoreEstimate::query()->count());
        $this->assertStringNotContainsString('79990001122', (string) file_get_contents($this->logPath));

        $assembler = $this->tokenFor($this->admin('assembler'));
        $stock = json_decode($this->toolText($this->rpc($assembler, 'resources/read', [
            'uri' => 'store://warehouse/stock',
        ])->assertOk()), true);
        $this->assertSame('SN-KEEP', $stock['items'][0]['serials'][0]);
        $this->assertArrayNotHasKey('purchase_price', $stock['items'][0]);

        $managerStock = json_decode($this->toolText($this->rpc($manager, 'resources/read', [
            'uri' => 'store://warehouse/stock',
        ])->assertOk()), true);
        $this->assertArrayHasKey('purchase_price', $managerStock['items'][0]);

        $pc = StoreBuiltPc::create([
            'club_id' => $this->club->id,
            'assembled_by' => $this->admin('store_manager')->id,
            'title' => 'Чужая',
            'status' => 'assembling',
        ]);
        $denied = $this->rpc($assembler, 'tools/call', [
            'name' => 'check_build_verification',
            'arguments' => [
                'built_pc_id' => $pc->id,
                'components' => [['type' => 'cpu', 'serial' => 'SN', 'name' => 'CPU']],
            ],
        ])->assertOk();
        $this->assertTrue($denied->json('result.isError'));
        $this->assertStringContainsString('Сборщик', $this->toolText($denied));
        $this->assertNull($pc->fresh()->verified_at);
    }

    public function test_diagnostics_skip_phpunit_and_run_cache(): void
    {
        $owner = $this->tokenFor($this->admin('owner'));

        $blocked = $this->rpc($owner, 'tools/call', [
            'name' => 'run_system_diagnostics',
            'arguments' => ['test_contour' => 'phpunit:all', 'confirm' => true],
        ])->assertOk();
        $this->assertTrue($blocked->json('result.isError'));
        $this->assertStringContainsString('PHPUnit', $this->toolText($blocked));

        $cache = $this->rpc($owner, 'tools/call', [
            'name' => 'run_system_diagnostics',
            'arguments' => ['test_contour' => 'cache'],
        ])->assertOk();
        $this->assertFalse($cache->json('result.isError'));
        $this->assertStringContainsString('pass', $this->toolText($cache));
    }

    public function test_artisan_token_can_be_revoked(): void
    {
        $admin = $this->admin('owner');

        $this->artisan('mcp:token', ['email' => $admin->email, '--name' => 'cli'])
            ->assertOk();

        $issued = McpToken::query()->where('name', 'cli')->first();
        $this->assertNotNull($issued);

        $this->artisan('mcp:revoke', ['id' => $issued->id])->assertOk();
        $this->assertNotNull($issued->fresh()->revoked_at);
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'name' => $role,
            'email' => $role.'-'.uniqid().'@mcp.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $this->club->id,
        ]);
    }

    private function tokenFor(Admin|User $subject): string
    {
        [, $plain] = McpToken::issue($subject, 'test');

        return $plain;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $plain, string $method, array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($plain)->postJson('/mcp', $this->rpcBody($method, $params));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpcBody(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * @return list<string>
     */
    private function toolNames(string $plain): array
    {
        return collect($this->rpc($plain, 'tools/list')->json('result.tools'))->pluck('name')->all();
    }

    private function toolText(\Illuminate\Testing\TestResponse $response): string
    {
        return (string) ($response->json('result.content.0.text') ?? $response->json('result.contents.0.text') ?? '');
    }

    private function activeBooking(User $user, Computer $computer): Booking
    {
        $startsAt = now()->subHour();
        $endsAt = now()->addHour();
        $group = BookingGroup::create([
            'user_id' => $user->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'active',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $startsAt->copy()->subDay(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $user->id,
            'computer_id' => $computer->id,
            'pc_ids' => [(string) $computer->id],
            'date' => $startsAt->toDateString(),
            'start_time' => $startsAt->hour + ($startsAt->minute / 60),
            'duration' => 2,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_started_at' => $startsAt,
        ]);
    }
}
