<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGame;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\ClubGame;
use App\Models\Computer;
use App\Models\ComputerGame;
use App\Models\Game;
use App\Models\GameAccount;
use App\Models\GameAccountReservation;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedGameBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private CarbonImmutable $startsAt;

    private CarbonImmutable $endsAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Booking Tester',
            'phone' => '+79990000001',
            'email' => 'booking@example.test',
            'password' => 'password',
        ]);

        $this->club = Club::create([
            'name' => 'Test Club',
            'slug' => 'test-club',
        ]);

        Tariff::create([
            'name' => 'One hour',
            'category' => 'standard',
            'threshold_hours' => 1,
            'price_per_package' => 100,
            'is_active' => true,
        ]);

        $this->startsAt = CarbonImmutable::now()
            ->addDays(7)
            ->startOfDay()
            ->addHours(10);
        $this->endsAt = $this->startsAt->addHour();
    }

    public function test_free_game_cannot_be_reserved_via_booking_api(): void
    {
        $computer = $this->createComputer();
        $game = $this->createGameOffer([$computer], 'free', 0);
        $this->createAccount($game);
        $this->fundWallet(500);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'game_ids' => [$game->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('game_ids');

        $this->assertDatabaseCount('booking_groups', 0);
        $this->assertDatabaseCount('game_account_reservations', 0);
    }

    public function test_paid_game_is_reserved_with_game_charge(): void
    {
        $computer = $this->createComputer();
        $game = $this->createGameOffer([$computer], 'fixed', 5000);
        $account = $this->createAccount($game);
        $this->fundWallet(500);

        $response = $this->actingAs($this->user)->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computer->id],
            'game_ids' => [$game->id],
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('pricing.computers_total_minor', 10000)
            ->assertJsonPath('pricing.games_total_minor', 5000)
            ->assertJsonPath('pricing.total_minor', 15000);

        $groupId = $response->json('booking_group_id');
        $this->assertDatabaseHas('booking_groups', [
            'id' => $groupId,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'games_total_minor' => 5000,
            'total_minor' => 15000,
        ]);
        $this->assertDatabaseHas('booking_games', [
            'booking_group_id' => $groupId,
            'billing_mode' => 'fixed',
            'line_total_minor' => 5000,
        ]);
        $this->assertDatabaseHas('game_account_reservations', [
            'game_account_id' => $account->id,
            'status' => 'confirmed',
        ]);
        $this->assertSame(350.0, Wallet::where('user_id', $this->user->id)->firstOrFail()->depositAmount());
    }

    public function test_availability_lists_only_paid_games_and_keeps_sold_out_inactive(): void
    {
        $computer = $this->createComputer();
        $paid = $this->createGameOffer([$computer], 'fixed', 1000, 60, 'Paid Game');
        $free = $this->createGameOffer([$computer], 'free', 0, 60, 'Free Game');
        $this->createAccount($paid);
        $this->createAccount($free);

        $response = $this->actingAs($this->user)
            ->postJson('/api/booking/games/availability', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertOk();

        $ids = collect($response->json('games'))->pluck('id');
        $this->assertTrue($ids->contains($paid->id));
        $this->assertFalse($ids->contains($free->id));
        $this->assertTrue($response->json('games.0.is_paid'));
        $this->assertTrue($response->json('games.0.is_available'));

        // Без свободных аккаунтов платная игра остаётся в списке, но неактивна.
        GameAccount::query()->where('game_id', $paid->id)->update(['status' => 'banned', 'is_enabled' => false]);

        $soldOut = $this->actingAs($this->user)
            ->postJson('/api/booking/games/availability', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertOk();

        $this->assertSame($paid->id, $soldOut->json('games.0.id'));
        $this->assertFalse($soldOut->json('games.0.is_available'));
    }

    public function test_per_seat_hour_game_price_uses_rounded_up_units_for_every_computer(): void
    {
        $computers = [$this->createComputer('PC-1'), $this->createComputer('PC-2')];
        $game = $this->createGameOffer($computers, 'per_seat_hour', 3000, 60);
        $this->createAccount($game, 'account-1');
        $this->createAccount($game, 'account-2');

        $endsAt = $this->startsAt->addMinutes(90);

        $this->actingAs($this->user)
            ->postJson('/api/booking/calculate-price', [
                'club_id' => $this->club->id,
                'pc_ids' => collect($computers)->pluck('id')->all(),
                'game_ids' => [$game->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('duration_minutes', 90)
            ->assertJsonPath('computers_total_minor', 30000)
            ->assertJsonPath('games_total_minor', 12000)
            ->assertJsonPath('total_minor', 42000)
            ->assertJsonPath('games.0.quantity', 2)
            ->assertJsonPath('games.0.line_total_minor', 12000);
    }

    public function test_multiple_computers_receive_specific_distinct_accounts(): void
    {
        $computerOne = $this->createComputer('PC-1');
        $computerTwo = $this->createComputer('PC-2');
        $game = $this->createGameOffer([$computerOne, $computerTwo]);
        $accountOne = $this->createAccount($game, 'account-1');
        $accountTwo = $this->createAccount($game, 'account-2');
        $this->fundWallet(1000);

        $response = $this->actingAs($this->user)->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computerOne->id, $computerTwo->id],
            'game_ids' => [$game->id],
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ])->assertCreated();

        $group = BookingGroup::with('games.reservations.booking')
            ->findOrFail($response->json('booking_group_id'));
        $reservations = $group->games->sole()->reservations
            ->sortBy('id')
            ->values();

        $this->assertCount(2, $group->bookings);
        $this->assertSame(
            [$accountOne->id, $accountTwo->id],
            $reservations->pluck('game_account_id')->all()
        );
        $this->assertSame($computerOne->id, $reservations[0]->booking->computer_id);
        $this->assertSame($computerTwo->id, $reservations[1]->booking->computer_id);
        $this->assertSame(2, $reservations->pluck('game_account_id')->unique()->count());
    }

    public function test_reservation_fails_atomically_when_there_are_not_enough_accounts(): void
    {
        $computers = [$this->createComputer('PC-1'), $this->createComputer('PC-2')];
        $game = $this->createGameOffer($computers);
        $this->createAccount($game);
        $this->fundWallet(1000);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => collect($computers)->pluck('id')->all(),
                'game_ids' => [$game->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('game_ids');

        $this->assertDatabaseCount('booking_groups', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('game_account_reservations', 0);
        $this->assertSame(1000.0, Wallet::where('user_id', $this->user->id)->firstOrFail()->depositAmount());
    }

    public function test_reservation_fails_when_game_is_not_installed_on_every_selected_computer(): void
    {
        $computerOne = $this->createComputer('PC-1');
        $computerTwo = $this->createComputer('PC-2');
        $game = $this->createGameOffer([$computerOne]);
        $this->createAccount($game, 'account-1');
        $this->createAccount($game, 'account-2');
        $this->fundWallet(1000);

        ComputerGame::create([
            'computer_id' => $computerTwo->id,
            'game_id' => $game->id,
            'is_installed' => false,
            'verified_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computerOne->id, $computerTwo->id],
                'game_ids' => [$game->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('game_ids');

        $this->assertDatabaseCount('booking_groups', 0);
        $this->assertDatabaseCount('game_account_reservations', 0);
    }

    public function test_reservation_rejects_a_computer_from_another_club(): void
    {
        $otherClub = Club::create([
            'name' => 'Other Club',
            'slug' => 'other-club',
        ]);
        $foreignComputer = $this->createComputer('Foreign PC', $otherClub);
        $this->fundWallet(1000);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$foreignComputer->id],
                'game_ids' => [],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pc_ids');

        $this->assertDatabaseCount('booking_groups', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_reservation_fails_atomically_when_balance_is_insufficient(): void
    {
        $computer = $this->createComputer();
        $game = $this->createGameOffer([$computer]);
        $this->createAccount($game);
        $this->fundWallet(99);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'game_ids' => [$game->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance');

        $this->assertDatabaseCount('booking_groups', 0);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(99.0, Wallet::where('user_id', $this->user->id)->firstOrFail()->depositAmount());
    }

    public function test_cancellation_fully_releases_resources_and_refunds_only_once(): void
    {
        $computer = $this->createComputer();
        $game = $this->createGameOffer([$computer], 'per_seat_hour', 3000);
        $this->createAccount($game);
        $this->fundWallet(500);

        $reserveResponse = $this->actingAs($this->user)->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computer->id],
            'game_ids' => [$game->id],
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ])->assertCreated();

        $groupId = $reserveResponse->json('booking_group_id');
        $this->assertSame(370.0, Wallet::where('user_id', $this->user->id)->firstOrFail()->depositAmount());

        $cancelUrl = "/api/booking/{$groupId}/cancel";
        $this->postJson($cancelUrl)
            ->assertOk()
            ->assertJson([
                'booking_group_id' => $groupId,
                'booking_status' => 'cancelled',
                'payment_status' => 'refunded',
                'refunded_total_minor' => 13000,
            ]);
        $this->postJson($cancelUrl)
            ->assertOk()
            ->assertJson([
                'booking_group_id' => $groupId,
                'booking_status' => 'cancelled',
                'payment_status' => 'refunded',
                'refunded_total_minor' => 13000,
            ]);

        $this->assertSame(500.0, Wallet::where('user_id', $this->user->id)->firstOrFail()->depositAmount());
        $this->assertDatabaseHas('booking_groups', [
            'id' => $groupId,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'paid_total_minor' => 13000,
            'refunded_total_minor' => 13000,
        ]);
        $this->assertDatabaseMissing('bookings', [
            'booking_group_id' => $groupId,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseMissing('game_account_reservations', [
            'status' => 'confirmed',
        ]);
        $this->assertNotNull(
            GameAccountReservation::firstOrFail()->released_at
        );
        $this->assertSame(
            1,
            Transaction::where('idempotency_key', "booking-group:{$groupId}:refund")->count()
        );
        $this->assertSame(1, Transaction::where('booking_group_id', $groupId)->where('type', 'booking')->count());
        $this->assertSame(1, Transaction::where('booking_group_id', $groupId)->where('type', 'refund')->count());
    }

    public function test_cancellation_blocked_inside_cancel_before_window(): void
    {
        \App\Models\ClubBookingSetting::current()->update([
            'cancel_before_minutes' => 120,
        ]);

        $computer = $this->createComputer();
        $this->fundWallet(500);

        $startsAt = CarbonImmutable::now()->addMinutes(60);
        $endsAt = $startsAt->addHour();

        $reserveResponse = $this->actingAs($this->user)->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computer->id],
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ])->assertCreated();

        $groupId = $reserveResponse->json('booking_group_id');

        $this->postJson("/api/booking/{$groupId}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking']);

        $this->assertDatabaseHas('booking_groups', [
            'id' => $groupId,
            'status' => 'confirmed',
        ]);
    }

    public function test_cancellation_allowed_outside_cancel_before_window(): void
    {
        \App\Models\ClubBookingSetting::current()->update([
            'cancel_before_minutes' => 120,
        ]);

        $computer = $this->createComputer();
        $this->fundWallet(500);

        $startsAt = CarbonImmutable::now()->addHours(5);
        $endsAt = $startsAt->addHour();

        $reserveResponse = $this->actingAs($this->user)->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computer->id],
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ])->assertCreated();

        $groupId = $reserveResponse->json('booking_group_id');

        $this->postJson("/api/booking/{$groupId}/cancel")
            ->assertOk()
            ->assertJsonPath('booking_status', 'cancelled');
    }

    public function test_postgresql_rejects_overlapping_reservations_for_the_same_account(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'SQLite does not enforce the PostgreSQL GiST exclusion constraint used for concurrent account allocation.'
            );
        }

        $computers = [$this->createComputer('PC-1'), $this->createComputer('PC-2')];
        $game = $this->createGameOffer($computers);
        $account = $this->createAccount($game);
        $this->fundWallet(1000);

        $response = $this->actingAs($this->user)->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computers[0]->id],
            'game_ids' => [$game->id],
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ])->assertCreated();

        $firstGroup = BookingGroup::findOrFail($response->json('booking_group_id'));
        $secondGroup = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
        $secondBooking = Booking::create([
            'booking_group_id' => $secondGroup->id,
            'user_id' => $this->user->id,
            'computer_id' => $computers[1]->id,
            'pc_ids' => [$computers[1]->id],
            'date' => $this->startsAt->toDateString(),
            'start_time' => 10,
            'duration' => 1,
            'price' => 100,
            'price_minor' => 10000,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'status' => 'confirmed',
        ]);
        $secondBookingGame = BookingGame::create([
            'booking_group_id' => $secondGroup->id,
            'club_game_id' => $firstGroup->games()->firstOrFail()->club_game_id,
            'quantity' => 1,
            'game_title' => $game->title,
            'platform' => $game->platform,
            'billing_mode' => 'fixed',
        ]);

        $this->expectException(QueryException::class);

        GameAccountReservation::create([
            'booking_game_id' => $secondBookingGame->id,
            'booking_id' => $secondBooking->id,
            'game_account_id' => $account->id,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'status' => 'confirmed',
        ]);
    }

    public function test_confirmed_booking_blocks_overlapping_slot_on_the_same_computer(): void
    {
        $computer = $this->createComputer();
        $this->fundWallet(500);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertCreated();

        $availability = $this->postJson('/api/booking/computers/availability', [
            'club_id' => $this->club->id,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ])->assertOk();

        $bookedIds = array_map('intval', $availability->json('booked_pc_ids') ?? []);
        $occupiedIds = array_map('intval', $availability->json('occupied_pc_ids') ?? []);
        $this->assertContains((int) $computer->id, $bookedIds);
        $this->assertContains((int) $computer->id, $occupiedIds);

        $other = User::create([
            'name' => 'Other Guest',
            'phone' => '+79990000002',
            'email' => 'other-booking@example.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $other->id,
            'deposit_balance' => 500,
            'bonus_balance' => 0,
            'total_spent' => 0,
        ]);

        $this->actingAs($other)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'starts_at' => $this->startsAt->addMinutes(30)->toIso8601String(),
                'ends_at' => $this->endsAt->addMinutes(30)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pc_ids');
    }

    public function test_availability_uses_wall_clock_when_starts_at_is_timezone_skewed(): void
    {
        $computer = $this->createComputer();
        $startHour = $this->startsAt->timezone(config('app.timezone'))->hour
            + ($this->startsAt->timezone(config('app.timezone'))->minute / 60);

        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $computer->id,
            'pc_ids' => [$computer->id],
            'date' => $this->startsAt->timezone(config('app.timezone'))->toDateString(),
            'start_time' => $startHour,
            'duration' => 1,
            'price' => 100,
            'price_minor' => 10000,
            'starts_at' => $this->startsAt->addHours(3),
            'ends_at' => $this->endsAt->addHours(3),
            'status' => 'confirmed',
        ]);

        $availability = $this->postJson('/api/booking/computers/availability', [
            'club_id' => $this->club->id,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
        ])->assertOk();

        $bookedIds = array_map('intval', $availability->json('booked_pc_ids') ?? []);
        $this->assertContains((int) $computer->id, $bookedIds);

        $this->fundWallet(500);
        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pc_ids');
    }

    public function test_adjacent_slot_after_booking_stays_free(): void
    {
        $computer = $this->createComputer();
        $this->fundWallet(500);

        $this->actingAs($this->user)
            ->postJson('/api/booking/reserve', [
                'club_id' => $this->club->id,
                'pc_ids' => [$computer->id],
                'starts_at' => $this->startsAt->toIso8601String(),
                'ends_at' => $this->endsAt->toIso8601String(),
            ])
            ->assertCreated();

        $nextStart = $this->endsAt;
        $nextEnd = $nextStart->addHour();

        $availability = $this->postJson('/api/booking/computers/availability', [
            'club_id' => $this->club->id,
            'starts_at' => $nextStart->toIso8601String(),
            'ends_at' => $nextEnd->toIso8601String(),
        ])->assertOk();

        $bookedIds = array_map('intval', $availability->json('booked_pc_ids') ?? []);
        $this->assertNotContains((int) $computer->id, $bookedIds);

        $this->postJson('/api/booking/reserve', [
            'club_id' => $this->club->id,
            'pc_ids' => [$computer->id],
            'starts_at' => $nextStart->toIso8601String(),
            'ends_at' => $nextEnd->toIso8601String(),
        ])->assertCreated();
    }

    private function createComputer(string $name = 'PC-1', ?Club $club = null): Computer
    {
        return Computer::create([
            'club_id' => ($club ?? $this->club)->id,
            'name' => $name,
            'status' => 'available',
        ]);
    }

    /**
     * @param  array<int, Computer>  $computers
     */
    private function createGameOffer(
        array $computers,
        string $billingMode = 'fixed',
        int $unitPriceMinor = 0,
        int $billingUnitMinutes = 60,
        string $title = 'Counter-Strike 2'
    ): Game {
        $game = Game::create([
            'title' => $title,
            'platform' => 'Steam',
        ]);

        ClubGame::create([
            'club_id' => $this->club->id,
            'game_id' => $game->id,
            'is_enabled' => true,
            'billing_mode' => $billingMode,
            'unit_price_minor' => $unitPriceMinor,
            'billing_unit_minutes' => $billingUnitMinutes,
            'currency' => 'RUB',
        ]);

        foreach ($computers as $computer) {
            ComputerGame::create([
                'computer_id' => $computer->id,
                'game_id' => $game->id,
                'is_installed' => true,
                'verified_at' => now(),
            ]);
        }

        return $game;
    }

    private function createAccount(Game $game, string $login = 'account-1'): GameAccount
    {
        return GameAccount::create([
            'game_id' => $game->id,
            'club_id' => $this->club->id,
            'login' => $login,
            'password' => 'secret',
            'status' => 'free',
            'is_enabled' => true,
        ]);
    }

    private function fundWallet(float $amount): Wallet
    {
        return Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => $amount,
            'bonus_balance' => 0,
            'total_spent' => 0,
        ]);
    }
}
