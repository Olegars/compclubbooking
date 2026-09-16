<?php

namespace Tests\Unit;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Zone;
use App\Services\OwnerSystemTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerSystemTestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_fails_when_sessions_table_missing(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions']);
        Schema::dropIfExists('sessions');

        $result = app(OwnerSystemTestService::class)->run('database');
        $this->assertSame('fail', $result['status']);
        $this->assertStringContainsString('sessions', $result['message']);
    }

    public function test_cache_and_app_checks_pass_in_testing(): void
    {
        $service = app(OwnerSystemTestService::class);

        $cache = $service->run('cache');
        $this->assertSame('pass', $cache['status']);

        $app = $service->run('app');
        $this->assertContains($app['status'], ['pass', 'warn']);

        $sms = $service->run('sms');
        $this->assertSame('skip', $sms['status']);
    }

    public function test_computers_fail_when_none_are_online(): void
    {
        $club = Club::query()->create([
            'name' => 'Hall',
            'slug' => 'hall-'.uniqid(),
            'type' => 'club',
        ]);
        Computer::query()->create([
            'club_id' => $club->id,
            'name' => 'PC-1',
            'status' => 'available',
            'kind' => 'pc',
        ]);

        $result = app(OwnerSystemTestService::class)->run('computers', $club);
        $this->assertSame('fail', $result['status']);
    }

    public function test_computers_pass_when_heartbeat_is_fresh(): void
    {
        $club = Club::query()->create([
            'name' => 'Hall',
            'slug' => 'hall-on-'.uniqid(),
            'type' => 'club',
        ]);
        Computer::query()->create([
            'club_id' => $club->id,
            'name' => 'PC-1',
            'status' => 'available',
            'kind' => 'pc',
            'last_seen_at' => now(),
            'power_state' => 'on',
            'cache_ok' => true,
            'nic_link_mbps' => 1000,
            'ssd_health' => 'healthy',
        ]);

        $computers = app(OwnerSystemTestService::class)->run('computers', $club);
        $this->assertSame('pass', $computers['status']);

        $health = app(OwnerSystemTestService::class)->run('station_health', $club);
        $this->assertSame('pass', $health['status']);
    }

    public function test_tariffs_fail_without_zones(): void
    {
        $result = app(OwnerSystemTestService::class)->run('tariffs');
        $this->assertSame('fail', $result['status']);
    }

    public function test_tariffs_pass_with_zone_and_tariff(): void
    {
        Zone::query()->create(['name' => 'VIP', 'slug' => 'vip-'.uniqid()]);
        \App\Models\Tariff::query()->create([
            'name' => 'Hour',
            'category' => 'standard',
            'threshold_hours' => 1,
            'price_per_package' => 100,
            'is_active' => true,
        ]);

        $result = app(OwnerSystemTestService::class)->run('tariffs');
        $this->assertSame('pass', $result['status']);
    }

    public function test_wol_token_fail_when_empty(): void
    {
        config(['club.power.wol_relay_token' => '']);
        $result = app(OwnerSystemTestService::class)->run('wol_relay');
        $this->assertSame('fail', $result['status']);
    }

    public function test_wol_token_pass_when_set(): void
    {
        config(['club.power.wol_relay_token' => 'secret-token']);
        $result = app(OwnerSystemTestService::class)->run('wol_relay');
        $this->assertSame('pass', $result['status']);
    }

    public function test_cache_roundtrip_uses_store(): void
    {
        Cache::flush();
        $result = app(OwnerSystemTestService::class)->run('cache');
        $this->assertSame('pass', $result['status']);
        $this->assertGreaterThan(0, $result['duration_ms']);
    }

    public function test_php_cli_candidates_drop_fpm_and_keep_versioned_cli(): void
    {
        $service = app(OwnerSystemTestService::class);
        $cands = $service->phpCliCandidates('/usr/sbin/php-fpm8.4', 'fpm-fcgi', '/usr/sbin');
        $bases = array_map(
            static fn (string $path) => basename(str_replace('\\', '/', $path)),
            $cands,
        );

        $this->assertNotContains('php-fpm8.4', $bases);
        $this->assertContains('php8.4', $bases);
        $this->assertContains('php', $bases);
        $this->assertFalse(collect($cands)->contains(
            fn (string $path) => str_contains(strtolower($path), 'php-fpm'),
        ));
    }

    public function test_php_cli_candidates_prefer_configured_binary(): void
    {
        config(['app.php_cli_binary' => '/opt/php/bin/php']);
        $cands = app(OwnerSystemTestService::class)->phpCliCandidates('/usr/sbin/php-fpm8.4', 'fpm-fcgi', '/usr/sbin');
        $this->assertSame('/opt/php/bin/php', $cands[0]);
    }

    public function test_phpunit_process_env_forces_sqlite_memory(): void
    {
        $env = app(OwnerSystemTestService::class)->phpunitProcessEnv();
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame(':memory:', $env['DB_DATABASE']);
        $this->assertSame('testing', $env['APP_ENV']);
        $this->assertSame('', $env['DB_URL']);
    }

    public function test_sql_time_instant_has_no_timestamptz_on_sqlite(): void
    {
        $this->assertSame('?', \App\Support\SqlTime::instant());
        $this->assertStringNotContainsString('timestamptz', \App\Support\SqlTime::instant());
    }

    public function test_phpunit_pid_zero_is_not_alive(): void
    {
        $this->assertFalse(app(OwnerSystemTestService::class)->phpunitPidIsAlive(0));
        $this->assertFalse(app(OwnerSystemTestService::class)->phpunitPidIsAlive(-1));
    }

    public function test_phpunit_result_cache_key_includes_id(): void
    {
        $service = app(OwnerSystemTestService::class);
        $this->assertSame(
            'owner-system-tests-phpunit-result:phpunit:all',
            $service->phpunitResultCacheKey('phpunit:all'),
        );
    }

    public function test_php_cli_binary_on_cli_sapi_is_not_fpm(): void
    {
        $php = app(OwnerSystemTestService::class)->phpCliBinary();
        $this->assertNotNull($php);
        $this->assertFileExists($php);
        $this->assertStringNotContainsString('php-fpm', strtolower($php));
    }
}
