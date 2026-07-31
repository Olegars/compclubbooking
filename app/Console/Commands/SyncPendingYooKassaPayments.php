<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\YooKassaService;
use Illuminate\Console\Command;

class SyncPendingYooKassaPayments extends Command
{
    protected $signature = 'reactor:sync-payments';

    protected $description = 'Досинхронизация платежей ЮKassa, если webhook не дошёл';

    public function handle(YooKassaService $yookassa): int
    {
        if (!$yookassa->isConfigured()) {
            $this->warn('ЮKassa не настроена, пропуск.');
            return self::SUCCESS;
        }

        $payments = Payment::query()
            ->whereNotIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_CANCELED])
            ->whereNotNull('provider_payment_id')
            ->where('created_at', '>=', now()->subDay())
            ->get();

        foreach ($payments as $payment) {
            try {
                $synced = $yookassa->syncAndFulfill($payment);
                $this->line("{$synced->uuid}: {$synced->status}");
            } catch (\Throwable $e) {
                $this->error("{$payment->uuid}: {$e->getMessage()}");
            }
        }

        $this->info("Проверено платежей: {$payments->count()}");

        return self::SUCCESS;
    }
}
