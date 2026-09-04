<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoChat;
use App\Models\StoreAvitoDictValue;
use App\Models\StoreAvitoMessage;
use App\Models\StoreAvitoProductAttr;
use App\Models\StoreAvitoSetting;
use App\Models\StoreSupplierCatalogProduct;
use App\Services\StoreAvito\StoreAvitoAdGenerator;
use App\Services\StoreAvito\StoreAvitoDictMatcher;
use App\Services\StoreAvito\StoreAvitoPricer;
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
