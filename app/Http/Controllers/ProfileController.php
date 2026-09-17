<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Booking;
use App\Models\Computer;
use Inertia\Inertia;
use App\Models\GuestClip;
use App\Models\Order;
use Carbon\Carbon;
use App\Models\ReviewClaim;
use App\Services\AchievementService;
use App\Services\BookingSessionTimingService;
use App\Services\FiscalService;
use App\Services\GameBookingService;
use App\Services\GuestClipService;
use App\Services\TelegramGuestService;
use Carbon\CarbonImmutable;

class ProfileController extends Controller
{
    public function dashboard(FiscalService $fiscal)
    {
        $user = Auth::user();
        $now = now();
        // Берем брони за сегодня и вчера (на случай ночных смен)
        $yesterday = now()->subDay()->toDateString();

        // 1. Кошелек (единый spendable баланс для shell / shop / кабинета)
        $user->syncBalanceToWallet();
        $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['deposit_balance' => 0]
        );

        // No-show / отложенные чеки до отрисовки истории (не ждать только cron).
        $timing = app(BookingSessionTimingService::class);
        $timing->cancelNoShows();
        $fiscal->settleOrphanedDeferredBookings();

        // 2. Активные заказы из магазина
        $activeOrders = Order::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'cooking', 'new', 'waiting', 'scheduled'])
            ->latest()
            ->get();

        // 3. Последние транзакции
        $transactions = $user->transactions()
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($t) use ($fiscal) {
                $titles = collect(data_get($t->payload, 'games', []))
                    ->pluck('title')
                    ->filter()
                    ->unique()
                    ->values();

                $description = $t->description;
                if ($titles->isNotEmpty() && (
                    ! $description
                    || str_contains(mb_strtolower((string) $description), 'компьютеры и игры')
                    || preg_match('/^бронь #\d+:\s*компьютеры$/iu', (string) $description)
                )) {
                    $description = 'Бронь #'.($t->booking_group_id ?: '—').': '.$titles->implode(', ');
                }

                // В чеке/логе — забронированные часы из quote, не remaining после activate.
                $bookedHours = $fiscal->bookedHoursFromTransaction($t);
                $hoursLabel = $fiscal->formatHoursLabel($bookedHours);
                if ($hoursLabel && preg_match('/·\s*[\d.,]+\s*ч/u', (string) $description)) {
                    $description = preg_replace('/·\s*[\d.,]+\s*ч/u', '· '.$hoursLabel, (string) $description, 1);
                }

                $receiptUrl = $fiscal->displayReceiptUrl($t);
                $isStub = $fiscal->isStubReceiptUrl($receiptUrl)
                    || ($t->fiscal_status === 'skipped' && filled($receiptUrl));

                $isNoShow = false;
                if (
                    $t->booking_group_id
                    && in_array((string) $t->type, ['booking', 'booking_upgrade'], true)
                ) {
                    $group = \App\Models\BookingGroup::query()->find($t->booking_group_id);
                    if (
                        $group
                        && $group->status === 'cancelled'
                        && $group->payment_status !== 'refunded'
                        && ! $group->bookings()->whereNotNull('actual_started_at')->exists()
                    ) {
                        $isNoShow = true;
                    }
                }

                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => (float) round((float) $t->amount),
                    'description' => $description,
                    'games' => $titles->all(),
                    'date' => $t->created_at->format('d.m / H:i'),
                    'fiscal_receipt_url' => $receiptUrl,
                    'fiscal_status' => $t->fiscal_status ?: ($isStub ? 'skipped' : null),
                    'payment_uuid' => data_get($t->payload, 'payment_uuid'),
                    'has_receipt' => filled($receiptUrl),
                    'is_stub_receipt' => $isStub,
                    'is_no_show' => $isNoShow,
                ];
            });

        // 4. Активные бронирования (включая опоздание в soft-grace окне)
        $bookingService = app(GameBookingService::class);
        $nowImmutable = CarbonImmutable::instance($now->copy()->timezone(config('app.timezone')));

        $activeBookings = Booking::where('user_id', $user->id)
            ->with([
                'computer:id,name',
                'gameReservations.bookingGame',
                'group.games',
            ])
            ->whereIn('status', ['active', 'paid', 'confirmed', 'new'])
            ->where('date', '>=', $yesterday)
            ->get()
            ->map(function ($booking) use ($nowImmutable, $timing, $bookingService) {
                $tz = config('app.timezone');
                $startTime = (float) $booking->start_time;
                $duration = (float) $booking->duration;

                // Для карточки в кабинете опираемся на wall-clock (date + start_time),
                // который совпадает с выбором пользователя на экране бронирования.
                // starts_at/ends_at могут быть сдвинуты из-за naive timestamp в PG.
                $dateString = $booking->date instanceof \DateTimeInterface
                    ? $booking->date->format('Y-m-d')
                    : Carbon::parse($booking->date)->format('Y-m-d');

                $startDateTime = Carbon::parse($dateString, $tz)
                    ->startOfDay()
                    ->addMinutes((int) round($startTime * 60));
                $endDateTime = (clone $startDateTime)->addMinutes((int) round($duration * 60));

                // Если modern-поля согласованы с wall-clock — используем их (точнее до секунд).
                if ($booking->starts_at && $booking->ends_at) {
                    $modernStart = Carbon::parse($booking->starts_at)->timezone($tz);
                    $modernEnd = Carbon::parse($booking->ends_at)->timezone($tz);
                    if (abs($modernStart->diffInMinutes($startDateTime)) <= 1) {
                        $startDateTime = $modernStart;
                        $endDateTime = $modernEnd;
                    }
                }

                $scheduledStart = CarbonImmutable::instance($startDateTime);
                $scheduledEnd = CarbonImmutable::instance($endDateTime);
                $paidMinutes = $timing->paidDurationMinutes($booking, $scheduledStart, $scheduledEnd);
                // Active без actual_started_at (legacy) всё равно считаем начатой —
                // иначе кабинет рисует start+grace+duration (~+30–60 мин к оплаченному).
                $started = filled($booking->actual_started_at) || $booking->status === 'active';
                $waiting = $nowImmutable->lt($scheduledStart);

                // phase: waiting | late_waiting | late_billing | active
                $phase = 'waiting';
                $billingStart = $scheduledStart;

                if ($started) {
                    $booking = $timing->healSkewedWindow($booking);
                    $remainingSeconds = $timing->remainingSeconds($booking, $nowImmutable);
                    $phase = 'active';
                    $effectiveEndMs = ($nowImmutable->getTimestamp() + $remainingSeconds) * 1000;
                } elseif ($waiting) {
                    $following = $timing->hasFollowingBookingConflict(
                        $booking,
                        $scheduledStart,
                        $scheduledEnd,
                        $paidMinutes
                    );
                    $graceMinutes = $following ? 0 : $timing->lateStartGraceMinutes();
                    $billingStart = $scheduledStart->addMinutes($graceMinutes);
                    // До старта в UI «Ожидание»; конец сессии если войти сразу = now-эквивалент оплаченных минут.
                    $remainingSeconds = $paidMinutes * 60;
                    $phase = 'waiting';
                    $effectiveEndMs = ($nowImmutable->getTimestamp() + $remainingSeconds) * 1000;
                } else {
                    $following = $timing->hasFollowingBookingConflict(
                        $booking,
                        $scheduledStart,
                        $scheduledEnd,
                        $paidMinutes
                    );
                    $graceMinutes = $following ? 0 : $timing->lateStartGraceMinutes();
                    $billingStart = $scheduledStart->addMinutes($graceMinutes);
                    // Важно: softGraceRemainingSeconds = оплаченные минуты (не +grace).
                    // Раньше absoluteEnd = start+grace+paid раздувал «Осталось» до ~3 ч при заказе 2 ч.
                    $remainingSeconds = $timing->softGraceRemainingSeconds(
                        $scheduledStart,
                        $paidMinutes,
                        $nowImmutable
                    );
                    $phase = $nowImmutable->lt($billingStart) ? 'late_waiting' : 'late_billing';
                    $effectiveEndMs = ($nowImmutable->getTimestamp() + $remainingSeconds) * 1000;
                }

                // Обрабатываем pc_ids (Postgres часто отдает строку вместо массива)
                $pcIds = $booking->pc_ids;
                if (is_string($pcIds)) {
                    $pcIds = json_decode($pcIds, true) ?: [$pcIds];
                }

                $pcLabel = $booking->computer?->name
                    ?: implode(', ', array_filter((array) $pcIds));

                $titles = [];
                foreach ($booking->gameReservations as $reservation) {
                    $title = optional($reservation->bookingGame)->game_title;
                    if (is_string($title) && $title !== '') {
                        $titles[] = $title;
                    }
                }
                if ($titles === [] && $booking->group) {
                    foreach ($booking->group->games as $bookingGame) {
                        $title = $bookingGame->game_title;
                        if (is_string($title) && $title !== '') {
                            $titles[] = $title;
                        }
                    }
                }
                $titles = array_values(array_unique($titles));

                $booking->end_timestamp = $effectiveEndMs;
                $booking->start_timestamp = $scheduledStart->getTimestamp() * 1000;
                $booking->billing_start_timestamp = $billingStart->getTimestamp() * 1000;
                $booking->remaining_seconds = $remainingSeconds;
                $booking->phase = $phase;
                $booking->is_late_waiting = $phase === 'late_waiting';
                $booking->is_late_billing = $phase === 'late_billing';
                $booking->is_started = $started;
                $booking->is_expired = $remainingSeconds <= 0 && ! $waiting;
                $booking->formatted_pc = $pcLabel;
                $booking->game_titles = $titles;
                $booking->game_label = implode(', ', $titles);
                $booking->display_start = $scheduledStart->format('H:i');
                $booking->display_end = $scheduledEnd->format('H:i');

                $group = $booking->group;
                $canCancel = $group
                    ? $bookingService->canUserCancel($group, $nowImmutable, $scheduledStart)
                    : false;
                $cancelDeadline = $group
                    ? $bookingService->cancelDeadlineFor($group, $scheduledStart)
                    : null;
                $booking->can_cancel = $canCancel;
                $booking->cancel_deadline_at = $cancelDeadline?->toIso8601String();
                $booking->cancel_before_minutes = $bookingService->cancelBeforeMinutes();

                return $booking;
            })
            // Будущие + идущие + опоздание, пока ещё можно войти
            ->filter(fn ($b) => ! $b->is_expired)
            ->values();

        $shopOrders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['scheduled', 'pending', 'cooking'])
            ->orderByDesc('id')
            ->get();

        $claimedOrderIds = [];
        $activeBookings = $activeBookings->map(function ($booking) use ($shopOrders, &$claimedOrderIds) {
            $matched = $shopOrders->filter(function (Order $order) use ($booking, $claimedOrderIds) {
                if (in_array((int) $order->id, $claimedOrderIds, true)) {
                    return false;
                }
                if ($order->booking_id) {
                    return (int) $order->booking_id === (int) $booking->id;
                }
                $pc = (string) ($booking->formatted_pc ?? '');
                $computerId = (int) ($booking->computer_id ?? 0);

                return ($pc !== '' && (string) $order->pc_name === $pc)
                    || ($computerId > 0 && (string) $order->pc_name === 'ПК №'.$computerId);
            })->values();

            foreach ($matched as $order) {
                $claimedOrderIds[] = (int) $order->id;
            }

            $booking->shop_orders = $matched->map(function (Order $order) {
                return [
                    'id' => $order->id,
                    'product_name' => $order->product_name,
                    'items' => $order->lineItems(),
                    'price' => (float) $order->price,
                    'status' => $order->status,
                ];
            })->values()->all();

            return $booking;
        });

        // 5. Бонусы за отзыв (pending имеет приоритет для статуса в UI)
        $latestReview = ReviewClaim::where('user_id', $user->id)
            ->where('status', ReviewClaim::STATUS_PENDING)
            ->latest()
            ->first()
            ?? ReviewClaim::where('user_id', $user->id)->latest()->first();

        $reviewMeta = app(\App\Services\ReviewBonusService::class)->clientMeta();

        // 6. Квесты / ачивки
        $achievements = app(AchievementService::class)->progressForUser($user);

        $clipService = app(GuestClipService::class);
        $telegram = app(TelegramGuestService::class);
        $clips = GuestClip::query()
            ->with('computer:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(GuestClipService::MAX_PER_USER)
            ->get()
            ->map(fn (GuestClip $c) => $clipService->serialize($c))
            ->values();

        $clanWars = ['live' => null, 'mine' => [], 'board' => []];
        try {
            $clanWars = app(\App\Services\ClanWarService::class)->cabinetForUser($user);
        } catch (\Throwable $e) {
            report($e);
        }

        // 7. Рендер (Все ключи приведены к соответствию с Vue)
        return Inertia::render('User/Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'avatar_url' => $user->avatar_url,
            ],
            'transactions' => $transactions,
            'active_bookings' => $activeBookings,
            'orders' => $activeOrders,
            'latest_review' => $latestReview,
            'review_meta' => $reviewMeta,
            'achievements' => $achievements,
            'clips' => $clips,
            'clips_telegram' => $clipService->telegramConfigured(),
            'telegram' => $telegram->payload($user),
            'clan_wars' => $clanWars,
            'arena' => $this->arenaCabinet($user),
            'server_time' => $now->toIso8601String(),
        ]);
    }

    public function edit()
    {
        return Inertia::render('User/Profile');
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
        ]);

        return back();
    }

    public function updateAvatar(Request $request, \App\Services\PlayerAvatarService $avatars)
    {
        $request->validate([
            'photo' => ['required', 'file', 'max:8192'],
            'stylize' => ['sometimes', 'boolean'],
        ]);

        try {
            $photo = $request->file('photo');
            if (! $photo instanceof \Illuminate\Http\UploadedFile) {
                return back()->withErrors(['photo' => 'Нужно фото.']);
            }
            $avatars->save(Auth::user(), $photo, $request->boolean('stylize'));
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['photo' => $e->getMessage() ?: 'Не удалось сохранить фото.']);
        }

        return back()->with('success', $request->boolean('stylize')
            ? 'Клубный аватар собран'
            : 'Фото сохранено');
    }

    public function transferTargets(\App\Services\BookingSeatTransferService $transfers)
    {
        $user = Auth::user();
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Нет активной сессии'], 404);
        }

        try {
            $this->assertSeatTransfer($booking);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $map = $transfers->mapForTransfer($booking);

        return response()->json([
            'status' => 'success',
            'booking_id' => $booking->id,
            'from_computer_id' => $map['from_computer_id'],
            'targets' => $map['targets'],
            'map_config' => $map['map_config'],
            'computers' => $map['computers'],
            'occupied_ids' => $map['occupied_ids'],
            'selectable_ids' => $map['selectable_ids'],
        ]);
    }

    public function transferPreview(Request $request, \App\Services\BookingSeatTransferService $transfers)
    {
        $user = Auth::user();
        $data = $request->validate([
            'target_computer_id' => 'required|integer',
        ]);

        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Нет активной сессии'], 404);
        }
        $this->assertSeatTransfer($booking);

        try {
            $preview = $transfers->preview($booking, (int) $data['target_computer_id']);

            return response()->json(['status' => 'success', 'preview' => $preview]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function transferConfirm(Request $request, \App\Services\BookingSeatTransferService $transfers)
    {
        $user = Auth::user();
        $data = $request->validate([
            'target_computer_id' => 'required|integer',
        ]);

        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Нет активной сессии'], 404);
        }
        $this->assertSeatTransfer($booking);

        try {
            $result = $transfers->transfer($booking, (int) $data['target_computer_id'], $user);

            return response()->json([
                'status' => 'success',
                'message' => 'Пересадка выполнена. Войдите PIN на новом ПК.',
                'pin_code' => $result['pin_code'] ?? null,
                'to' => $result['to'] ?? null,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function showSharedClip(string $token)
    {
        $clip = GuestClip::query()->where('share_token', $token)->firstOrFail();

        return response()->view('clips.show', [
            'clip' => $clip,
            'url' => $clip->publicUrl(),
            'book_url' => url('/'),
        ]);
    }

    public function shareClipTelegram(GuestClip $clip, GuestClipService $clips)
    {
        if ((int) $clip->user_id !== (int) Auth::id()) {
            abort(404);
        }
        if (! $clips->botConfigured()) {
            return back()->withErrors(['clip' => 'Бот Telegram не настроен']);
        }
        if (! $clips->postTelegram($clip, true)) {
            return back()->withErrors(['clip' => $clip->fresh()->telegram_error ?: 'Telegram не принял клип']);
        }

        return back();
    }

    public function unlinkTelegram(TelegramGuestService $telegram)
    {
        $telegram->unlink(Auth::user());

        return back();
    }

    public function destroyClip(GuestClip $clip, GuestClipService $clips)
    {
        if ((int) $clip->user_id !== (int) Auth::id()) {
            abort(404);
        }
        $clips->destroy($clip);

        return back();
    }

    public function arenaLive()
    {
        return response()->json([
            'status' => 'success',
            'arena' => $this->arenaCabinet(Auth::user()),
        ]);
    }

    public function createArena(\Illuminate\Http\Request $request)
    {
        [$computer, $booking, $user] = $this->arenaSeat();
        $data = $request->validate([
            'game' => 'nullable|in:cs2,dota,dota2',
            'mode' => 'required|in:1v1_aim,2v2_wingman,1v1_mid',
            'kind' => 'nullable|in:duel,battle',
            'scope' => 'nullable|in:hall,computer,pc,zone,bootcamp',
            'target_computer_id' => 'nullable|integer',
            'scheduled_at' => 'nullable|date',
            'max_players' => 'nullable|integer|min:2|max:16',
        ]);
        try {
            $duel = app(\App\Services\LanLive\ArenaDuelService::class)->create($user, $computer, $booking, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Вызов брошен',
            'arena' => $this->arenaCabinet($user->fresh()),
            'duel' => app(\App\Services\LanLive\ArenaDuelService::class)->payload($duel, $computer, $booking, $user),
        ]);
    }

    public function acceptArena(string $uuid)
    {
        [$computer, $booking, $user] = $this->arenaSeat();
        $arena = app(\App\Services\LanLive\ArenaDuelService::class);
        try {
            $duel = $arena->accept($user, $computer, $booking, $arena->findByUuid($uuid));
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Вызов принят',
            'arena' => $this->arenaCabinet($user->fresh()),
            'duel' => $arena->payload($duel, $computer, $booking, $user),
        ]);
    }

    public function declineArena(string $uuid)
    {
        [$computer, $booking, $user] = $this->arenaSeat();
        $arena = app(\App\Services\LanLive\ArenaDuelService::class);
        try {
            $arena->decline($user, $computer, $arena->findByUuid($uuid));
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Вызов отклонён',
            'arena' => $this->arenaCabinet($user->fresh()),
        ]);
    }

    public function cancelArena(string $uuid)
    {
        $user = Auth::user();
        $arena = app(\App\Services\LanLive\ArenaDuelService::class);
        try {
            $arena->cancel($user, $arena->findByUuid($uuid));
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Вызов снят',
            'arena' => $this->arenaCabinet($user->fresh()),
        ]);
    }

    public function raiseArena(\Illuminate\Http\Request $request, string $uuid)
    {
        [$computer, $booking, $user] = $this->arenaSeat();
        $arena = app(\App\Services\LanLive\ArenaDuelService::class);
        $data = $request->validate([
            'entry_fee' => 'required|numeric|min:1|max:20000',
        ]);
        try {
            $duel = $arena->proposeRaise($user, $arena->findByUuid($uuid), (float) $data['entry_fee']);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $duel->raise_to ? 'Ждём согласие остальных' : 'Ставка повышена',
            'arena' => $this->arenaCabinet($user->fresh()),
            'duel' => $arena->payload($duel, $computer, $booking, $user),
        ]);
    }

    public function voteArenaRaise(\Illuminate\Http\Request $request, string $uuid)
    {
        [$computer, $booking, $user] = $this->arenaSeat();
        $arena = app(\App\Services\LanLive\ArenaDuelService::class);
        $data = $request->validate([
            'agree' => 'required|boolean',
        ]);
        try {
            $duel = $arena->voteRaise($user, $arena->findByUuid($uuid), (bool) $data['agree']);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $data['agree'] ? ($duel->raise_to ? 'Голос принят' : 'Ставка повышена') : 'Повышение отклонено',
            'arena' => $this->arenaCabinet($user->fresh()),
            'duel' => $arena->payload($duel, $computer, $booking, $user),
        ]);
    }

    public function startArena(string $uuid)
    {
        $user = Auth::user();
        $arena = app(\App\Services\LanLive\ArenaDuelService::class);
        try {
            $duel = $arena->start($user, $arena->findByUuid($uuid));
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Лобби закрыто, можно начинать',
            'arena' => $this->arenaCabinet($user->fresh()),
            'duel' => $arena->payload($duel, null, null, $user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function arenaCabinet($user): array
    {
        try {
            return app(\App\Services\LanLive\ArenaDuelService::class)->cabinetFor($user);
        } catch (\Throwable $e) {
            report($e);

            return ['enabled' => false, 'incoming' => null, 'board' => [], 'open' => [], 'live' => [], 'highlight_computer_ids' => []];
        }
    }

    /**
     * @return array{0: ?Computer, 1: ?Booking, 2: \App\Models\User}
     */
    private function arenaSeat(): array
    {
        $user = Auth::user();
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        $computer = $booking ? Computer::query()->find((int) $booking->computer_id) : null;

        return [$computer, $booking, $user];
    }

    private function assertSeatTransfer(Booking $booking): void
    {
        $computer = Computer::query()->find((int) $booking->computer_id);
        app(\App\Services\ClubFeatureService::class)->assertEnabled(
            app(\App\Services\ClubFeatureService::class)->clubIdForComputer($computer),
            'seat_transfer',
            'Пересадка выключена'
        );
    }
}
