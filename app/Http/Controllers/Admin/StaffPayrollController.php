<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Services\ShiftSlotService;
use App\Services\StaffPayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class StaffPayrollController extends Controller
{
    public function __construct(
        private readonly StaffPayrollService $payroll,
        private readonly ShiftSlotService $slots,
    ) {
    }

    public function index(Request $request)
    {
        $admin = auth('admin')->user();

        return Inertia::render('Admin/Salary', array_merge(
            $this->payroll->snapshot($admin),
            ['calendar' => $this->slots->calendar($admin, $request->string('month')->toString() ?: null)]
        ));
    }

    public function withdraw(Request $request)
    {
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
        try {
            $this->slots->book(auth('admin')->user(), $slot);
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

    public function setShiftModel(Request $request)
    {
        $data = $request->validate([
            'hours' => ['required', 'integer', 'in:12,24'],
        ]);

        try {
            $this->slots->setHours(auth('admin')->user(), (int) $data['hours']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        $label = (int) $data['hours'] === 24 ? 'смены по 24 часа' : 'смены по 12 часов';

        return back()->with('success', 'Модель: '.$label);
    }
}
