<?php

namespace App\Services\LanLive;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\LanBounty;
use App\Models\LanBountyEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\KitchenOrderPrintService;
use App\Services\ProductStockService;
use App\Support\OrderChannel;
use App\Support\OrderDeliveryTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LanBountyService
{
    public const MIN_DEPOSIT = 50.0;

    public const MAX_DEPOSIT = 5000.0;

    public const MATCH_WINDOW_SECONDS = 4;

    public function __construct(
        private readonly ProductStockService $stock,
        private readonly KitchenOrderPrintService $kitchen,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function boardForComputer(Computer $computer, ?Booking $booking): array
    {
        $this->expireOpen($computer->club_id ? (int) $computer->club_id : 0);

        $clubId = (int) ($computer->club_id ?? 0);
        $rows = LanBounty::query()
            ->with(['targetComputer', 'hunterComputer'])
            ->where('club_id', $clubId)
            ->whereIn('status', [LanBounty::STATUS_OPEN, LanBounty::STATUS_WON])
            ->where(function ($q) {
                $q->where('status', LanBounty::STATUS_OPEN)
                    ->orWhere('settled_at', '>=', now()->subMinutes(8));
            })
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return $rows->map(fn (LanBounty $b) => $this->payload($b, $booking))->all();
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function targets(Computer $computer, Booking $booking): array
    {
        $clubId = (int) ($computer->club_id ?? 0);
        $mine = (int) $computer->id;
        $ids = Booking::query()
            ->where('status', 'active')
            ->whereNotNull('computer_id')
            ->where('computer_id', '!=', $mine)
            ->whereHas('computer', fn ($q) => $q->where('club_id', $clubId)->where('kind', 'pc'))
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $names = Computer::query()->whereIn('id', $ids)->pluck('name', 'id');
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => $id,
                'name' => (string) ($names[$id] ?? ('ПК №'.$id)),
            ];
        }
        usort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return list<array{id:int,name:string,price:float}>
     */
    public function stakeProducts(): array
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
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'price' => (float) $p->price,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $poster, Computer $from, Booking $booking, array $input): LanBounty
    {
        $targetId = (int) ($input['target_computer_id'] ?? 0);
        if ($targetId < 1 || $targetId === (int) $from->id) {
            throw new RuntimeException('Выберите чужой ПК в клубе');
        }
        $target = Computer::query()->find($targetId);
        if (! $target || (int) $target->club_id !== (int) $from->club_id) {
            throw new RuntimeException('Цель не в этом клубе');
        }
        $alive = Booking::query()
            ->where('status', 'active')
            ->where('computer_id', $targetId)
            ->exists();
        if (! $alive) {
            throw new RuntimeException('На этом месте сейчас никто не сидит');
        }

        $open = LanBounty::query()
            ->where('poster_user_id', $poster->id)
            ->where('status', LanBounty::STATUS_OPEN)
            ->exists();
        if ($open) {
            throw new RuntimeException('Сначала снимите предыдущую охоту');
        }

        $kind = ($input['kind'] ?? '') === LanBounty::KIND_DUEL
            ? LanBounty::KIND_DUEL
            : LanBounty::KIND_FRAG;
        $game = ($input['game'] ?? '') === 'dota' ? 'dota' : 'cs2';
        $weapon = $this->normalizeWeapon((string) ($input['weapon'] ?? 'any'));
        $stakeType = ($input['stake_type'] ?? '') === LanBounty::STAKE_PRODUCT
            ? LanBounty::STAKE_PRODUCT
            : LanBounty::STAKE_DEPOSIT;

        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) > 180) {
            $title = mb_substr($title, 0, 180);
        }
        if ($title === '') {
            $title = $this->defaultTitle($kind, $weapon, $game, (string) $target->name);
        }

        $ends = $booking->ends_at
            ? CarbonImmutable::parse($booking->ends_at)
            : now()->addHours(4);
        $expires = $ends->greaterThan(now()->addHours(4)) ? now()->addHours(4) : $ends;

        return DB::transaction(function () use (
            $poster, $from, $booking, $target, $kind, $game, $weapon, $stakeType, $title, $expires, $input
        ) {
            $user = User::query()->lockForUpdate()->findOrFail($poster->id);
            $user->syncBalanceToWallet();
            $wallet = $user->wallet()->lockForUpdate()->first();
            if (! $wallet) {
                throw new RuntimeException('Кошелёк не найден');
            }

            $product = null;
            $amount = 0.0;
            $productName = null;
            if ($stakeType === LanBounty::STAKE_PRODUCT) {
                $product = Product::query()->lockForUpdate()->find((int) ($input['product_id'] ?? 0));
                if (! $product || ! $product->is_active) {
                    throw new RuntimeException('Напиток не найден');
                }
                if ($product->requires_marking) {
                    throw new RuntimeException('Маркированный товар в охоту нельзя');
                }
                $this->stock->assertAvailable($product, 1);
                $amount = (float) $product->price;
                $productName = (string) $product->name;
            } else {
                $amount = round((float) ($input['stake_amount'] ?? 0), 2);
                if ($amount + 0.009 < self::MIN_DEPOSIT || $amount > self::MAX_DEPOSIT) {
                    throw new RuntimeException('Ставка от '.((int) self::MIN_DEPOSIT).' до '.((int) self::MAX_DEPOSIT).' ₽');
                }
            }

            if ((float) $user->availableBalance() + 0.009 < $amount) {
                throw new RuntimeException('Недостаточно депозита на награду');
            }

            $wallet->debitSpendable($amount);
            if ($product) {
                $this->stock->decrementUnmarked($product, 1, null);
            }

            $bounty = LanBounty::query()->create([
                'club_id' => (int) $from->club_id,
                'poster_user_id' => $user->id,
                'poster_computer_id' => $from->id,
                'poster_booking_id' => $booking->id,
                'target_computer_id' => $target->id,
                'kind' => $kind,
                'game' => $game,
                'weapon' => $weapon,
                'title' => $title,
                'stake_type' => $stakeType,
                'stake_amount' => $amount,
                'product_id' => $product?->id,
                'product_name' => $productName,
                'status' => LanBounty::STATUS_OPEN,
                'expires_at' => $expires,
                'payload' => [
                    'target_name' => $target->name,
                    'poster_pc' => $from->name,
                ],
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'amount' => -$amount,
                'type' => 'purchase',
                'source' => 'lan_bounty',
                'description' => 'Охота: '.$title,
                'payload' => [
                    'bounty_id' => $bounty->id,
                    'stake_type' => $stakeType,
                    'target_computer_id' => $target->id,
                ],
            ]);

            return $bounty;
        });
    }

    public function cancel(User $actor, LanBounty $bounty): LanBounty
    {
        if ((int) $bounty->poster_user_id !== (int) $actor->id) {
            throw new RuntimeException('Снять охоту может только автор');
        }
        if ($bounty->status !== LanBounty::STATUS_OPEN) {
            throw new RuntimeException('Охота уже закрыта');
        }

        return DB::transaction(function () use ($bounty) {
            $bounty = LanBounty::query()->lockForUpdate()->findOrFail($bounty->id);
            if ($bounty->status !== LanBounty::STATUS_OPEN) {
                return $bounty;
            }
            $this->refundPoster($bounty, 'Охота отменена: '.$bounty->title);
            $bounty->update(['status' => LanBounty::STATUS_CANCELLED]);

            return $bounty->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $snap
     * @return array{settled: ?array<string, mixed>, event_id: int}
     */
    public function ingest(
        Computer $computer,
        User $user,
        Booking $booking,
        array $snap,
    ): array {
        $eventName = strtolower(trim((string) ($snap['event'] ?? 'heartbeat')));
        $game = ($snap['game'] ?? '') === 'dota' ? 'dota' : 'cs2';
        $clubId = (int) ($computer->club_id ?? 0);

        $row = LanBountyEvent::query()->create([
            'club_id' => $clubId,
            'computer_id' => $computer->id,
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'steam_id' => substr((string) ($snap['steam_id'] ?? ''), 0, 32) ?: null,
            'event' => $eventName,
            'game' => $game,
            'weapon' => substr((string) ($snap['weapon'] ?? ''), 0, 64) ?: null,
            'map' => substr((string) ($snap['map'] ?? ''), 0, 64) ?: null,
            'match_id' => substr((string) ($snap['match_id'] ?? ''), 0, 48) ?: null,
            'round' => isset($snap['round']) ? (int) $snap['round'] : null,
            'occurred_at' => now(),
            'payload' => [
                'team' => $snap['team'] ?? null,
                'money' => $snap['money'] ?? null,
            ],
        ]);

        $settled = null;
        if (in_array($eventName, ['kill', 'death', 'round_win', 'round_loss', 'match_win'], true)) {
            $settled = $this->trySettle($row);
        }

        return [
            'event_id' => (int) $row->id,
            'settled' => $settled,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function trySettle(LanBountyEvent $event): ?array
    {
        $pair = $this->counterpart($event);
        if (! $pair) {
            return null;
        }

        if ($event->event === 'kill' || $event->event === 'round_win' || $event->event === 'match_win') {
            $hunterEvent = $event;
            $targetEvent = $pair;
        } else {
            $hunterEvent = $pair;
            $targetEvent = $event;
        }

        $kind = in_array($hunterEvent->event, ['round_win', 'match_win'], true)
            ? LanBounty::KIND_DUEL
            : LanBounty::KIND_FRAG;

        $open = LanBounty::query()
            ->where('status', LanBounty::STATUS_OPEN)
            ->where('club_id', $event->club_id)
            ->where('game', $event->game)
            ->where('kind', $kind)
            ->where('target_computer_id', $targetEvent->computer_id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->get();

        foreach ($open as $bounty) {
            if (! $this->weaponMatches($bounty->weapon, $hunterEvent->weapon)) {
                continue;
            }
            if ((int) $hunterEvent->computer_id === (int) $bounty->target_computer_id) {
                continue;
            }

            return $this->settle($bounty, $hunterEvent);
        }

        return null;
    }

    private function counterpart(LanBountyEvent $event): ?LanBountyEvent
    {
        $need = match ($event->event) {
            'kill' => 'death',
            'death' => 'kill',
            'round_win' => 'round_loss',
            'round_loss' => 'round_win',
            'match_win' => 'death',
            default => null,
        };
        if ($need === null) {
            return null;
        }

        $from = $event->occurred_at->copy()->subSeconds(self::MATCH_WINDOW_SECONDS);
        $to = $event->occurred_at->copy()->addSeconds(self::MATCH_WINDOW_SECONDS);

        $q = LanBountyEvent::query()
            ->where('club_id', $event->club_id)
            ->where('event', $need)
            ->where('game', $event->game)
            ->where('computer_id', '!=', $event->computer_id)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('id');

        if ($event->map) {
            $q->where(function ($inner) use ($event) {
                $inner->where('map', $event->map)
                    ->orWhereNull('map')
                    ->orWhere('map', '');
            });
        }

        return $q->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function settle(LanBounty $bounty, LanBountyEvent $hunterEvent): array
    {
        return DB::transaction(function () use ($bounty, $hunterEvent) {
            $bounty = LanBounty::query()->lockForUpdate()->findOrFail($bounty->id);
            if ($bounty->status !== LanBounty::STATUS_OPEN) {
                return ['already' => true];
            }

            $hunterUser = User::query()->lockForUpdate()->find($hunterEvent->user_id);
            $hunterPc = Computer::query()->find($hunterEvent->computer_id);
            if (! $hunterUser || ! $hunterPc) {
                throw new RuntimeException('Охотник не найден');
            }

            $prize = null;
            if ($bounty->stake_type === LanBounty::STAKE_PRODUCT && $bounty->product_id) {
                $prize = $this->printPrizeSlip($bounty, $hunterUser, $hunterPc);
            } else {
                $hunterUser->syncBalanceToWallet();
                $wallet = $hunterUser->wallet()->lockForUpdate()->first();
                if (! $wallet) {
                    $wallet = \App\Models\Wallet::query()->create([
                        'user_id' => $hunterUser->id,
                        'deposit_balance' => 0,
                        'bonus_balance' => 0,
                    ]);
                    $wallet = $hunterUser->wallet()->lockForUpdate()->first();
                }
                $wallet->creditSpendable((float) $bounty->stake_amount);
                Transaction::create([
                    'user_id' => $hunterUser->id,
                    'amount' => (float) $bounty->stake_amount,
                    'type' => 'deposit',
                    'source' => 'lan_bounty',
                    'description' => 'Награда охоты: '.$bounty->title,
                    'payload' => ['bounty_id' => $bounty->id],
                ]);
                $prize = [
                    'type' => 'deposit',
                    'amount' => (float) $bounty->stake_amount,
                    'label' => number_format((float) $bounty->stake_amount, 0, '.', ' ').' ₽ на депозит',
                ];
            }

            $bounty->update([
                'status' => LanBounty::STATUS_WON,
                'hunter_user_id' => $hunterUser->id,
                'hunter_computer_id' => $hunterPc->id,
                'settled_at' => now(),
                'payload' => array_merge($bounty->payload ?? [], [
                    'hunter_name' => $hunterUser->name,
                    'hunter_pc' => $hunterPc->name,
                    'weapon' => $hunterEvent->weapon,
                ]),
            ]);

            $message = sprintf(
                'Охота закрыта: %s. Награда — %s, ПК %s.',
                $bounty->title,
                $prize['label'] ?? 'приз',
                $hunterPc->name
            );

            return [
                'bounty_id' => $bounty->id,
                'title' => $bounty->title,
                'message' => $message,
                'prize' => $prize,
                'hunter_computer_id' => (int) $hunterPc->id,
                'target_computer_id' => (int) $bounty->target_computer_id,
            ];
        });
    }

    /**
     * @return array{type:string,label:string,order_id:int,name:string}
     */
    private function printPrizeSlip(LanBounty $bounty, User $hunter, Computer $pc): array
    {
        $name = (string) ($bounty->product_name ?: 'Напиток');
        $line = [[
            'product_id' => $bounty->product_id,
            'name' => $name,
            'qty' => 1,
            'unit_price' => (float) $bounty->stake_amount,
            'line_total' => (float) $bounty->stake_amount,
        ]];
        $summary = 'ОХОТА: '.$name.' → '.($pc->name ?: 'ПК');
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => $hunter->id,
            'booking_id' => $bounty->poster_booking_id,
            'product_name' => $summary,
            'items' => json_encode($line, JSON_UNESCAPED_UNICODE),
            'price' => (float) $bounty->stake_amount,
            'pc_name' => OrderDeliveryTarget::labelForComputerId((int) $pc->id) ?: (string) $pc->name,
            'channel' => OrderChannel::SHELL,
            'status' => Order::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->find($orderId);
        if ($order) {
            try {
                $this->kitchen->enqueue($order);
            } catch (\Throwable) {
            }
        }

        return [
            'type' => 'product',
            'order_id' => $orderId,
            'name' => $name,
            'label' => $name.' (слип на бар)',
        ];
    }

    private function refundPoster(LanBounty $bounty, string $description): void
    {
        $poster = User::query()->lockForUpdate()->find($bounty->poster_user_id);
        if (! $poster) {
            return;
        }
        $poster->syncBalanceToWallet();
        $wallet = $poster->wallet()->lockForUpdate()->first();
        if ($wallet && (float) $bounty->stake_amount > 0) {
            $wallet->creditSpendable((float) $bounty->stake_amount);
            Transaction::create([
                'user_id' => $poster->id,
                'amount' => (float) $bounty->stake_amount,
                'type' => 'deposit',
                'source' => 'lan_bounty_refund',
                'description' => $description,
                'payload' => ['bounty_id' => $bounty->id],
            ]);
        }
        if ($bounty->stake_type === LanBounty::STAKE_PRODUCT && $bounty->product_id) {
            $product = Product::query()->lockForUpdate()->find($bounty->product_id);
            if ($product) {
                $product->increment('stock', 1);
            }
        }
    }

    public function expireOpen(?int $clubId = null): int
    {
        $q = LanBounty::query()
            ->where('status', LanBounty::STATUS_OPEN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
        if ($clubId) {
            $q->where('club_id', $clubId);
        }
        $n = 0;
        foreach ($q->get() as $bounty) {
            DB::transaction(function () use ($bounty, &$n) {
                $row = LanBounty::query()->lockForUpdate()->find($bounty->id);
                if (! $row || $row->status !== LanBounty::STATUS_OPEN) {
                    return;
                }
                $this->refundPoster($row, 'Охота истекла: '.$row->title);
                $row->update(['status' => LanBounty::STATUS_EXPIRED]);
                $n++;
            });
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(LanBounty $bounty, ?Booking $viewer = null): array
    {
        $target = $bounty->relationLoaded('targetComputer')
            ? $bounty->targetComputer
            : $bounty->targetComputer()->first();
        $mine = $viewer && (int) $viewer->computer_id === (int) $bounty->poster_computer_id;

        return [
            'id' => $bounty->id,
            'title' => $bounty->title,
            'kind' => $bounty->kind,
            'game' => $bounty->game,
            'weapon' => $bounty->weapon,
            'stake_type' => $bounty->stake_type,
            'stake_amount' => (float) $bounty->stake_amount,
            'product_name' => $bounty->product_name,
            'status' => $bounty->status,
            'target_computer_id' => (int) $bounty->target_computer_id,
            'target_name' => $target?->name,
            'poster_computer_id' => (int) $bounty->poster_computer_id,
            'hunter_computer_id' => $bounty->hunter_computer_id ? (int) $bounty->hunter_computer_id : null,
            'mine' => $mine,
            'expires_at' => optional($bounty->expires_at)?->toIso8601String(),
            'prize_label' => $bounty->stake_type === LanBounty::STAKE_PRODUCT
                ? (string) $bounty->product_name
                : number_format((float) $bounty->stake_amount, 0, '.', ' ').' ₽',
        ];
    }

    public function normalizeWeapon(string $raw): string
    {
        $w = strtolower(trim($raw));

        return match (true) {
            $w === 'knife' || str_contains($w, 'knife') || str_contains($w, 'нож') => 'knife',
            $w === 'awp' || str_contains($w, 'awp') => 'awp',
            default => 'any',
        };
    }

    public function weaponMatches(string $wanted, ?string $actual): bool
    {
        $wanted = $this->normalizeWeapon($wanted);
        if ($wanted === 'any') {
            return true;
        }
        $actual = strtolower((string) $actual);
        if ($wanted === 'knife') {
            foreach (['knife', 'bayonet', 'karambit', 'gut', 'flip', 'butterfly', 'shadow', 'falchion', 'bowie', 'huntsman', 'navaja', 'stiletto', 'talon', 'ursus', 'skeleton', 'kukri'] as $n) {
                if (str_contains($actual, $n)) {
                    return true;
                }
            }

            return false;
        }
        if ($wanted === 'awp') {
            return str_contains($actual, 'awp');
        }

        return true;
    }

    private function defaultTitle(string $kind, string $weapon, string $game, string $pc): string
    {
        if ($kind === LanBounty::KIND_DUEL) {
            $w = $weapon === 'awp' ? '1v1 AWP' : '1v1';

            return $w.' против '.$pc;
        }
        $w = match ($weapon) {
            'knife' => 'ножом',
            'awp' => 'с AWP',
            default => '',
        };
        $g = $game === 'dota' ? 'в Dota' : 'в CS2';

        return trim('Убей '.$pc.' '.$w.' '.$g);
    }
}
