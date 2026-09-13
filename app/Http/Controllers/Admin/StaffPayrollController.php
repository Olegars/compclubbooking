<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $payload = $this->payroll->snapshot($admin);
        $payload['employment'] = $this->employment->payload($admin);
        if ($admin->isStoreRole() && $admin->needsEmployment()) {
            return redirect()->route('store.hire');
        }
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
        $payload['store_desk'] = $admin->needsEmployment()
            ? null
            : $this->storeDesk->desk($admin);

        return Inertia::render('Admin/Salary', $payload);
    }

    public function withdraw(Request $request)
    {
        $admin = auth('admin')->user();
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
        try {
            $this->slots->cancel(auth('admin')->user(), $booking);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Смена отменена');
    }

    public function acceptEmploymentRule(Request $request)
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        try {
            $this->employment->acceptRule(auth('admin')->user(), (int) $data['rule_id']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back();
    }

    public function acceptFireSafetyRule(Request $request)
    {
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        try {
            $this->employment->acceptFireRule(auth('admin')->user(), (int) $data['rule_id']);
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
}
