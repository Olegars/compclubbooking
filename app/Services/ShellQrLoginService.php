<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\ShellQrChallenge;
use App\Models\User;
use App\Services\BookingSeatTransferService;
use App\Services\BookingSessionTimingService;
use App\Services\ComputerPowerService;
use App\Services\Fan\FanControlService;
use App\Services\GameBookingService;
use App\Services\UserCloudSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShellQrLoginService
{
    public const TTL_SECONDS = 120;

    public const MIN_DURATION_MINUTES = 60;

    public const DURATION_STEP_MINUTES = 15;

    public function __construct(
        private readonly GameBookingService $bookings,
        private readonly BookingSessionTimingService $timing,
    ) {
    }

    /**
     * @return array{token:string,expires_at:string,qr_payload:string,computer:array{id:int,name:string}}
     */
    public function issue(Computer $computer): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        ShellQrChallenge::query()
            ->where('computer_id', $computer->id)
            ->where('status', ShellQrChallenge::STATUS_PENDING)
            ->update(['status' => ShellQrChallenge::STATUS_EXPIRED]);

        $token = (string) Str::uuid();
        $expiresAt = $now->addSeconds(self::TTL_SECONDS);

        ShellQrChallenge::create([
            'token' => $token,
            'computer_id' => $computer->id,
            'status' => ShellQrChallenge::STATUS_PENDING,
            'expires_at' => $expiresAt,
        ]);

        $payload = $this->qrPayload($token);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'qr_payload' => $payload,
            'computer' => [
                'id' => (int) $computer->id,
                'name' => (string) $computer->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $token): array
    {
        $challenge = $this->findChallenge($token);
        if (! $challenge) {
            return ['status' => 'expired', 'message' => 'Код не найден или истёк'];
        }

        $this->expireIfNeeded($challenge);
        $challenge->refresh();

        if ($challenge->status === ShellQrChallenge::STATUS_CONSUMED) {
            $user = $challenge->user_id ? User::query()->find($challenge->user_id) : null;
            $booking = $challenge->booking_id ? Booking::query()->find($challenge->booking_id) : null;
            if (! $user || ! $booking) {
                return ['status' => 'consumed', 'message' => 'Сессия открыта'];
            }

            return array_merge(
                ['status' => 'consumed'],
                $this->buildShellLoginPayload($user, $booking)
            );
        }

        if ($challenge->status !== ShellQrChallenge::STATUS_PENDING || ! $challenge->isPending()) {
            return ['status' => 'expired', 'message' => 'Код истёк — обновите QR на терминале'];
        }

        return [
            'status' => 'pending',
            'expires_at' => $challenge->expires_at?->toIso8601String(),
            'computer' => [
                'id' => (int) $challenge->computer_id,
                'name' => (string) ($challenge->computer?->name ?? ''),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function redeem(User $user, string $token): array
    {
        $challenge = $this->pendingChallengeOrFail($token);
        $computer = Computer::query()->with('space.zone')->findOrFail($challenge->computer_id);
        $terminalId = (int) $computer->id;

        $booking = $this->findUserBookingOnComputer($user, $terminalId);
        if ($booking) {
            return $this->activateAndConsume($user, $challenge, $booking);
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $probeEnd = $now->addHour();
        $occupied = $this->bookings->occupiedComputerIds([$terminalId], $now, $probeEnd);
        if ($occupied !== []) {
            return [
                'status' => 'occupied',
                'message' => 'Этот компьютер сейчас занят другой сессией.',
                'computer' => $this->computerPayload($computer),
            ];
        }

        $balance = (float) $user->syncBalanceToWallet();
        $quote = $this->quoteForDuration($computer, self::MIN_DURATION_MINUTES);
        $price = ((int) ($quote['total_minor'] ?? 0)) / 100.0;

        return [
            'status' => 'needs_booking',
            'message' => 'Данный компьютер не забронирован. Вы можете забронировать его прямо сейчас.',
            'token' => $challenge->token,
            'computer' => $this->computerPayload($computer),
            'balance' => $balance,
            'duration_minutes' => self::MIN_DURATION_MINUTES,
            'quote' => $this->publicQuote($quote),
            'can_pay' => $balance + 0.009 >= $price,
            'min_duration_minutes' => self::MIN_DURATION_MINUTES,
            'duration_step_minutes' => self::DURATION_STEP_MINUTES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewQuote(User $user, string $token, int $durationMinutes): array
    {
        $this->assertValidDuration($durationMinutes);
        $challenge = $this->pendingChallengeOrFail($token);
        $computer = Computer::query()->findOrFail($challenge->computer_id);
        $balance = (float) $user->syncBalanceToWallet();
        $quote = $this->quoteForDuration($computer, $durationMinutes);
        $price = ((int) ($quote['total_minor'] ?? 0)) / 100.0;

        return [
            'status' => 'ok',
            'token' => $challenge->token,
            'duration_minutes' => $durationMinutes,
            'balance' => $balance,
            'quote' => $this->publicQuote($quote),
            'can_pay' => $balance + 0.009 >= $price,
            'computer' => $this->computerPayload($computer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bookFromQr(User $user, string $token, int $durationMinutes): array
    {
        $this->assertValidDuration($durationMinutes);
        $challenge = $this->pendingChallengeOrFail($token);
        $computer = Computer::query()->findOrFail($challenge->computer_id);
        $terminalId = (int) $computer->id;
        $clubId = (int) $computer->club_id;

        $now = CarbonImmutable::now(config('app.timezone'))->addSeconds(2);
        $endsAt = $now->addMinutes($durationMinutes);

        $occupied = $this->bookings->occupiedComputerIds([$terminalId], $now, $endsAt);
        if ($occupied !== []) {
            return [
                'status' => 'occupied',
                'message' => 'Этот компьютер уже занят.',
                'computer' => $this->computerPayload($computer),
            ];
        }

        $quote = $this->quoteForDuration($computer, $durationMinutes);
        $price = ((int) ($quote['total_minor'] ?? 0)) / 100.0;
        $balance = (float) $user->syncBalanceToWallet();

        if ($balance + 0.009 < $price) {
            return [
                'status' => 'needs_topup',
                'message' => 'Недостаточно средств на балансе.',
                'balance' => $balance,
                'shortage' => round(max(0, $price - $balance), 2),
                'quote' => $this->publicQuote($quote),
                'duration_minutes' => $durationMinutes,
                'computer' => $this->computerPayload($computer),
                'can_pay' => false,
            ];
        }

        try {
            $group = $this->bookings->reserve(
                $user,
                $clubId,
                [$terminalId],
                [],
                $now,
                $endsAt,
                'hourly',
                null,
                []
            );
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Не удалось забронировать';

            return [
                'status' => 'error',
                'message' => $msg,
                'balance' => $balance,
                'quote' => $this->publicQuote($quote),
            ];
        }

        $booking = Booking::query()
            ->where('booking_group_id', $group->id)
            ->where('computer_id', $terminalId)
            ->firstOrFail();

        return $this->activateAndConsume($user, $challenge, $booking);
    }

    public function qrPayload(string $token): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/account/dashboard?qr='.$token;
    }

    private function assertValidDuration(int $durationMinutes): void
    {
        if ($durationMinutes < self::MIN_DURATION_MINUTES
            || ($durationMinutes % self::DURATION_STEP_MINUTES) !== 0
        ) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'Длительность от 1 часа, шаг 15 минут.',
            ]);
        }
    }

    private function findChallenge(string $token): ?ShellQrChallenge
    {
        return ShellQrChallenge::query()
            ->with('computer:id,name,club_id')
            ->where('token', $token)
            ->first();
    }

    private function pendingChallengeOrFail(string $token): ShellQrChallenge
    {
        $challenge = $this->findChallenge($token);
        if (! $challenge) {
            throw ValidationException::withMessages([
                'token' => 'Код не найден.',
            ]);
        }
        $this->expireIfNeeded($challenge);
        $challenge->refresh();
        if (! $challenge->isPending()) {
            throw ValidationException::withMessages([
                'token' => 'Код истёк — обновите QR на терминале и отсканируйте снова.',
            ]);
        }

        return $challenge;
    }

    private function expireIfNeeded(ShellQrChallenge $challenge): void
    {
        if ($challenge->status === ShellQrChallenge::STATUS_PENDING
            && $challenge->expires_at
            && $challenge->expires_at->isPast()
        ) {
            $challenge->update(['status' => ShellQrChallenge::STATUS_EXPIRED]);
        }
    }

    private function findUserBookingOnComputer(User $user, int $computerId): ?Booking
    {
        return Booking::query()
            ->where('user_id', $user->id)
            ->where('computer_id', $computerId)
            ->whereIn('status', ['paid', 'confirmed', 'active'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function activateAndConsume(User $user, ShellQrChallenge $challenge, Booking $booking): array
    {
        $timing = $this->timing;
        $fiscalReceipts = [];

        try {
            if ($booking->status === 'active' && $booking->actual_started_at) {
                app(BookingSeatTransferService::class)->clearTransferPending($booking);
                $booking = $timing->healSkewedWindow($booking->fresh());
                $formattedTime = $timing->formatRemainingHms($booking);
            } else {
                $activation = $timing->activate($booking);
                $booking = $activation['booking'];
                if (isset($activation['time_remaining_seconds'])) {
                    $secs = max(0, (int) $activation['time_remaining_seconds']);
                    $formattedTime = sprintf(
                        '%02d:%02d:%02d',
                        intdiv($secs, 3600),
                        intdiv($secs % 3600, 60),
                        $secs % 60
                    );
                } else {
                    $formattedTime = $timing->formatRemainingHms($booking);
                }
                $fiscalReceipts = $activation['fiscal_receipts'] ?? [];
            }
        } catch (RuntimeException $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        $challenge->update([
            'status' => ShellQrChallenge::STATUS_CONSUMED,
            'user_id' => $user->id,
            'booking_id' => $booking->id,
        ]);

        $terminalId = (int) $booking->computer_id;
        try {
            app(FanControlService::class)->reconcileForComputer($terminalId);
        } catch (\Throwable $e) {
            Log::warning('Fan reconcile after QR login failed: '.$e->getMessage());
        }
        try {
            app(ComputerPowerService::class)->touchOnline($terminalId);
        } catch (\Throwable $e) {
            Log::warning('Power touch after QR login failed: '.$e->getMessage());
        }

        $payload = $this->buildShellLoginPayload($user, $booking, $formattedTime, $fiscalReceipts);

        return array_merge(['status' => 'activated'], $payload, [
            'computer' => [
                'id' => $terminalId,
                'name' => (string) ($booking->computer?->name ?? Computer::query()->where('id', $terminalId)->value('name')),
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $fiscalReceipts
     * @return array<string, mixed>
     */
    private function buildShellLoginPayload(
        User $user,
        Booking $booking,
        ?string $formattedTime = null,
        array $fiscalReceipts = []
    ): array {
        $balance = $user->syncBalanceToWallet();
        $formattedTime ??= $this->timing->formatRemainingHms($booking);
        $cloud = app(UserCloudSettingsService::class)->getPackWithMeta($user);

        $fanState = ['available' => false];
        try {
            $fanState = app(FanControlService::class)->stateForComputer((int) $booking->computer_id);
        } catch (\Throwable $e) {
            // ignore
        }

        $primaryReceipt = collect($fiscalReceipts)
            ->first(fn ($r) => filled($r['fiscal_receipt_url'] ?? null));

        return [
            'message' => 'Авторизация успешна.',
            'booking_id' => $booking->id,
            'fan' => $fanState,
            'user' => [
                'id' => $user->id,
                'name' => $user->name ?? 'Игрок',
                'phone' => $user->phone,
                'balance' => $balance,
                'deposit_balance' => $balance,
                'total_balance' => $balance,
                'time_remaining' => $formattedTime,
            ],
            'settings_pack' => $cloud['payload'],
            'settings_updated_at' => $cloud['updated_at'],
            'fiscal_receipt' => $primaryReceipt ? [
                'transaction_id' => $primaryReceipt['transaction_id'],
                'amount' => $primaryReceipt['amount'],
                'description' => $primaryReceipt['description'],
                'fiscal_status' => $primaryReceipt['fiscal_status'],
                'fiscal_receipt_url' => $primaryReceipt['fiscal_receipt_url'],
                'is_stub' => (bool) ($primaryReceipt['is_stub'] ?? false),
            ] : null,
            'fiscal_receipts' => $fiscalReceipts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteForDuration(Computer $computer, int $durationMinutes): array
    {
        $now = CarbonImmutable::now(config('app.timezone'))->addSeconds(2);
        $ends = $now->addMinutes($durationMinutes);

        return $this->bookings->quote(
            (int) $computer->club_id,
            [(int) $computer->id],
            [],
            $now,
            $ends,
            'hourly',
            null,
            []
        );
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function publicQuote(array $quote): array
    {
        return [
            'duration_minutes' => (int) ($quote['duration_minutes'] ?? 0),
            'total_minor' => (int) ($quote['total_minor'] ?? 0),
            'total_price' => ((int) ($quote['total_minor'] ?? 0)) / 100.0,
            'currency' => $quote['currency'] ?? 'RUB',
        ];
    }

    /**
     * @return array{id:int,name:string,club_id:int,zone:?string}
     */
    private function computerPayload(Computer $computer): array
    {
        if (! $computer->relationLoaded('space')) {
            $computer->load('space.zone');
        }

        return [
            'id' => (int) $computer->id,
            'name' => (string) $computer->name,
            'club_id' => (int) $computer->club_id,
            'zone' => $computer->space?->zone?->name,
        ];
    }
}
