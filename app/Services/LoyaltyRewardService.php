<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClubStatus;
use App\Models\CosmeticFrame;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCosmetic;
use App\Models\UserRewardGrant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoyaltyRewardService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function grant(
        User $user,
        string $kind,
        array $payload,
        string $periodKey,
        ?int $seasonId = null,
        ?int $levelId = null,
        ?int $tierId = null,
    ): array {
        if ($tierId) {
            $existing = UserRewardGrant::query()
                ->where('user_id', $user->id)
                ->where('tier_id', $tierId)
                ->where('period_key', $periodKey)
                ->first();
            if ($existing && ($existing->expires_at === null || $existing->expires_at->isFuture())) {
                return ['kind' => $kind, 'duplicate' => true, 'grant_id' => $existing->id];
            }
            if ($existing) {
                $existing->delete();
            }
        }

        return DB::transaction(function () use ($user, $kind, $payload, $periodKey, $seasonId, $levelId, $tierId) {
            $result = match ($kind) {
                'cosmetic_frame' => $this->frame($user, $payload),
                'cosmetic_status' => $this->status($user, $payload),
                'session_minutes' => $this->minutes($user, $payload),
                'bar_item' => $this->barItem($user, $payload),
                'tariff_discount', 'partner_promo', 'lfg_vip' => $this->coupon($user, $kind, $payload, $periodKey, $seasonId, $levelId, $tierId),
                'bonus_balance', 'deposit_balance' => $this->wallet($user, $kind, $payload),
                'none' => ['kind' => 'none'],
                default => throw new RuntimeException('Неизвестный тип награды'),
            };

            if (! in_array($kind, ['tariff_discount', 'partner_promo', 'lfg_vip'], true)) {
                UserRewardGrant::query()->create([
                    'user_id' => $user->id,
                    'season_id' => $seasonId,
                    'level_id' => $levelId,
                    'tier_id' => $tierId,
                    'reward_kind' => $kind,
                    'payload' => $payload,
                    'period_key' => $periodKey,
                    'granted_at' => now(),
                    'expires_at' => null,
                ]);
            }

            $this->toast($user, (string) ($result['toast'] ?? $this->toastLine($kind, $payload)));

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function frame(User $user, array $payload): array
    {
        $id = (int) ($payload['frame_id'] ?? 0);
        $frame = $id > 0 ? CosmeticFrame::query()->find($id) : null;
        if (! $frame) {
            return ['kind' => 'cosmetic_frame', 'missing' => true, 'toast' => ''];
        }
        UserCosmetic::query()->updateOrCreate(
            ['user_id' => $user->id, 'kind' => 'frame', 'ref_id' => $frame->id],
            ['equipped_at' => now()],
        );
        UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'frame')
            ->where('ref_id', '!=', $frame->id)
            ->update(['equipped_at' => null]);

        return ['kind' => 'cosmetic_frame', 'frame_id' => $frame->id, 'toast' => 'Рамка «'.$frame->name.'»'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function status(User $user, array $payload): array
    {
        $id = (int) ($payload['status_id'] ?? 0);
        $status = $id > 0 ? ClubStatus::query()->find($id) : null;
        if (! $status) {
            return ['kind' => 'cosmetic_status', 'missing' => true, 'toast' => ''];
        }
        $equipped = UserCosmetic::query()
            ->where('user_id', $user->id)
            ->where('kind', 'status')
            ->whereNotNull('equipped_at')
            ->first();
        $current = $equipped ? ClubStatus::query()->find($equipped->ref_id) : null;
        UserCosmetic::query()->updateOrCreate(
            ['user_id' => $user->id, 'kind' => 'status', 'ref_id' => $status->id],
            [],
        );
        if (! $current || (int) $status->priority >= (int) $current->priority) {
            UserCosmetic::query()
                ->where('user_id', $user->id)
                ->where('kind', 'status')
                ->update(['equipped_at' => null]);
            UserCosmetic::query()
                ->where('user_id', $user->id)
                ->where('kind', 'status')
                ->where('ref_id', $status->id)
                ->update(['equipped_at' => now()]);
        }

        return ['kind' => 'cosmetic_status', 'status_key' => $status->key, 'toast' => 'Статус «'.$status->label.'»'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function minutes(User $user, array $payload): array
    {
        $minutes = (int) ($payload['minutes'] ?? 0);
        if (! in_array($minutes, [15, 30, 60, 120], true)) {
            $minutes = max(1, $minutes);
        }
        DB::table('bonus_logs')->insert([
            'user_id' => $user->id,
            'admin_id' => $user->id,
            'minutes' => $minutes,
            'reason' => 'Боевой пропуск',
            'source' => 'battle_pass',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->latest('id')
            ->first();
        if ($booking) {
            $booking->ends_at = $booking->ends_at->copy()->addMinutes($minutes);
            $booking->save();
        }

        return ['kind' => 'session_minutes', 'minutes' => $minutes, 'toast' => '+'.$minutes.' мин'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function barItem(User $user, array $payload): array
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        $product = $productId > 0 ? Product::query()->find($productId) : null;
        if (! $product || $product->requires_marking || (int) $product->stock < 1) {
            $fallback = (float) ($payload['fallback_bonus'] ?? 20);
            if ($fallback > 0) {
                $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
                $wallet->increment('bonus_balance', $fallback);
            }

            return ['kind' => 'bar_item', 'fallback' => 'bonus_balance', 'toast' => 'Напитка нет на складе'];
        }

        $before = (int) $product->stock;
        $product->decrement('stock', 1);
        try {
            app(InventoryCostService::class)->consumeFifo($product->id, 1);
        } catch (\Throwable) {
        }
        StockMovement::query()->create([
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_COMP,
            'reason_code' => StockMovement::REASON_COMP,
            'reason' => 'battle_pass',
            'qty' => -1,
            'stock_before' => $before,
            'stock_after' => $before - 1,
            'meta' => ['reason' => 'battle_pass'],
        ]);
        Order::query()->create([
            'user_id' => $user->id,
            'product_name' => $product->name,
            'price' => 0,
            'status' => Order::STATUS_COOKING,
            'channel' => 'shell',
            'items' => [[
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'unit_price' => 0,
                'line_total' => 0,
            ]],
        ]);

        return ['kind' => 'bar_item', 'product_id' => $product->id, 'toast' => $product->name.' в подарок'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function coupon(
        User $user,
        string $kind,
        array $payload,
        string $periodKey,
        ?int $seasonId,
        ?int $levelId,
        ?int $tierId,
    ): array {
        $ttl = (int) ($payload['ttl_days'] ?? ($kind === 'lfg_vip' ? 14 : 30));
        $expires = now()->addDays(max(1, $ttl));
        if ($kind === 'lfg_vip' && $seasonId) {
            $season = \App\Models\BattlePassSeason::query()->find($seasonId);
            if ($season && $season->status !== \App\Models\BattlePassSeason::LIVE) {
                $graceEnd = $season->ends_on->copy()->endOfDay()->addDays((int) $season->claim_grace_days);
                if ($graceEnd->lt($expires)) {
                    $expires = $graceEnd;
                }
            }
        }
        $grant = UserRewardGrant::query()->create([
            'user_id' => $user->id,
            'season_id' => $seasonId,
            'level_id' => $levelId,
            'tier_id' => $tierId,
            'reward_kind' => $kind,
            'payload' => $payload,
            'period_key' => $periodKey,
            'granted_at' => now(),
            'expires_at' => $expires,
        ]);

        return ['kind' => $kind, 'grant_id' => $grant->id, 'expires_at' => $expires->toIso8601String(), 'toast' => $this->toastLine($kind, $payload)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function wallet(User $user, string $kind, array $payload): array
    {
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            return ['kind' => $kind, 'amount' => 0, 'toast' => ''];
        }
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
        if ($kind === 'bonus_balance') {
            $wallet->increment('bonus_balance', $amount);
        } else {
            $wallet->creditSpendable($amount);
        }
        Transaction::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => 'deposit',
            'source' => 'battle_pass',
            'description' => $kind === 'bonus_balance' ? 'Фантики боевого пропуска' : 'Депозит боевого пропуска',
            'payload' => ['reward_kind' => $kind],
        ]);

        return ['kind' => $kind, 'amount' => $amount, 'toast' => '+'.(int) $amount];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toastLine(string $kind, array $payload): string
    {
        return match ($kind) {
            'session_minutes' => '+'.(int) ($payload['minutes'] ?? 0).' мин',
            'tariff_discount' => 'Скидка '.(int) ($payload['percent'] ?? 0).'%',
            'partner_promo' => 'Код '.(string) ($payload['code'] ?? ''),
            'lfg_vip' => 'VIP в поиске пати',
            default => 'Награда пропуска',
        };
    }

    private function toast(User $user, string $line): void
    {
        if ($line === '') {
            return;
        }
        $key = 'bp-toasts:'.$user->id;
        $queue = Cache::get($key, []);
        $queue[] = $line;
        Cache::put($key, array_slice($queue, -5), now()->addHour());
    }

    /**
     * @return list<string>
     */
    public function pullToasts(User $user): array
    {
        $key = 'bp-toasts:'.$user->id;
        $queue = Cache::pull($key, []);

        return array_values(array_filter($queue, fn ($line) => is_string($line) && $line !== ''));
    }
}
