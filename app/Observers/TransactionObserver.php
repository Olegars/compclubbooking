<?php

namespace App\Observers;

use App\Jobs\ProcessFiscalReceipt;
use App\Models\Transaction;
use App\Services\FiscalService;

class TransactionObserver
{
    public function __construct(
        protected FiscalService $fiscal
    ) {}

    public function created(Transaction $transaction): void
    {
        $mode = $this->fiscal->resolveMode($transaction);
        if ($mode === null) {
            return;
        }

        if (! $this->fiscal->isEnabled()) {
            $transaction->forceFill([
                'fiscal_mode' => $mode,
                'fiscal_status' => 'skipped',
            ])->saveQuietly();

            return;
        }

        $transaction->forceFill(['fiscal_mode' => $mode])->saveQuietly();

        ProcessFiscalReceipt::dispatch($transaction->id)->afterCommit();
    }
}
