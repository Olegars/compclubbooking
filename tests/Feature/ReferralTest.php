<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\ClubFeature;
use App\Models\Computer;
use App\Models\Referral;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClubFeatureService;
use App\Services\ReferralService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private ReferralService $referrals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'Referral Club', 'slug' => 'referral-club']);
        $this->referrals = app(ReferralService::class);
    }

    public function test_personal_link_attaches_only_a_new_guest_and_issues_a_promo(): void
    {
        $host = $this->user('79001000001');
        $code = $this->referrals->ensureCode($host);

        $this->get('/r/'.$code)
            ->assertRedirect(route('home'))
            ->assertSessionHas('referral_code', $code);

        $this->assertNull($this->referrals->attachNewUser($host, $code));

        $friend = $this->user('79001000002');
        $row = $this->referrals->attachNewUser($friend, $code);

        $this->assertNotNull($row);
        $this->assertSame($host->id, $friend->fresh()->referred_by_user_id);
        $this->assertSame(10, $row->promo_percent);
        $this->assertStringStartsWith('RF', $row->promo_code);
        $this->assertNull($this->referrals->attachNewUser($friend, $code));

        $card = $this->referrals->cabinet($host->fresh());
        $this->assertSame(url('/r/'.$code), $card['url']);
        $this->assertSame(1, $card['invited']);
        $this->assertSame(0, $card['rewarded']);

        $friendCard = $this->referrals->cabinet($friend->fresh());
        $this->assertSame($row->promo_code, $friendCard['promo']['code']);
    }

    public function test_first_paid_booking_discounts_the_friend_and_pays_both_once(): void
    {
        [$host, $friend, $row] = $this->linkedPair();

        $quote = $this->referrals->applyToQuote($friend, [
            'total_minor' => 10000,
            'total_price' => 100,
            'duration_minutes' => 30,
        ], $this->club->id, true);

        $this->assertSame(9000, $quote['total_minor']);
        $this->assertNotNull($row->fresh()->promo_used_at);
        $again = $this->referrals->applyToQuote($friend, [
            'total_minor' => 10000,
            'total_price' => 100,
            'duration_minutes' => 120,
        ], $this->club->id, true);
        $this->assertSame(10000, $again['total_minor']);

        $short = $this->paidGroup($friend, 30);
        $this->referrals->rewardFirstPayment($friend, $short);
        $this->assertNull($row->fresh()->rewarded_at);
        $this->assertSame(0.0, (float) $host->wallet->fresh()->bonus_balance);

        $long = $this->paidGroup($friend, 90);
        $this->referrals->rewardFirstPayment($friend, $long);
        $this->referrals->rewardFirstPayment($friend, $long);

        $row = $row->fresh();
        $this->assertNotNull($row->rewarded_at);
        $this->assertSame($long->id, $row->reward_booking_group_id);
        $this->assertSame(100.0, (float) $host->wallet->fresh()->bonus_balance);
        $this->assertSame(100.0, (float) $friend->wallet->fresh()->bonus_balance);
        $this->assertSame(1, Transaction::query()->where('user_id', $host->id)->where('source', 'referral')->count());
        $this->assertSame(1, $this->referrals->cabinet($host)['rewarded']);
    }

    public function test_session_minutes_extend_the_booking_and_a_refund_takes_them_back(): void
    {
        ClubFeature::query()->create([
            'club_id' => $this->club->id,
            'key' => 'referrals',
            'enabled' => true,
            'settings' => [
                'reward_kind' => 'session_minutes',
                'referrer_amount' => 30,
                'friend_amount' => 45,
                'friend_discount_percent' => 0,
                'min_minutes' => 60,
                'promo_days' => 30,
            ],
        ]);
        app(ClubFeatureService::class)->flush();

        [$host, $friend] = $this->linkedPair();
        $booking = $this->seat($friend);
        $group = $booking->group;
        $group->update([
            'payment_status' => 'paid',
            'pricing_snapshot' => ['duration_minutes' => 90],
        ]);

        $this->referrals->rewardFirstPayment($friend, $group->fresh());

        $booking->refresh();
        $this->assertEqualsWithDelta(2.75, (float) $booking->duration, 0.01);
        $this->assertSame(30, (int) $host->wallet->fresh()->bonus_minutes);
        $this->assertSame(45, (int) \DB::table('bonus_logs')->where('user_id', $friend->id)->where('source', 'referral')->value('minutes'));

        $group->update(['payment_status' => 'refunded']);
        $this->referrals->reverseIfRefunded($group->fresh());

        $this->assertEqualsWithDelta(2.0, (float) $booking->fresh()->duration, 0.01);
        $this->assertSame(0, (int) $host->wallet->fresh()->bonus_minutes);
        $this->assertNull(Referral::query()->where('referee_user_id', $friend->id)->value('rewarded_at'));
    }

    public function test_disabled_feature_does_not_pay(): void
    {
        ClubFeature::query()->create([
            'club_id' => $this->club->id,
            'key' => 'referrals',
            'enabled' => false,
            'settings' => [],
        ]);
        app(ClubFeatureService::class)->flush();

        [$host, $friend] = $this->linkedPair();
        $this->assertNotNull(Referral::query()->where('referee_user_id', $friend->id)->first());
        $this->referrals->rewardFirstPayment($friend, $this->paidGroup($friend, 120));

        $this->assertNull(Referral::query()->where('referee_user_id', $friend->id)->value('rewarded_at'));
        $this->assertSame(0.0, (float) $host->wallet->fresh()->bonus_balance);
        $this->assertSame(0.0, (float) $friend->wallet->fresh()->bonus_balance);
    }

    /**
     * @return array{0: User, 1: User, 2: Referral}
     */
    private function linkedPair(): array
    {
        $host = $this->user('79001000011');
        $friend = $this->user('79001000012');
        $code = $this->referrals->ensureCode($host);
        $row = $this->referrals->attachNewUser($friend, $code);

        return [$host, $friend, $row];
    }

    private function user(string $phone): User
    {
        $user = User::create([
            'name' => 'Guest '.$phone,
            'phone' => $phone,
            'email' => $phone.'@referral.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => 0,
            'bonus_balance' => 0,
            'bonus_minutes' => 0,
        ]);

        return $user;
    }

    private function paidGroup(User $user, int $minutes): BookingGroup
    {
        $start = CarbonImmutable::now()->addHour();

        return BookingGroup::create([
            'user_id' => $user->id,
            'club_id' => $this->club->id,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($minutes),
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => now(),
            'pricing_snapshot' => ['duration_minutes' => $minutes],
        ]);
    }

    private function seat(User $user): Booking
    {
        $pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-REF',
            'status' => 'free',
            'kind' => 'pc',
        ]);
        $start = CarbonImmutable::now()->addHour();
        $end = $start->addHours(2);
        $group = BookingGroup::create([
            'user_id' => $user->id,
            'club_id' => $this->club->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 0,
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $start->timezone(config('app.timezone'))->toDateString(),
            'start_time' => 12,
            'duration' => 2,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'confirmed',
            'pin_code' => '1234',
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }
}
