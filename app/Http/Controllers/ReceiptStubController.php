<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\FiscalService;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptStubController extends Controller
{
    public function show(Transaction $transaction, FiscalService $fiscal): Response
    {
        $mode = $transaction->fiscal_mode ?: $fiscal->resolveMode($transaction);

        $modeLabel = match ($mode) {
            FiscalService::MODE_ADVANCE => 'Аванс',
            FiscalService::MODE_SETTLEMENT => 'Полный расчёт',
            FiscalService::MODE_REFUND => 'Возврат',
            default => 'Операция',
        };

        $amount = (float) $transaction->amount;
        $signed = ($amount > 0 ? '+' : '').(int) round($amount).' ₽';

        return Inertia::render('Legal/ReceiptStub', [
            'receipt' => [
                'id' => $transaction->id,
                'description' => $transaction->description ?: 'Операция',
                'amount' => $amount,
                'amount_text' => $signed,
                'mode' => $mode,
                'mode_label' => $modeLabel,
                'status' => $transaction->fiscal_status ?: 'skipped',
                'date' => optional($transaction->created_at)?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                'is_stub' => true,
            ],
        ]);
    }
}
