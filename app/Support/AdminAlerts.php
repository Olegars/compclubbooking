<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Дешёвые счётчики для бейджей сайдбара админки (только COUNT, без выборок).
 */
class AdminAlerts
{
    public static function counts(): array
    {
        try {
            $pendingOrders = (int) DB::table('orders')->where('status', 'pending')->count();
            $sos = (int) DB::table('computer_sos_alerts')->whereNull('resolved_at')->count();
            $input = (int) DB::table('computer_input_alerts')->whereNull('resolved_at')->count();
            $incidents = (int) DB::table('incidents')->whereNull('resolved_at')->count();

            return [
                'pending_orders' => $pendingOrders,
                'sos' => $sos,
                'input' => $input,
                // Столько строк реально увидит оператор в «Реестре инцидентов»
                'incidents' => $incidents + $sos + $input,
            ];
        } catch (\Throwable $e) {
            Log::warning('AdminAlerts::counts failed: '.$e->getMessage());

            return self::empty();
        }
    }

    public static function empty(): array
    {
        return [
            'pending_orders' => 0,
            'sos' => 0,
            'input' => 0,
            'incidents' => 0,
        ];
    }
}
