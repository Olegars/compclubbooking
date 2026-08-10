<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\ShellQrChallenge;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ShellQrLoginService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellQrLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private Computer $computer;

    private ShellQrLoginService $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'QR User',
            'phone' => '+79991234567',
            'email' => 'qr@example.test',
            'password' => 'password',
            'balance' => 2000,
        ]);
        Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => 2000,
            'bonus_balance' => 0,
        ]);

        $this->club = Club::create(['name' => 'QR Club', 'slug' => 'qr-club']);
        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-QR',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $this->qr = app(ShellQrLoginService::class);
    }

    public function test_issue_and_status_pending(): void
    {
        $issued = $this->qr->issue($this->computer);
        $this->assertNotEmpty($issued['token']);

        $status = $this->qr->status($issued['token']);
        $this->assertSame('pending', $status['status']);
    }

    public function test_redeem_activates_existing_booking(): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $now,
            'ends_at' => $now->addHour(),
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 30000,
            'games_total_minor' => 0,
            'total_minor' => 30000,
            'paid_total_minor' => 30000,
            'paid_at' => $now,
        ]);
        Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $now->toDateString(),
            'start_time' => $now->hour + $now->minute / 60,
            'duration' => 1,
            'price' => 300,
            'price_minor' => 30000,
            'status' => 'confirmed',
            'pin_code' => '1234',
            'starts_at' => $now,
            'ends_at' => $now->addHour(),
        ]);

        $issued = $this->qr->issue($this->computer);
        $result = $this->qr->redeem($this->user, $issued['token']);
        $this->assertSame('activated', $result['status']);
        $this->assertArrayHasKey('booking_id', $result);

        $status = $this->qr->status($issued['token']);
        $this->assertSame('consumed', $status['status']);
    }

    public function test_redeem_without_booking_returns_needs_booking(): void
    {
        $issued = $this->qr->issue($this->computer);
        $result = $this->qr->redeem($this->user, $issued['token']);
        $this->assertSame('needs_booking', $result['status']);
        $this->assertTrue($result['can_pay']);
        $this->assertSame(60, $result['duration_minutes']);
    }

    public function test_book_from_qr_activates_session(): void
    {
        $issued = $this->qr->issue($this->computer);
        $result = $this->qr->bookFromQr($this->user, $issued['token'], 60);
        $this->assertSame('activated', $result['status'], json_encode($result));

        $challenge = ShellQrChallenge::query()->where('token', $issued['token'])->first();
        $this->assertSame('consumed', $challenge->status);
        $this->assertNotNull($challenge->booking_id);
    }

    public function test_book_needs_topup_when_balance_low(): void
    {
        $this->user->wallet->update(['deposit_balance' => 10]);
        $this->user->update(['balance' => 10]);

        $issued = $this->qr->issue($this->computer);
        $result = $this->qr->bookFromQr($this->user->fresh(), $issued['token'], 60);
        $this->assertSame('needs_topup', $result['status']);
    }

    public function test_shell_http_challenge_and_account_redeem(): void
    {
        $this->postJson('/api/shell/qr/challenge', ['terminal_id' => $this->computer->id])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $token = ShellQrChallenge::query()->latest('id')->value('token');
        $this->assertNotEmpty($token);

        $this->actingAs($this->user)
            ->postJson('/account/qr/redeem', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'needs_booking');
    }
}
