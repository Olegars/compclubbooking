<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoChat;
use App\Models\StoreAvitoConfig;
use App\Models\StoreAvitoDictValue;
use App\Models\StoreAvitoMessage;
use App\Models\StoreAvitoPart;
use App\Models\StoreAvitoProductAttr;
use App\Models\StoreAvitoSetting;
use App\Models\StoreSupplierCatalogProduct;
use App\Services\StoreAvito\StoreAvitoAdGenerator;
use App\Services\StoreAvito\StoreAvitoDictMatcher;
use App\Services\StoreAvito\StoreAvitoPricer;
use Database\Seeders\StoreAvitoPartsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAvitoTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create([
            'name' => 'Store Club',
            'slug' => 'store-club',
            'type' => 'store',
        ]);
    }

    public function test_pricer_applies_markup_extra_and_rounding(): void
    {
        $settings = StoreAvitoSetting::current();
        $settings->forceFill([
            'markup_percent' => 10,
            'extra_rub' => 4000,
            'round_to' => 100,
            'discount_over_60k_pct' => 0,
            'discount_over_100k_pct' => 0,
        ])->save();

        $price = (new StoreAvitoPricer)->quote([
            ['purchase' => 10000],
            ['purchase' => 20000],
        ], $settings);

        // (10000+20000)*1.1 + 4000 = 37000
        $this->assertSame(37000, $price);
    }

    public function test_generator_makes_unique_pc_ads_with_config_id(): void
    {
        $this->seedPcPool();
        StoreAvitoSetting::current()->forceFill([
            'address' => 'Москва, Тестовая 1',
            'markup_percent' => 15,
            'extra_rub' => 4000,
            'pc_type' => 'Игровой',
        ])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(3, enrich: false);

        $this->assertSame(3, $result['created']);
        $ads = StoreAvitoAd::query()->get();
        $this->assertCount(3, $ads);
        $this->assertCount(3, $ads->pluck('fingerprint')->unique());
        $this->assertCount(3, $ads->pluck('config_id')->unique());

        $ad = $ads->first();
        $this->assertMatchesRegularExpression('/^[A-Z]{3}\d{5}$/', $ad->config_id);
        $this->assertStringContainsString($ad->config_id, $ad->title);
        foreach ($ad->components as $row) {
            if (! empty($row['name'])) {
                $this->assertStringContainsString((string) $row['name'], $ad->description);
            }
        }
        $this->assertStringContainsString(
            'Для получения текущего списка комплектующих для данной конфигурации (ID:'.$ad->config_id.') запросите в чате',
            $ad->description
        );
        $this->assertSame('Intel', $ad->xml['BrandProcessor']);
        $this->assertMatchesRegularExpression('/Core i[57]/', (string) $ad->xml['ModelProcessor']);
        $this->assertSame('32 ГБ', $ad->xml['RamSize']);
        $this->assertContains($ad->xml['BrandVideocard'] ?? '', ['ZOTAC', 'Palit', 'MSI']);
        $this->assertStringContainsString('RTX', (string) ($ad->xml['ModelVideocard'] ?? ''));
        $this->assertNotSame('NVIDIA', $ad->xml['BrandVideocard'] ?? null);
        $this->assertNotSame('GeForce RTX 4060', $ad->xml['ModelVideocard'] ?? null);
        $this->assertGreaterThan(0, $ad->price);
    }

    public function test_xml_feed_contains_avito_pc_fields(): void
    {
        $this->seedPcPool();
        $settings = StoreAvitoSetting::current();
        $settings->forceFill(['address' => 'Москва, Тестовая 1'])->save();
        app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $ad = StoreAvitoAd::query()->first();

        $this->get('/avito/'.$settings->fresh()->feed_token.'/feed.xml')
            ->assertOk()
            ->assertSee('<Ads formatVersion="3" target="Avito.ru">', false)
            ->assertSee('<Id>pc-'.$ad->config_id.'</Id>', false)
            ->assertSee('<Category>Настольные компьютеры</Category>', false)
            ->assertSee('<GoodsSubType>Системные блоки</GoodsSubType>', false)
            ->assertSee('<BrandProcessor>Intel</BrandProcessor>', false)
            ->assertSee($ad->config_id, false);

        $this->get('/avito/wrong-token/feed.xml')->assertNotFound();
    }

    public function test_webhook_replies_with_live_bom_for_config_id(): void
    {
        $this->seedPcPool();
        StoreAvitoSetting::current()->forceFill(['auto_reply_enabled' => false])->save();
        app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $ad = StoreAvitoAd::query()->first();

        $this->postJson('/api/store/avito/webhook', [
            'payload' => [
                'value' => [
                    'id' => 'm1',
                    'chat_id' => 'u2i-test',
                    'user_id' => 1,
                    'author_id' => 99,
                    'type' => 'text',
                    'content' => ['text' => 'Здравствуйте, пришлите комплектующие ID:'.$ad->config_id],
                    'created' => time(),
                ],
            ],
        ])->assertOk();

        $this->assertTrue(StoreAvitoChat::query()->where('chat_id', 'u2i-test')->exists());
        $this->assertTrue(
            StoreAvitoMessage::query()
                ->where('chat_id', 'u2i-test')
                ->where('from_us', true)
                ->get()
                ->contains(fn (StoreAvitoMessage $m) => str_contains($m->text(), 'ID:'.$ad->config_id))
        );
    }

    public function test_generate_http_returns_immediately_without_building_ads(): void
    {
        $owner = Admin::create([
            'name' => 'Owner',
            'email' => 'owner-gen@avito.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/store/avito/generate', ['count' => 20])
            ->assertRedirect();

        $this->assertSame(0, StoreAvitoAd::query()->count());
        $this->assertSame('running', StoreAvitoSetting::current()->last_generate_result['status'] ?? null);
    }

    public function test_admin_search_finds_ad_by_config_id(): void
    {
        StoreAvitoAd::query()->create([
            'config_id' => 'DZK48190',
            'fingerprint' => sha1('a'),
            'title' => 'ПК i5 DZK48190',
            'description' => 'test',
            'price' => 80000,
            'components' => [],
            'xml' => [],
            'status' => 'active',
            'generated_at' => now(),
        ]);
        StoreAvitoAd::query()->create([
            'config_id' => 'ABC12345',
            'fingerprint' => sha1('b'),
            'title' => 'ПК i7 ABC12345',
            'description' => 'test',
            'price' => 90000,
            'components' => [],
            'xml' => [],
            'status' => 'active',
            'generated_at' => now(),
        ]);

        $owner = Admin::create([
            'name' => 'Owner',
            'email' => 'owner-search@avito.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/store/avito?q=dzk48190')
            ->assertOk()
            ->assertSee('DZK48190', false)
            ->assertDontSee('ABC12345', false);
    }

    public function test_owner_opens_avito_admin_page(): void
    {
        $owner = Admin::create([
            'name' => 'Owner',
            'email' => 'owner@avito.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->get('/admin/store/avito')
            ->assertOk();
    }

    public function test_dict_matcher_uses_avito_catalog_strings(): void
    {
        $rows = [
            ['BrandProcessor', 'Intel', ''],
            ['ModelProcessor', 'Core i5', 'Intel'],
            ['CodeProcessor', '12400F', 'Core i5'],
            ['BrandVideocard', 'ZOTAC', ''],
            ['BrandVideocard', 'Palit', ''],
            ['ModelVideocard', 'GAMING GEFORCE RTX 4060 8GB', 'ZOTAC'],
            ['ModelVideocard', 'GAMING GEFORCE RTX 4060 Ti 8GB', 'ZOTAC'],
            ['ModelVideocard', 'GAMING GEFORCE RTX 4060 Ti 16GB AMP', 'ZOTAC'],
            ['BrandMotherboard', 'GIGABYTE', ''],
            ['ModelMotherboard', 'B760M DS3H DDR4', 'GIGABYTE'],
            ['ModelMotherboard', 'B760M GAMING X DDR4 (rev. 1.0)', 'GIGABYTE'],
        ];
        foreach ($rows as $i => [$tag, $value, $parent]) {
            StoreAvitoDictValue::query()->create([
                'tag' => $tag,
                'value' => $value,
                'parent_value' => $parent,
                'sort_order' => $i,
            ]);
        }

        $m = app(StoreAvitoDictMatcher::class);
        $this->assertSame('Intel', $m->match('BrandProcessor', 'Процессор Intel Core i5-12400F OEM'));
        $this->assertSame('Core i5', $m->match('ModelProcessor', 'Intel Core i5-12400F', 'Intel'));
        $this->assertSame('12400F', $m->match('CodeProcessor', 'Intel Core i5-12400F', 'Core i5'));
        $this->assertSame('ZOTAC', $m->match('BrandVideocard', 'ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP'));
        $this->assertSame(
            'GAMING GEFORCE RTX 4060 Ti 16GB AMP',
            $m->match('ModelVideocard', 'Видеокарта ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP', 'ZOTAC')
        );
        $this->assertSame('GIGABYTE', $m->match('BrandMotherboard', 'GIGABYTE B760M GAMING X DDR4 (rev. 1.0)'));
        $this->assertSame(
            'B760M GAMING X DDR4 (rev. 1.0)',
            $m->match('ModelMotherboard', 'GIGABYTE B760M GAMING X DDR4 (rev. 1.0)', 'GIGABYTE')
        );
    }

    public function test_sync_dicts_http_queues_without_calling_avito(): void
    {
        StoreAvitoSetting::current()->forceFill([
            'client_id' => 'cid',
            'client_secret' => 'secret',
        ])->save();

        $owner = Admin::create([
            'name' => 'Owner',
            'email' => 'owner-dicts@avito.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/store/avito/dicts')
            ->assertRedirect();

        $this->assertSame('running', StoreAvitoSetting::current()->last_dict_sync_result['status'] ?? null);
        $this->assertSame(0, StoreAvitoDictValue::query()->count());
    }

    public function test_owner_creates_config_from_abstract_parts(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->assertSame(4, StoreAvitoPart::query()->where('type', 'ram')->count());
        $this->assertSame(2, StoreAvitoPart::query()->where('type', 'ssd')->count());
        $this->assertSame(8, StoreAvitoPart::query()->where('type', 'psu')->count());
        $this->assertGreaterThan(20, StoreAvitoPart::query()->where('type', 'cpu')->count());
        $this->assertGreaterThan(10, StoreAvitoPart::query()->where('type', 'gpu')->where('enabled', true)->count());
        $this->assertGreaterThan(15, StoreAvitoPart::query()->where('type', 'motherboard')->count());
        $this->assertTrue(StoreAvitoPart::query()->where('code', 'mb-b550')->exists());
        $this->assertTrue(StoreAvitoPart::query()->where('code', 'mb-b650')->exists());
        $this->assertTrue(StoreAvitoPart::query()->where('code', 'mb-b850')->exists());
        $this->assertFalse(StoreAvitoPart::query()->where('code', 'gpu-rtx-3060')->where('enabled', true)->exists());
        $owner = Admin::create([
            'name' => 'Owner',
            'email' => 'owner-cfg@avito.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/store/avito/configs', [
                'cpu_part_id' => StoreAvitoPart::query()->where('code', 'cpu-12400f')->value('id'),
                'mb_part_id' => StoreAvitoPart::query()->where('code', 'mb-b760')->value('id'),
                'gpu_part_id' => StoreAvitoPart::query()->where('code', 'gpu-rtx-4060-ti')->value('id'),
                'ram_part_id' => StoreAvitoPart::query()->where('code', 'ram-ddr5-16')->value('id'),
                'ssd_part_id' => StoreAvitoPart::query()->where('code', 'ssd-m2-256')->value('id'),
                'psu_part_id' => StoreAvitoPart::query()->where('code', 'psu-600')->value('id'),
            ])
            ->assertRedirect();

        $cfg = StoreAvitoConfig::query()->first();
        $this->assertNotNull($cfg);
        $this->assertSame(1, $cfg->sort_order);
        $this->assertSame('LGA1700', $cfg->socket);
        $this->assertSame('DDR5', $cfg->ddr);
        $this->assertStringContainsString('12400F', $cfg->name);
        $this->assertStringContainsString('B760', $cfg->name);
        $this->assertStringContainsString('4060 Ti', $cfg->name);
        $this->assertSame(
            StoreAvitoPart::query()->where('code', 'mb-b760')->value('id'),
            $cfg->mb_part_id
        );
    }

    public function test_generator_walks_configs_in_order(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->seedPcPool();
        $this->addCatalogRow(211, 'motherboard', 'MSI B760 DDR5', 'MSI', 10000, ['socket' => 'LGA1700', 'ddr' => 'DDR5', 'avito_brand' => 'MSI']);
        $this->addCatalogRow(311, 'ram', 'Kingston DDR5 16GB 2x8', 'Kingston', 5000, ['ddr' => 'DDR5', 'ram_gb' => 16, 'avito_code' => '16 ГБ']);
        $this->addCatalogRow(511, 'ssd', 'Kingston NV2 256GB', 'Kingston', 3000, ['ram_gb' => 256]);
        $this->addCatalogRow(611, 'psu', 'Chieftec 600W', 'Chieftec', 4500, ['wattage' => 600]);

        $a = $this->makeConfig('cpu-12400f', 'ram-ddr5-16', 'ssd-m2-256', 'psu-600', 'gpu-rtx-4060-ti');
        $b = $this->makeConfig('cpu-14700f', 'ram-ddr5-16', 'ssd-m2-256', 'psu-600', 'gpu-rtx-4070');

        StoreAvitoSetting::current()->forceFill([
            'address' => 'Москва, Тестовая 1',
            'markup_percent' => 15,
            'extra_rub' => 4000,
            'pc_type' => 'Игровой',
        ])->save();

        $gen = app(StoreAvitoAdGenerator::class);
        $first = $gen->generate(1, enrich: false);
        $this->assertSame(1, $first['created']);
        $ad1 = StoreAvitoAd::query()->first();
        $this->assertSame($a->id, $ad1->store_avito_config_id);
        $this->assertSame('12400F', $ad1->xml['CodeProcessor']);
        $this->assertSame('16 ГБ', $ad1->xml['RamSize']);
        $this->assertStringContainsString('4060 Ti', (string) ($ad1->xml['ModelVideocard'] ?? $ad1->xml['CodeVideocard'] ?? ''));
        foreach ($ad1->components as $row) {
            if (! empty($row['name'])) {
                $this->assertStringContainsString((string) $row['name'], $ad1->description);
            }
        }
        $this->assertSame($a->id, StoreAvitoSetting::current()->last_config_id);
        $this->assertSame(1, $a->fresh()->use_count);

        $second = $gen->generate(1, enrich: false);
        $this->assertSame(1, $second['created']);
        $ad2 = StoreAvitoAd::query()->orderByDesc('id')->first();
        $this->assertSame($b->id, $ad2->store_avito_config_id);
        $this->assertSame('14700F', $ad2->xml['CodeProcessor']);
        $this->assertStringContainsString('4070', (string) ($ad2->xml['ModelVideocard'] ?? $ad2->xml['CodeVideocard'] ?? ''));
        $this->assertStringNotContainsString('4060', (string) ($ad2->xml['ModelVideocard'] ?? ''));
        $this->assertSame($b->id, StoreAvitoSetting::current()->last_config_id);
    }

    public function test_disabled_configs_do_not_fall_back_to_random_catalog(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->seedPcPool();
        $cfg = $this->makeConfig('cpu-12400f', 'ram-ddr5-16', 'ssd-m2-256', 'psu-600', 'gpu-rtx-4060-ti');
        $cfg->forceFill(['enabled' => false])->save();
        StoreAvitoSetting::current()->forceFill([
            'address' => 'Москва, Тестовая 1',
            'pc_type' => 'Игровой',
        ])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(2, enrich: false);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, StoreAvitoAd::query()->count());
        $this->assertSame(0, $cfg->fresh()->use_count);
        $this->assertStringContainsString('выключен', (string) $result['error']);
    }

    public function test_classifies_7500f_and_skips_already_complete(): void
    {
        config(['ai_assistant.deepseek.api_key' => 'sk-deepseek-test']);
        StoreSupplierCatalogProduct::query()->create([
            'sku' => 10718447,
            'name' => 'Процессор AMD Ryzen 5 7500F Soc-AM5 3.7GHz OEM',
            'part' => '100-000000597',
            'vendor' => 'AMD',
            'price' => 12000,
            'stock_qty' => 0,
        ]);
        $svc = app(\App\Services\StoreAvito\StoreAvitoCatalogAttrService::class);
        $first = $svc->classifyProducts(
            'cpu',
            StoreSupplierCatalogProduct::query()->where('sku', 10718447)->get(),
            false,
            false,
        );
        $this->assertSame(1, $first['classified']);
        $attr = StoreAvitoProductAttr::query()->where('sku', 10718447)->first();
        $this->assertNotNull($attr);
        $this->assertSame('7500F', $attr->avito_code);
        $this->assertSame('AM5', $attr->socket);

        \Illuminate\Support\Facades\Http::fake();
        $again = $svc->classifyProducts(
            'cpu',
            StoreSupplierCatalogProduct::query()->where('sku', 10718447)->get(),
            false,
            true,
        );
        $this->assertSame(0, $again['pending_before']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_classifies_llm_markdown_json_without_crashing(): void
    {
        config(['ai_assistant.deepseek.api_key' => 'sk-deepseek-test']);
        StoreSupplierCatalogProduct::query()->create([
            'sku' => 42,
            'name' => 'Блок питания OEM ATX',
            'vendor' => 'OEM',
            'price' => 3000,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "```json\n[{\"sku\":42,\"wattage\":500,\"avito_brand\":\"OEM\",\"avito_model\":\"500W\",\"avito_code\":\"500W\",}]\n```",
                    ],
                ]],
            ]),
        ]);
        $svc = app(\App\Services\StoreAvito\StoreAvitoCatalogAttrService::class);
        $svc->classifyProducts(
            'psu',
            StoreSupplierCatalogProduct::query()->where('sku', 42)->get(),
            false,
            true,
        );
        $attr = StoreAvitoProductAttr::query()->where('sku', 42)->first();
        $this->assertNotNull($attr);
        $this->assertSame(500, (int) $attr->wattage);
        $this->assertSame('deepseek', $attr->source);
    }

    public function test_classifies_workstation_gpu_without_deepseek_geforce_code(): void
    {
        config(['ai_assistant.deepseek.api_key' => 'sk-deepseek-test']);
        StoreSupplierCatalogProduct::query()->create([
            'sku' => 77,
            'name' => 'Видеокарта NVIDIA RTX A400, 4 GB GDDR6, 64 bit, 4xDisplayPort, GPU 727 MHz',
            'vendor' => 'NVIDIA',
            'price' => 12000,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '[{"sku":77,"avito_brand":"NVIDIA","avito_model":"RTX 4060","avito_code":"RTX 4060"}]',
                    ],
                ]],
            ]),
        ]);
        $svc = app(\App\Services\StoreAvito\StoreAvitoCatalogAttrService::class);
        $svc->classifyProducts(
            'gpu',
            StoreSupplierCatalogProduct::query()->where('sku', 77)->get(),
            false,
            true,
        );
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $attr = StoreAvitoProductAttr::query()->where('sku', 77)->first();
        $this->assertNotNull($attr);
        $this->assertSame('SKIP', $attr->avito_code);
        $this->assertSame('heuristic', $attr->source);
    }

    public function test_deepseek_cannot_override_gpu_chip_absent_from_name(): void
    {
        config(['ai_assistant.deepseek.api_key' => 'sk-deepseek-test']);
        StoreSupplierCatalogProduct::query()->create([
            'sku' => 78,
            'name' => 'Видеокарта OEM Gaming 8G',
            'vendor' => 'OEM',
            'price' => 15000,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*chat/completions' => \Illuminate\Support\Facades\Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '[{"sku":78,"avito_brand":"Palit","avito_model":"GeForce RTX 4060","avito_code":"RTX 4060"}]',
                    ],
                ]],
            ]),
        ]);
        $svc = app(\App\Services\StoreAvito\StoreAvitoCatalogAttrService::class);
        $svc->classifyProducts(
            'gpu',
            StoreSupplierCatalogProduct::query()->where('sku', 78)->get(),
            false,
            true,
        );
        $attr = StoreAvitoProductAttr::query()->where('sku', 78)->first();
        $this->assertNotNull($attr);
        $this->assertSame('SKIP', $attr->avito_code);
    }

    public function test_generator_matches_ryzen_5_7500f_catalog_name(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->addCatalogRow(10718447, 'cpu', 'Процессор AMD Ryzen 5 7500F Soc-AM5 3.7GHz OEM', 'AMD', 12000, [
            'socket' => 'AM5',
            'avito_brand' => 'AMD',
            'avito_model' => 'Ryzen 5',
            'avito_code' => '7500F',
        ]);
        $this->addCatalogRow(221, 'motherboard', 'MSI B650 GAMING DDR5', 'MSI', 14000, [
            'socket' => 'AM5',
            'ddr' => 'DDR5',
            'avito_brand' => 'MSI',
        ]);
        $this->addCatalogRow(321, 'ram', 'Kingston DDR5 32GB', 'Kingston', 8000, [
            'ddr' => 'DDR5',
            'ram_gb' => 32,
            'avito_code' => '32 ГБ',
        ]);
        $this->addCatalogRow(521, 'ssd', 'Kingston NV2 256GB', 'Kingston', 2500, ['ram_gb' => 256]);
        $this->addCatalogRow(621, 'psu', 'Chieftec 500W', 'Chieftec', 4000, ['wattage' => 500]);
        $this->addCatalogRow(421, 'gpu', 'Palit GeForce RTX 5060 8GB', 'Palit', 28000, [
            'avito_brand' => 'Palit',
            'avito_model' => 'Palit GeForce RTX 5060 8GB',
            'avito_code' => 'RTX 5060',
        ]);
        $this->makeConfig('cpu-7500f', 'ram-ddr5-32', 'ssd-m2-256', 'psu-500', 'gpu-rtx-5060');
        StoreAvitoSetting::current()->forceFill([
            'address' => 'Москва, Тестовая 1',
            'pc_type' => 'Игровой',
        ])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $this->assertSame(1, $result['created'], (string) ($result['error'] ?? ''));
        $ad = StoreAvitoAd::query()->first();
        $this->assertSame('7500F', $ad->xml['CodeProcessor'] ?? null);
        $this->assertStringContainsString('7500F', $ad->description);
        $this->assertStringContainsString('RTX 5060', implode(' ', array_column($ad->components, 'name')));
    }

    public function test_generator_reads_500w_from_psu_model_name(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->addCatalogRow(10718447, 'cpu', 'Процессор AMD Ryzen 5 7500F Soc-AM5 3.7GHz OEM', 'AMD', 12000, [
            'socket' => 'AM5', 'avito_brand' => 'AMD', 'avito_model' => 'Ryzen 5', 'avito_code' => '7500F',
        ]);
        $this->addCatalogRow(221, 'motherboard', 'MSI B650 GAMING DDR5', 'MSI', 14000, [
            'socket' => 'AM5', 'ddr' => 'DDR5', 'avito_brand' => 'MSI',
        ]);
        $this->addCatalogRow(321, 'ram', 'Kingston DDR5 32GB', 'Kingston', 8000, [
            'ddr' => 'DDR5', 'ram_gb' => 32, 'avito_code' => '32 ГБ',
        ]);
        $this->addCatalogRow(521, 'ssd', 'Kingston NV2 256GB', 'Kingston', 2500, ['ram_gb' => 256]);
        $this->addCatalogRow(421, 'gpu', 'Palit GeForce RTX 5060 8GB', 'Palit', 28000, [
            'avito_brand' => 'Palit', 'avito_model' => 'Palit GeForce RTX 5060 8GB', 'avito_code' => 'RTX 5060',
        ]);
        StoreSupplierCatalogProduct::query()->create([
            'sku' => 9001,
            'name' => 'Блок питания Chieftec GPS-500A8',
            'vendor' => 'Chieftec',
            'price' => 4500,
            'stock_qty' => 3,
        ]);
        $this->makeConfig('cpu-7500f', 'ram-ddr5-32', 'ssd-m2-256', 'psu-500', 'gpu-rtx-5060');
        StoreAvitoSetting::current()->forceFill(['address' => 'Москва', 'pc_type' => 'Игровой'])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $this->assertSame(1, $result['created'], (string) ($result['error'] ?? ''));
        $names = implode(' ', array_column(StoreAvitoAd::query()->first()->components, 'name'));
        $this->assertStringContainsString('GPS-500A8', $names);
    }

    public function test_generator_does_not_pick_workstation_gpu_for_rtx_4060(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->addCatalogRow(101, 'cpu', 'Процессор Intel Core i5-12400F', 'Intel', 15000, [
            'socket' => 'LGA1700', 'avito_brand' => 'Intel', 'avito_model' => 'Core i5', 'avito_code' => '12400F',
        ]);
        $this->addCatalogRow(211, 'motherboard', 'MSI B760 DDR5', 'MSI', 10000, [
            'socket' => 'LGA1700', 'ddr' => 'DDR5', 'avito_brand' => 'MSI', 'avito_code' => 'B760',
        ]);
        $this->addCatalogRow(311, 'ram', 'Kingston DDR5 16GB', 'Kingston', 5000, [
            'ddr' => 'DDR5', 'ram_gb' => 16, 'avito_code' => '16 ГБ',
        ]);
        $this->addCatalogRow(511, 'ssd', 'Kingston NV2 256GB', 'Kingston', 3000, ['ram_gb' => 256]);
        $this->addCatalogRow(611, 'psu', 'Chieftec 600W', 'Chieftec', 4500, ['wattage' => 600]);
        $this->addCatalogRow(401, 'gpu', 'Palit GeForce RTX 4060 Dual 8GB', 'Palit', 28000, [
            'avito_brand' => 'Palit', 'avito_model' => 'Palit GeForce RTX 4060 Dual 8GB', 'avito_code' => 'RTX 4060',
        ]);
        $this->addCatalogRow(402, 'gpu', 'Видеокарта NVIDIA RTX A400, 4 GB GDDR6, 64 bit, 4xDisplayPort, GPU 727 MHz', 'NVIDIA', 12000, [
            'avito_brand' => 'NVIDIA', 'avito_model' => 'RTX 4060', 'avito_code' => 'RTX 4060',
        ]);
        $this->addCatalogRow(403, 'gpu', 'Видеокарта NVIDIA L40S, 48 GB GDDR6, 384 bit, 4xDisplayPort, GPU 1110 MHz', 'NVIDIA', 90000, [
            'avito_brand' => 'NVIDIA', 'avito_model' => 'RTX 4060', 'avito_code' => 'RTX 4060',
        ]);
        $this->addCatalogRow(404, 'gpu', 'MSI GeForce RTX 3060 Ventus 12G', 'MSI', 18000, [
            'avito_brand' => 'MSI', 'avito_model' => 'RTX 4060', 'avito_code' => 'RTX 4060',
        ]);
        $this->makeConfig('cpu-12400f', 'ram-ddr5-16', 'ssd-m2-256', 'psu-600', 'gpu-rtx-4060');
        StoreAvitoSetting::current()->forceFill(['address' => 'Москва', 'pc_type' => 'Игровой'])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $this->assertSame(1, $result['created'], (string) ($result['error'] ?? ''));
        $names = implode(' ', array_column(StoreAvitoAd::query()->first()->components, 'name'));
        $this->assertStringContainsString('RTX 4060', $names);
        $this->assertStringNotContainsString('A400', $names);
        $this->assertStringNotContainsString('L40S', $names);
        $this->assertStringNotContainsString('3060', $names);
    }

    public function test_generator_fails_when_only_workstation_gpus_for_4060(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->addCatalogRow(101, 'cpu', 'Процессор Intel Core i5-12400F', 'Intel', 15000, [
            'socket' => 'LGA1700', 'avito_brand' => 'Intel', 'avito_model' => 'Core i5', 'avito_code' => '12400F',
        ]);
        $this->addCatalogRow(211, 'motherboard', 'MSI B760 DDR5', 'MSI', 10000, [
            'socket' => 'LGA1700', 'ddr' => 'DDR5', 'avito_brand' => 'MSI', 'avito_code' => 'B760',
        ]);
        $this->addCatalogRow(311, 'ram', 'Kingston DDR5 16GB', 'Kingston', 5000, [
            'ddr' => 'DDR5', 'ram_gb' => 16, 'avito_code' => '16 ГБ',
        ]);
        $this->addCatalogRow(511, 'ssd', 'Kingston NV2 256GB', 'Kingston', 3000, ['ram_gb' => 256]);
        $this->addCatalogRow(611, 'psu', 'Chieftec 600W', 'Chieftec', 4500, ['wattage' => 600]);
        $this->addCatalogRow(402, 'gpu', 'Видеокарта NVIDIA RTX A400, 4 GB GDDR6', 'NVIDIA', 12000, [
            'avito_brand' => 'NVIDIA', 'avito_code' => 'RTX 4060',
        ]);
        $this->makeConfig('cpu-12400f', 'ram-ddr5-16', 'ssd-m2-256', 'psu-600', 'gpu-rtx-4060');
        StoreAvitoSetting::current()->forceFill(['address' => 'Москва', 'pc_type' => 'Игровой'])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('нет видеокарты RTX 4060', (string) ($result['error'] ?? ''));
    }

    public function test_generator_picks_b650_board_not_b650e(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->addCatalogRow(10718447, 'cpu', 'Процессор AMD Ryzen 5 7500F Soc-AM5', 'AMD', 12000, [
            'socket' => 'AM5', 'avito_brand' => 'AMD', 'avito_model' => 'Ryzen 5', 'avito_code' => '7500F',
        ]);
        $this->addCatalogRow(221, 'motherboard', 'MSI B650M GAMING DDR5', 'MSI', 14000, [
            'socket' => 'AM5', 'ddr' => 'DDR5', 'avito_brand' => 'MSI', 'avito_code' => 'B650',
        ]);
        $this->addCatalogRow(222, 'motherboard', 'ASUS TUF GAMING B650E-PLUS WIFI', 'ASUS', 18000, [
            'socket' => 'AM5', 'ddr' => 'DDR5', 'avito_brand' => 'ASUS', 'avito_code' => 'B650E',
        ]);
        $this->addCatalogRow(321, 'ram', 'Kingston DDR5 32GB', 'Kingston', 8000, [
            'ddr' => 'DDR5', 'ram_gb' => 32, 'avito_code' => '32 ГБ',
        ]);
        $this->addCatalogRow(521, 'ssd', 'Kingston NV2 256GB', 'Kingston', 2500, ['ram_gb' => 256]);
        $this->addCatalogRow(621, 'psu', 'Chieftec 500W', 'Chieftec', 4000, ['wattage' => 500]);
        $this->addCatalogRow(421, 'gpu', 'Palit GeForce RTX 5060 8GB', 'Palit', 28000, [
            'avito_brand' => 'Palit', 'avito_model' => 'Palit GeForce RTX 5060 8GB', 'avito_code' => 'RTX 5060',
        ]);
        $this->makeConfig('cpu-7500f', 'ram-ddr5-32', 'ssd-m2-256', 'psu-500', 'gpu-rtx-5060', 'mb-b650');
        StoreAvitoSetting::current()->forceFill(['address' => 'Москва', 'pc_type' => 'Игровой'])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $this->assertSame(1, $result['created'], (string) ($result['error'] ?? ''));
        $names = implode(' ', array_column(StoreAvitoAd::query()->first()->components, 'name'));
        $this->assertStringContainsString('B650M', $names);
        $this->assertStringNotContainsString('B650E', $names);
    }

    public function test_generator_fails_when_only_b650e_for_b650_config(): void
    {
        $this->seed(StoreAvitoPartsSeeder::class);
        $this->addCatalogRow(10718447, 'cpu', 'Процессор AMD Ryzen 5 7500F Soc-AM5', 'AMD', 12000, [
            'socket' => 'AM5', 'avito_brand' => 'AMD', 'avito_model' => 'Ryzen 5', 'avito_code' => '7500F',
        ]);
        $this->addCatalogRow(222, 'motherboard', 'ASUS TUF GAMING B650E-PLUS WIFI', 'ASUS', 18000, [
            'socket' => 'AM5', 'ddr' => 'DDR5', 'avito_brand' => 'ASUS', 'avito_code' => 'B650E',
        ]);
        $this->addCatalogRow(321, 'ram', 'Kingston DDR5 32GB', 'Kingston', 8000, [
            'ddr' => 'DDR5', 'ram_gb' => 32, 'avito_code' => '32 ГБ',
        ]);
        $this->addCatalogRow(521, 'ssd', 'Kingston NV2 256GB', 'Kingston', 2500, ['ram_gb' => 256]);
        $this->addCatalogRow(621, 'psu', 'Chieftec 500W', 'Chieftec', 4000, ['wattage' => 500]);
        $this->addCatalogRow(421, 'gpu', 'Palit GeForce RTX 5060 8GB', 'Palit', 28000, [
            'avito_brand' => 'Palit', 'avito_model' => 'Palit GeForce RTX 5060 8GB', 'avito_code' => 'RTX 5060',
        ]);
        $this->makeConfig('cpu-7500f', 'ram-ddr5-32', 'ssd-m2-256', 'psu-500', 'gpu-rtx-5060', 'mb-b650');
        StoreAvitoSetting::current()->forceFill(['address' => 'Москва', 'pc_type' => 'Игровой'])->save();

        $result = app(StoreAvitoAdGenerator::class)->generate(1, enrich: false);
        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('нет платы B650', (string) ($result['error'] ?? ''));
    }

    private function makeConfig(string $cpu, string $ram, string $ssd, string $psu, string $gpu = 'gpu-rtx-4060-ti', ?string $mb = null): StoreAvitoConfig
    {
        $cpuPart = StoreAvitoPart::query()->where('code', $cpu)->firstOrFail();
        $gpuPart = StoreAvitoPart::query()->where('code', $gpu)->firstOrFail();
        $ramPart = StoreAvitoPart::query()->where('code', $ram)->firstOrFail();
        $ssdPart = StoreAvitoPart::query()->where('code', $ssd)->firstOrFail();
        $psuPart = StoreAvitoPart::query()->where('code', $psu)->firstOrFail();
        $mbPart = $mb ? StoreAvitoPart::query()->where('code', $mb)->firstOrFail() : null;
        $next = ((int) StoreAvitoConfig::query()->max('sort_order')) + 1;

        return StoreAvitoConfig::query()->create([
            'name' => StoreAvitoConfig::makeName($cpuPart, $ramPart, $ssdPart, $psuPart, $gpuPart, $mbPart),
            'cpu_part_id' => $cpuPart->id,
            'mb_part_id' => $mbPart?->id,
            'gpu_part_id' => $gpuPart->id,
            'ram_part_id' => $ramPart->id,
            'ssd_part_id' => $ssdPart->id,
            'psu_part_id' => $psuPart->id,
            'socket' => (string) $cpuPart->socket,
            'ddr' => (string) $ramPart->ddr,
            'sort_order' => $next,
            'enabled' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function addCatalogRow(int $sku, string $type, string $name, string $vendor, int $price, array $extra): void
    {
        StoreSupplierCatalogProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'vendor' => $vendor,
            'price' => $price,
            'stock_qty' => 5,
        ]);
        StoreAvitoProductAttr::query()->create(array_merge([
            'sku' => $sku,
            'type' => $type,
            'source' => 'heuristic',
            'mapped_at' => now(),
            'avito_brand' => $vendor,
            'avito_model' => $name,
        ], $extra));
    }

    private function seedPcPool(): void
    {
        $rows = [
            [101, 'cpu', 'Процессор Intel Core i5-12400F', 'Intel', 15000, ['socket' => 'LGA1700', 'avito_brand' => 'Intel', 'avito_model' => 'Core i5', 'avito_code' => '12400F']],
            [102, 'cpu', 'Процессор Intel Core i7-14700F', 'Intel', 28000, ['socket' => 'LGA1700', 'avito_brand' => 'Intel', 'avito_model' => 'Core i7', 'avito_code' => '14700F']],
            [201, 'motherboard', 'GIGABYTE B760M GAMING X DDR4 (rev. 1.0)', 'Gigabyte', 9000, ['socket' => 'LGA1700', 'ddr' => 'DDR4', 'avito_brand' => 'Gigabyte', 'avito_model' => 'GIGABYTE B760M GAMING X DDR4 (rev. 1.0)']],
            [202, 'motherboard', 'MSI B760 GAMING PLUS DDR4', 'MSI', 11000, ['socket' => 'LGA1700', 'ddr' => 'DDR4', 'avito_brand' => 'MSI', 'avito_model' => 'MSI B760 GAMING PLUS DDR4']],
            [301, 'ram', 'Kingston DDR4 32GB 2x16', 'Kingston', 7000, ['ddr' => 'DDR4', 'ram_gb' => 32, 'avito_code' => '32 ГБ']],
            [302, 'ram', 'ADATA DDR4 32GB', 'ADATA', 7500, ['ddr' => 'DDR4', 'ram_gb' => 32, 'avito_code' => '32 ГБ']],
            [401, 'gpu', 'ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP', 'ZOTAC', 32000, ['avito_brand' => 'ZOTAC', 'avito_model' => 'ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP']],
            [402, 'gpu', 'Palit GeForce RTX 4070 Dual 12GB', 'Palit', 54000, ['avito_brand' => 'Palit', 'avito_model' => 'Palit GeForce RTX 4070 Dual 12GB']],
            [501, 'ssd', 'Kingston NV2 1TB', 'Kingston', 6000, []],
            [502, 'ssd', 'Samsung 990 EVO 1TB', 'Samsung', 9000, []],
            [601, 'psu', 'Chieftec 650W', 'Chieftec', 5000, ['wattage' => 650]],
            [701, 'cooler', 'ID-COOLING SE-224', 'ID-COOLING', 2500, []],
            [801, 'case', 'Deepcool CC560', 'Deepcool', 4000, []],
        ];

        foreach ($rows as [$sku, $type, $name, $vendor, $price, $extra]) {
            StoreSupplierCatalogProduct::query()->create([
                'sku' => $sku,
                'name' => $name,
                'vendor' => $vendor,
                'price' => $price,
                'stock_qty' => 5,
            ]);
            StoreAvitoProductAttr::query()->create(array_merge([
                'sku' => $sku,
                'type' => $type,
                'source' => 'heuristic',
                'mapped_at' => now(),
                'avito_brand' => $vendor,
                'avito_model' => $name,
            ], $extra));
        }
    }
}
