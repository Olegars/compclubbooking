<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Referral;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReferralService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function ensureCode(User $user): string
    {
        $existing = strtoupper(trim((string) $user->referral_code));
        if ($existing !== '') {
            return $existing;
        }

        do {
            $code = $this->randomCode(8);
        } while (User::query()->where('referral_code', $code)->exists());

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function attachNewUser(User $user, ?string $code): ?Referral
    {
        if (! Schema::hasTable('referrals')) {
            return null;
        }

        $code = $this->normalizeCode($code);
        if ($code === '' || $user->referred_by_user_id) {
            return null;
        }
        if (Referral::query()->where('referee_user_id', $user->id)->exists()) {
            return null;
        }

        $referrer = User::query()->where('referral_code', $code)->first();
        if (! $referrer || (int) $referrer->id === (int) $user->id) {
            return null;
        }

        $features = app(ClubFeatureService::class);
        $clubId = $features->clubIdForUser($referrer);
        if (! $features->enabled($clubId, 'referrals')) {
            return null;
        }

        $percent = max(0, min(50, $features->int($clubId, 'referrals', 'friend_discount_percent', 10)));
        $days = max(1, $features->int($clubId, 'referrals', 'promo_days', 30));

        $user->forceFill(['referred_by_user_id' => $referrer->id])->save();

        return Referral::query()->create([
            'referrer_user_id' => $referrer->id,
            'referee_user_id' => $user->id,
            'promo_code' => $this->makePromoCode(),
            'promo_percent' => $percent,
            'promo_expires_at' => now()->addDays($days),
        ]);
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    public function applyToQuote(User $user, array $quote, int $clubId, bool $consume): array
    {
        if (! Schema::hasTable('referrals')) {
            return $quote;
        }
        if (! app(ClubFeatureService::class)->enabled($clubId, 'referrals')) {
            return $quote;
        }

        $referral = Referral::query()->where('referee_user_id', $user->id)->first();
        if (! $referral || ! $this->promoOpen($referral)) {
            return $quote;
        }

        $percent = (int) $referral->promo_percent;
        if ($percent < 1) {
            return $quote;
        }

        if ($consume) {
            $referral = Referral::query()->whereKey($referral->id)->lockForUpdate()->first();
            if (! $referral || ! $this->promoOpen($referral)) {
                return $quote;
            }
        }

        $total = (int) ($quote['total_minor'] ?? 0);
        $discount = (int) round($total * $percent / 100);
        if ($discount < 1) {
            return $quote;
        }

        $quote['total_minor'] = max(0, $total - $discount);
        $quote['total_price'] = $quote['total_minor'] / 100;
        $quote['referral_discount_minor'] = $discount;
        $quote['referral_id'] = $referral->id;
        $quote['referral_promo_code'] = $referral->promo_code;

        if ($consume) {
            $referral->forceFill(['promo_used_at' => now()])->save();
        }

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    public function bindPromo(array $quote, BookingGroup $group): void
    {
        $id = (int) ($quote['referral_id'] ?? 0);
        if ($id < 1 || (int) ($quote['referral_discount_minor'] ?? 0) < 1) {
            return;
        }

        Referral::query()
            ->whereKey($id)
            ->whereNull('promo_booking_group_id')
            ->update(['promo_booking_group_id' => $group->id]);
    }

    public function rewardFirstPayment(User $user, BookingGroup $group): void
    {
        if (! Schema::hasTable('referrals') || $group->payment_status !== 'paid') {
            return;
        }

        $clubId = (int) $group->club_id;
        $features = app(ClubFeatureService::class);
        if (! $features->enabled($clubId, 'referrals')) {
            return;
        }

        $referral = Referral::query()->where('referee_user_id', $user->id)->lockForUpdate()->first();
        if (! $referral) {
            return;
        }

        if (! $referral->first_paid_at) {
            $referral->forceFill([
                'first_paid_at' => now(),
                'first_booking_group_id' => $group->id,
            ])->save();
        }

        if ($referral->rewarded_at) {
            return;
        }

        $minutes = $this->paidMinutes($group);
        $min = max(1, $features->int($clubId, 'referrals', 'min_minutes', 60));
        if ($minutes < $min) {
            return;
        }

        $kind = (string) $features->setting($clubId, 'referrals', 'reward_kind');
        if (! in_array($kind, ['bonus_balance', 'session_minutes'], true)) {
            $kind = 'bonus_balance';
        }

        $referrer = User::query()->find($referral->referrer_user_id);
        if (! $referrer || (int) $referrer->id === (int) $user->id) {
            return;
        }

        $snapshot = [
            'kind' => $kind,
            'referrer' => $this->grant(
                $referrer,
                $kind,
                max(0, $features->int($clubId, 'referrals', 'referrer_amount', 100)),
                'referrer',
                $referral,
                $group
            ),
            'friend' => $this->grant(
                $user,
                $kind,
                max(0, $features->int($clubId, 'referrals', 'friend_amount', 100)),
                'friend',
                $referral,
                $group
            ),
        ];

        $referral->forceFill([
            'rewarded_at' => now(),
            'reward_booking_group_id' => $group->id,
            'reward_snapshot' => $snapshot,
        ])->save();

        try {
            app(BattlePassService::class)->completeByCode($referrer, 'referral_soul', $clubId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function flushPendingMinutes(User $user): void
    {
        if (! Schema::hasColumn('wallets', 'bonus_minutes')) {
            return;
        }

        $wallet = $user->wallet()->first();
        $minutes = (int) ($wallet->bonus_minutes ?? 0);
        if (! $wallet || $minutes < 1) {
            return;
        }

        if ($this->extendBooking($user, $minutes)) {
            $wallet->bonus_minutes = 0;
            $wallet->save();
        }
    }

    public function reverseIfRefunded(BookingGroup $group): void
    {
        if (! Schema::hasTable('referrals') || $group->payment_status !== 'refunded') {
            return;
        }
        if ($group->bookings()->whereNotNull('actual_started_at')->exists()) {
            return;
        }

        $referral = Referral::query()->where('referee_user_id', $group->user_id)->lockForUpdate()->first();
        if (! $referral) {
            return;
        }

        if ((int) $referral->promo_booking_group_id === (int) $group->id) {
            $referral->promo_used_at = null;
            $referral->promo_booking_group_id = null;
        }
        if ((int) $referral->first_booking_group_id === (int) $group->id) {
            $referral->first_paid_at = null;
            $referral->first_booking_group_id = null;
        }
        if ($referral->rewarded_at && (int) $referral->reward_booking_group_id === (int) $group->id) {
            $this->undoSnapshot($referral, $group);
            $referral->rewarded_at = null;
            $referral->reward_booking_group_id = null;
            $referral->reward_snapshot = null;
        }

        $referral->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cabinet(User $user): ?array
    {
        if (! Schema::hasTable('referrals')) {
            return null;
        }

        $features = app(ClubFeatureService::class);
        $clubId = $features->clubIdForUser($user);
        if (! $features->enabled($clubId, 'referrals')) {
            return null;
        }

        $code = $this->ensureCode($user);
        $kind = (string) $features->setting($clubId, 'referrals', 'reward_kind');
        $kind = $kind === 'session_minutes' ? 'session_minutes' : 'bonus_balance';
        $received = Referral::query()->where('referee_user_id', $user->id)->first();
        $made = Referral::query()->where('referrer_user_id', $user->id);

        return [
            'code' => $code,
            'url' => url('/r/'.$code),
            'reward_kind' => $kind,
            'referrer_amount' => $features->int($clubId, 'referrals', 'referrer_amount', 100),
            'friend_amount' => $features->int($clubId, 'referrals', 'friend_amount', 100),
            'friend_discount_percent' => $features->int($clubId, 'referrals', 'friend_discount_percent', 10),
            'min_minutes' => $features->int($clubId, 'referrals', 'min_minutes', 60),
            'invited' => (clone $made)->count(),
            'rewarded' => (clone $made)->whereNotNull('rewarded_at')->count(),
            'promo' => $this->promoOpen($received) ? [
                'code' => $received->promo_code,
                'percent' => (int) $received->promo_percent,
                'expires_at' => $received->promo_expires_at?->toDateString(),
            ] : null,
        ];
    }

    public function normalizeCode(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        return preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
    }

    private function promoOpen(?Referral $referral): bool
    {
        if (! $referral || $referral->promo_used_at || (int) $referral->promo_percent < 1) {
            return false;
        }

        return ! $referral->promo_expires_at || $referral->promo_expires_at->isFuture();
    }

    private function paidMinutes(BookingGroup $group): int
    {
        $fromQuote = (int) data_get($group->pricing_snapshot, 'duration_minutes', 0);
        if ($fromQuote > 0) {
            return $fromQuote;
        }
        if ($group->starts_at && $group->ends_at) {
            return max(0, (int) $group->starts_at->diffInMinutes($group->ends_at));
        }

        return 0;
    }

    /**
     * @return array{user_id:int,kind:string,amount:int,booking_id:?int}
     */
    private function grant(
        User $user,
        string $kind,
        int $amount,
        string $role,
        Referral $referral,
        BookingGroup $group,
    ): array {
        $line = [
            'user_id' => $user->id,
            'kind' => $kind,
            'amount' => $amount,
            'booking_id' => null,
        ];
        if ($amount < 1) {
            return $line;
        }

        if ($kind === 'session_minutes') {
            DB::table('bonus_logs')->insert([
                'user_id' => $user->id,
                'admin_id' => $user->id,
                'minutes' => $amount,
                'reason' => $role === 'referrer' ? 'Приведи друга' : 'Первая оплата по приглашению',
                'source' => 'referral',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $bookingId = $this->extendBooking($user, $amount);
            if ($bookingId) {
                $line['booking_id'] = $bookingId;
            } else {
                $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
                $wallet->increment('bonus_minutes', $amount);
            }

            return $line;
        }

        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
        $wallet->increment('bonus_balance', $amount);
        Transaction::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => 'deposit',
            'source' => 'referral',
            'description' => $role === 'referrer'
                ? 'Бонус «Приведи друга»'
                : 'Бонус за первую оплату по приглашению',
            'idempotency_key' => "referral:{$referral->id}:{$role}:{$group->id}",
            'payload' => ['referral_id' => $referral->id, 'role' => $role],
        ]);

        return $line;
    }

    private function undoSnapshot(Referral $referral, BookingGroup $group): void
    {
        $snapshot = $referral->reward_snapshot ?? [];
        foreach (['referrer', 'friend'] as $role) {
            $line = $snapshot[$role] ?? null;
            if (! is_array($line)) {
                continue;
            }
            $amount = (int) ($line['amount'] ?? 0);
            $user = User::query()->find((int) ($line['user_id'] ?? 0));
            if (! $user || $amount < 1) {
                continue;
            }
            if (($line['kind'] ?? '') === 'session_minutes') {
                $bookingId = (int) ($line['booking_id'] ?? 0);
                if ($bookingId > 0) {
                    $this->shrinkBooking($bookingId, $amount);
                } else {
                    $wallet = $user->wallet()->first();
                    if ($wallet) {
                        $wallet->bonus_minutes = max(0, (int) $wallet->bonus_minutes - $amount);
                        $wallet->save();
                    }
                }
                DB::table('bonus_logs')->insert([
                    'user_id' => $user->id,
                    'admin_id' => $user->id,
                    'minutes' => -$amount,
                    'reason' => 'Отмена реферала',
                    'source' => 'referral',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $wallet = $user->wallet()->first();
            if ($wallet) {
                $wallet->bonus_balance = max(0, (float) $wallet->bonus_balance - $amount);
                $wallet->save();
            }
            Transaction::query()->firstOrCreate(
                ['idempotency_key' => "referral:{$referral->id}:{$role}:{$group->id}:reverse"],
                [
                    'user_id' => $user->id,
                    'amount' => -$amount,
                    'type' => 'deposit',
                    'source' => 'referral',
                    'description' => 'Отмена бонуса «Приведи друга»',
                    'payload' => ['referral_id' => $referral->id, 'role' => $role, 'reverse' => true],
                ]
            );
        }
    }

    private function extendBooking(User $user, int $minutes): ?int
    {
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'confirmed', 'paid', 'new', 'pending_payment'])
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now()->subMinute());
            })
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('starts_at')
            ->first();
        if (! $booking) {
            return null;
        }

        $booking->duration = (float) $booking->duration + ($minutes / 60);
        if ($booking->ends_at) {
            $booking->ends_at = $booking->ends_at->addMinutes($minutes);
        }
        $booking->save();

        $group = $booking->group;
        if ($group && $group->ends_at) {
            $group->ends_at = $group->ends_at->addMinutes($minutes);
            $group->save();
        }

        return (int) $booking->id;
    }

    private function shrinkBooking(int $bookingId, int $minutes): void
    {
        $booking = Booking::query()->find($bookingId);
        if (! $booking) {
            return;
        }

        $booking->duration = max(0, (float) $booking->duration - ($minutes / 60));
        if ($booking->ends_at) {
            $booking->ends_at = $booking->ends_at->subMinutes($minutes);
        }
        $booking->save();

        $group = $booking->group;
        if ($group && $group->ends_at) {
            $group->ends_at = $group->ends_at->subMinutes($minutes);
            $group->save();
        }
    }

    private function randomCode(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private function makePromoCode(): string
    {
        do {
            $code = 'RF'.$this->randomCode(6);
        } while (Referral::query()->where('promo_code', $code)->exists());

        return $code;
    }
}
