<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Order;
use App\Models\OrderKitchenPrint;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PreSessionOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreSessionShopOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private Computer $computer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kitchen_print.enabled' => true]);

        $this->user = User::create([
            'name' => 'Shop Tester',
            'phone' => '+79991112233',
            'email' => 'shop-pre@example.test',
            'password' => 'password',
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => 1000,
            'bonus_balance' => 0,
            'total_spent' => 0,
        ]);

        $this->club = Club::create([
            'name' => 'Test Club',
            'slug' => 'shop-pre-club',
        ]);

        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-08',
            'status' => 'available',
        ]);

        $this->product = Product::create([
            'name' => 'Энергетик',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    public function test_checkout_without_session_or_booking_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ])
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'Нет активной сессии в клубе. Заказ можно оформить только когда вы сидите за ПК',
            ]);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_checkout_with_future_booking_is_scheduled_and_hidden_from_admin(): void
    {
        $startsAt = CarbonImmutable::now()->addHour();
        $this->makeBooking($startsAt, $startsAt->addHour());

        $response = $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ]);

        $response->assertOk()
            ->assertJsonPath('scheduled', true)
            ->assertJsonPath('message', PreSessionOrderService::bookingDeliveredMessage('PC-08'));

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertSame(Order::STATUS_SCHEDULED, $order->status);
        $this->assertSame('PC-08', $order->pc_name);
        $this->assertSame(0, OrderKitchenPrint::query()->count());

        $this->actingAs($this->user)
            ->getJson('/api/shop/delivery-context')
            ->assertOk()
            ->assertJsonPath('mode', 'booking');

        $this->assertSame(0, Order::query()->whereIn('status', ['pending', 'cooking'])->count());
    }

    public function test_scheduled_order_releases_seven_minutes_before_session(): void
    {
        $startsAt = CarbonImmutable::now()->addHour();
        $booking = $this->makeBooking($startsAt, $startsAt->addHour());

        $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ])
            ->assertOk();

        $order = Order::query()->first();
        $this->assertSame(Order::STATUS_SCHEDULED, $order->status);

        $released = app(PreSessionOrderService::class)
            ->releaseDueOrders($startsAt->subMinutes(7));

        $this->assertSame(1, $released);
        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertNotNull($order->released_at);
        $this->assertSame(1, OrderKitchenPrint::query()->where('order_id', $order->id)->count());
        $this->assertSame($booking->id, $order->booking_id);
    }

    public function test_shell_im_here_releases_scheduled_order(): void
    {
        $startsAt = CarbonImmutable::now()->addHour();
        $this->makeBooking($startsAt, $startsAt->addHour());

        $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ])
            ->assertOk();

        $status = $this->getJson('/api/shell/store/order-status?terminal_id='.$this->computer->id);
        $status->assertOk()->assertJsonPath('has_scheduled_order', true);

        $this->postJson('/api/shell/store/release-scheduled', [
            'terminal_id' => $this->computer->id,
        ])
            ->assertOk()
            ->assertJsonPath('released', 1)
            ->assertJsonPath('has_scheduled_order', false)
            ->assertJsonPath('has_active_order', true);

        $this->assertSame(Order::STATUS_PENDING, Order::query()->value('status'));
    }

    public function test_booking_within_seven_minutes_goes_to_admin_immediately(): void
    {
        $startsAt = CarbonImmutable::now()->addMinutes(3);
        $this->makeBooking($startsAt, $startsAt->addHour());

        $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ])
            ->assertOk()
            ->assertJsonPath('scheduled', false);

        $this->assertSame(Order::STATUS_PENDING, Order::query()->value('status'));
        $this->assertSame(1, OrderKitchenPrint::query()->count());
    }

    public function test_two_scheduled_preorders_print_as_one_slip_on_release(): void
    {
        $startsAt = CarbonImmutable::now()->addHour();
        $this->makeBooking($startsAt, $startsAt->addHour());

        $other = Product::create([
            'name' => 'Пепсикола',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/shop/checkout', [
                'items' => [['product_id' => $other->id, 'qty' => 1]],
                'order_type' => 'desktop',
                'payment_method' => 'account',
            ])
            ->assertOk();

        $this->assertSame(2, Order::query()->where('status', Order::STATUS_SCHEDULED)->count());
        $this->assertSame(0, OrderKitchenPrint::query()->count());

        $released = app(PreSessionOrderService::class)
            ->releaseDueOrders($startsAt->subMinutes(7));

        $this->assertSame(2, $released);
        $this->assertSame(1, OrderKitchenPrint::query()->count());

        $job = OrderKitchenPrint::query()->first();
        $ids = Order::query()->orderBy('id')->pluck('id');
        $this->assertStringContainsString('#'.$ids[0].', #'.$ids[1], $job->payload_text);
        $this->assertStringContainsString('1x Энергетик', $job->payload_text);
        $this->assertStringContainsString('1x Пепсикола', $job->payload_text);
        $this->assertStringContainsString('ПРЕДЗАКАЗ', $job->payload_text);
    }

    private function makeBooking(CarbonImmutable $startsAt, CarbonImmutable $endsAt): Booking
    {
        $local = $startsAt->timezone(config('app.timezone'));
        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $startsAt->subDay(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => $startsAt->diffInMinutes($endsAt) / 60,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'confirmed',
            'pin_code' => '1234',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
