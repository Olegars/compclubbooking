<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\InventoryCostService;
use App\Services\ProductStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryCostTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'inv-cost@test.local',
            'password' => 'password',
            'role' => 'supervisor',
        ]);
        $this->adminId = (int) $admin->id;
    }

    public function test_receive_updates_avg_cost_batch_and_invoice(): void
    {
        $supplier = Supplier::create([
            'name' => 'ООО Снэк',
            'payment_terms_days' => 7,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Cola',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 0,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $stock = app(ProductStockService::class);
        $stock->receiveUnmarked($product, 10, $this->adminId, 40.0, $supplier->id);

        $product->refresh();
        $this->assertSame(10, (int) $product->stock);
        $this->assertEquals(40.0, (float) $product->cost_price);
        $this->assertSame($supplier->id, (int) $product->supplier_id);

        $this->assertDatabaseHas('inventory_batches', [
            'product_id' => $product->id,
            'qty_remaining' => 10,
            'unit_cost' => 40,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RECEIVE,
            'qty' => 10,
        ]);

        $this->assertDatabaseHas('supplier_invoices', [
            'supplier_id' => $supplier->id,
            'total_amount' => 400,
            'status' => SupplierInvoice::STATUS_OPEN,
        ]);

        $stock->receiveUnmarked($product->fresh(), 10, $this->adminId, 60.0, $supplier->id);
        $product->refresh();
        $this->assertEquals(50.0, (float) $product->cost_price);
        $this->assertSame(20, (int) $product->stock);
    }

    public function test_fifo_consume_on_sale(): void
    {
        $product = Product::create([
            'name' => 'Chips',
            'category' => 'Снэки',
            'price' => 80,
            'stock' => 0,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $stock = app(ProductStockService::class);
        $stock->receiveUnmarked($product, 5, $this->adminId, 20.0, null, false);
        $stock->receiveUnmarked($product->fresh(), 5, $this->adminId, 40.0, null, false);

        $stock->decrementUnmarked($product->fresh(), 6);

        $product->refresh();
        $this->assertSame(4, (int) $product->stock);

        $batches = InventoryBatch::query()->where('product_id', $product->id)->orderBy('id')->get();
        $this->assertSame(0, (int) $batches[0]->qty_remaining);
        $this->assertSame(4, (int) $batches[1]->qty_remaining);

        $sale = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::TYPE_SALE)
            ->latest('id')
            ->first();

        $this->assertNotNull($sale);
        $this->assertEquals(140.0, (float) ($sale->meta['cogs'] ?? 0)); // 5*20 + 1*40
    }

    public function test_invoice_partial_payment(): void
    {
        $supplier = Supplier::create(['name' => 'Vendor', 'payment_terms_days' => 0, 'is_active' => true]);
        $invoice = SupplierInvoice::create([
            'supplier_id' => $supplier->id,
            'number' => 'INV-1',
            'issued_at' => now()->toDateString(),
            'due_at' => now()->toDateString(),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'status' => SupplierInvoice::STATUS_OPEN,
        ]);

        $costs = app(InventoryCostService::class);
        $costs->addPayment($invoice, 400, $this->adminId, 'часть');
        $invoice->refresh();
        $this->assertSame(SupplierInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertEquals(600.0, $invoice->balanceDue());

        $costs->addPayment($invoice->fresh(), 600, $this->adminId);
        $invoice->refresh();
        $this->assertSame(SupplierInvoice::STATUS_PAID, $invoice->status);
    }

    public function test_low_stock_incident_deduped(): void
    {
        Product::create([
            'name' => 'Water',
            'category' => 'Напитки',
            'price' => 50,
            'stock' => 2,
            'min_stock' => 5,
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);

        Artisan::call('reactor:check-quality');
        Artisan::call('reactor:check-quality');

        $this->assertSame(1, DB::table('incidents')->where('type', 'low_stock')->count());
    }
}
