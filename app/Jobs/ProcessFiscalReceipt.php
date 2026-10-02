<?php

namespace App\Jobs;

use App\Models\FiscalJob;
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

        if ($transaction->fiscal_status === 'void') {
            return;
        }

        // Уже есть заглушка/URL — повторно не бьём.
        if ($transaction->fiscal_status === 'skipped' && filled($transaction->fiscal_receipt_url)) {
            return;
        }

        $mode = $fiscal->resolveMode($transaction);
        if ($mode === null) {
            return;
        }

        // Отложенные бронь-чеки: job запускается уже после login / no-show.
        if ($transaction->fiscal_status === 'deferred') {
            $mode = $transaction->fiscal_mode ?: $mode;
        }

        if (! $fiscal->isEnabled()) {
            $fiscal->markSkippedWithStub($transaction, $mode);

            return;
        }

        $result = $fiscal->registerForTransaction($transaction->fresh(['user']));

        if (! empty($result['skipped'])) {
            $fiscal->markSkippedWithStub($transaction, $mode);

            return;
        }

        if (! empty($result['queued'])) {
            $job = FiscalJob::query()->find((int) ($result['job_id'] ?? 0));
            $jobStatus = (string) ($job->status ?? FiscalJob::STATUS_PENDING);
            if ($jobStatus === FiscalJob::STATUS_SUCCESS) {
                $stored = is_array($job?->result) ? $job->result : [];
                $url = $stored['receipt_url'] ?? null;
                if ($transaction->fiscal_status !== 'success') {
                    $transaction->update([
                        'fiscal_mode' => $mode,
                        'fiscal_status' => 'success',
                        'fiscal_receipt_url' => $url,
                        'receipt_id' => ($stored['fd'] ?? null) ?: $url ?: $transaction->receipt_id,
                        'fiscal_error' => null,
                        'fiscal_at' => $transaction->fiscal_at ?? now(),
                    ]);
                }

                return;
            }

            $fiscalStatus = in_array($jobStatus, [FiscalJob::STATUS_ERROR, FiscalJob::STATUS_UNCERTAIN], true)
                ? $jobStatus
                : 'pending';

            $transaction->update([
                'fiscal_mode' => $mode,
                'fiscal_status' => $fiscalStatus,
                'fiscal_error' => $fiscalStatus === 'pending' ? null : $job?->last_error,
            ]);

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
