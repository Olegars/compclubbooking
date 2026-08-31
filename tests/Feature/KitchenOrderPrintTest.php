<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderKitchenPrint;
use App\Models\User;
use App\Services\KitchenOrderPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'kitchen-relay-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kitchen_print.enabled' => true,
            'kitchen_print.relay_token' => $this->token,
            'kitchen_print.printer_host' => '10.0.0.50',
            'kitchen_print.printer_port' => 9100,
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::create([
            'name' => 'Guest',
            'phone' => '+79990001133',
            'email' => 'kitchen@example.test',
            'password' => 'password',
        ]);

        return Order::create([
            'user_id' => $user->id,
            'product_name' => 'Энергетик ×2, Сэндвич',
            'items' => [
                ['product_id' => 1, 'name' => 'Энергетик', 'qty' => 2, 'unit_price' => 100, 'line_total' => 200],
                ['product_id' => 2, 'name' => 'Сэндвич', 'qty' => 1, 'unit_price' => 150, 'line_total' => 150],
            ],
            'price' => 350,
            'pc_name' => 'ПК-08',
            'status' => 'pending',
        ]);
    }

    public function test_enqueue_builds_slip_and_job(): void
    {
        $order = $this->makeOrder();
        $job = app(KitchenOrderPrintService::class)->enqueue($order);

        $this->assertNotNull($job);
        $this->assertSame(OrderKitchenPrint::STATUS_PENDING, $job->status);
        $this->assertStringContainsString('ПК-08 | #'.$order->id, $job->payload_text);
        $this->assertStringContainsString('2x Энергетик', $job->payload_text);
        $this->assertStringContainsString('1x Сэндвич', $job->payload_text);
        $this->assertStringNotContainsString('ПРЕДЗАКАЗ', $job->payload_text);
    }

    public function test_enqueue_skipped_when_disabled(): void
    {
        config(['kitchen_print.enabled' => false]);
        $order = $this->makeOrder();

        $this->assertNull(app(KitchenOrderPrintService::class)->enqueue($order));
        $this->assertSame(0, OrderKitchenPrint::query()->count());
    }

    public function test_escpos_contains_init_and_cut(): void
    {
        $raw = app(KitchenOrderPrintService::class)->buildEscPos("ПК-01 | #1\n1x Cola\n");
        $this->assertStringStartsWith("\x1B@", $raw);
        $this->assertStringContainsString("\x1DV\x00", $raw);
    }

    public function test_relay_claim_and_applied(): void
    {
        $order = $this->makeOrder();
        app(KitchenOrderPrintService::class)->enqueue($order);

        $claim = $this->getJson('/api/kitchen/print-targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('count', 1);

        $jobId = (int) $claim->json('jobs.0.id');
        $this->assertNotEmpty($claim->json('jobs.0.escpos_base64'));
        $this->assertDatabaseHas('order_kitchen_prints', [
            'id' => $jobId,
            'status' => OrderKitchenPrint::STATUS_CLAIMED,
        ]);

        $this->postJson('/api/kitchen/print-applied', [
            'token' => $this->token,
            'printed_ids' => [$jobId],
        ])->assertOk()->assertJsonPath('printed', 1);

        $this->assertDatabaseHas('order_kitchen_prints', [
            'id' => $jobId,
            'status' => OrderKitchenPrint::STATUS_PRINTED,
        ]);
    }

    public function test_relay_rejects_bad_token(): void
    {
        $this->getJson('/api/kitchen/print-targets?token=wrong')
            ->assertStatus(401);
    }

    public function test_preorders_of_same_client_print_as_one_slip(): void
    {
        $user = User::create([
            'name' => 'Guest',
            'phone' => '+79990001144',
            'email' => 'kitchen-pre@example.test',
            'password' => 'password',
        ]);

        $first = Order::create([
            'user_id' => $user->id,
            'product_name' => 'Snickers Super',
            'items' => [
                ['product_id' => 1, 'name' => 'Snickers Super', 'qty' => 1, 'unit_price' => 90, 'line_total' => 90],
            ],
            'price' => 90,
            'pc_name' => 'PC-1',
            'status' => 'pending',
            'fulfill_at' => now()->subMinute(),
            'session_starts_at' => now()->addMinutes(6),
        ]);

        app(KitchenOrderPrintService::class)->enqueue($first);
        $this->assertSame(1, OrderKitchenPrint::query()->count());

        $second = Order::create([
            'user_id' => $user->id,
            'product_name' => 'Пепсикола',
            'items' => [
                ['product_id' => 2, 'name' => 'Пепсикола', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100],
            ],
            'price' => 100,
            'pc_name' => 'PC-1',
            'status' => 'pending',
            'fulfill_at' => now()->subMinute(),
            'session_starts_at' => now()->addMinutes(6),
        ]);

        $job = app(KitchenOrderPrintService::class)->enqueue($second);

        $this->assertNotNull($job);
        $this->assertSame(1, OrderKitchenPrint::query()->where('status', OrderKitchenPrint::STATUS_PENDING)->count());
        $this->assertSame($first->id, $job->order_id);
        $this->assertStringContainsString('PC-1 | #'.$first->id.', #'.$second->id, $job->payload_text);
        $this->assertStringContainsString('1x Snickers Super', $job->payload_text);
        $this->assertStringContainsString('1x Пепсикола', $job->payload_text);
        $this->assertStringContainsString('ПРЕДЗАКАЗ', $job->payload_text);
    }
}
