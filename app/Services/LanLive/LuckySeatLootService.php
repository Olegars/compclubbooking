<?php

namespace App\Services\LanLive;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\LuckySeatDrop;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorePromoCode;
use App\Models\Transaction;
use App\Models\User;
use App\Services\KitchenOrderPrintService;
use App\Services\ProductStockService;
use App\Services\ClubFeatureService;
use App\Support\OrderChannel;
use App\Support\OrderDeliveryTarget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LuckySeatLootService
{
    public const COOLDOWN_SECONDS = 10800;

    public const MATCH_STREAK = 2;

    public const ROUND_STREAK = 5;

    public const SESSION_TTL = 28800;

    /** @var list<int> */
    public const BONUS_AMOUNTS = [50, 75, 100];

    public const PROMO_PERCENT = 10;

    public const PROMO_DAYS = 30;

    /** @var array<string, array{match_streak:int, round_streak:int}> */
    private static array $sessions = [];

    /** Tests only: bonus | drink | store_promo */
    public static ?string $forceReward = null;

    public function __construct(
        private readonly ProductStockService $stock,
        private readonly KitchenOrderPrintService $kitchen,
    ) {
    }

    public static function flushSessions(): void
    {
        self::$sessions = [];
        self::$forceReward = null;
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    public function observe(Computer $computer, User $user, Booking $booking, array $snap): ?LuckySeatDrop
    {
        if (! $this->features()->enabled($this->clubId($computer), 'lucky_seat')) {
            return null;
        }
        $event = strtolower((string) ($snap['event'] ?? 'heartbeat'));
        $stats = $this->sessionStats((int) $booking->id);

        if ($event === 'match_win') {
            $stats['match_streak']++;
        } elseif ($event === 'match_loss') {
            $stats['match_streak'] = 0;
        } elseif ($event === 'round_win') {
            $stats['round_streak']++;
        } elseif ($event === 'round_loss') {
            $stats['round_streak'] = 0;
        }
        $this->putSession((int) $booking->id, $stats);

        $clubId = $this->clubId($computer);
        $streakHit = $stats['match_streak'] >= $this->matchStreak($clubId)
            || $stats['round_streak'] >= $this->roundStreak($clubId);
        if ($streakHit && in_array($event, ['match_win', 'round_win'], true)) {
            $drop = $this->tryGrant($computer, $user, $booking, LuckySeatDrop::TRIGGER_WIN_STREAK);
            if ($drop) {
                $stats['match_streak'] = 0;
                $stats['round_streak'] = 0;
                $this->putSession((int) $booking->id, $stats);

                return $drop;
            }
        }

        return $this->tryGrant($computer, $user, $booking, LuckySeatDrop::TRIGGER_PLAYTIME);
    }

    public function maybePlaytime(Computer $computer, User $user, Booking $booking): ?LuckySeatDrop
    {
        if (! $this->features()->enabled($this->clubId($computer), 'lucky_seat')) {
            return null;
        }

        return $this->tryGrant($computer, $user, $booking, LuckySeatDrop::TRIGGER_PLAYTIME);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingPayload(User $user, Booking $booking): ?array
    {
        $computer = Computer::query()->find((int) $booking->computer_id);
        if ($computer && ! $this->features()->enabled($this->clubId($computer), 'lucky_seat')) {
            return null;
        }
        $drop = $this->pendingDrop($user, $booking);
        if (! $drop) {
            return null;
        }

        return $this->payload($drop, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function open(User $user, Booking $booking, Computer $computer, int $id): array
    {
        return DB::transaction(function () use ($user, $booking, $computer, $id) {
            $drop = LuckySeatDrop::query()->lockForUpdate()->find($id);
            if (! $drop || (int) $drop->user_id !== (int) $user->id || (int) $drop->booking_id !== (int) $booking->id) {
                throw new RuntimeException('Кейс не найден');
            }
            if ($drop->status === LuckySeatDrop::STATUS_OPENED) {
                return $this->payload($drop, true);
            }
            if (! $drop->isPending()) {
                throw new RuntimeException('Кейс уже недействителен');
            }

            $this->fulfill($drop, $user, $booking, $computer);
            $drop->refresh();

            return $this->payload($drop, true);
        });
    }

    public function settlePendingOnLogout(User $user, Booking $booking, ?Computer $computer): void
    {
        $drop = $this->pendingDrop($user, $booking);
        if (! $drop) {
            return;
        }
        $pc = $computer ?: Computer::query()->find((int) $drop->computer_id);
        if (! $pc) {
            return;
        }
        try {
            $this->open($user, $booking, $pc, (int) $drop->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(LuckySeatDrop $drop, bool $withReward): array
    {
        $opened = $drop->status === LuckySeatDrop::STATUS_OPENED;
        $reward = $opened || $withReward ? ($drop->reward ?? []) : [];

        return [
            'id' => (int) $drop->id,
            'status' => $drop->status,
            'trigger' => $drop->trigger,
            'trigger_label' => $drop->trigger === LuckySeatDrop::TRIGGER_PLAYTIME
                ? $this->playtimeLabel((int) ($drop->club_id ?: 0))
                : 'Серия побед',
            'title' => 'Lucky Seat',
            'subtitle' => $drop->trigger === LuckySeatDrop::TRIGGER_PLAYTIME
                ? 'Кейс за активную игру'
                : 'Кейс за стрик',
            'reward_type' => $opened ? $drop->reward_type : null,
            'reward' => $opened ? $reward : null,
        ];
    }

    private function tryGrant(Computer $computer, User $user, Booking $booking, string $trigger): ?LuckySeatDrop
    {
        if ($this->pendingDrop($user, $booking)) {
            return null;
        }
        $clubId = $this->clubId($computer);
        if ($this->onCooldown($user, $clubId)) {
            return null;
        }
        if ($trigger === LuckySeatDrop::TRIGGER_PLAYTIME && ! $this->playtimeReady($user, $booking, $clubId)) {
            return null;
        }

        $ends = $booking->ends_at;
        $expires = $ends && $ends->greaterThan(now())
            ? $ends
            : now()->addHours(4);

        return LuckySeatDrop::query()->create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'computer_id' => $computer->id,
            'club_id' => $computer->club_id,
            'trigger' => $trigger,
            'status' => LuckySeatDrop::STATUS_PENDING,
            'expires_at' => $expires,
        ]);
    }

    private function pendingDrop(User $user, Booking $booking): ?LuckySeatDrop
    {
        $drop = LuckySeatDrop::query()
            ->where('user_id', $user->id)
            ->where('booking_id', $booking->id)
            ->where('status', LuckySeatDrop::STATUS_PENDING)
            ->latest('id')
            ->first();
        if (! $drop) {
            return null;
        }
        if ($drop->expires_at && $drop->expires_at->isPast()) {
            $drop->update(['status' => LuckySeatDrop::STATUS_EXPIRED]);

            return null;
        }

        return $drop;
    }

    private function onCooldown(User $user, ?int $clubId): bool
    {
        return LuckySeatDrop::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds($this->cooldownSeconds($clubId)))
            ->exists();
    }

    private function playtimeReady(User $user, Booking $booking, ?int $clubId): bool
    {
        $start = $booking->actual_started_at ?? $booking->starts_at ?? $booking->created_at;
        if (! $start) {
            return false;
        }
        $last = LuckySeatDrop::query()
            ->where('user_id', $user->id)
            ->where('booking_id', $booking->id)
            ->latest('id')
            ->value('created_at');
        $anchor = $last ? \Carbon\CarbonImmutable::parse($last) : $start;
        $elapsed = now()->getTimestamp() - $anchor->getTimestamp();

        return $elapsed >= $this->playtimeSeconds($clubId);
    }

    private function fulfill(LuckySeatDrop $drop, User $user, Booking $booking, Computer $computer): void
    {
        $rolled = $this->roll();
        $reward = match ($rolled) {
            LuckySeatDrop::REWARD_DRINK => $this->grantDrink($user, $booking, $computer),
            LuckySeatDrop::REWARD_STORE_PROMO => $this->grantPromo($user, $drop),
            default => null,
        };
        if (! $reward) {
            $rolled = LuckySeatDrop::REWARD_BONUS;
            $reward = $this->grantBonus($user, (int) ($drop->club_id ?: 0));
        }

        $drop->update([
            'status' => LuckySeatDrop::STATUS_OPENED,
            'reward_type' => $rolled,
            'reward' => $reward,
            'opened_at' => now(),
        ]);
    }

    private function roll(): string
    {
        if (self::$forceReward && in_array(self::$forceReward, [
            LuckySeatDrop::REWARD_BONUS,
            LuckySeatDrop::REWARD_DRINK,
            LuckySeatDrop::REWARD_STORE_PROMO,
        ], true)) {
            return self::$forceReward;
        }
        $n = random_int(1, 100);
        if ($n <= 55) {
            return LuckySeatDrop::REWARD_BONUS;
        }
        if ($n <= 85) {
            return LuckySeatDrop::REWARD_DRINK;
        }

        return LuckySeatDrop::REWARD_STORE_PROMO;
    }

    /**
     * @return array<string, mixed>
     */
    private function grantBonus(User $user, ?int $clubId = null): array
    {
        $amounts = $this->bonusAmounts($clubId);
        $amount = $amounts[array_rand($amounts)];
        $user->syncBalanceToWallet();
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
        $wallet->increment('bonus_balance', $amount);
        Transaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'type' => 'deposit',
            'source' => 'lucky_seat',
            'is_taxable' => false,
            'description' => 'Lucky Seat: бонус '.$amount.' ₽',
        ]);

        return [
            'type' => LuckySeatDrop::REWARD_BONUS,
            'amount' => $amount,
            'label' => '+'.$amount.' ₽ на бонусный баланс',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function grantDrink(User $user, Booking $booking, Computer $computer): ?array
    {
        $product = $this->pickDrink();
        if (! $product) {
            return null;
        }
        try {
            $this->stock->assertAvailable($product, 1);
            $this->stock->decrementUnmarked($product, 1, null);
        } catch (Throwable) {
            return null;
        }

        $name = (string) $product->name;
        $line = [[
            'product_id' => $product->id,
            'name' => $name,
            'qty' => 1,
            'unit_price' => 0,
            'line_total' => 0,
        ]];
        $summary = 'LUCKY SEAT: '.$name;
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'product_name' => $summary,
            'items' => json_encode($line, JSON_UNESCAPED_UNICODE),
            'price' => 0,
            'pc_name' => OrderDeliveryTarget::labelForComputerId((int) $computer->id) ?: (string) $computer->name,
            'channel' => OrderChannel::SHELL,
            'status' => Order::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->find($orderId);
        if ($order) {
            try {
                $this->kitchen->enqueue($order);
            } catch (Throwable) {
            }
        }

        return [
            'type' => LuckySeatDrop::REWARD_DRINK,
            'order_id' => $orderId,
            'product_name' => $name,
            'label' => 'Напиток на бар: '.$name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grantPromo(User $user, LuckySeatDrop $drop): array
    {
        $clubId = (int) ($drop->club_id ?: 0);
        $percent = max(5, $this->features()->int($clubId, 'lucky_seat', 'promo_percent', self::PROMO_PERCENT));
        $days = max(1, $this->features()->int($clubId, 'lucky_seat', 'promo_days', self::PROMO_DAYS));
        $code = $this->uniquePromoCode();
        $expires = now()->addDays($days);
        StorePromoCode::query()->create([
            'code' => $code,
            'user_id' => $user->id,
            'percent' => $percent,
            'scope' => StorePromoCode::SCOPE_PERIPHERAL,
            'status' => StorePromoCode::STATUS_ACTIVE,
            'lucky_seat_drop_id' => $drop->id,
            'expires_at' => $expires,
        ]);

        return [
            'type' => LuckySeatDrop::REWARD_STORE_PROMO,
            'code' => $code,
            'percent' => $percent,
            'expires_at' => $expires->toIso8601String(),
            'label' => 'Промокод '.$code.' — '.$percent.'% на периферию в REACTOR Store',
        ];
    }

    private function pickDrink(): ?Product
    {
        return Product::query()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->where(function ($q) {
                $q->whereNull('requires_marking')->orWhere('requires_marking', false);
            })
            ->where(function ($q) {
                $q->where('category', 'like', '%напит%')
                    ->orWhere('category', 'like', '%drink%')
                    ->orWhere('category', 'like', '%бар%')
                    ->orWhere('name', 'like', '%red bull%')
                    ->orWhere('name', 'like', '%энерг%')
                    ->orWhere('name', 'like', '%кола%')
                    ->orWhere('name', 'like', '%адреналин%');
            })
            ->orderBy('price')
            ->orderBy('id')
            ->first();
    }

    private function uniquePromoCode(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code = 'RX-'.strtoupper(Str::random(5));
            if (! StorePromoCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return 'RX-'.strtoupper(Str::lower(bin2hex(random_bytes(3))));
    }

    /**
     * @return array{match_streak:int, round_streak:int}
     */
    private function sessionStats(int $bookingId): array
    {
        $key = $this->cacheKey($bookingId);
        if (isset(self::$sessions[$key])) {
            return self::$sessions[$key];
        }
        $cached = Cache::get($key);
        if (is_array($cached)) {
            self::$sessions[$key] = [
                'match_streak' => (int) ($cached['match_streak'] ?? 0),
                'round_streak' => (int) ($cached['round_streak'] ?? 0),
            ];

            return self::$sessions[$key];
        }

        return ['match_streak' => 0, 'round_streak' => 0];
    }

    /**
     * @param  array{match_streak:int, round_streak:int}  $stats
     */
    private function putSession(int $bookingId, array $stats): void
    {
        $key = $this->cacheKey($bookingId);
        self::$sessions[$key] = $stats;
        Cache::put($key, $stats, self::SESSION_TTL);
    }

    private function cacheKey(int $bookingId): string
    {
        return 'lucky:sess:'.$bookingId;
    }

    private function features(): ClubFeatureService
    {
        return app(ClubFeatureService::class);
    }

    private function clubId(Computer $computer): ?int
    {
        return $computer->club_id ? (int) $computer->club_id : null;
    }

    private function matchStreak(?int $clubId): int
    {
        return max(1, $this->features()->int($clubId, 'lucky_seat', 'match_streak', self::MATCH_STREAK));
    }

    private function roundStreak(?int $clubId): int
    {
        return max(2, $this->features()->int($clubId, 'lucky_seat', 'round_streak', self::ROUND_STREAK));
    }

    private function cooldownSeconds(?int $clubId): int
    {
        $hours = $this->features()->float($clubId, 'lucky_seat', 'cooldown_hours', self::COOLDOWN_SECONDS / 3600);

        return max(60, (int) round($hours * 3600));
    }

    private function playtimeSeconds(?int $clubId): int
    {
        $hours = $this->features()->float($clubId, 'lucky_seat', 'playtime_hours', self::COOLDOWN_SECONDS / 3600);

        return max(60, (int) round($hours * 3600));
    }

    private function playtimeLabel(?int $clubId): string
    {
        $hours = $this->features()->float($clubId, 'lucky_seat', 'playtime_hours', 3);
        $label = fmod($hours, 1.0) < 0.05 ? (string) (int) $hours : rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');

        return $label.' ч в клубе';
    }

    /**
     * @return list<int>
     */
    private function bonusAmounts(?int $clubId): array
    {
        $low = max(1, $this->features()->int($clubId, 'lucky_seat', 'bonus_low', 50));
        $mid = max(1, $this->features()->int($clubId, 'lucky_seat', 'bonus_mid', 75));
        $high = max(1, $this->features()->int($clubId, 'lucky_seat', 'bonus_high', 100));

        return [$low, $mid, $high];
    }
}
