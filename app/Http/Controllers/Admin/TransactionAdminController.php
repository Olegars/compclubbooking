<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\FiscalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransactionAdminController extends Controller
{
    public function index(Request $request, FiscalService $fiscal)
    {
        $phone = trim((string) $request->query('phone', ''));
        $type = trim((string) $request->query('type', ''));
        $status = trim((string) $request->query('fiscal_status', ''));

        $query = Transaction::query()
            ->with(['user:id,name,phone,email'])
            ->whereNotNull('fiscal_mode')
            ->latest('id');

        if ($phone !== '') {
            $digits = preg_replace('/\D+/', '', $phone) ?: $phone;
            $query->whereHas('user', function ($q) use ($phone, $digits) {
                $q->where('phone', 'like', '%'.$digits.'%')
                    ->orWhere('phone', 'like', '%'.$phone.'%')
                    ->orWhere('name', 'like', '%'.$phone.'%');
            });
        }

        if ($type !== '' && in_array($type, ['deposit', 'booking', 'booking_upgrade', 'purchase', 'refund'], true)) {
            $query->where('type', $type);
        }

        if ($status !== '' && in_array($status, ['success', 'pending', 'error', 'skipped'], true)) {
            $query->where('fiscal_status', $status);
        }

        $transactions = $query
            ->paginate(40)
            ->withQueryString()
            ->through(function (Transaction $t) use ($fiscal) {
                $url = $fiscal->displayReceiptUrl($t);
                $isStub = $fiscal->isStubReceiptUrl($url);

                return [
                    'id' => $t->id,
                    'type' => $t->type,
                    'source' => $t->source,
                    'amount' => (float) $t->amount,
                    'description' => $t->description,
                    'send_receipt' => (bool) $t->send_receipt,
                    'fiscal_mode' => $t->fiscal_mode,
                    'fiscal_status' => $t->fiscal_status,
                    'fiscal_receipt_url' => $url,
                    'fiscal_error' => $t->fiscal_error,
                    'fiscal_at' => optional($t->fiscal_at)?->format('d.m.Y H:i'),
                    'created_at' => optional($t->created_at)?->format('d.m.Y H:i'),
                    'user' => $t->user ? [
                        'id' => $t->user->id,
                        'name' => $t->user->name,
                        'phone' => $t->user->phone,
                        'email' => $t->user->email,
                    ] : null,
                    'is_stub_receipt' => $isStub,
                    'can_print' => filled($url) && ! $isStub,
                ];
            });

        return Inertia::render('Admin/Transactions', [
            'transactions' => $transactions,
            'filters' => [
                'phone' => $phone,
                'type' => $type,
                'fiscal_status' => $status,
            ],
        ]);
    }

    /**
     * Данные для печати «КОПИЯ ЧЕКА» без повторной фискализации.
     */
    public function printCopy(Transaction $transaction, FiscalService $fiscal)
    {
        $transaction->loadMissing('user:id,name,phone,email');

        $url = $fiscal->displayReceiptUrl($transaction);
        if (! filled($url)) {
            return response()->json([
                'message' => 'У транзакции нет ссылки на фискальный чек',
            ], 422);
        }

        if ($fiscal->isStubReceiptUrl($url) || $transaction->fiscal_status === 'skipped') {
            return response()->json([
                'message' => 'Это заглушка чека (касса выключена). Печать копии ОФД недоступна.',
            ], 422);
        }

        return response()->json([
            'id' => $transaction->id,
            'title' => 'КОПИЯ ЧЕКА',
            'description' => $transaction->description,
            'amount' => (float) $transaction->amount,
            'type' => $transaction->type,
            'fiscal_mode' => $transaction->fiscal_mode,
            'fiscal_receipt_url' => $url,
            'fiscal_at' => optional($transaction->fiscal_at)?->toIso8601String(),
            'created_at' => optional($transaction->created_at)?->toIso8601String(),
            'user' => $transaction->user ? [
                'name' => $transaction->user->name,
                'phone' => $transaction->user->phone,
                'email' => $transaction->user->email,
            ] : null,
            'qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='
                .urlencode((string) $url),
        ]);
    }
}
