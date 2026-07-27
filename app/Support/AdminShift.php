<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Состояние текущей открытой смены для шапки админки.
 * Открытой считается смена в статусе open/transferring — так же, как в ShiftController.
 */
class AdminShift
{
    public static function current(?int $adminId): ?array
    {
        if (! $adminId) {
            return null;
        }

        try {
            $shift = DB::table('shifts')
                ->leftJoin('admins', 'admins.id', '=', 'shifts.admin_id')
                ->where('shifts.status', '!=', 'closed')
                ->orderByDesc('shifts.started_at')
                ->orderByDesc('shifts.id')
                ->select('shifts.id', 'shifts.admin_id', 'shifts.status', 'shifts.started_at', 'admins.name as admin_name')
                ->first();

            if (! $shift) {
                return null;
            }

            return [
                'id' => (int) $shift->id,
                'status' => $shift->status,
                'started_at' => $shift->started_at,
                'admin_name' => $shift->admin_name,
                // Смена может быть открыта другим оператором — в шапке это важно различать
                'is_mine' => (int) $shift->admin_id === $adminId,
            ];
        } catch (\Throwable $e) {
            Log::warning('AdminShift::current failed: '.$e->getMessage());

            return null;
        }
    }
}
