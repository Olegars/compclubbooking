<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiAssistantSetting;
use App\Models\Club;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\InventoryInvoiceOcrService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InventoryInvoiceOcrTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_assistant.deepseek.api_key' => 'sk-deepseek-test',
            'ai_assistant.deepseek.base_url' => 'https://api.deepseek.com',
            'ai_assistant.deepseek.model' => 'deepseek-v4-flash',
            'ai_assistant.deepseek.vision_model' => 'deepseek-flash',
        ]);

        $this->club = Club::create([
            'name' => 'Invoice Club',
            'slug' => 'invoice-club',
            'type' => 'club',
        ]);

        AiAssistantSetting::forClub($this->club->id)->forceFill([
            'is_enabled' => true,
            'llm_provider' => 'deepseek',
            'llm_api_key' => 'sk-deepseek-test',
        ])->save();

        $this->admin = Admin::create([
            'name' => 'Inv Super',
            'email' => 'inv-ocr@test.local',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $this->club->id,
        ]);
    }

    public function test_photo_forms_invoice_without_changing_stock(): void
    {
        $product = Product::create([
            'name' => 'Cola 0.5',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 3,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
            'barcode' => '4601234567890',
        ]);

        $this->fakeHttp(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return Http::response('unexpected '.$request->url(), 599);
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'invoice_number' => 'УПД-77',
                            'invoice_date' => '2026-09-16',
                            'supplier_name' => 'ООО Снэк',
                            'supplier_inn' => '7701234567',
                            'lines' => [[
                                'name' => 'Cola 0.5л',
                                'qty' => 24,
                                'unit_cost' => 42.5,
                                'barcode' => '4601234567890',
                                'amount' => 1020,
                            ]],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]);
        });

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/api/inventory/parse-invoice', [
                'photo' => $this->fakeImageUpload('waybill.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('invoice_number', 'УПД-77')
            ->assertJsonPath('lines.0.qty', 24)
            ->assertJsonPath('lines.0.scanned_qty', 0)
            ->assertJsonPath('lines.0.product_id', $product->id);

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('supplier_invoices', 0);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['model'] ?? null) === 'deepseek-flash'
                && is_array($body['messages'][1]['content'] ?? null)
                && collect($body['messages'][1]['content'])->contains(
                    fn ($part) => ($part['type'] ?? null) === 'image_url'
                );
        });
    }

    public function test_close_rejects_mismatch_and_does_not_touch_stock(): void
    {
        $product = Product::create([
            'name' => 'Cola 0.5',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 3,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
            'barcode' => '4601234567890',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/inventory/close-invoice', [
                'invoice_number' => 'УПД-77',
                'lines' => [[
                    'product_id' => $product->id,
                    'name' => 'Cola 0.5',
                    'qty' => 24,
                    'scanned_qty' => 10,
                    'unit_cost' => 42.5,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Накладная и факт не совпадают.');

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_close_when_matched_opens_one_supplier_bill_without_stock(): void
    {
        $supplier = Supplier::create([
            'name' => 'ООО Снэк',
            'inn' => '7701234567',
            'payment_terms_days' => 7,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Cola 0.5',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 3,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
            'barcode' => '4601234567890',
            'supplier_id' => $supplier->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/inventory/close-invoice', [
                'supplier_id' => $supplier->id,
                'invoice_number' => 'УПД-77',
                'invoice_date' => '2026-09-16',
                'lines' => [[
                    'product_id' => $product->id,
                    'name' => 'Cola 0.5',
                    'qty' => 24,
                    'scanned_qty' => 24,
                    'unit_cost' => 40,
                ]],
                'extras' => [],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'matched')
            ->assertJsonPath('invoice_number', 'УПД-77');

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('supplier_invoices', [
            'supplier_id' => $supplier->id,
            'number' => 'УПД-77',
            'total_amount' => 960,
            'status' => SupplierInvoice::STATUS_OPEN,
        ]);
    }

    public function test_hydrate_matches_product_by_barcode(): void
    {
        $product = Product::create([
            'name' => 'Sprite',
            'category' => 'Напитки',
            'price' => 90,
            'stock' => 1,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
            'barcode' => '4600000000123',
        ]);

        $draft = app(InventoryInvoiceOcrService::class)->hydrateDraft([
            'invoice_number' => '1',
            'lines' => [[
                'name' => 'Sprite 0.5',
                'qty' => 6,
                'barcode' => '4600000000123',
                'unit_cost' => 30,
            ]],
        ]);

        $this->assertSame($product->id, $draft['lines'][0]['product_id']);
        $this->assertSame('barcode', $draft['lines'][0]['match']);
        $this->assertSame(0, $draft['lines'][0]['scanned_qty']);
        $this->assertSame(1, (int) $product->fresh()->stock);
    }
}
