<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderQueuePollTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_the_queue(): void
    {
        $this->get('/admin/api/orders-queue')->assertRedirect('/admin/login');
    }

    public function test_queue_is_json_and_unchanged_poll_is_not_modified(): void
    {
        $admin = $this->makeSupervisor();
        $order = $this->makeOrder('pending');
        $this->makeOrder('delivered', '+79990001134', 'done@example.test');

        $first = $this->actingAs($admin, 'admin')
            ->getJson('/admin/api/orders-queue')
            ->assertOk()
            ->assertJsonPath('orders.0.id', $order->id)
            ->assertJsonCount(1, 'orders');

        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($admin, 'admin')
            ->withHeaders(['If-None-Match' => $etag])
            ->get('/admin/api/orders-queue')
            ->assertStatus(304);

        $this->makeOrder('cooking', '+79990001135', 'cook@example.test');

        $this->actingAs($admin, 'admin')
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson('/admin/api/orders-queue')
            ->assertOk()
            ->assertJsonCount(2, 'orders');
    }

    private function makeSupervisor(): Admin
    {
        return Admin::query()->create([
            'name' => 'Supervisor '.uniqid(),
            'email' => 'sup.'.uniqid().'@queue.test',
            'password' => 'password',
            'role' => 'supervisor',
            'pay_type' => 'shift',
        ]);
    }

    private function makeOrder(string $status, string $phone = '+79990001133', string $email = 'queue@example.test'): Order
    {
        $user = User::create([
            'name' => 'Guest',
            'phone' => $phone,
            'email' => $email,
            'password' => 'password',
        ]);

        return Order::create([
            'user_id' => $user->id,
            'product_name' => 'Энергетик',
            'items' => [
                ['product_id' => 1, 'name' => 'Энергетик', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100],
            ],
            'price' => 100,
            'pc_name' => 'ПК-08',
            'channel' => 'shell',
            'status' => $status,
        ]);
    }
}
