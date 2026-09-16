<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\StoreBuiltPc;
use App\Models\StoreBuiltPcComponent;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreOrder;
use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceSetting;
use App\Services\StoreAssemblyCaptureService;
use App\Services\StoreWarrantyService;
use App\Support\WarrantyQr;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorePcPassportTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Admin $assembler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::query()->create([
            'name' => 'REACTOR Store',
            'slug' => 'reactor-store-'.uniqid(),
            'type' => 'store',
        ]);
        $this->assembler = Admin::query()->create([
            'name' => 'Иван Петров',
            'email' => 'ivan.'.uniqid().'@store.test',
            'password' => 'password',
            'role' => 'assembler',
            'club_id' => $this->club->id,
        ]);
    }

    public function test_warranty_qr_encodes_passport_url_not_plain_text(): void
    {
        $pc = $this->makeSoldPc();
        $warranty = app(StoreWarrantyService::class)->ensureForBuiltPc($pc);

        $payload = WarrantyQr::payload($warranty);

        $this->assertStringContainsString('/pc/', $payload);
        $this->assertStringNotContainsString("S/N:", $payload);
        $this->assertSame($payload, $warranty->fresh()->passportUrl());
    }

    public function test_public_passport_shows_assembler_serials_and_part_warranty_without_phone(): void
    {
        $this->travelTo(now()->startOfDay());
        $pc = $this->makeSoldPc();
        $warranty = app(StoreWarrantyService::class)->ensureForBuiltPc($pc);
        $token = app(StoreWarrantyService::class)->ensurePublicToken($warranty);

        $this->get('/pc/'.$token)
            ->assertOk()
            ->assertSee('QR-паспорт', false)
            ->assertSee('Иван', false);

        $html = $this->get('/pc/'.$token)->getContent();
        $this->assertStringContainsString('Иван', $html);
        $this->assertStringNotContainsString('Петров', $html);
        $this->assertStringNotContainsString('79001112233', $html);
        $this->assertStringContainsString('SN-GPU-1', $html);
        $this->assertStringContainsString('Видеокарта', $html);
        $this->assertStringContainsString('Гарантия истекает', $html);
        $this->assertStringContainsString('Сборка начата', $html);
    }

    public function test_unknown_or_short_token_is_not_found(): void
    {
        $this->get('/pc/abcdefghijklmnopqrstuvwx')->assertNotFound();
        $this->get('/pc/short')->assertNotFound();
    }

    public function test_order_assembling_starts_capture_and_ready_enqueues_clip_job(): void
    {
        config(['video_surveillance.relay_token' => 'video-relay-secret']);
        $settings = VideoSurveillanceSetting::forClub($this->club->id);
        $settings->update([
            'is_enabled' => true,
            'provider' => 'hikvision',
            'api_base_url' => 'http://192.168.222.12',
            'api_login' => 'admin',
            'api_secret' => 'nvr-pass',
            'default_channel' => '4',
        ]);
        VideoSurveillanceEvent::query()->create([
            'club_id' => $this->club->id,
            'code' => 'store_assembly_start',
            'name' => 'Начало сборки',
            'is_enabled' => true,
            'trigger_key' => 'store.assembly_start',
            'channel' => '4',
            'marker_title' => 'Сборка ПК',
            'sort' => 1,
        ]);
        VideoSurveillanceEvent::query()->create([
            'club_id' => $this->club->id,
            'code' => 'store_assembly_done',
            'name' => 'Сборка готова',
            'is_enabled' => true,
            'trigger_key' => 'store.assembly_done',
            'channel' => '4',
            'marker_title' => 'ПК готов',
            'sort' => 2,
        ]);

        $client = StoreClient::query()->create([
            'club_id' => $this->club->id,
            'name' => 'Покупатель',
            'phone' => '79001112233',
        ]);
        $component = StoreComponent::query()->create([
            'club_id' => $this->club->id,
            'name' => 'RTX 4070',
            'type' => 'gpu',
            'warranty_number' => 'SN-GPU-1',
            'warranty_months' => 12,
            'status' => 'reserved',
            'purchase_price' => 1000,
        ]);
        $order = StoreOrder::query()->create([
            'club_id' => $this->club->id,
            'store_client_id' => $client->id,
            'assignee_id' => $this->assembler->id,
            'status' => 'new',
            'total' => 150000,
        ]);
        $order->items()->create([
            'name' => 'RTX 4070',
            'qty' => 1,
            'price' => 150000,
            'store_component_id' => $component->id,
        ]);

        $this->actingAs($this->assembler, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/store/orders/{$order->id}/status", ['status' => 'assembling'])
            ->assertRedirect();

        $pc = StoreBuiltPc::query()->where('store_order_id', $order->id)->first();
        $this->assertNotNull($pc);
        $this->assertNotNull($pc->assembly_started_at);
        $this->assertNull($pc->assembly_finished_at);

        $order->update(['verified_ok' => true, 'verified_at' => now()]);
        $this->actingAs($this->assembler, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/store/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertRedirect();

        $pc->refresh();
        $this->assertNotNull($pc->assembly_finished_at);
        $this->assertDatabaseHas('store_assembly_clip_jobs', [
            'store_built_pc_id' => $pc->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('video_surveillance_marker_jobs', [
            'club_id' => $this->club->id,
            'event' => 'store_assembly_start',
        ]);
    }

    public function test_lan_agent_can_upload_assembly_clip_and_passport_plays_it(): void
    {
        Storage::fake('local');
        config(['video_surveillance.relay_token' => 'video-relay-secret']);

        $pc = $this->makeSoldPc();
        $warranty = app(StoreWarrantyService::class)->ensureForBuiltPc($pc);
        $token = app(StoreWarrantyService::class)->ensurePublicToken($warranty);

        $job = \App\Models\StoreAssemblyClipJob::query()->create([
            'club_id' => $this->club->id,
            'store_built_pc_id' => $pc->id,
            'status' => 'claimed',
            'channel' => '4',
            'track_id' => 401,
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('bench.mp4', 400, 'video/mp4');

        $this->post('/api/video/assembly-clips', [
            'token' => 'video-relay-secret',
            'job_id' => $job->id,
            'clip' => $file,
        ])->assertOk()->assertJsonPath('status', 'success');

        $pc->refresh();
        $this->assertTrue($pc->hasAssemblyClip());
        Storage::disk('local')->assertExists($pc->assembly_clip_path);

        $page = $this->get('/pc/'.$token);
        $page->assertOk()->assertSee('/pc/'.$token.'/video', false);

        $this->get('/pc/'.$token.'/video')->assertOk();
    }

    public function test_assembly_clip_targets_require_token_and_return_rtsp(): void
    {
        config(['video_surveillance.relay_token' => 'video-relay-secret']);
        $settings = VideoSurveillanceSetting::forClub($this->club->id);
        $settings->update([
            'is_enabled' => true,
            'provider' => 'hikvision',
            'api_base_url' => 'http://192.168.222.12',
            'api_login' => 'admin',
            'api_secret' => 'nvr-pass',
            'default_channel' => '4',
        ]);

        $pc = $this->makeSoldPc(['assembly_started_at' => now()->subHour(), 'assembly_finished_at' => now()]);
        app(StoreAssemblyCaptureService::class)->enqueueClipExport($pc);

        $this->get('/api/video/assembly-clip-targets')->assertUnauthorized();

        $this->get('/api/video/assembly-clip-targets?token=video-relay-secret')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.track_id', 401)
            ->assertJsonPath('nvr.login', 'admin');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeSoldPc(array $extra = []): StoreBuiltPc
    {
        $client = StoreClient::query()->create([
            'club_id' => $this->club->id,
            'name' => 'Секретный Клиент',
            'phone' => '79001112233',
        ]);
        $component = StoreComponent::query()->create([
            'club_id' => $this->club->id,
            'name' => 'GeForce RTX 4070 Super',
            'type' => 'gpu',
            'warranty_number' => 'SN-GPU-1',
            'serials' => ['SN-GPU-1'],
            'warranty_months' => 12,
            'status' => 'sold',
            'purchase_price' => 80000,
        ]);
        $pc = StoreBuiltPc::query()->create(array_merge([
            'club_id' => $this->club->id,
            'store_client_id' => $client->id,
            'assembled_by' => $this->assembler->id,
            'title' => 'Игровой ПК',
            'serial_number' => '5123456789',
            'status' => 'sold',
            'sold_at' => now()->subDay(),
            'assembly_started_at' => now()->subDays(2)->setTime(12, 0),
            'assembly_finished_at' => now()->subDays(2)->setTime(16, 0),
            'verified_ok' => true,
            'verified_at' => now()->subDays(2)->setTime(15, 30),
        ], $extra));
        StoreBuiltPcComponent::query()->create([
            'store_built_pc_id' => $pc->id,
            'store_component_id' => $component->id,
            'type' => 'gpu',
            'name' => $component->name,
        ]);

        return $pc->fresh(['componentLinks.component', 'assembler', 'client']);
    }
}
