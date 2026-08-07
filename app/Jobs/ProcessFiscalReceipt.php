<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\FiscalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessFiscalReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public int $transactionId
    ) {}

    public function handle(FiscalService $fiscal): void
    {
        $transaction = Transaction::query()->with('user')->find($this->transactionId);
        if (! $transaction) {
            return;
        }

        if ($transaction->fiscal_status === 'success') {
            return;
        }

        $mode = $fiscal->resolveMode($transaction);
        if ($mode === null) {
            return;
        }

        if (! $fiscal->isEnabled()) {
            $fiscal->markSkippedWithStub($transaction, $mode);

            return;
        }

        $transaction->update([
            'fiscal_mode' => $mode,
            'fiscal_status' => 'pending',
            'fiscal_error' => null,
        ]);

        $result = $fiscal->registerForTransaction($transaction->fresh(['user']));

        if (! empty($result['skipped'])) {
            $fiscal->markSkippedWithStub($transaction, $mode);

            return;
        }

        if (! empty($result['success'])) {
            $transaction->update([
                'fiscal_mode' => $mode,
                'fiscal_status' => 'success',
                'fiscal_receipt_url' => $result['url'] ?? null,
                'receipt_id' => $result['url'] ?? $transaction->receipt_id,
                'fiscal_error' => null,
                'fiscal_at' => now(),
            ]);

            return;
        }

        $error = (string) ($result['error'] ?? 'Fiscal failed');
        $transaction->update([
            'fiscal_mode' => $mode,
            'fiscal_status' => 'error',
            'fiscal_error' => $error,
        ]);

        Log::warning('ProcessFiscalReceipt failed', [
            'transaction_id' => $transaction->id,
            'mode' => $mode,
            'error' => $error,
        ]);

        // Retry via queue if attempts remain
        throw new \RuntimeException('Fiscal receipt failed: '.$error);
    }
}
