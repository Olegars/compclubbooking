<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Services\ShiftSlotService;
use App\Services\StaffEmploymentService;
use App\Services\StaffPayrollService;
use App\Services\StoreStaffCabinetService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class StaffPayrollController extends Controller
{
    public function __construct(
        private readonly StaffPayrollService $payroll,
        private readonly ShiftSlotService $slots,
        private readonly StaffEmploymentService $employment,
        private readonly StoreStaffCabinetService $storeDesk,
    ) {
    }

    public function index(Request $request)
    {
        $admin = auth('admin')->user();
        if ($admin?->isOwner()) {
            return redirect()->route('admin.cabinet');
        }
        if ($admin?->isStoreRole()) {
            return redirect()->route($admin->needsEmployment() ? 'store.hire' : 'store.cabinet');
        }

        return $this->renderCabinet($request, $admin, 'Admin/Salary', false);
    }

    public function storeCabinet(Request $request)
    {
        $admin = auth('admin')->user();
        if (! $admin?->isStoreRole()) {
            return redirect()->route($admin?->homeRoute() ?: 'admin.salary');
        }
        if ($admin->needsEmployment()) {
            return redirect()->route('store.hire');
        }

        return $this->renderCabinet($request, $admin, 'Admin/StoreCabinet', true);
    }

    public function withdraw(Request $request)
    {
        $admin = auth('admin')->user();
        $this->assertStaffCabinet($admin);
        if ($admin->needsEmployment()) {
            return back()->withErrors(['message' => 'Сначала завершите устройство на работу.']);
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        try {
            $entry = $this->payroll->withdraw(
                auth('admin')->user(),
                isset($data['amount']) ? (float) $data['amount'] : null
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        $formatted = number_format((float) $entry->amount, 2, ',', ' ');

        return back()->with('success', "Выведено {$formatted} ₽");
    }

    public function bookSlot(ShiftSlot $slot)
    {
        $admin = auth('admin')->user();
        $this->assertStaffCabinet($admin);
        if ($admin->needsEmployment()) {
            return back()->withErrors(['message' => 'Сначала завершите устройство на работу.']);
        }

        try {
            $this->slots->book($admin, $slot);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Смена выбрана');
    }

    public function cancelSlot(ShiftSlotBooking $booking)
    {
        $admin = auth('admin')->user();
        $this->assertStaffCabinet($admin);

        try {
            $this->slots->cancel($admin, $booking);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Смена отменена');
    }

    public function acceptEmploymentRule(Request $request)
    {
        $admin = auth('admin')->user();
        $this->assertStaffCabinet($admin);

        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        try {
            $this->employment->acceptRule($admin, (int) $data['rule_id']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back();
    }

    public function acceptFireSafetyRule(Request $request)
    {
        $admin = auth('admin')->user();
        $this->assertStaffCabinet($admin);

        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        try {
            $this->employment->acceptFireRule($admin, (int) $data['rule_id']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        $admin = auth('admin')->user()->fresh();
        if (! $admin->needsEmployment()) {
            return back()->with('success', 'Вы приняты. Личный кабинет открыт.');
        }

        return back();
    }

    public function hire(Request $request)
    {
        $admin = auth('admin')->user();
        $this->assertStaffCabinet($admin);
        $hasScan = filled($this->employment->profile($admin)->passport_scan_path);

        $data = $request->validate($this->employment->hireRules($hasScan), $this->employment->hireMessages());

        $scan = $request->file('passport_scan');

        try {
            $this->employment->submit($admin, $data, $scan);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Анкета отправлена на проверку.');
    }

    private function assertStaffCabinet(?Admin $admin): void
    {
        abort_if(! $admin || $admin->isOwner(), 403, 'У владельца отдельный кабинет.');
    }

    private function renderCabinet(Request $request, Admin $admin, string $page, bool $withStoreDesk)
    {
        try {
            $payload = $this->payroll->snapshot($admin);
        } catch (\Throwable $e) {
            report($e);
            try {
                $this->payroll->syncFor($admin);
            } catch (\Throwable $syncError) {
                report($syncError);
            }
            $payload = [
                'pay_type' => $admin->pay_type,
                'base_rate' => $admin->base_rate !== null ? (float) $admin->base_rate : null,
                'accrued_total' => 0.0,
                'fines_total' => 0.0,
                'payouts_total' => 0.0,
                'balance' => 0.0,
                'available' => 0.0,
                'shifts' => [],
                'fines' => [],
                'payouts' => [],
                'monthly_accruals' => [],
            ];
        }
        try {
            $payload['employment'] = $this->employment->payload($admin);
        } catch (\Throwable $e) {
            report($e);
            $payload['employment'] = [
                'required' => false,
                'status' => 'draft',
                'rejection_reason' => null,
                'appointment_at' => null,
                'rules_title' => '',
                'rules' => [],
                'accepted_ids' => [],
                'rules_complete' => false,
                'fire_rules_title' => '',
                'fire_rules' => [],
                'accepted_fire_ids' => [],
                'fire_rules_complete' => false,
                'profile' => [
                    'full_name' => $admin->name,
                    'passport_series' => null,
                    'passport_number' => null,
                    'issued_by' => null,
                    'issued_at' => null,
                    'department_code' => null,
                    'birth_date' => null,
                    'has_scan' => false,
                ],
            ];
        }
        try {
            $payload['calendar'] = $admin->needsEmployment()
                ? [
                    'month' => now()->format('Y-m'),
                    'cancel_before_hours' => ShiftSlotService::CANCEL_BEFORE_HOURS,
                    'shift_hours' => 12,
                    'starts_hour' => 10,
                    'days' => [],
                    'my_bookings' => [],
                ]
                : $this->slots->calendar($admin, $request->string('month')->toString() ?: null);
        } catch (\Throwable $e) {
            report($e);
            $payload['calendar'] = [
                'month' => now()->format('Y-m'),
                'cancel_before_hours' => ShiftSlotService::CANCEL_BEFORE_HOURS,
                'shift_hours' => 12,
                'starts_hour' => 10,
                'days' => [],
                'my_bookings' => [],
            ];
        }
        if ($withStoreDesk) {
            $payload['store_desk'] = $this->storeDesk->desk($admin);
            abort_if(! $payload['store_desk'], 403);
        }

        return Inertia::render($page, $payload);
    }
}
