<?php

namespace App\Services;

use App\Jobs\ProcessFiscalReceipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FiscalService
{
    public const MODE_ADVANCE = 'advance';

    public const MODE_SETTLEMENT = 'settlement';

    public const MODE_REFUND = 'refund';

    /** Признак способа расчёта (тег 1214) */
    public const METHOD_ADVANCE = 3;

    public const METHOD_FULL = 4;

    /** Признак предмета расчёта (тег 1212) */
    public const OBJECT_SERVICE = 4;

    public const OBJECT_PAYMENT = 10;

    public const OBJECT_GOODS = 1;

    public function isEnabled(): bool
    {
        return (bool) config('fiscal.enabled', false);
    }

    public function stubReceiptUrl(Transaction $transaction): string
    {
        return url('/receipt/stub/'.$transaction->id);
    }

    public function isStubReceiptUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        return str_contains($url, '/receipt/stub/');
    }

    /**
     * URL для показа QR: реальный ОФД или заглушка, если касса выключена / skipped.
     */
    public function displayReceiptUrl(Transaction $transaction): ?string
    {
        if (filled($transaction->fiscal_receipt_url)) {
            return (string) $transaction->fiscal_receipt_url;
        }

        // До оказания услуги / аннулировано без чека — QR не показываем.
        if (in_array((string) $transaction->fiscal_status, ['deferred', 'void'], true)) {
            return null;
        }

        $mode = $transaction->fiscal_mode ?: $this->resolveMode($transaction);
        if ($mode === null) {
            return null;
        }

        if ($transaction->fiscal_status === 'skipped' || ! $this->isEnabled()) {
            return $this->stubReceiptUrl($transaction);
        }

        return null;
    }

    /**
     * Пометить транзакцию как пропущенную кассой и выдать URL-заглушку для QR.
     */
    public function markSkippedWithStub(Transaction $transaction, string $mode): void
    {
        $transaction->forceFill([
            'fiscal_mode' => $mode,
            'fiscal_status' => 'skipped',
            'fiscal_receipt_url' => $this->stubReceiptUrl($transaction),
            'fiscal_error' => null,
            'fiscal_at' => now(),
        ])->saveQuietly();
    }

    /**
     * Какой режим чека нужен для транзакции (или null — не фискалить).
     */
    public function resolveMode(Transaction $transaction): ?string
    {
        $type = (string) $transaction->type;
        $source = strtolower(trim((string) ($transaction->source ?? '')));
        $amount = (float) $transaction->amount;

        if ($type === 'deposit' && $amount > 0) {
            $skip = array_map('strtolower', config('fiscal.skip_advance_sources', [
                'bonus', 'promo', 'achievement', 'referral', 'gift', 'fantiki', 'admin_bonus',
            ]));
            if (in_array($source, $skip, true)) {
                return null;
            }

            return self::MODE_ADVANCE;
        }

        if ($amount < 0 && in_array($type, config('fiscal.settlement_types', []), true)) {
            return self::MODE_SETTLEMENT;
        }

        if ($type === 'refund' && $amount > 0) {
            // Возврат на кошелёк до закрывающего чека — фискально не бьём
            // (аванс с пополнения остаётся; деньги просто вернулись на баланс).
            if ($this->refundNeedsFiscal($transaction)) {
                return self::MODE_REFUND;
            }

            return null;
        }

        return null;
    }

    /**
     * Бронь / апгрейд: чек полного расчёта после старта сессии, не в момент списания.
     */
    public function shouldDeferSettlement(Transaction $transaction): bool
    {
        $mode = $this->resolveMode($transaction);
        if ($mode !== self::MODE_SETTLEMENT) {
            return false;
        }

        $deferred = array_map('strtolower', config('fiscal.deferred_settlement_types', [
            'booking', 'booking_upgrade',
        ]));
        $type = strtolower((string) $transaction->type);
        if (! in_array($type, $deferred, true)) {
            return false;
        }

        // Апгрейд во время уже активной сессии — бьём сразу (услуга уже идёт).
        if ($type === 'booking_upgrade' && $transaction->booking_group_id) {
            $active = \App\Models\Booking::query()
                ->where('booking_group_id', $transaction->booking_group_id)
                ->where('status', 'active')
                ->whereNotNull('actual_started_at')
                ->exists();
            if ($active) {
                return false;
            }
        }

        return true;
    }

    public function markDeferred(Transaction $transaction, string $mode): void
    {
        $transaction->forceFill([
            'fiscal_mode' => $mode,
            'fiscal_status' => 'deferred',
            'fiscal_receipt_url' => null,
            'fiscal_error' => null,
            'fiscal_at' => null,
        ])->saveQuietly();
    }

    public function voidDeferred(Transaction $transaction, string $reason = 'refunded_before_service'): void
    {
        if ($transaction->fiscal_status !== 'deferred') {
            return;
        }

        $transaction->forceFill([
            'fiscal_status' => 'void',
            'fiscal_error' => $reason,
            'fiscal_receipt_url' => null,
            'fiscal_at' => now(),
        ])->saveQuietly();
    }

    /**
     * Закрыть отложенные чеки группы (login / no-show с удержанием оплаты).
     *
     * @return list<array{transaction_id:int,amount:float,description:?string,fiscal_status:?string,fiscal_receipt_url:?string,is_stub:bool}>
     */
    public function settleDeferredForBooking(\App\Models\Booking $booking): array
    {
        $groupId = $booking->booking_group_id;
        if (! $groupId) {
            return [];
        }

        return $this->settleDeferredForBookingGroup((int) $groupId, $booking);
    }

    /**
     * @return list<array{transaction_id:int,amount:float,description:?string,fiscal_status:?string,fiscal_receipt_url:?string,is_stub:bool}>
     */
    public function settleDeferredForBookingGroup(int $bookingGroupId, ?\App\Models\Booking $contextBooking = null): array
    {
        $txs = Transaction::query()
            ->where('booking_group_id', $bookingGroupId)
            ->where('fiscal_status', 'deferred')
            ->whereIn('type', config('fiscal.deferred_settlement_types', ['booking', 'booking_upgrade']))
            ->orderBy('id')
            ->get();

        if ($txs->isEmpty()) {
            return [];
        }

        $booking = $contextBooking
            ?? \App\Models\Booking::query()
                ->with('computer:id,name')
                ->where('booking_group_id', $bookingGroupId)
                ->orderByDesc('actual_started_at')
                ->orderBy('id')
                ->first();

        if ($booking && ! $booking->relationLoaded('computer')) {
            $booking->load('computer:id,name');
        }

        $results = [];
        foreach ($txs as $tx) {
            $this->enrichBookingSettlementDescription($tx, $booking);

            try {
                // Синхронно — шеллу нужен URL чека в ответе login.
                (new ProcessFiscalReceipt($tx->id))->handle($this);
            } catch (\Throwable $e) {
                Log::warning('Deferred fiscal settle failed: '.$e->getMessage(), [
                    'transaction_id' => $tx->id,
                    'booking_group_id' => $bookingGroupId,
                ]);
            }

            $tx->refresh();
            $url = $this->displayReceiptUrl($tx);
            $results[] = [
                'transaction_id' => (int) $tx->id,
                'amount' => (float) $tx->amount,
                'description' => $tx->description,
                'fiscal_status' => $tx->fiscal_status,
                'fiscal_receipt_url' => $url,
                'is_stub' => $this->isStubReceiptUrl($url) || $tx->fiscal_status === 'skipped',
            ];
        }

        return $results;
    }

    public function voidDeferredForBookingGroup(int $bookingGroupId, string $reason = 'refunded_before_service'): void
    {
        $txs = Transaction::query()
            ->where('booking_group_id', $bookingGroupId)
            ->where('fiscal_status', 'deferred')
            ->get();

        foreach ($txs as $tx) {
            $this->voidDeferred($tx, $reason);
        }
    }

    /**
     * Дозакрыть deferred-чеки броней, у которых уже нет открытых booking
     * (completed/cancelled без входа — типичный зависший «После входа»).
     */
    public function settleOrphanedDeferredBookings(): int
    {
        $groupIds = Transaction::query()
            ->where('fiscal_status', 'deferred')
            ->whereIn('type', config('fiscal.deferred_settlement_types', ['booking', 'booking_upgrade']))
            ->whereNotNull('booking_group_id')
            ->pluck('booking_group_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter()
            ->values();

        if ($groupIds->isEmpty()) {
            return 0;
        }

        $settled = 0;
        foreach ($groupIds as $groupId) {
            $open = \App\Models\Booking::query()
                ->where('booking_group_id', $groupId)
                ->whereIn('status', ['confirmed', 'paid', 'active', 'pending', 'pending_payment'])
                ->exists();

            if ($open) {
                continue;
            }

            $results = $this->settleDeferredForBookingGroup($groupId);
            $settled += count($results);
        }

        return $settled;
    }

    protected function enrichBookingSettlementDescription(Transaction $transaction, ?\App\Models\Booking $booking): void
    {
        if (! $booking) {
            return;
        }

        $pc = trim((string) ($booking->computer?->name ?? ''));
        if ($pc === '') {
            $pc = 'ПК #'.((int) $booking->computer_id);
        }

        // Оплаченная длительность из quote при оплате — не remaining после late activate
        // (booking.duration тогда переписывается на фактический остаток, напр. 0.8 ч).
        $hours = $this->bookedHoursFromTransaction($transaction, $booking);
        $hoursLabel = $this->formatHoursLabel($hours);

        $base = trim((string) ($transaction->description ?? ''));
        if ($base === '') {
            $base = $transaction->type === 'booking_upgrade' ? 'Апгрейд игрового времени' : 'Игровое время';
        }

        // Снимаем прошлый суффикс «· PC · N ч», чтобы повторный settle был идемпотентным.
        $escapedPc = preg_quote($pc, '/');
        $base = preg_replace('/\s*·\s*'.$escapedPc.'(?:\s*·\s*[\d.,]+\s*ч)?$/iu', '', $base) ?? $base;
        $base = preg_replace('/\s*·\s*[\d.,]+\s*ч\s*$/u', '', $base) ?? $base;
        $base = trim($base);

        $name = $hoursLabel
            ? "{$base} · {$pc} · {$hoursLabel}"
            : "{$base} · {$pc}";

        $transaction->forceFill([
            'description' => mb_substr($name, 0, 240),
        ])->saveQuietly();
    }

    /**
     * Booked (paid) hours for receipt text — prefer quote payload over mutated booking.duration.
     */
    public function bookedHoursFromTransaction(Transaction $transaction, ?\App\Models\Booking $booking = null): float
    {
        $minutes = (int) data_get($transaction->payload, 'duration_minutes', 0);
        if ($minutes > 0) {
            return $minutes / 60.0;
        }

        if ($booking && (float) $booking->duration > 0 && ! $booking->actual_started_at) {
            return (float) $booking->duration;
        }

        return $booking ? max(0.0, (float) $booking->duration) : 0.0;
    }

    public function formatHoursLabel(float $hours): ?string
    {
        if ($hours <= 0) {
            return null;
        }

        if (fmod($hours, 1.0) < 0.05) {
            return ((int) round($hours)).' ч';
        }

        return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.').' ч';
    }

    protected function refundNeedsFiscal(Transaction $transaction): bool
    {
        $groupId = $transaction->booking_group_id;
        if (! $groupId) {
            // Возврат без привязки к брони — оставляем как раньше (фискалим).
            return true;
        }

        $settled = Transaction::query()
            ->where('booking_group_id', $groupId)
            ->whereIn('type', config('fiscal.deferred_settlement_types', ['booking', 'booking_upgrade']))
            ->where('fiscal_status', 'success')
            ->exists();

        return $settled;
    }

    /**
     * @return array{success: bool, url?: ?string, error?: string, skipped?: bool}
     */
    public function registerForTransaction(Transaction $transaction): array
    {
        if (! $this->isEnabled()) {
            return [
                'success' => true,
                'skipped' => true,
                'url' => $this->stubReceiptUrl($transaction),
            ];
        }

        $mode = $transaction->fiscal_mode ?: $this->resolveMode($transaction);
        if ($mode === null) {
            return ['success' => true, 'skipped' => true, 'url' => null];
        }

        return match ($mode) {
            self::MODE_ADVANCE => $this->registerAdvance($transaction),
            self::MODE_SETTLEMENT => $this->registerSettlement($transaction),
            self::MODE_REFUND => $this->registerRefund($transaction),
            default => ['success' => false, 'error' => 'Unknown fiscal mode: '.$mode],
        };
    }

    /**
     * Пополнение кошелька — чек «АВАНС».
     *
     * @return array{success: bool, url?: ?string, error?: string}
     */
    public function registerAdvance(Transaction $transaction): array
    {
        $amount = abs((float) $transaction->amount);
        $name = $this->itemName($transaction, 'Аванс (пополнение баланса)');

        $payload = $this->basePayload(
            typeCheck: 0,
            itemName: $name,
            amount: $amount,
            signMethod: self::METHOD_ADVANCE,
            signObject: self::OBJECT_PAYMENT,
            cash: $this->isCashSource($transaction) ? $amount : 0.0,
            electronic: $this->isCashSource($transaction) ? 0.0 : $amount,
            advancePayment: 0.0,
            clientAddress: $this->clientAddress($transaction),
        );

        return $this->execute($payload, $transaction, self::MODE_ADVANCE);
    }

    /**
     * Списание с депозита (бронь / магазин) — полный расчёт с зачётом аванса.
     *
     * @return array{success: bool, url?: ?string, error?: string}
     */
    public function registerSettlement(Transaction $transaction): array
    {
        $amount = abs((float) $transaction->amount);
        $isGoods = $transaction->type === 'purchase';
        $name = $this->itemName(
            $transaction,
            $isGoods ? 'Товары клуба' : 'Игровое время'
        );

        $payload = $this->basePayload(
            typeCheck: 0,
            itemName: $name,
            amount: $amount,
            signMethod: self::METHOD_FULL,
            signObject: $isGoods ? self::OBJECT_GOODS : self::OBJECT_SERVICE,
            cash: 0.0,
            electronic: 0.0,
            advancePayment: $amount,
            clientAddress: $this->clientAddress($transaction),
        );

        return $this->execute($payload, $transaction, self::MODE_SETTLEMENT);
    }

    /**
     * Возврат на баланс (возврат прихода) — упрощённо как возврат аванса.
     *
     * @return array{success: bool, url?: ?string, error?: string}
     */
    public function registerRefund(Transaction $transaction): array
    {
        $amount = abs((float) $transaction->amount);
        $name = $this->itemName($transaction, 'Возврат аванса');

        $payload = $this->basePayload(
            typeCheck: 1,
            itemName: $name,
            amount: $amount,
            signMethod: self::METHOD_ADVANCE,
            signObject: self::OBJECT_PAYMENT,
            cash: $this->isCashSource($transaction) ? $amount : 0.0,
            electronic: $this->isCashSource($transaction) ? 0.0 : $amount,
            advancePayment: 0.0,
            clientAddress: $this->clientAddress($transaction),
        );

        return $this->execute($payload, $transaction, self::MODE_REFUND);
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePayload(
        int $typeCheck,
        string $itemName,
        float $amount,
        int $signMethod,
        int $signObject,
        float $cash,
        float $electronic,
        float $advancePayment,
        ?string $clientAddress,
    ): array {
        $kkm = config('fiscal.kkm', []);
        $amount = round($amount, 2);

        $payload = [
            'Command' => 'RegisterCheck',
            'NumDevice' => (int) ($kkm['num_device'] ?? 0),
            'InnKassa' => (string) ($kkm['inn_kassa'] ?? ''),
            'IsFiscalCheck' => true,
            'TypeCheck' => $typeCheck,
            'NotPrint' => (bool) ($kkm['not_print'] ?? false),
            'NumberCopies' => 0,
            'CashierName' => (string) (filled($kkm['cashier_name'] ?? null) ? $kkm['cashier_name'] : \App\Support\ClubBrand::name()),
            'CheckStrings' => [
                [
                    'Register' => [
                        'Name' => mb_substr($itemName, 0, 128),
                        'Quantity' => 1,
                        'Price' => $amount,
                        'Amount' => $amount,
                        'Tax' => (int) ($kkm['tax'] ?? -1),
                        'SignMethodCalculation' => $signMethod,
                        'SignCalculationObject' => $signObject,
                        'MeasureOfQuantity' => 0,
                    ],
                ],
            ],
            'Cash' => round($cash, 2),
            'ElectronicPayment' => round($electronic, 2),
            'AdvancePayment' => round($advancePayment, 2),
            'Credit' => 0,
            'CashProvision' => 0,
            'IdCommand' => (string) Str::uuid(),
            'Timeout' => (int) ($kkm['timeout'] ?? 15),
            'User' => (string) ($kkm['user'] ?? 'Admin'),
            'Password' => (string) ($kkm['password'] ?? ''),
        ];

        if ($clientAddress) {
            $payload['ClientAddress'] = $clientAddress;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, url?: ?string, error?: string}
     */
    protected function execute(array $payload, Transaction $transaction, string $mode): array
    {
        $url = (string) config('fiscal.kkm.url');
        $timeout = (int) config('fiscal.kkm.timeout', 15);

        try {
            $response = Http::timeout($timeout)->post($url, $payload);
            $result = $response->json();

            if (! is_array($result)) {
                Log::warning('Fiscal KKM non-JSON response', [
                    'transaction_id' => $transaction->id,
                    'mode' => $mode,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['success' => false, 'error' => 'KKM returned non-JSON response'];
            }

            if ((int) ($result['Status'] ?? -1) === 0) {
                return [
                    'success' => true,
                    'url' => $result['URL'] ?? $result['Url'] ?? null,
                ];
            }

            $error = (string) ($result['Error'] ?? $result['Message'] ?? 'Unknown KKM Error');
            Log::warning('Fiscal KKM error', [
                'transaction_id' => $transaction->id,
                'mode' => $mode,
                'error' => $error,
                'result' => $result,
            ]);

            return ['success' => false, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('Fiscal KKM exception', [
                'transaction_id' => $transaction->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function itemName(Transaction $transaction, string $fallback): string
    {
        $desc = trim((string) ($transaction->description ?? ''));

        return $desc !== '' ? $desc : $fallback;
    }

    protected function isCashSource(Transaction $transaction): bool
    {
        $source = strtolower(trim((string) ($transaction->source ?? '')));

        return in_array($source, ['cash', 'admin_cash'], true);
    }

    protected function clientAddress(Transaction $transaction): ?string
    {
        // Электронная доставка ОФД — только если клиент явно попросил
        // галочкой «Отправить чек» при оплате.
        if (! $transaction->send_receipt) {
            return null;
        }

        $user = $transaction->relationLoaded('user')
            ? $transaction->user
            : $transaction->user()->first();

        if (! $user) {
            return null;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $phone = preg_replace('/\D+/', '', (string) ($user->phone ?? ''));
        if ($phone !== null && strlen($phone) >= 10) {
            return '+'.$phone;
        }

        return null;
    }
}
