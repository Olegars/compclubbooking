<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessFiscalReceip implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    // php artisan make:job ProcessFiscalReceipt
    public function handle(FiscalService $fiscal): void {
        $this->transaction->update(['fiscal_status' => 'pending']);

        $result = $fiscal->registerReceipt($this->transaction);

        if ($result['success']) {
            $this->transaction->update([
                'fiscal_status' => 'success',
                'fiscal_receipt_url' => $result['url']
            ]);
        } else {
            $this->transaction->update([
                'fiscal_status' => 'error',
                'fiscal_error' => $result['error']
            ]);
        }
    }
}
