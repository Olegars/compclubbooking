<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\YooKassaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(protected YooKassaService $yookassa)
    {
    }

    /**
     * Создать платёж ЮKassa и вернуть confirmation_url для редиректа.
     */
    public function topUp(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100|max:100000',
            'method' => 'nullable|string|in:card,sbp',
            'return_to' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();
        $amount = round((float) $request->amount, 2);
        $method = $request->input('method', 'card');
        $returnTo = $request->input('return_to');

        try {
            $payment = $this->yookassa->createTopUp($user, $amount, $method, $returnTo);

            if (!$payment->confirmation_url) {
                return response()->json(['message' => 'ЮKassa не вернула ссылку на оплату'], 502);
            }

            return response()->json([
                'message' => 'Перенаправление на оплату',
                'payment_id' => $payment->uuid,
                'confirmation_url' => $payment->confirmation_url,
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
}
