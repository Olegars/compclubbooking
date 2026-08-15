<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\FiscalService;
use App\Services\YooKassaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(
        protected YooKassaService $yookassa,
        protected FiscalService $fiscal,
    ) {
    }

    /**
     * Создать платёж ЮKassa и вернуть токен встроенного Checkout Widget.
     */
    public function topUp(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100|max:100000',
            'method' => 'nullable|string|in:card,sbp',
            'return_to' => 'nullable|string|max:2048',
            'send_receipt' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $amount = round((float) $request->amount, 2);
        $method = $request->input('method', 'card');
        $returnTo = $request->input('return_to');
        $sendReceipt = $request->boolean('send_receipt');

        try {
            $payment = $this->yookassa->createTopUp(
                $user,
                $amount,
                $method,
                $returnTo,
                'embedded',
                $sendReceipt,
            );
            $confirmationToken = $payment->confirmationToken();

            if (!$confirmationToken) {
                return response()->json(['message' => 'ЮKassa не вернула токен платежного виджета'], 502);
            }

            return response()->json([
                'message' => 'Платёжный виджет готов',
                'payment_id' => $payment->uuid,
                'confirmation_token' => $confirmationToken,
                'sync_url' => route('billing.yookassa.sync', ['payment' => $payment->uuid]),
                'amount' => $payment->amount,
                'status' => $payment->status,
            ]);
        } catch (\Throwable $e) {
            Log::error('YooKassa top-up failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Не удалось создать платёж: ' . $e->getMessage(),
            ], 502);
        }
    }

    /**
     * HTTP-уведомления ЮKassa (webhook).
     */
    public function webhook(Request $request)
    {
        try {
            $this->yookassa->handleNotification($request->all());
        } catch (\Throwable $e) {
            Log::error('YooKassa webhook error: ' . $e->getMessage(), [
                'body' => $request->all(),
            ]);
        }

        // Always 200 so YooKassa does not retry forever on our bugs.
        return response()->json(['ok' => true]);
    }

    /**
     * Return URL после оплаты на стороне ЮKassa.
     */
    public function returnFromYooKassa(Request $request, string $payment)
    {
        $local = Payment::query()
            ->where('uuid', $payment)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $local = $this->yookassa->syncAndFulfill($local);
        } catch (\Throwable $e) {
            Log::warning('YooKassa return sync failed: ' . $e->getMessage(), [
                'payment_uuid' => $local->uuid,
            ]);
        }

        $returnTo = $local->payload['return_to'] ?? '/account/dashboard';

        if ($local->isSucceeded()) {
            return redirect($returnTo)
                ->with('success', 'Баланс пополнен на ' . number_format((float) $local->amount, 0, '.', ' ') . ' ₽');
        }

        if ($local->status === Payment::STATUS_CANCELED) {
            return redirect($returnTo)->with('error', 'Оплата отменена');
        }

        return redirect($returnTo)
            ->with('info', 'Платёж ещё обрабатывается. Баланс обновится в течение минуты.');
    }

    /**
     * Embedded widget page for Shell (no auth — uuid is the capability token).
     */
    public function widget(string $payment)
    {
        $local = Payment::query()->where('uuid', $payment)->firstOrFail();
        $token = $local->confirmationToken();

        if (!$token && !$local->isFinal()) {
            abort(409, 'Платёж ещё не готов к отображению виджета');
        }

        return response()
            ->view('billing.yookassa-widget', [
                'payment' => $local,
                'confirmationToken' => $token,
                'syncUrl' => url('/api/billing/yookassa/sync/'.$local->uuid),
                // Обязательный параметр виджета ЮKassa (см. quick-start).
                // Берём host из текущего запроса (как syncUrl), а не APP_URL.
                'returnUrl' => url('/billing/yookassa/return/'.$local->uuid),
                'clubName' => \App\Support\ClubBrand::name(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Shell/widget asks backend to sync payment status after checkout events.
     */
    public function sync(string $payment)
    {
        $local = Payment::query()->where('uuid', $payment)->firstOrFail();

        try {
            $local = $this->yookassa->syncAndFulfill($local);
        } catch (\Throwable $e) {
            Log::warning('YooKassa sync failed: '.$e->getMessage(), [
                'payment_uuid' => $local->uuid,
            ]);
        }

        $local = $local->fresh();
        $tx = null;
        if ($local->transaction_id) {
            $tx = \App\Models\Transaction::query()->find($local->transaction_id);
        }
        if (! $tx) {
            $tx = \App\Models\Transaction::query()
                ->where(function ($q) use ($local) {
                    $key = 'yookassa:'.($local->provider_payment_id ?: $local->uuid);
                    $q->where('idempotency_key', $key)
                        ->orWhere('payload->payment_uuid', $local->uuid);
                })
                ->latest('id')
                ->first();
        }

        $receiptUrl = $tx ? $this->fiscal->displayReceiptUrl($tx) : null;

        return response()->json([
            'status' => 'success',
            'payment_id' => $local->uuid,
            'payment_status' => $local->status,
            'amount' => $local->amount,
            'paid' => $local->isSucceeded(),
            'fiscal_receipt_url' => $receiptUrl,
            'fiscal_status' => $tx?->fiscal_status ?: ($this->fiscal->isStubReceiptUrl($receiptUrl) ? 'skipped' : null),
            'is_stub_receipt' => $this->fiscal->isStubReceiptUrl($receiptUrl),
            'description' => $tx?->description,
            'transaction_id' => $tx?->id ?? $local->transaction_id,
        ]);
    }

    /**
     * Поллинг ссылки на чек после оплаты (Success Screen / лог).
     */
    public function receiptByPayment(string $payment)
    {
        $local = Payment::query()->where('uuid', $payment)->firstOrFail();

        $tx = null;
        if ($local->transaction_id) {
            $tx = \App\Models\Transaction::query()->find($local->transaction_id);
        }
        if (! $tx) {
            $tx = \App\Models\Transaction::query()
                ->where(function ($q) use ($local) {
                    $key = 'yookassa:'.($local->provider_payment_id ?: $local->uuid);
                    $q->where('idempotency_key', $key)
                        ->orWhere('payload->payment_uuid', $local->uuid);
                })
                ->latest('id')
                ->first();
        }

        $receiptUrl = $tx ? $this->fiscal->displayReceiptUrl($tx) : null;

        return response()->json([
            'payment_id' => $local->uuid,
            'paid' => $local->isSucceeded(),
            'amount' => $local->amount,
            'transaction_id' => $tx?->id,
            'fiscal_status' => $tx?->fiscal_status ?: ($this->fiscal->isStubReceiptUrl($receiptUrl) ? 'skipped' : null),
            'fiscal_receipt_url' => $receiptUrl,
            'is_stub_receipt' => $this->fiscal->isStubReceiptUrl($receiptUrl),
            'description' => $tx?->description,
            'has_receipt' => filled($receiptUrl),
        ]);
    }
}
