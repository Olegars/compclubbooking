<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

            $clubId = AdminLocation::id();
            $tournamentInbox = 0;
            if ($clubId && Schema::hasTable('tournament_challenges')) {
                $tournamentInbox = (int) DB::table('tournament_challenges')
                    ->where('waiting_club_id', $clubId)
                    ->where('status', 'pending')
                    ->count();
            }

            $edoOpen = 0;
            if (Schema::hasTable('staff_disciplinary_incidents')) {
                $edoOpen = (int) DB::table('staff_disciplinary_incidents')
                    ->whereIn('status', ['demand_sent', 'explanation_submitted', 'expired_no_response', 'memo_for_signature'])
                    ->count();
            }

            return [
                'pending_orders' => $pendingOrders,
                'sos' => $sos,
                'input' => $input,
                'incidents' => $incidents + $sos + $input,
                'avito_unread' => Schema::hasTable('store_avito_chats')
                    ? (int) DB::table('store_avito_chats')->where('unread', true)->count()
                    : 0,
                'tournament_inbox' => $tournamentInbox,
                'edo_open' => $edoOpen,
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
            'avito_unread' => 0,
            'tournament_inbox' => 0,
            'edo_open' => 0,
        ];
    }
}
