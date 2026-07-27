<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Models\User;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Order;
use App\Models\GameAccount;
use App\Models\GameAccountMachineCache;
use App\Models\Game;
use App\Models\Computer;
use App\Models\UserGameStat;
use App\Models\ComputerInputDevice;
use App\Models\ComputerInputAlert;
use App\Models\ComputerSosAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShellApiController extends Controller
{
    public function getActiveOverlays()
    {
        $overlays = Overlay::where('is_active', true)
            ->get()
            ->keyBy('block_position');

        return response()->json([
            'status' => 'success',
            'data' => $overlays
        ]);
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'pin' => 'required|string|size:4',
                'terminal_id' => 'required|integer'
            ]);

            $user = User::where('phone', $request->phone)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Пользователь не найден'], 404);
            }

            $booking = Booking::where('user_id', $user->id)
                ->where('pin_code', $request->pin)
                ->whereIn('status', ['paid', 'active', 'completed'])
                ->first();

            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Неверный PIN-код, либо сессия уже была активирована.'
                ], 401);
            }

            $now = now();
            $duration = $booking->duration ?: $booking->end_time->diffInMinutes($booking->start_time);
            $durationMinutes = (float)$duration * 60;

            $floatStartTime = $now->hour + ($now->minute / 60);

            $booking->update([
                'status' => 'active',
                'start_time' => $floatStartTime,
                'pin_code' => null,
                'computer_id' => (int) $request->terminal_id,
            ]);

            $hours = floor($durationMinutes / 60);
            $minutes = floor($durationMinutes % 60);
            $formattedTime = sprintf('%02d:%02d:%02d', $hours, $minutes, 0);

            // Sync legacy users.balance / wallets.balance into deposit_balance so shell matches admin.
            $balance = $user->syncBalanceToWallet();

            return response()->json([
                'status' => 'success',
                'message' => 'Авторизация успешна.',
                'booking_id' => $booking->id,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Игрок',
                    'balance' => $balance,
                    'deposit_balance' => $balance,
                    'total_balance' => $balance,
                    'time_remaining' => $formattedTime
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error("Shell API Login Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lightweight balance poll for an active shell session (no auth re-login).
     * Prefer booking_id / active booking on terminal; fall back to user_id.
     */
    public function getBalance(Request $request)
    {
        try {
            $bookingId = (int) $request->query('booking_id', 0);
            $terminalId = (int) $request->query('terminal_id', 0);
            $userId = (int) $request->query('user_id', 0);

            $booking = null;
            if ($bookingId > 0) {
                $booking = Booking::where('id', $bookingId)
                    ->where('status', 'active')
                    ->first();
            }
            if (!$booking && $terminalId > 0) {
                $booking = Booking::where('status', 'active')
                    ->where(function ($query) use ($terminalId) {
                        $query->whereJsonContains('pc_ids', (string) $terminalId)
                            ->orWhere('computer_id', $terminalId);
                    })->first();
            }

            $user = null;
            if ($booking) {
                $user = User::find($booking->user_id);
            } elseif ($userId > 0) {
                $user = User::find($userId);
            }

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Активная сессия не найдена.',
                ], 404);
            }

            // Read-only for polling (login already syncs legacy columns).
            $balance = $user->availableBalance();

            return response()->json([
                'status' => 'success',
                'user_id' => $user->id,
                'booking_id' => $booking?->id,
                'balance' => $balance,
                'deposit_balance' => $balance,
                'total_balance' => $balance,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API getBalance: '.$e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getGames(Request $request)
    {
        try {
            $games = Game::query()
                ->orderBy('title')
                ->get()
                ->map(fn (Game $game) => $this->mapGamePayload($game))
                ->values();

            $userId = (int) $request->query('user_id', 0);
            $featured = $this->buildFeaturedGames($userId > 0 ? $userId : null);

            // Enriched payload for personalization; shell understands both array and object.
            return response()->json([
                'status' => 'success',
                'games' => $games,
                'featured' => $featured,
            ]);
        } catch (\Exception $e) {
            Log::error('Shell API getGames: '.$e->getMessage());
            return response()->json([]);
        }
    }

    public function recordGameLaunch(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'game_id' => 'required|integer|exists:games,id',
        ]);

        try {
            $stat = UserGameStat::firstOrNew([
                'user_id' => (int) $request->user_id,
                'game_id' => (int) $request->game_id,
            ]);
            $stat->launch_count = ((int) $stat->launch_count) + 1;
            $stat->last_launched_at = now();
            $stat->save();

            return response()->json([
                'status' => 'success',
                'launch_count' => $stat->launch_count,
                'last_launched_at' => optional($stat->last_launched_at)->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API recordGameLaunch: '.$e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getGameTops(Request $request)
    {
        $userId = (int) $request->query('user_id', 0);

        return response()->json([
            'status' => 'success',
            'featured' => $this->buildFeaturedGames($userId > 0 ? $userId : null),
        ]);
    }

    public function saveHidSnapshot(Request $request)
    {
        $request->validate([
            'computer_id' => 'required|integer|exists:computers,id',
            'fingerprint' => 'required|array',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        try {
            $device = ComputerInputDevice::updateOrCreate(
                ['computer_id' => (int) $request->computer_id],
                [
                    'booking_id' => $request->booking_id ? (int) $request->booking_id : null,
                    'fingerprint' => $request->fingerprint,
                    'bound_at' => now(),
                ]
            );

            return response()->json([
                'status' => 'success',
                'computer_id' => $device->computer_id,
                'bound_at' => optional($device->bound_at)->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API saveHidSnapshot: '.$e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function reportHidAlert(Request $request)
    {
        $request->validate([
            'computer_id' => 'required|integer|exists:computers,id',
            'type' => 'required|string|in:device_changed,disconnected,unstable',
            'payload' => 'nullable|array',
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'severity' => 'nullable|string|in:info,warn,critical',
        ]);

        try {
            $alert = ComputerInputAlert::create([
                'computer_id' => (int) $request->computer_id,
                'booking_id' => $request->booking_id ? (int) $request->booking_id : null,
                'type' => $request->type,
                'severity' => $request->input('severity', 'warn'),
                'payload' => $request->input('payload', []),
            ]);

            Log::warning('[HID-ALERT]', [
                'id' => $alert->id,
                'computer_id' => $alert->computer_id,
                'type' => $alert->type,
                'severity' => $alert->severity,
            ]);

            return response()->json([
                'status' => 'success',
                'alert_id' => $alert->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API reportHidAlert: '.$e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function reportSos(Request $request)
    {
        $request->validate([
            'computer_id' => 'required|integer|exists:computers,id',
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'reason' => 'required|array',
            'reason.code' => 'required|string|in:peripherals,auth_help,other',
            'reason.label' => 'required|string|max:255',
            'timestamp' => 'nullable|date',
        ]);

        try {
            $reasonCode = (string) $request->input('reason.code');
            $reasonLabel = (string) $request->input('reason.label');
            $reportedAt = $request->filled('timestamp')
                ? \Carbon\Carbon::parse($request->input('timestamp'))
                : now();

            $alert = ComputerSosAlert::create([
                'computer_id' => (int) $request->computer_id,
                'booking_id' => $request->booking_id ? (int) $request->booking_id : null,
                'reason_code' => $reasonCode,
                'reason_label' => $reasonLabel,
                'payload' => [
                    'reason' => [
                        'code' => $reasonCode,
                        'label' => $reasonLabel,
                    ],
                    'reported_at' => $reportedAt->toIso8601String(),
                ],
            ]);

            // Hook into existing admin_calls channel when a user session is known.
            $pc = Computer::find((int) $request->computer_id);
            $pcName = $pc?->name ?: ('PC-'.$request->computer_id);
            $booking = $request->booking_id
                ? Booking::find((int) $request->booking_id)
                : null;
            if ($booking && $booking->user_id) {
                DB::table('admin_calls')->insert([
                    'user_id' => $booking->user_id,
                    'pc_name' => $pcName,
                    'message' => 'SOS: '.$reasonLabel,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::warning('[SOS-ALERT]', [
                'id' => $alert->id,
                'computer_id' => $alert->computer_id,
                'booking_id' => $alert->booking_id,
                'reason_code' => $alert->reason_code,
                'reason_label' => $alert->reason_label,
                'reported_at' => $reportedAt->toIso8601String(),
            ]);

            return response()->json([
                'status' => 'success',
                'alert_id' => $alert->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API reportSos: '.$e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function mapGamePayload(Game $game): array
    {
        return [
            'id' => $game->id,
            'title' => $game->title,
            'category' => $game->category ?? 'STEAM',
            'platform' => $game->platform ?? 'PC',
            'poster' => $game->poster ? $game->poster : '',
            'exe_path' => $game->exe_path,
            'args' => $game->launch_args ?? $game->args ?? '',
        ];
    }

    /**
     * Personal top (last 30 days by launch_count) or club top fallback.
     */
    private function buildFeaturedGames(?int $userId): array
    {
        $since = now()->subDays(30);
        // One first-row strip in the shell grid (max 6 tiles).
        $personalLimit = 6;
        $clubLimit = 6;

        $personalIds = [];
        if ($userId) {
            $personalIds = UserGameStat::query()
                ->where('user_id', $userId)
                ->where('last_launched_at', '>=', $since)
                ->orderByDesc('launch_count')
                ->orderByDesc('last_launched_at')
                ->limit($personalLimit)
                ->pluck('game_id')
                ->all();
        }

        if (!empty($personalIds)) {
            $games = Game::whereIn('id', $personalIds)->get()->keyBy('id');
            $ordered = collect($personalIds)
                ->map(fn ($id) => isset($games[$id]) ? $this->mapGamePayload($games[$id]) : null)
                ->filter()
                ->values();

            return [
                'mode' => 'personal',
                'label' => 'Вы часто играете',
                'games' => $ordered,
            ];
        }

        $clubIds = UserGameStat::query()
            ->select('game_id', DB::raw('SUM(launch_count) as total_launches'), DB::raw('MAX(last_launched_at) as last_at'))
            ->where('last_launched_at', '>=', $since)
            ->groupBy('game_id')
            ->orderByDesc('total_launches')
            ->orderByDesc('last_at')
            ->limit($clubLimit)
            ->pluck('game_id')
            ->all();

        if (empty($clubIds)) {
            return [
                'mode' => 'club',
                'label' => 'Популярно в клубе',
                'games' => [],
            ];
        }

        $games = Game::whereIn('id', $clubIds)->get()->keyBy('id');
        $ordered = collect($clubIds)
            ->map(fn ($id) => isset($games[$id]) ? $this->mapGamePayload($games[$id]) : null)
            ->filter()
            ->values();

        return [
            'mode' => 'club',
            'label' => 'Популярно в клубе',
            'games' => $ordered,
        ];
    }

    /** Russian labels for shell / admin order statuses. */
    public static function orderStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'ЗАКАЗ ПРИНЯТ',
            'cooking' => 'В РАБОТЕ',
            'delivered' => 'ЗАКАЗ ВЫПОЛНЕН',
            'cancelled' => 'ЗАКАЗ ОТМЕНЁН',
            default => 'В РАБОТЕ',
        };
    }

    private function ordersForTerminal(int $terminalId)
    {
        return DB::table('orders')->where('pc_name', 'ПК №' . $terminalId);
    }

    /**
     * Active + briefly keep finished orders so shell can show ✓ / отмена.
     */
    private function shellOrderSnapshot(int $terminalId): array
    {
        if ($terminalId <= 0) {
            return [
                'has_active_order' => false,
                'status_text' => '',
                'order_id' => null,
                'status' => null,
                'orders' => [],
            ];
        }

        $active = $this->ordersForTerminal($terminalId)
            ->whereIn('status', ['pending', 'cooking'])
            ->orderByDesc('id')
            ->get();

        $recentDone = $this->ordersForTerminal($terminalId)
            ->whereIn('status', ['delivered', 'cancelled'])
            ->where('updated_at', '>=', now()->subSeconds(60))
            ->orderByDesc('updated_at')
            ->get();

        $orders = $active->concat($recentDone)->unique('id')->values()->map(function ($order) {
            return $this->mapOrderPayload($order);
        });

        $primary = $active->first() ?? $recentDone->first();
        $hasActive = $active->isNotEmpty()
            || ($primary && in_array($primary->status, ['delivered', 'cancelled'], true));

        return [
            'has_active_order' => (bool) $hasActive && $primary !== null,
            'status_text' => $primary ? self::orderStatusLabel($primary->status) : '',
            'order_id' => $primary ? (int) $primary->id : null,
            'status' => $primary?->status,
            'orders' => $orders,
        ];
    }

    public function getProducts(Request $request)
    {
        $terminalId = (int) $request->input('terminal_id', 0);
        $snapshot = $this->shellOrderSnapshot($terminalId);

        return response()->json([
            'has_active_order' => $snapshot['has_active_order'],
            'status_text' => $snapshot['status_text'],
            'order_id' => $snapshot['order_id'],
            'status' => $snapshot['status'],
            'orders' => $snapshot['orders'],
            'products' => Product::where('stock', '>', 0)->get(),
        ]);
    }

    public function getOrderStatus(Request $request)
    {
        $request->validate([
            'terminal_id' => 'required|integer|min:1',
            'order_id' => 'nullable|integer|min:1',
        ]);

        $terminalId = (int) $request->input('terminal_id');
        $orderId = (int) $request->input('order_id', 0);

        if ($orderId > 0) {
            $order = $this->ordersForTerminal($terminalId)->where('id', $orderId)->first();
            if (!$order) {
                return response()->json([
                    'status' => 'success',
                    'has_active_order' => false,
                    'status_text' => '',
                    'order_id' => null,
                    'order_status' => null,
                    'orders' => [],
                ]);
            }

            $isActive = in_array($order->status, ['pending', 'cooking'], true)
                || (in_array($order->status, ['delivered', 'cancelled'], true)
                    && $order->updated_at
                    && now()->diffInSeconds($order->updated_at) <= 60);

            // Full terminal snapshot so shell can show all cart lines, not only tracked id
            $snapshot = $this->shellOrderSnapshot($terminalId);
            $orders = !empty($snapshot['orders'])
                ? $snapshot['orders']
                : [$this->mapOrderPayload($order)];

            return response()->json([
                'status' => 'success',
                'has_active_order' => $isActive,
                'status_text' => self::orderStatusLabel($order->status),
                'order_id' => (int) $order->id,
                'order_status' => $order->status,
                'orders' => $orders,
            ]);
        }

        $snapshot = $this->shellOrderSnapshot($terminalId);

        return response()->json([
            'status' => 'success',
            'has_active_order' => $snapshot['has_active_order'],
            'status_text' => $snapshot['status_text'],
            'order_id' => $snapshot['order_id'],
            'order_status' => $snapshot['status'],
            'orders' => $snapshot['orders'],
        ]);
    }

    /**
     * Map DB order row → shell/admin payload (supports multi-item orders).
     */
    private function mapOrderPayload(object $order): array
    {
        $items = Order::normalizeItems(
            $order->items ?? null,
            $order->product_name ?? null,
            (float) ($order->price ?? 0)
        );

        return [
            'id' => (int) $order->id,
            'product_name' => $order->product_name,
            'status' => $order->status,
            'status_label' => self::orderStatusLabel($order->status),
            'price' => (float) $order->price,
            'items' => $items,
        ];
    }

    public function checkout(Request $request)
    {
        // Multi-item: { terminal_id, items: [{ product_id, qty }] }
        // Legacy single: { terminal_id, product_id } (+ optional qty)
        $request->validate([
            'terminal_id' => 'required|integer',
            'product_id' => 'nullable|exists:products,id',
            'qty' => 'nullable|integer|min:1|max:50',
            'items' => 'nullable|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.qty' => 'nullable|integer|min:1|max:50',
        ]);

        $rawItems = $request->input('items');
        if (!is_array($rawItems) || count($rawItems) === 0) {
            if (!$request->filled('product_id')) {
                return response()->json(['message' => 'Корзина пуста'], 422);
            }
            $rawItems = [[
                'product_id' => (int) $request->product_id,
                'qty' => max(1, (int) $request->input('qty', 1)),
            ]];
        }

        // Merge duplicate product_ids
        $qtyByProduct = [];
        foreach ($rawItems as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($pid <= 0) {
                continue;
            }
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        }

        if (count($qtyByProduct) === 0) {
            return response()->json(['message' => 'Корзина пуста'], 422);
        }

        try {
            $booking = Booking::where('status', 'active')
                ->where(function ($query) use ($request) {
                    $query->whereJsonContains('pc_ids', (string) $request->terminal_id)
                        ->orWhere('computer_id', $request->terminal_id);
                })->first();

            if (!$booking) {
                Log::warning('Shell shop checkout: no active booking', [
                    'terminal_id' => $request->terminal_id,
                    'items' => $qtyByProduct,
                ]);
                return response()->json(['message' => 'Активная сессия не найдена.'], 403);
            }

            $user = User::find($booking->user_id);
            if (!$user) {
                return response()->json(['message' => 'Пользователь не найден'], 404);
            }

            $products = Product::whereIn('id', array_keys($qtyByProduct))->get()->keyBy('id');
            if ($products->count() !== count($qtyByProduct)) {
                return response()->json(['message' => 'Товар не найден'], 404);
            }

            $lineItems = [];
            $totalPrice = 0.0;
            foreach ($qtyByProduct as $pid => $qty) {
                /** @var Product $product */
                $product = $products[$pid];
                if ((int) $product->stock < $qty) {
                    return response()->json([
                        'message' => "Недостаточно «{$product->name}» на складе (нужно {$qty}, есть {$product->stock})",
                    ], 422);
                }
                $unit = (float) $product->price;
                $lineTotal = $unit * $qty;
                $totalPrice += $lineTotal;
                $lineItems[] = [
                    'product_id' => (int) $product->id,
                    'name' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ];
            }

            $balance = $user->syncBalanceToWallet();
            $wallet = $user->wallet()->first();

            if (!$wallet || $balance < $totalPrice) {
                Log::warning('Shell shop checkout: insufficient funds', [
                    'user_id' => $user->id,
                    'balance' => $balance,
                    'price' => $totalPrice,
                ]);
                return response()->json([
                    'message' => 'Недостаточно средств',
                    'balance' => $balance,
                ], 422);
            }

            $summary = Order::summaryFromItems($lineItems);
            $newBalance = $balance;
            $orderId = 0;
            $orderStatus = 'pending';

            DB::transaction(function () use (
                $user,
                $wallet,
                $request,
                $lineItems,
                $qtyByProduct,
                $products,
                $totalPrice,
                $summary,
                &$newBalance,
                &$orderId,
                &$orderStatus
            ) {
                $newBalance = $wallet->debitSpendable($totalPrice);

                foreach ($qtyByProduct as $pid => $qty) {
                    $products[$pid]->decrement('stock', $qty);
                }

                $orderStatus = 'pending';
                $orderId = (int) DB::table('orders')->insertGetId([
                    'user_id' => $user->id,
                    'product_name' => $summary,
                    'items' => json_encode($lineItems, JSON_UNESCAPED_UNICODE),
                    'price' => $totalPrice,
                    'pc_name' => 'ПК №' . $request->terminal_id,
                    'status' => $orderStatus,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            Log::info('Shell shop checkout OK', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'items' => $lineItems,
                'price' => $totalPrice,
                'balance' => $newBalance,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Заказ оформлен!',
                'balance' => $newBalance,
                'deposit_balance' => $newBalance,
                'order_id' => $orderId,
                'order_status' => $orderStatus,
                'status_label' => self::orderStatusLabel($orderStatus),
                'items' => $lineItems,
                'price' => $totalPrice,
            ]);
        } catch (\Exception $e) {
            Log::error('Shell shop checkout error: ' . $e->getMessage(), [
                'terminal_id' => $request->terminal_id,
                'items' => $qtyByProduct ?? null,
            ]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function callAdmin(Request $request)
    {
        return response()->json(['status' => 'success']);
    }

    public function takeAccount(Request $request)
    {
        $request->validate([
            'game_id' => 'required|integer',
            'terminal_id' => 'required|integer',
        ]);

        $game = Game::find($request->game_id);
        if (!$game) {
            return response()->json(['status' => 'error', 'message' => 'Игра не найдена'], 404);
        }

        $computer = Computer::find($request->terminal_id);
        if (!$computer) {
            return response()->json(['status' => 'error', 'message' => 'Терминал не найден'], 404);
        }

        $account = GameAccount::where('game_id', $request->game_id)->where('status', 'free')->first();
        if (!$account) {
            return response()->json(['status' => 'error', 'message' => 'Все аккаунты заняты'], 200);
        }

        // JWT с сервера для десктопного Steam не работает (ip_subject чужой машины).
        // Авторизация: machine VDF-кэш + fallback логин/пароль в шелле.
        $account->update([
            'status' => 'in_use',
            'current_pc_id' => $request->terminal_id,
        ]);

        $machineCache = $account->cacheForComputer((int) $request->terminal_id);
        $finalArgs = trim((string) ($game->launch_args ?? $game->args ?? ''));
        $exePath = (string) ($game->exe_path ?? '');
        $platformRaw = (string) ($game->platform ?? '');
        $platform = strtolower($platformRaw);

        $exeLower = strtolower($exePath);
        $argsLower = strtolower($finalArgs);
        $looksEpic = str_contains($argsLower, 'com.epicgames.launcher')
            || str_contains($exeLower, 'epicgameslauncher')
            || str_contains($exeLower, 'epic games');
        $looksEa = str_contains($exeLower, 'eadesktop.exe')
            || str_contains($exeLower, 'ea desktop')
            || str_contains($argsLower, 'origin2://')
            || str_contains($argsLower, 'origin://')
            || str_contains($argsLower, 'eadm://');
        $titleLower = strtolower((string) ($game->title ?? ''));
        $looksRiot = str_contains($exeLower, 'riotclient')
            || str_contains($exeLower, 'riot games')
            || str_contains($exeLower, 'riot client')
            || str_contains($argsLower, 'valorant')
            || str_contains($argsLower, 'league_of_legends')
            || str_contains($titleLower, 'valorant')
            || str_contains($titleLower, 'league of legends')
            || str_contains($platform, 'riot');

        $platformSource = 'db';
        if ($looksEpic) {
            $platform = 'epic';
            $platformSource = 'inferred_epic_from_exe_args';
        } elseif ($looksEa || in_array($platform, ['ea', 'origin', 'eadesktop', 'eaapp', 'ea app', 'electronic arts'], true)) {
            $platform = 'ea';
            $platformSource = $looksEa ? 'inferred_ea_from_exe_args' : 'normalized_ea';
        } elseif ($looksRiot || in_array($platform, ['riot', 'riot games', 'riotgames', 'valorant', 'league of legends'], true)) {
            $platform = 'riot';
            $platformSource = $looksRiot ? 'inferred_riot_from_exe_args' : 'normalized_riot';
        } elseif ($platform === '' || $platform === 'valve') {
            $platform = 'steam';
            $platformSource = $platformRaw === '' ? 'default_steam' : 'normalized_valve';
        } elseif ($platform === 'pc') {
            // «PC» без признаков Epic/EA/Riot — считаем Steam (как раньше)
            $platform = 'steam';
            $platformSource = 'normalized_pc_to_steam';
        }

        $vdfFiles = [
            'config_vdf'     => $machineCache?->config_vdf,
            'loginusers_vdf' => $machineCache?->loginusers_vdf,
            'local_vdf'      => $machineCache?->local_vdf,
        ];
        $hasMachineCache = !empty($machineCache?->local_vdf);

        \Log::info('[shell.take-account]', [
            'game_id' => $game->id,
            'title' => $game->title,
            'platform_raw' => $platformRaw,
            'platform' => $platform,
            'platform_source' => $platformSource,
            'exe_path' => $exePath,
            'args' => $finalArgs,
            'account_id' => $account->id,
            'login' => $account->login,
            'terminal_id' => (int) $request->terminal_id,
            'has_machine_cache' => $hasMachineCache,
        ]);

        return response()->json([
            'status'           => 'success',
            'platform'         => $platform,
            'platform_raw'     => $platformRaw,
            'platform_source'  => $platformSource,
            'game_id'          => (int) $game->id,
            'game_title'       => (string) $game->title,
            'account_id'       => (int) $account->id,
            'login'            => $account->login,
            'password'         => $account->password,
            'persona_name'     => $account->persona_name ?? $account->login,
            'display_name'     => $account->persona_name ?? $account->login,
            'steam_id'         => (string) ($account->steam_id ?? ''),
            'platform_user_id' => (string) ($account->steam_id ?? ''),
            'platform_app_id'  => '',
            'exe_path'         => $exePath,
            'args'             => $finalArgs,
            'terminal_id'      => (int) $request->terminal_id,
            'launcher'         => [
                'exe_path' => $exePath,
                'args'     => $finalArgs,
            ],
            // Совместимость со старым шеллом + универсальный auth-блок
            'vdf_files'        => $vdfFiles,
            'auth'             => [
                'mode'  => $hasMachineCache ? 'cache' : 'interactive',
                'cache' => [
                    'vdf_files' => $vdfFiles,
                ],
            ],
        ], 200);
    }

    public function freeAccount(Request $request)
    {
        $request->validate([
            'game_id' => 'required|integer',
            'terminal_id' => 'required|integer',
        ]);

        $account = GameAccount::where('game_id', $request->game_id)
            ->where('current_pc_id', $request->terminal_id)
            ->where('status', 'in_use')
            ->first();

        if ($account) {
            $account->update([
                'status' => 'free',
                'current_pc_id' => null
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function setPause(Request $request)
    {
        $request->validate([
            'computer_id' => 'required|integer',
            'booking_id' => 'nullable|integer',
        ]);
        $computerId = (int) $request->input('computer_id');
        $bookingId = (int) $request->input('booking_id', 0);

        try {
            $booking = null;

            if ($bookingId > 0) {
                $booking = Booking::where('id', $bookingId)
                    ->where('status', 'active')
                    ->first();
            }

            if (!$booking) {
                $booking = Booking::where('status', 'active')
                    ->where(function ($query) use ($computerId) {
                        $query->where('computer_id', $computerId)
                            ->orWhereJsonContains('pc_ids', $computerId)
                            ->orWhereJsonContains('pc_ids', (string) $computerId);
                    })
                    ->orderByDesc('id')
                    ->first();
            }

            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Активная бронь на ПК не найдена.',
                ], 404);
            }

            $newPin = (string) random_int(1000, 9999);
            $booking->update([
                'pin_code' => $newPin,
                'computer_id' => $computerId,
            ]);

            return response()->json([
                'status' => 'success',
                'pin_code' => $newPin,
                'booking_id' => $booking->id,
            ]);
        } catch (\Exception $e) {
            Log::error("Ошибка паузы: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    public function clearPause(Request $request)
    {
        $request->validate([
            'computer_id' => 'required|integer',
            'pin_code' => 'required|string',
            'booking_id' => 'nullable|integer',
        ]);

        $computerId = (int) $request->input('computer_id');
        $pin = preg_replace('/\D+/', '', (string) $request->input('pin_code'));
        $bookingId = (int) $request->input('booking_id', 0);

        try {
            $booking = null;

            if ($bookingId > 0) {
                $booking = Booking::where('id', $bookingId)
                    ->where('status', 'active')
                    ->first();
            }

            if (!$booking) {
                $booking = Booking::where('status', 'active')
                    ->where(function ($query) use ($computerId) {
                        $query->where('computer_id', $computerId)
                            ->orWhereJsonContains('pc_ids', $computerId)
                            ->orWhereJsonContains('pc_ids', (string) $computerId);
                    })
                    ->orderByDesc('id')
                    ->first();
            }

            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Активная бронь не найдена.',
                ], 404);
            }

            $storedPin = preg_replace('/\D+/', '', (string) ($booking->pin_code ?? ''));
            if ($storedPin === '' || $storedPin !== $pin) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Неверный PIN-код.',
                ], 401);
            }

            // Как при обычном входе — одноразовый PIN сгорает
            $booking->update(['pin_code' => null]);

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error("Ошибка снятия паузы: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    public function registerTerminal(Request $request)
    {
        try {
            $request->validate([
                'hwid'      => 'required|string',
                'zone_type' => 'required|string',
                'name'      => 'required|string'
            ]);

            $zoneType = strtolower($request->zone_type);
            $computer = Computer::where('hwid', $request->hwid)->first();

            if ($computer) {
                if ($computer->type !== $zoneType || $computer->name !== $request->name) {
                    $computer->update([
                        'type' => $zoneType,
                        'name' => $request->name
                    ]);
                }

                return response()->json([
                    'status' => 'success',
                    'terminal_id' => $computer->id,
                    'message' => 'Конфигурация обновлена. ПК №' . $computer->name . ' изменен на ' . strtoupper($zoneType)
                ], 200);
            }

            $defaultClubId = \App\Models\Club::first()?->id ?? 1;

            $newComputer = Computer::updateOrCreate(
                ['hwid' => $request->hwid],
                [
                    'club_id'   => $defaultClubId,
                    'type'      => $zoneType,
                    'name'      => $request->name
                ]
            );

            return response()->json([
                'status' => 'success',
                'terminal_id' => $newComputer->id,
                'message' => 'Успешная автоматическая генерация ПК №' . $newComputer->name
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Shell API Register Terminal Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера привязки: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkTerminalBooking(Request $request)
    {
        try {
            $request->validate([
                'hwid' => 'required|string'
            ]);

            $computer = Computer::where('hwid', $request->hwid)->first();

            if ($computer) {
                return response()->json([
                    'status' => 'success',
                    'computer_id' => $computer->id,
                    'name' => $computer->name,
                    'type' => $computer->type ?? 'standard'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'computer_id' => 0,
                'message' => 'Оборудование не зарегистрировано'
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Shell API Check Terminal Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'computer_id' => 0], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'required'
            ]);

            $termId = (string)$request->terminal_id;
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($termId) {
                    $query->whereJsonContains('pc_ids', $termId)
                        ->orWhere('computer_id', (int)$termId);
                })->first();

            if ($booking) {
                $booking->update([
                    'status' => 'completed',
                    'end_time' => now()
                ]);

                GameAccount::where('current_pc_id', (int)$termId)
                    ->update(['status' => 'free', 'current_pc_id' => null]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Сессия успешно закрыта. Терминал освобожден.'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Active session for this terminal not found.'
            ], 404);

        } catch (\Throwable $e) {
            Log::error("Shell API Logout Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера при выходе: ' . $e->getMessage()
            ], 500);
        }
    }
    public function updateAccountVdf(Request $request)
    {
        try {
            $request->validate([
                'login'          => 'required|string',
                'terminal_id'    => 'required|integer',
                'account_id'     => 'nullable|integer',
                'game_id'        => 'nullable|integer',
                'config_vdf'     => 'nullable|string',
                'loginusers_vdf' => 'nullable|string',
                'local_vdf'      => 'nullable|string',
                'refresh_token'  => 'nullable|string',
                'steam_id'       => 'nullable|string',
            ]);

            // Один login может быть у нескольких game_accounts (LoL / Valorant / …).
            // Приоритет: account_id → login+game_id → login+in_use на этом ПК → login first.
            $account = null;
            if ($request->filled('account_id')) {
                $account = GameAccount::find((int) $request->account_id);
            }
            if (!$account && $request->filled('game_id')) {
                $account = GameAccount::where('login', $request->login)
                    ->where('game_id', (int) $request->game_id)
                    ->first();
            }
            if (!$account) {
                $account = GameAccount::where('login', $request->login)
                    ->where('current_pc_id', (int) $request->terminal_id)
                    ->where('status', 'in_use')
                    ->first();
            }
            if (!$account) {
                $account = GameAccount::where('login', $request->login)->first();
            }

            if (!$account) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Аккаунт не найден в базе данных'
                ], 404);
            }

            $computer = Computer::find($request->terminal_id);
            if (!$computer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Терминал не найден'
                ], 404);
            }

            // JWT относится к аккаунту (переносим между машинами как plaintext)
            $accountUpdate = [];
            if ($request->filled('refresh_token')) {
                $accountUpdate['refresh_token'] = $request->refresh_token;
                $accountUpdate['refresh_token_updated_at'] = now();
            }
            if ($request->filled('steam_id')) {
                $accountUpdate['steam_id'] = $request->steam_id;
            }
            if (!empty($accountUpdate)) {
                $account->update($accountUpdate);
            }

            // VDF — строго пара аккаунт × компьютер
            $cache = GameAccountMachineCache::firstOrNew([
                'game_account_id' => $account->id,
                'computer_id' => $computer->id,
            ]);

            if ($request->has('config_vdf')) {
                $cache->config_vdf = $request->config_vdf;
            }
            if ($request->has('loginusers_vdf')) {
                $cache->loginusers_vdf = $request->loginusers_vdf;
            }
            if ($request->has('local_vdf')) {
                $cache->local_vdf = $request->local_vdf;
            }
            $cache->save();

            Log::info("[SHELL-API] Обновлен кэш VDF для {$account->login} (account_id={$account->id}, game_id={$account->game_id}) на PC#{$computer->id}");

            return response()->json([
                'status'  => 'success',
                'message' => 'Кэш авторизации сохранён для пары аккаунт×машина',
                'account_id' => $account->id,
                'game_id' => $account->game_id,
                'computer_id' => $computer->id,
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Shell API Update VDF Error: " . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Ошибка сервера при сохранении кэша: ' . $e->getMessage()
            ], 500);
        }
    }
}
