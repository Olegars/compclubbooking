<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Space;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Zone;
use App\Services\PartyBookingService;
use App\Support\OrderChannel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_consecutive_seats_in_one_zone(): void
    {
        $club = Club::create(['name' => 'Party Club', 'slug' => 'party-club']);
        $zoneA = Zone::create(['name' => 'Standard', 'slug' => 'party-standard']);
        $zoneB = Zone::create(['name' => 'VIP', 'slug' => 'party-vip']);
        $spaceA = Space::create([
            'club_id' => $club->id,
            'zone_id' => $zoneA->id,
            'name' => 'Row-A',
            'x' => 0,
            'y' => 0,
            'w' => 40,
            'h' => 10,
        ]);
        $spaceB = Space::create([
            'club_id' => $club->id,
            'zone_id' => $zoneB->id,
            'name' => 'Row-B',
            'x' => 0,
            'y' => 20,
            'w' => 40,
            'h' => 10,
        ]);
        $pcs = [];
        foreach ([1, 2, 3, 5, 6] as $n) {
            $pcs[$n] = Computer::create([
                'club_id' => $club->id,
                'name' => 'PC-0'.$n,
                'status' => 'available',
                'kind' => 'pc',
                'type' => 'standard',
                'space_id' => $spaceA->id,
            ]);
        }
        Computer::create([
            'club_id' => $club->id,
            'name' => 'PC-04',
            'status' => 'available',
            'kind' => 'pc',
            'type' => 'vip',
            'space_id' => $spaceB->id,
        ]);

        $service = app(PartyBookingService::class);
        $ids = $service->suggestConsecutive($pcs, [], 3, $pcs[2]->id);
        $this->assertSame([$pcs[1]->id, $pcs[2]->id, $pcs[3]->id], $ids);

        $blocked = $service->suggestConsecutive($pcs, [$pcs[2]->id], 3);
        $this->assertSame([], $blocked);
    }

    public function test_shell_party_checkout_keeps_captain_pc_name(): void
    {
        $user = User::create([
            'name' => 'Captain',
            'phone' => '+79991110001',
            'email' => 'captain@party.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 1000,
            'bonus_balance' => 0,
            'total_spent' => 0,
        ]);
        $club = Club::create(['name' => 'Party Club', 'slug' => 'party-checkout']);
        $pc1 = Computer::create([
            'club_id' => $club->id,
            'name' => 'PC-08',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $pc2 = Computer::create([
            'club_id' => $club->id,
            'name' => 'PC-09',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $product = Product::create([
            'name' => 'Энергетик',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);

        $startsAt = CarbonImmutable::now()->addHour();
        $endsAt = $startsAt->addHour();
        $group = BookingGroup::create([
            'user_id' => $user->id,
            'club_id' => $club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 20000,
            'games_total_minor' => 0,
            'total_minor' => 20000,
            'paid_total_minor' => 20000,
            'paid_at' => $startsAt->subDay(),
        ]);

        $this->makeSeat($group, $user, $pc1, $startsAt, $endsAt);
        $this->makeSeat($group, $user, $pc2, $startsAt, $endsAt);

        $this->postJson('/api/shell/checkout', [
            'terminal_id' => $pc1->id,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'for_party' => true,
        ])->assertOk();

        $order = Order::query()->first();
        $this->assertNotNull($order);
        $this->assertSame(OrderChannel::SHELL, $order->channel);
        $this->assertSame('PC-08', $order->pc_name);
        $this->assertStringStartsWith('Пати ', $order->product_name);
        $this->assertStringContainsString('PC-08', $order->product_name);
        $this->assertStringContainsString('PC-09', $order->product_name);
        $this->assertSame(9, (int) $product->fresh()->stock);
    }

    private function makeSeat(
        BookingGroup $group,
        User $user,
        Computer $pc,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): Booking {
        $local = $startsAt->timezone(config('app.timezone'));

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
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
