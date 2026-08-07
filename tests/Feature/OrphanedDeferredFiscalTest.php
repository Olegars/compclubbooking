<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FiscalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrphanedDeferredFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_settle_orphaned_deferred_after_completed_no_show(): void
    {
        config(['fiscal.enabled' => false]);

        $user = User::create([
            'name' => 'Fiscal Tester',
            'phone' => '+79990000107',
            'email' => 'fiscal107@example.test',
            'password' => 'password',
        ]);

        $club = Club::create([
            'name' => 'Fiscal Club',
            'slug' => 'fiscal-club',
        ]);

        $computer = Computer::create([
            'club_id' => $club->id,
            'name' => 'PC-107',
            'status' => 'available',
        ]);

        $startsAt = CarbonImmutable::parse('2026-08-07 19:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();

        $group = BookingGroup::create([
            'user_id' => $user->id,
            'club_id' => $club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 30000,
            'games_total_minor' => 0,
            'total_minor' => 30000,
            'paid_total_minor' => 30000,
            'paid_at' => $startsAt->subHour(),
        ]);

        Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $user->id,
            'computer_id' => $computer->id,
            'pc_ids' => [(string) $computer->id],
            'date' => $startsAt->toDateString(),
            'start_time' => 19.0,
            'duration' => 1,
            'price' => 300,
            'price_minor' => 30000,
            'status' => 'completed',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $tx = Transaction::create([
            'user_id' => $user->id,
            'booking_group_id' => $group->id,
            'amount' => -300,
            'type' => 'booking',
            'source' => 'wallet',
            'description' => 'Бронь #107: компьютеры',
            'fiscal_mode' => FiscalService::MODE_SETTLEMENT,
            'fiscal_status' => 'deferred',
        ]);

        $settled = app(FiscalService::class)->settleOrphanedDeferredBookings();

        $this->assertSame(1, $settled);
        $tx->refresh();
        $this->assertSame('skipped', $tx->fiscal_status);
        $this->assertNotEmpty($tx->fiscal_receipt_url);
    }
}
