<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Models\User;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Order;
use App\Models\GameAccount;
use App\Models\GameAccountReservation;
use App\Models\GameAccountMachineCache;
use App\Models\Game;
use App\Models\QuickApp;
use App\Models\Computer;
use App\Support\OrderDeliveryTarget;
use App\Support\ZoneSlug;
use App\Models\UserGameStat;
use App\Models\ComputerInputDevice;
use App\Models\ComputerInputAlert;
use App\Models\ComputerSosAlert;
use App\Services\AchievementService;
use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\VoiceGreetingService;
use App\Services\BookingSessionTimingService;
use App\Services\ComputerPowerService;
use App\Services\ComputerStatusService;
use App\Services\Fan\FanControlService;
use App\Services\GameRequestService;
use App\Services\ProductStockService;
use App\Services\UserCloudSettingsService;
use App\Services\VideoMarkerService;
use App\Services\YooKassaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;

class ShellApiController extends Controller
{
    public function getActiveOverlays(Request $request)
    {
        $terminalId = (int) $request->query('terminal_id', 0);
        if ($terminalId > 0) {
            try {
                app(ComputerPowerService::class)->touchOnline($terminalId);
            } catch (\Throwable $e) {
                Log::warning('Power touch on overlays failed: '.$e->getMessage());
            }
        }

        $overlays = Overlay::where('is_active', true)
            ->get()
            ->keyBy('block_position')
            ->map(function (Overlay $overlay) {
                $content = $overlay->content;
                if (is_array($content) && isset($content['layers']) && is_array($content['layers'])) {
                    foreach ($content['layers'] as $key => $layer) {
                        if (! is_array($layer) || empty($layer['value']) || ! is_string($layer['value'])) {
                            continue;
                        }
                        $path = parse_url($layer['value'], PHP_URL_PATH);
                        if (is_string($path) && str_contains($path, '/storage/')) {
                            // Relative path → Shell prepends its configured server URL.
                            $content['layers'][$key]['value'] = ltrim($path, '/');
                        }
                    }
                    $overlay->content = $content;
                }

                return $overlay;
            });

        return response()->json([
            'status' => 'success',
            'data' => $overlays
        ]);
    }

    public function login(Request $request)
    {
        try {
            Log::info('Shell login request', [
                'payload' => $request->all(),
                'content_type' => $request->header('Content-Type'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $request->validate([
                'phone' => 'required|string',
                'pin' => 'required|string|size:4',
                'terminal_id' => 'required|integer'
            ]);

            $phone = (string) $request->phone;
            $pin = (string) $request->pin;
            $terminalId = (int) $request->terminal_id;

            $user = User::where('phone', $phone)->first();
            if (!$user) {
                Log::warning('Shell login: user not found', ['phone' => $phone]);
                // HTTP 200: QML показывает reply->errorString() на 4xx и глотает JSON.
                return response()->json(['status' => 'error', 'message' => 'Пользователь не найден']);
            }

            $candidates = Booking::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['paid', 'confirmed', 'active', 'pending', 'pending_payment', 'waiting', 'new'])
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'status', 'pin_code', 'computer_id', 'pc_ids', 'starts_at', 'ends_at'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'status' => $b->status,
                    'computer_id' => $b->computer_id,
                    'pc_ids' => $b->pc_ids,
                    'pin_code' => $b->pin_code,
                    'pin_match' => (string) ($b->pin_code ?? '') === $pin,
                    'pc_match' => (int) $b->computer_id === $terminalId,
                    'starts_at' => optional($b->starts_at)?->toDateTimeString(),
                    'ends_at' => optional($b->ends_at)?->toDateTimeString(),
                ])
                ->all();

            Log::info('Shell login: booking candidates', [
                'user_id' => $user->id,
                'phone' => $phone,
                'pin' => $pin,
                'terminal_id' => $terminalId,
                'candidates' => $candidates,
            ]);

            $booking = Booking::where('user_id', $user->id)
                ->where('pin_code', $pin)
                ->where('computer_id', $terminalId)
                ->whereIn('status', ['paid', 'confirmed', 'active'])
                ->first();

            if (!$booking) {
                Log::warning('Shell login: no matching booking', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'pin' => $pin,
                    'terminal_id' => $terminalId,
                    'candidates' => $candidates,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Неверный PIN-код, либо сессия уже была активирована.',
                ]);
            }

            try {
                $activation = app(BookingSessionTimingService::class)->activate($booking);
            } catch (RuntimeException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

            $booking = $activation['booking'];
            $durationMinutes = $activation['time_remaining_minutes'];

            $hours = floor($durationMinutes / 60);
            $minutes = floor($durationMinutes % 60);
            $formattedTime = sprintf('%02d:%02d:%02d', $hours, $minutes, 0);

            // Sync legacy users.balance / wallets.balance into deposit_balance so shell matches admin.
            $balance = $user->syncBalanceToWallet();

            // Cloud Saves: pack for Shell to restore on this PC (may be null if never saved).
            $cloud = app(UserCloudSettingsService::class)->getPackWithMeta($user);

            try {
                app(FanControlService::class)->reconcileForComputer($terminalId);
            } catch (\Throwable $e) {
                Log::warning('Fan reconcile after login failed: '.$e->getMessage());
            }

            try {
                app(ComputerPowerService::class)->touchOnline($terminalId);
            } catch (\Throwable $e) {
                Log::warning('Power touch after login failed: '.$e->getMessage());
            }

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
                ],
                'settings_pack' => $cloud['payload'],
                'settings_updated_at' => $cloud['updated_at'],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Некорректные данные входа';
            return response()->json(['status' => 'error', 'message' => $msg]);
        } catch (\Throwable $e) {
            Log::error("Shell API Login Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Create YooKassa top-up for the player on the active terminal session.
     * Test shop: card only (SBP/QR is not available).
     */
    public function topUp(Request $request, YooKassaService $yookassa)
    {
        $request->validate([
            'terminal_id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:100|max:100000',
            'booking_id' => 'nullable|integer|min:1',
            'user_id' => 'nullable|integer|min:1',
        ]);

        $terminalId = (int) $request->input('terminal_id');
        $amount = round((float) $request->input('amount'), 2);

        $user = null;
        $bookingId = (int) $request->input('booking_id', 0);
        if ($bookingId > 0) {
            $booking = Booking::where('id', $bookingId)
                ->where('status', 'active')
                ->where(function ($query) use ($terminalId) {
                    $query->where('computer_id', $terminalId)
                        ->orWhereJsonContains('pc_ids', $terminalId)
                        ->orWhereJsonContains('pc_ids', (string) $terminalId);
                })
                ->first();
            if ($booking?->user_id) {
                $user = User::find($booking->user_id);
            }
        }

        if (!$user) {
            $user = $this->resolveShellSessionUser(
                $terminalId,
                $request->filled('user_id') ? (int) $request->user_id : null
            );
        }

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Активная сессия не найдена.',
            ], 403);
        }

        try {
            // Shell embeds YooKassa Checkout Widget (confirmation=embedded).
            $payment = $yookassa->createTopUp($user, $amount, 'card', '/account/dashboard', 'embedded');
            $token = $payment->confirmationToken();

            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ЮKassa не вернула confirmation_token для виджета',
                ], 502);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Виджет оплаты готов',
                'payment_id' => $payment->uuid,
                'confirmation_token' => $token,
                'widget_url' => url('/billing/yookassa/widget/'.$payment->uuid),
                'amount' => $payment->amount,
                'method' => 'card',
                'payment_status' => $payment->status,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell top-up failed', [
                'user_id' => $user->id,
                'terminal_id' => $terminalId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Не удалось создать платёж: '.$e->getMessage(),
            ], 502);
        }
    }

    /**
     * Lightweight balance poll for an active shell session (no auth re-login).
     * Prefer booking_id / active booking on terminal; fall back to user_id.
     */
    public function getBalance(Request $request)
    {
        try {
            // Пока шелл поллит баланс — закрываем просроченные сессии сразу,
            // не дожидаясь минуты scheduler'а.
            app(BookingSessionTimingService::class)->completeExpiredSessions();

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

    public function getQuickApps()
    {
        try {
            return response()->json([
                'status' => 'success',
                'apps' => QuickApp::query()
                    ->where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->orderBy('title')
                    ->get()
                    ->map(fn (QuickApp $app) => [
                        'id' => $app->id,
                        'title' => $app->title,
                        'exe_path' => $app->exe_path,
                        'args' => $app->launch_args ?? '',
                    ])
                    ->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API getQuickApps: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'apps' => [],
                'message' => 'Не удалось загрузить быстрый софт',
            ], 500);
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

            // Метка на видеосервер (если в админке создано событие с этим триггером)
            $trigger = match ($alert->type) {
                ComputerInputAlert::TYPE_DISCONNECTED => 'hid.disconnected',
                ComputerInputAlert::TYPE_DEVICE_CHANGED => 'hid.device_changed',
                ComputerInputAlert::TYPE_UNSTABLE => 'hid.unstable',
                default => null,
            };
            if ($trigger) {
                try {
                    $pc = Computer::query()->find($alert->computer_id);
                    app(VideoMarkerService::class)->placeMarkerForTrigger($trigger, [
                        'title' => ($trigger === 'hid.disconnected' ? 'Отключение периферии' : 'HID').' · '.($pc?->name ?: 'PC#'.$alert->computer_id),
                        'meta' => [
                            'computer_id' => $alert->computer_id,
                            'alert_id' => $alert->id,
                            'booking_id' => $alert->booking_id,
                            'hid_type' => $alert->type,
                        ],
                    ], $pc?->club_id ? (int) $pc->club_id : null);
                } catch (\Throwable $markerError) {
                    Log::warning('Video marker after HID alert failed: '.$markerError->getMessage());
                }
            }

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

    /**
     * Shell reports CPU temperature; backend decides fan state for the room (Space).
     */
    public function reportThermal(Request $request)
    {
        $request->validate([
            'terminal_id' => 'required|integer|exists:computers,id',
            'cpu_c' => 'required|numeric|min:0|max:150',
        ]);

        try {
            $fan = app(FanControlService::class)->reportThermal(
                (int) $request->terminal_id,
                (float) $request->cpu_c
            );

            try {
                app(ComputerPowerService::class)->touchOnline((int) $request->terminal_id);
            } catch (\Throwable $e) {
                Log::warning('Power touch on thermal failed: '.$e->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'fan' => $fan
                    ? app(FanControlService::class)->stateForComputer((int) $request->terminal_id)
                    : ['available' => false, 'reason' => 'no_fan'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API reportThermal: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Manual fan control for the room. Shared across all PCs in the Space.
     * action: on | off | auto
     */
    public function controlFan(Request $request)
    {
        $request->validate([
            'terminal_id' => 'required|integer|exists:computers,id',
            'action' => 'required|string|in:on,off,auto',
        ]);

        try {
            $fan = app(FanControlService::class)->setManualModeForComputer(
                (int) $request->terminal_id,
                (string) $request->action
            );

            return response()->json([
                'status' => 'success',
                'fan' => app(FanControlService::class)->stateForComputer((int) $request->terminal_id),
                'applied' => (bool) $fan,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shell API controlFan: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getFanState(Request $request)
    {
        $request->validate([
            'terminal_id' => 'required|integer|exists:computers,id',
        ]);

        return response()->json([
            'status' => 'success',
            'fan' => app(FanControlService::class)->stateForComputer((int) $request->terminal_id),
        ]);
    }

    /**
     * Personalized spoken greeting after Shell login: context → DeepSeek → TTS.
     * Qt Shell only; requires active booking on terminal.
     */
    public function voiceGreeting(Request $request)
    {
        $request->validate([
            'terminal_id' => 'required|integer|exists:computers,id',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $terminalId = (int) $request->terminal_id;
        $rateKey = 'shell-ai-greet:'.$terminalId;
        $limit = max(1, (int) config('ai_assistant.rate_limit_per_minute', 8));

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Слишком много запросов приветствия. Подожди немного.',
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        try {
            $result = app(VoiceGreetingService::class)->greet(
                $terminalId,
                $request->filled('booking_id') ? (int) $request->booking_id : null
            );

            return response()->json([
                'status' => 'success',
                ...$result,
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Shell voice greeting: '.$e->getMessage(), [
                'terminal_id' => $terminalId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Shell voice greeting failed: '.$e->getMessage(), [
                'terminal_id' => $terminalId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Не удалось сформировать приветствие.',
            ], 500);
        }
    }

    /**
     * F1 voice companion: audio in → STT → DeepSeek → TTS → audio+text out.
     * Qt Shell only; requires active booking on terminal.
     */
    public function aiAssistant(Request $request)
    {
        $maxKb = max(64, (int) config('ai_assistant.max_audio_kb', 5120));

        $request->validate([
            'terminal_id' => 'required|integer|exists:computers,id',
            'audio' => 'required|file|max:'.$maxKb,
            'game_id' => 'nullable|integer|exists:games,id',
            'game_title' => 'nullable|string|max:255',
        ]);

        $terminalId = (int) $request->terminal_id;
        $rateKey = 'shell-ai:'.$terminalId;
        $limit = max(1, (int) config('ai_assistant.rate_limit_per_minute', 8));

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Слишком много запросов к ассистенту. Подожди немного.',
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        try {
            $result = app(AiAssistantService::class)->handle(
                $terminalId,
                $request->file('audio'),
                $request->filled('game_id') ? (int) $request->game_id : null,
                $request->input('game_title')
            );

            return response()->json([
                'status' => 'success',
                ...$result,
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Shell AI assistant: '.$e->getMessage(), [
                'terminal_id' => $terminalId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Shell AI assistant failed: '.$e->getMessage(), [
                'terminal_id' => $terminalId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Не удалось обработать запрос ассистента.',
            ], 500);
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
        return DB::table('orders')->whereIn('pc_name', OrderDeliveryTarget::matchLabels($terminalId));
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
            'products' => Product::query()->orderBy('name')->get(),
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

            $stockService = app(ProductStockService::class);
            $lineItems = [];
            $totalPrice = 0.0;
            foreach ($qtyByProduct as $pid => $qty) {
                /** @var Product $product */
                $product = $products[$pid];
                try {
                    $stockService->assertAvailable($product, $qty);
                } catch (\Throwable $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
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
                $stockService,
                &$newBalance,
                &$orderId,
                &$orderStatus
            ) {
                $newBalance = $wallet->debitSpendable($totalPrice);

                \App\Models\Transaction::create([
                    'user_id' => $user->id,
                    'amount' => -$totalPrice,
                    'type' => 'purchase',
                    'source' => 'balance',
                    'description' => 'Магазин: '.$summary,
                    'payload' => [
                        'order_items' => $lineItems,
                        'terminal_id' => (int) $request->terminal_id,
                    ],
                ]);

                $orderStatus = 'pending';
                $orderId = (int) DB::table('orders')->insertGetId([
                    'user_id' => $user->id,
                    'product_name' => $summary,
                    'items' => json_encode($lineItems, JSON_UNESCAPED_UNICODE),
                    'price' => $totalPrice,
                    'pc_name' => OrderDeliveryTarget::labelForComputerId((int) $request->terminal_id),
                    'status' => $orderStatus,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($qtyByProduct as $pid => $qty) {
                    $stockService->decrementUnmarked($products[$pid], $qty, $orderId);
                }

                $stockService->reserveMarkedForOrder($orderId, $lineItems);
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
            'game_id' => 'required|integer|exists:games,id',
            'terminal_id' => 'required|integer|exists:computers,id',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $game = Game::find($request->game_id);
        if (!$game) {
            return response()->json(['status' => 'error', 'message' => 'Игра не найдена'], 404);
        }

        $computer = Computer::find($request->terminal_id);
        if (!$computer) {
            return response()->json(['status' => 'error', 'message' => 'Терминал не найден'], 404);
        }

        $booking = Booking::query()
            ->when(
                $request->filled('booking_id'),
                fn ($query) => $query->whereKey((int) $request->booking_id),
                fn ($query) => $query
                    ->where('computer_id', (int) $request->terminal_id)
                    ->where('status', 'active')
            )
            ->where('computer_id', (int) $request->terminal_id)
            ->first();

        $reservation = null;
        $account = DB::transaction(function () use ($request, $booking, $computer, &$reservation) {
            if ($booking) {
                $reservation = GameAccountReservation::query()
                    ->where('booking_id', $booking->id)
                    ->whereIn('status', ['confirmed', 'active'])
                    ->whereHas(
                        'bookingGame.clubGame',
                        fn ($query) => $query->where('game_id', (int) $request->game_id)
                    )
                    ->lockForUpdate()
                    ->first();
            }

            if ($reservation) {
                $reservedAccount = GameAccount::query()
                    ->whereKey($reservation->game_account_id)
                    ->lockForUpdate()
                    ->first();

                if (!$reservedAccount || !$reservedAccount->is_enabled) {
                    return null;
                }

                $reservation->update([
                    'status' => 'active',
                    'activated_at' => now(),
                    'released_at' => null,
                ]);

                $reservedAccount->update([
                    'status' => 'in_use',
                    'current_pc_id' => (int) $request->terminal_id,
                ]);

                return $reservedAccount;
            }

            // Walk-in: и бесплатные, и платные игры (если аккаунт не удерживает бронь).
            // Платный тариф открывает предбронь; shell по-прежнему может выдать свободный аккаунт.
            $sessionEndsAt = $booking?->ends_at ?? now()->addHours(1);

            $walkInAccount = GameAccount::query()
                ->where('game_id', (int) $request->game_id)
                ->where('is_enabled', true)
                ->where('status', 'free')
                ->where(function ($query) use ($computer) {
                    $query->where('club_id', $computer->club_id)->orWhereNull('club_id');
                })
                ->whereDoesntHave('reservations', function ($query) use ($sessionEndsAt) {
                    $query->whereIn('status', ['held', 'confirmed', 'active'])
                        ->where('starts_at', '<', $sessionEndsAt)
                        ->where('ends_at', '>', now());
                })
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if ($walkInAccount) {
                $walkInAccount->update([
                    'status' => 'in_use',
                    'current_pc_id' => (int) $request->terminal_id,
                ]);
            }

            return $walkInAccount;
        });

        if (!$account) {
            return response()->json(['status' => 'error', 'message' => 'Все аккаунты заняты'], 200);
        }

        // JWT с сервера для десктопного Steam не работает (ip_subject чужой машины).
        // Авторизация: machine VDF-кэш + fallback логин/пароль в шелле.
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
            DB::transaction(function () use ($account, $request) {
                $lockedAccount = GameAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $lockedAccount->update([
                    'status' => 'free',
                    'current_pc_id' => null
                ]);

                GameAccountReservation::query()
                    ->where('game_account_id', $account->id)
                    ->where('status', 'active')
                    ->whereHas('booking', fn ($query) => $query
                        ->where('computer_id', (int) $request->terminal_id)
                        ->where('status', 'active'))
                    ->update([
                        'status' => 'confirmed',
                        'released_at' => now(),
                    ]);
            });
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
                ]);
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

            $hwid = strtolower(trim((string) $request->hwid));
            $zoneType = strtolower(trim((string) $request->zone_type));
            $name = trim((string) $request->name);

            // Только HWID: нашли → update (в т.ч. имя на карте), не нашли → create
            $computer = Computer::query()
                ->whereRaw('LOWER(hwid) = ?', [$hwid])
                ->first();

            if ($computer) {
                $computer->update([
                    'type' => $zoneType,
                    'name' => $name,
                    'hwid' => $hwid,
                ]);

                Log::info('Shell registerTerminal: updated by hwid', [
                    'computer_id' => $computer->id,
                    'hwid' => $hwid,
                    'name' => $name,
                ]);

                return response()->json([
                    'status' => 'success',
                    'terminal_id' => $computer->id,
                    'message' => 'Конфигурация обновлена. ПК '.$computer->name.' (id '.$computer->id.')',
                ]);
            }

            $defaultClubId = \App\Models\Club::first()?->id ?? 1;

            $newComputer = Computer::create([
                'club_id' => $defaultClubId,
                'hwid' => $hwid,
                'type' => $zoneType,
                'name' => $name,
                'kind' => Computer::KIND_PC,
                'status' => 'available',
            ]);

            Game::query()->pluck('id')->each(fn ($gameId) =>
                \App\Models\ComputerGame::firstOrCreate(
                    ['computer_id' => $newComputer->id, 'game_id' => $gameId],
                    ['is_installed' => true, 'verified_at' => now()]
                )
            );

            Log::warning('Shell registerTerminal: created new PC (hwid not found)', [
                'computer_id' => $newComputer->id,
                'hwid' => $hwid,
                'name' => $name,
            ]);

            return response()->json([
                'status' => 'success',
                'terminal_id' => $newComputer->id,
                'message' => 'Создан новый ПК '.$newComputer->name.' (id '.$newComputer->id.'). При необходимости поставьте его на карту в админке.',
            ]);

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

            $hwid = strtolower(trim((string) $request->hwid));
            $computer = Computer::query()
                ->with(['space.zone'])
                ->whereRaw('LOWER(hwid) = ?', [$hwid])
                ->first();

            if ($computer) {
                // Шелл на связи: сначала жёстко пишем last_seen/power_state через SQL NOW().
                try {
                    app(ComputerPowerService::class)->heartbeat($computer);
                    $computer->refresh();
                } catch (\Throwable $e) {
                    Log::warning('Power heartbeat on check failed: '.$e->getMessage());
                    try {
                        app(ComputerPowerService::class)->touchOnline((int) $computer->id);
                    } catch (\Throwable $e2) {
                        Log::warning('Power touch on check failed: '.$e2->getMessage());
                    }
                }

                $zone = $computer->space?->zone;
                $zoneSlug = ZoneSlug::normalize($zone?->slug ?: ($computer->type ?? ''));
                if ($zoneSlug === '') {
                    $zoneSlug = 'singl';
                }

                return response()->json([
                    'status' => 'success',
                    'computer_id' => $computer->id,
                    'name' => $computer->name,
                    'type' => $zoneSlug,
                    'zone_name' => $zone?->name,
                    'zone_slug' => $zoneSlug,
                    'zone_color' => $zone?->color,
                    'power_desired' => $computer->power_desired ?? 'on',
                    'power_state' => $computer->power_state ?? 'on',
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
                'terminal_id' => 'required',
                // Cloud Saves: optional full pack collected by Shell before session end.
                'settings_pack' => 'nullable|array',
                'settings_merge' => 'nullable|boolean',
            ]);

            $termId = (string)$request->terminal_id;
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($termId) {
                    $query->whereJsonContains('pc_ids', $termId)
                        ->orWhere('computer_id', (int)$termId);
                })->first();

            if ($booking) {
                $settingsSaved = false;
                $settingsError = null;

                // Persist cloud pack BEFORE closing the session (user_id still known).
                if ($booking->user_id && $request->filled('settings_pack')) {
                    try {
                        $user = User::find($booking->user_id);
                        if ($user) {
                            $service = app(UserCloudSettingsService::class);
                            if ($request->boolean('settings_merge')) {
                                $service->mergePack($user, $request->input('settings_pack'));
                            } else {
                                $service->savePack($user, $request->input('settings_pack'));
                            }
                            $settingsSaved = true;
                        }
                    } catch (InvalidArgumentException $e) {
                        $settingsError = $e->getMessage();
                        Log::warning('Cloud settings reject on logout', [
                            'user_id' => $booking->user_id,
                            'error' => $e->getMessage(),
                        ]);
                    } catch (\Throwable $e) {
                        $settingsError = 'Не удалось сохранить настройки';
                        Log::warning('Cloud settings save on logout failed: '.$e->getMessage());
                    }
                }

                DB::transaction(function () use ($booking, $termId) {
                    $booking->update([
                        'status' => 'completed',
                        'actual_ended_at' => now(),
                    ]);

                    GameAccount::where('current_pc_id', (int) $termId)
                        ->update(['status' => 'free', 'current_pc_id' => null]);

                    $booking->gameReservations()
                        ->whereIn('status', ['confirmed', 'active'])
                        ->update([
                            'status' => 'completed',
                            'released_at' => now(),
                        ]);

                    if ($booking->group && !$booking->group->bookings()
                        ->whereNotIn('status', ['completed', 'cancelled'])
                        ->exists()) {
                        $booking->group->update(['status' => 'completed']);
                    }
                });

                app(ComputerStatusService::class)->syncFor((int) $termId);

                $powerAction = 'none';
                try {
                    $powerAction = app(ComputerPowerService::class)->powerActionFor((int) $termId);
                } catch (\Throwable $e) {
                    Log::warning('Power action after logout failed: '.$e->getMessage());
                }

                try {
                    app(FanControlService::class)->reconcileForComputer((int) $termId);
                } catch (\Throwable $e) {
                    Log::warning('Fan reconcile after logout failed: '.$e->getMessage());
                }

                if ($booking->user_id) {
                    try {
                        $user = User::find($booking->user_id);
                        if ($user) {
                            app(AchievementService::class)->evaluateForUser($user);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Achievement evaluate after logout failed: '.$e->getMessage());
                    }
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Сессия успешно закрыта. Терминал освобожден.',
                    'settings_saved' => $settingsSaved,
                    'settings_error' => $settingsError,
                    'power_action' => $powerAction,
                    'power_desired' => $powerAction === 'reboot' ? 'on' : 'off',
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

    /**
     * Периодический heartbeat шелла: last_seen, MAC, power_desired/action.
     * Если сессия уже закрыта планировщиком — шелл получит session_active=false
     * и power_action (reboot|shutdown).
     */
    public function powerHeartbeat(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'nullable|integer',
                'hwid' => 'nullable|string',
                'mac_address' => 'nullable|string|max:32',
            ]);

            $computer = null;
            if ($request->filled('terminal_id')) {
                $computer = Computer::find((int) $request->terminal_id);
            }
            if (! $computer && $request->filled('hwid')) {
                $hwid = strtolower(trim((string) $request->hwid));
                $computer = Computer::query()
                    ->whereRaw('LOWER(hwid) = ?', [$hwid])
                    ->first();
            }

            if (! $computer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Терминал не найден',
                ], 200);
            }

            $result = app(ComputerPowerService::class)->heartbeat(
                $computer,
                $request->input('mac_address')
            );

            return response()->json(array_merge(['status' => 'success'], $result), 200);
        } catch (\Throwable $e) {
            Log::error('Shell API Power Heartbeat Error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка heartbeat: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Шелл закрывается (aboutToQuit) — сразу power_state=off.
     */
    public function powerOffline(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'nullable|integer',
                'hwid' => 'nullable|string',
            ]);

            $computer = null;
            if ($request->filled('terminal_id')) {
                $computer = Computer::find((int) $request->terminal_id);
            }
            if (! $computer && $request->filled('hwid')) {
                $hwid = strtolower(trim((string) $request->hwid));
                $computer = Computer::query()
                    ->whereRaw('LOWER(hwid) = ?', [$hwid])
                    ->first();
            }

            if (! $computer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Терминал не найден',
                ], 200);
            }

            app(ComputerPowerService::class)->markOffline((int) $computer->id);

            return response()->json([
                'status' => 'success',
                'power_state' => 'off',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Shell API Power Offline Error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка offline: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET cloud settings pack for the player on the active terminal session.
     * Shell can also re-fetch mid-session (e.g. after reconnect).
     */
    public function getCloudSettings(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'required|integer',
                'user_id' => 'nullable|integer',
            ]);

            $user = $this->resolveShellSessionUser(
                (int) $request->terminal_id,
                $request->filled('user_id') ? (int) $request->user_id : null
            );

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Активная сессия не найдена',
                ], 200);
            }

            $cloud = app(UserCloudSettingsService::class)->getPackWithMeta($user);

            return response()->json([
                'status' => 'success',
                'user_id' => $user->id,
                'settings_pack' => $cloud['payload'],
                'settings_updated_at' => $cloud['updated_at'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Некорректный запрос';

            return response()->json(['status' => 'error', 'message' => $msg], 200);
        } catch (\Throwable $e) {
            Log::error('Shell API getCloudSettings: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера при чтении настроек',
            ], 500);
        }
    }

    /**
     * POST cloud settings pack mid-session (or before logout as a dedicated call).
     * Body: terminal_id, settings_pack (object), optional settings_merge=true for per-game merge.
     */
    public function saveCloudSettings(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'required|integer',
                'user_id' => 'nullable|integer',
                'settings_pack' => 'required|array',
                'settings_merge' => 'nullable|boolean',
            ]);

            $user = $this->resolveShellSessionUser(
                (int) $request->terminal_id,
                $request->filled('user_id') ? (int) $request->user_id : null
            );

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Активная сессия не найдена',
                ], 200);
            }

            $service = app(UserCloudSettingsService::class);
            if ($request->boolean('settings_merge')) {
                $row = $service->mergePack($user, $request->input('settings_pack'));
            } else {
                $row = $service->savePack($user, $request->input('settings_pack'));
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Настройки сохранены в облако клуба',
                'user_id' => $user->id,
                'settings_updated_at' => $row->updated_at?->toIso8601String(),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Некорректный запрос';

            return response()->json(['status' => 'error', 'message' => $msg], 200);
        } catch (\Throwable $e) {
            Log::error('Shell API saveCloudSettings: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера при сохранении настроек',
            ], 500);
        }
    }

    /**
     * Resolve player from active booking on terminal; optional user_id must match.
     */
    private function resolveShellSessionUser(int $terminalId, ?int $userId = null): ?User
    {
        $termId = (string) $terminalId;
        $booking = Booking::where('status', 'active')
            ->where(function ($query) use ($termId, $terminalId) {
                $query->whereJsonContains('pc_ids', $termId)
                    ->orWhere('computer_id', $terminalId);
            })
            ->first();

        if (!$booking?->user_id) {
            return null;
        }

        if ($userId !== null && $userId > 0 && (int) $booking->user_id !== $userId) {
            return null;
        }

        return User::find($booking->user_id);
    }

    public function storeGameRequest(Request $request, GameRequestService $service)
    {
        $data = $request->validate([
            'terminal_id' => 'required|integer',
            'title' => 'required|string|max:120',
            'comment' => 'nullable|string|max:500',
            'user_id' => 'nullable|integer',
        ]);

        $booking = Booking::where('status', 'active')
            ->where(function ($query) use ($data) {
                $query->whereJsonContains('pc_ids', (string) $data['terminal_id'])
                    ->orWhere('computer_id', $data['terminal_id']);
            })
            ->first();

        $userId = $booking?->user_id ?: (int) ($data['user_id'] ?? 0);
        if ($userId < 1) {
            return response()->json(['message' => 'Активная сессия не найдена'], 403);
        }

        $user = User::find($userId);
        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        try {
            $row = $service->create(
                $user,
                $data['title'],
                $data['comment'] ?? null,
                \App\Models\GameRequest::SOURCE_SHELL
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Не удалось создать заявку';

            return response()->json(['message' => $msg], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Заявка принята',
            'request_id' => $row->id,
        ], 201);
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
