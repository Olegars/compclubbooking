<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\ShiftIntern;
use Illuminate\Support\Facades\Log;

/**
 * Состояние текущей открытой смены и статус админа: активный / неактивный / стажёр.
 */
class AdminShift
{
    public static function openShift(): ?Shift
    {
        return Shift::query()
            ->with([
                'admin:id,name',
                'incomingAdmin:id,name',
                'activeInterns.admin:id,name',
                'inventoryItems',
            ])
            ->where('status', '!=', 'closed')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    public static function current(?int $adminId): ?array
    {
        if (! $adminId) {
            return null;
        }

        try {
            $viewer = Admin::query()->find($adminId);
            $shift = self::openShift();
            $interns = $shift
                ? $shift->activeInterns->map(fn (ShiftIntern $row) => [
                    'id' => (int) $row->admin_id,
                    'name' => $row->admin?->name,
                ])->values()->all()
                : [];
            $internIds = array_map(fn ($row) => (int) $row['id'], $interns);
            $isLead = $shift && (int) $shift->admin_id === (int) $adminId;
            $isIncoming = $shift && $shift->incoming_admin_id && (int) $shift->incoming_admin_id === (int) $adminId;
            $isInternOnShift = in_array((int) $adminId, $internIds, true);
            $transferring = $shift && $shift->status === 'transferring';

            $duty = 'inactive';
            $dutyLabel = 'Неактивный';

            if ($viewer?->isIntern()) {
                $duty = 'intern';
                $dutyLabel = $isInternOnShift
                    ? 'Стажёр · на смене с '.($shift?->admin?->name ?: 'активным')
                    : 'Стажёр';
            } elseif ($transferring && $isIncoming) {
                $duty = 'incoming';
                $dutyLabel = 'Приём смены';
            } elseif ($transferring && $isLead) {
                $duty = 'handing_over';
                $dutyLabel = 'Передача смены';
            } elseif ($isLead) {
                $duty = 'active';
                $dutyLabel = 'Активный';
            } elseif ($viewer?->role === Admin::ROLE_ADMIN) {
                $duty = 'inactive';
                $dutyLabel = 'Неактивный';
            } elseif ($viewer) {
                $duty = $viewer->role;
                $dutyLabel = $viewer->roleLabel();
            }

            $overlay = null;
            if ($viewer?->shift_handed_over_at) {
                $overlay = 'handed_over';
            } elseif ($transferring && $isLead && ! $isIncoming) {
                $overlay = 'transferring';
            }

            $canTake = $viewer?->role === Admin::ROLE_ADMIN && ! $isLead;
            if ($canTake && $transferring && ! $isIncoming) {
                $canTake = false;
            }

            return [
                'id' => $shift?->id,
                'status' => $shift?->status,
                'started_at' => $shift?->started_at?->toIso8601String(),
                'admin_id' => $shift && $shift->admin_id ? (int) $shift->admin_id : null,
                'admin_name' => $shift?->admin?->name,
                'incoming_admin_id' => $shift && $shift->incoming_admin_id ? (int) $shift->incoming_admin_id : null,
                'incoming_admin_name' => $shift?->incomingAdmin?->name,
                'is_mine' => (bool) $isLead,
                'is_incoming' => (bool) $isIncoming,
                'is_intern_on_shift' => $isInternOnShift,
                'duty' => $duty,
                'duty_label' => $dutyLabel,
                'interns' => $interns,
                'overlay' => $overlay,
                'can_take_shift' => $canTake,
                'can_join_as_intern' => (bool) ($viewer?->isIntern() && $shift && ! $isInternOnShift && ! $transferring),
                'can_leave_as_intern' => $isInternOnShift,
            ];
        } catch (\Throwable $e) {
            Log::warning('AdminShift::current failed: '.$e->getMessage());

            return null;
        }
    }
}
