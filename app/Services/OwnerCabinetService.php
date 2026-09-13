<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\StoreOrder;
use App\Models\User;
use App\Support\AdminAlerts;
use App\Support\AdminLocation;
use App\Support\AdminShift;
use Illuminate\Support\Facades\Schema;

class OwnerCabinetService
{
    public function __construct(private readonly TaxReportService $taxes) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Admin $admin): array
    {
        $location = AdminLocation::resolve($admin);
        $open = AdminShift::openShift();
        $alerts = AdminAlerts::counts();

        return [
            'location' => $location ? [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type,
            ] : null,
            'locations' => collect(AdminLocation::listForOwner($admin))->map(fn ($club) => [
                'id' => $club->id,
                'name' => $club->name,
                'type' => $club->type,
            ])->values()->all(),
            'shift' => $this->shiftCard($open),
            'alerts' => $alerts,
            'pending_hires' => (int) Admin::query()
                ->where('employment_pending', true)
                ->whereNull('fired_at')
                ->count(),
            'staff_count' => (int) Admin::query()
                ->whereNull('fired_at')
                ->where('role', '!=', Admin::ROLE_OWNER)
                ->count(),
            'store_open_orders' => Schema::hasTable('store_orders')
                ? (int) StoreOrder::query()->whereIn('status', ['new', 'assembling', 'ready'])->count()
                : 0,
            'today' => [
                'taxable' => $this->todayTaxable(),
                'new_guests' => Schema::hasTable('users')
                    ? (int) User::query()->whereDate('created_at', today())->count()
                    : 0,
                'bar_pending' => (int) ($alerts['pending_orders'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shiftCard(?Shift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        return [
            'id' => (int) $shift->id,
            'status' => $shift->status,
            'admin_name' => $shift->admin?->name,
            'started_at' => $shift->started_at?->toIso8601String(),
            'interns' => $shift->activeInterns
                ->map(fn ($row) => $row->admin?->name)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function todayTaxable(): float
    {
        return $this->taxes->incomeBetween(now()->startOfDay(), now()->endOfDay());
    }
}
