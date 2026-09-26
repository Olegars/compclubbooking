<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\StaffRoleRate;
use App\Support\StaffShiftPay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class StaffPaySettingsService
{
    /**
     * @return array<string, float>
     */
    public function defaults(): array
    {
        $roles = config('staff_pay.roles', []);
        $out = [];
        foreach ($roles as $role => $rate) {
            $out[(string) $role] = (float) $rate;
        }

        return $out;
    }

    public function rateFor(string $role): ?float
    {
        if (! array_key_exists($role, $this->defaults())) {
            return null;
        }

        if (Schema::hasTable('staff_role_rates')) {
            $saved = StaffRoleRate::query()->where('role', $role)->value('shift_rate');
            if ($saved !== null) {
                return round((float) $saved, 2);
            }
        }

        return round($this->defaults()[$role], 2);
    }

    /**
     * @return array{
     *     hourly: float,
     *     day_hours: int,
     *     night_hours: int,
     *     night_coefficient: float,
     *     day_pay: float,
     *     night_pay: float,
     *     official: float,
     *     roles: list<array{role: string, label: string, group: string, shift_rate: float, staff_count: int}>
     * }
     */
    public function present(): array
    {
        $roles = array_keys($this->defaults());
        $counts = [];
        if (Schema::hasTable('admins')) {
            $counts = Admin::query()
                ->whereNull('fired_at')
                ->whereIn('role', $roles)
                ->selectRaw('role, count(*) as aggregate')
                ->groupBy('role')
                ->pluck('aggregate', 'role')
                ->all();
        }

        $rows = [];
        foreach ($roles as $role) {
            $rows[] = [
                'role' => $role,
                'label' => Admin::labelForRole($role),
                'group' => in_array($role, Admin::STORE_ONLY_ROLES, true) ? 'store' : 'club',
                'shift_rate' => (float) $this->rateFor($role),
                'staff_count' => (int) ($counts[$role] ?? 0),
            ];
        }

        return [
            'hourly' => StaffShiftPay::hourly(),
            'day_hours' => StaffShiftPay::dayHours(),
            'night_hours' => StaffShiftPay::nightHours(),
            'night_coefficient' => StaffShiftPay::nightCoefficient(),
            'day_pay' => StaffShiftPay::dayPay(),
            'night_pay' => StaffShiftPay::nightPay(),
            'official' => StaffShiftPay::official(),
            'roles' => $rows,
        ];
    }

    /**
     * @return array{shift_rate: float, official: float, bonus: float, updated: int}
     */
    public function save(string $role, float $shiftRate): array
    {
        if (! array_key_exists($role, $this->defaults())) {
            throw new RuntimeException('Неизвестная должность.');
        }

        $split = StaffShiftPay::split($shiftRate);
        if ($split === null) {
            throw new RuntimeException('Ниже минимальной ставки по МРОТ с учетом ночных');
        }

        $updated = DB::transaction(function () use ($role, $split) {
            StaffRoleRate::query()->updateOrCreate(
                ['role' => $role],
                ['shift_rate' => $split['shift_rate']],
            );

            return Admin::query()
                ->where('role', $role)
                ->whereNull('fired_at')
                ->update([
                    'base_rate' => $split['shift_rate'],
                    'pay_type' => 'shift',
                ]);
        });

        return [
            'shift_rate' => $split['shift_rate'],
            'official' => $split['official'],
            'bonus' => $split['bonus'],
            'updated' => $updated,
        ];
    }
}
