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

        // Бронь: деньги списаны, чек полного расчёта — после входа на ПК.
        if ($this->fiscal->shouldDeferSettlement($transaction)) {
            $this->fiscal->markDeferred($transaction, $mode);

            return;
        }

        if (! $this->fiscal->isEnabled()) {
            $this->fiscal->markSkippedWithStub($transaction, $mode);

            return;
        }

        $transaction->forceFill(['fiscal_mode' => $mode])->saveQuietly();

        ProcessFiscalReceipt::dispatch($transaction->id)->afterCommit();
    }
}
