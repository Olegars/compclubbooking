<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LuckySeatDrop;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreOrder;
use App\Models\StorePromoCode;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LanLive\LuckySeatLootService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LuckySeatLootTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        LuckySeatLootService::flushSessions();
        $this->club = Club::create(['name' => 'Loot Club', 'slug' => 'lucky-seat-club']);
    }

    public function test_five_round_wins_drop_pending_case(): void
    {
        [$user, $pc, $booking] = $this->seat();

        for ($i = 0; $i < 4; $i++) {
            $this->gsi($pc, $booking, 'round_win')
                ->assertOk()
                ->assertJsonPath('lootbox_dropped', null);
        }

        $this->gsi($pc, $booking, 'round_win')
            ->assertOk()
            ->assertJsonPath('lootbox_dropped.status', 'pending')
            ->assertJsonPath('lootbox.status', 'pending');

        $this->assertSame(1, LuckySeatDrop::query()->count());
        $this->assertSame(LuckySeatDrop::TRIGGER_WIN_STREAK, LuckySeatDrop::query()->first()->trigger);
    }

    public function test_round_loss_resets_streak(): void
    {
        [$user, $pc, $booking] = $this->seat();

        for ($i = 0; $i < 4; $i++) {
            $this->gsi($pc, $booking, 'round_win')->assertOk();
        }
        $this->gsi($pc, $booking, 'round_loss')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->gsi($pc, $booking, 'round_win')->assertOk();
        }

        $this->assertSame(0, LuckySeatDrop::query()->count());
    }

    public function test_opening_bonus_credits_wallet(): void
    {
        LuckySeatLootService::$forceReward = LuckySeatDrop::REWARD_BONUS;
        [$user, $pc, $booking] = $this->seat();
        $drop = $this->forceDrop($pc, $booking);

        $this->postJson('/api/shell/lootbox/'.$drop->id.'/open', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
        ])->assertOk()
            ->assertJsonPath('lootbox.status', 'opened')
            ->assertJsonPath('lootbox.reward_type', 'bonus');

        $user->refresh();
        $this->assertContains((int) $user->wallet->bonus_balance, LuckySeatLootService::BONUS_AMOUNTS);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'source' => 'lucky_seat',
        ]);
    }

    public function test_opening_drink_prints_bar_order(): void
    {
        LuckySeatLootService::$forceReward = LuckySeatDrop::REWARD_DRINK;
        [$user, $pc, $booking] = $this->seat();
        Product::create([
            'name' => 'Adrenaline',
            'category' => 'Напитки',
            'price' => 120,
            'stock' => 3,
            'is_active' => true,
            'requires_marking' => false,
        ]);
        $drop = $this->forceDrop($pc, $booking);

        $this->postJson('/api/shell/lootbox/'.$drop->id.'/open', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
        ])->assertOk()
            ->assertJsonPath('lootbox.reward_type', 'drink');

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertStringContainsString('LUCKY SEAT', (string) $order->product_name);
        $this->assertStringContainsString('Adrenaline', (string) $order->product_name);
        $this->assertSame(2, (int) Product::query()->first()->stock);
    }

    public function test_opening_store_promo_and_applying_at_store(): void
    {
        LuckySeatLootService::$forceReward = LuckySeatDrop::REWARD_STORE_PROMO;
        [$user, $pc, $booking] = $this->seat();
        $drop = $this->forceDrop($pc, $booking);

        $open = $this->postJson('/api/shell/lootbox/'.$drop->id.'/open', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
        ])->assertOk();
        $code = $open->json('lootbox.reward.code');
        $this->assertNotEmpty($code);
        $this->assertDatabaseHas('store_promo_codes', [
            'code' => $code,
            'user_id' => $user->id,
            'status' => StorePromoCode::STATUS_ACTIVE,
        ]);

        $admin = Admin::query()->create([
            'name' => 'Store Admin',
            'email' => 'store-loot@test.local',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => $this->club->id,
        ]);
        $client = StoreClient::query()->create([
            'club_id' => $this->club->id,
            'name' => $user->name,
            'phone' => $user->phone,
        ]);
        $component = StoreComponent::query()->create([
            'club_id' => $this->club->id,
            'name' => 'Logitech G Pro',
            'type' => 'other',
            'purchase_price' => 10000,
            'status' => 'in_stock',
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/store/orders', [
            'store_client_id' => $client->id,
            'promo_code' => $code,
            'items' => [['store_component_id' => $component->id, 'qty' => 1]],
        ])->assertRedirect();

        $order = StoreOrder::query()->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(9000.0, (float) $order->total, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $order->discount_amount, 0.01);
        $this->assertSame(StorePromoCode::STATUS_USED, StorePromoCode::query()->first()->status);
    }

    public function test_playtime_drop_after_three_hours(): void
    {
        $start = CarbonImmutable::now()->subHours(3)->subMinutes(5);
        [$user, $pc, $booking] = $this->seat($start);

        $this->gsi($pc, $booking, 'heartbeat')
            ->assertOk()
            ->assertJsonPath('lootbox_dropped.trigger', LuckySeatDrop::TRIGGER_PLAYTIME);

        $this->assertSame(1, LuckySeatDrop::query()->count());
    }

    public function test_cooldown_blocks_second_drop(): void
    {
        LuckySeatLootService::$forceReward = LuckySeatDrop::REWARD_BONUS;
        [$user, $pc, $booking] = $this->seat();
        $drop = $this->forceDrop($pc, $booking);
        $this->postJson('/api/shell/lootbox/'.$drop->id.'/open', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
        ])->assertOk();

        LuckySeatLootService::flushSessions();
        LuckySeatLootService::$forceReward = LuckySeatDrop::REWARD_BONUS;
        for ($i = 0; $i < 6; $i++) {
            $this->gsi($pc, $booking, 'round_win')->assertOk();
        }

        $this->assertSame(1, LuckySeatDrop::query()->count());
    }

    /**
     * @return array{0: User, 1: Computer, 2: Booking}
     */
    private function seat(?CarbonImmutable $start = null): array
    {
        $user = User::create([
            'name' => 'Looter',
            'phone' => '79001110999',
            'email' => 'looter@lan.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 200,
            'bonus_balance' => 0,
        ]);
        $pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-77',
            'status' => 'busy',
            'kind' => 'pc',
        ]);
        $start = $start ?? CarbonImmutable::now()->subMinutes(20);
        $end = $start->addHours(5);
        $local = $start->timezone(config('app.timezone'));
        $booking = Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => 5,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => $end,
            'actual_started_at' => $start,
        ]);

        return [$user, $pc, $booking];
    }

    private function gsi(Computer $pc, Booking $booking, string $event)
    {
        return $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
            'event' => $event,
            'game' => 'cs2',
            'in_match' => true,
        ]);
    }

    private function forceDrop(Computer $pc, Booking $booking): LuckySeatDrop
    {
        for ($i = 0; $i < 5; $i++) {
            $this->gsi($pc, $booking, 'round_win')->assertOk();
        }

        $drop = LuckySeatDrop::query()->first();
        $this->assertNotNull($drop);

        return $drop;
    }
}
