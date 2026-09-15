<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Services\OwnerSystemTestService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnerSystemTestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/system-tests')->assertRedirect('/admin/login');
        $guest = $this->postJson('/admin/system-tests/run', ['id' => 'cache']);
        $this->assertTrue(in_array($guest->status(), [401, 302], true));
    }

    public function test_owner_opens_page_with_catalog(): void
    {
        $owner = $this->makeAdmin('owner');
        Club::query()->create([
            'name' => '0451',
            'slug' => 'club-'.uniqid(),
            'type' => 'both',
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/system-tests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemTests')
                ->has('tests')
                ->has('location')
                ->where('tests.0.id', 'app')
            );
    }

    public function test_supervisor_and_active_admin_cannot_open_or_run(): void
    {
        $supervisor = $this->makeAdmin('supervisor');
        $this->actingAs($supervisor, 'admin')
            ->get('/admin/system-tests')
            ->assertForbidden();
        $this->actingAs($supervisor, 'admin')
            ->postJson('/admin/system-tests/run', ['id' => 'cache'])
            ->assertForbidden();

        $admin = $this->makeAdmin('admin');
        \App\Models\Shift::query()->create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);
        $this->actingAs($admin, 'admin')
            ->get('/admin/system-tests')
            ->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->postJson('/admin/system-tests/run', ['id' => 'cache'])
            ->assertForbidden();
    }

    public function test_off_duty_admin_and_intern_are_kept_out(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->actingAs($admin, 'admin')
            ->get('/admin/system-tests')
            ->assertRedirect('/admin/salary');

        $intern = $this->makeAdmin('intern');
        $this->actingAs($intern, 'admin')
            ->get('/admin/system-tests')
            ->assertRedirect('/admin/salary');
    }

    public function test_store_staff_cannot_open(): void
    {
        $club = Club::query()->create([
            'name' => 'Store',
            'slug' => 'store-'.uniqid(),
            'type' => 'store',
        ]);
        $staff = Admin::query()->create([
            'name' => 'Сборщик',
            'email' => 'asm.'.uniqid().'@store.test',
            'password' => 'password',
            'role' => 'assembler',
            'club_id' => $club->id,
            'base_rate' => 2200,
            'pay_type' => 'shift',
        ]);

        $this->actingAs($staff, 'admin')
            ->get('/admin/system-tests')
            ->assertForbidden();
    }

    public function test_owner_runs_cache_database_and_sms_checks(): void
    {
        $owner = $this->makeAdmin('owner');
        Club::query()->create([
            'name' => '0451',
            'slug' => 'club-'.uniqid(),
            'type' => 'club',
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'cache'])
            ->assertOk()
            ->assertJsonPath('id', 'cache')
            ->assertJsonPath('status', 'pass');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'database'])
            ->assertOk()
            ->assertJsonPath('status', 'pass');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'sms'])
            ->assertOk()
            ->assertJsonPath('status', 'skip');
    }

    public function test_yookassa_skip_without_keys_and_pass_with_fake_api(): void
    {
        $owner = $this->makeAdmin('owner');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'yookassa'])
            ->assertOk()
            ->assertJsonPath('status', 'skip');

        config([
            'services.yookassa.shop_id' => '123456',
            'services.yookassa.secret_key' => 'test-secret',
        ]);
        Http::fake([
            'https://api.yookassa.ru/v3/me' => Http::response(['account_id' => '123456'], 200),
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'yookassa'])
            ->assertOk()
            ->assertJsonPath('status', 'pass');
    }

    public function test_unknown_id_is_rejected(): void
    {
        $owner = $this->makeAdmin('owner');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'does-not-exist'])
            ->assertStatus(422);
    }

    public function test_phpunit_runner_is_skipped_inside_phpunit(): void
    {
        $owner = $this->makeAdmin('owner');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/system-tests/run', ['id' => 'phpunit:all'])
            ->assertOk()
            ->assertJsonPath('status', 'skip');
    }

    public function test_catalog_includes_live_and_phpunit_files(): void
    {
        $service = app(OwnerSystemTestService::class);
        $ids = collect($service->catalog())->pluck('id');

        $this->assertTrue($ids->contains('computers'));
        $this->assertTrue($ids->contains('lights'));
        $this->assertTrue($ids->contains('fans'));
        $this->assertTrue($ids->contains('phpunit:all'));
        $this->assertTrue($ids->contains('phpunit:feature-OwnerSystemTestsTest'));
        $this->assertTrue($ids->contains('phpunit:feature-LightControlTest'));
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@tests.test',
            'password' => 'password',
            'role' => $role,
            'pay_type' => 'shift',
        ]);
    }
}
