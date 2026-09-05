<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\StaffPayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffPayrollService $payroll,
    ) {
    }

    public function index()
    {
        $staff = Admin::query()->with('club:id,name')->orderBy('name')->get()->map(function ($employee) {
            $this->payroll->syncFor($employee);
            $balance = $this->payroll->balance($employee);

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->role,
                'club_id' => $employee->club_id,
                'club_name' => $employee->club?->name,
                'is_official_employee' => $employee->is_official_employee,
                'base_rate' => $employee->base_rate,
                'pay_type' => $employee->pay_type,
                'available' => max(0, $balance),
                'balance' => $balance,
            ];
        });

        return Inertia::render('Admin/Staff', [
            'staff' => $staff,
        ]);
    }

    public function storeFine(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->payroll->addFine(
                $admin,
                (float) $data['amount'],
                $data['reason'],
                auth('admin')->user()
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Штраф начислен: '.$admin->name);
    }
}
