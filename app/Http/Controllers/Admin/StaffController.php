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
        $open = \App\Support\AdminShift::openShift();
        $internIds = $open
            ? $open->activeInterns->pluck('admin_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $staff = Admin::query()->with('club:id,name')->orderBy('name')->get()->map(function ($employee) use ($open, $internIds) {
            $this->payroll->syncFor($employee);
            $balance = $this->payroll->balance($employee);
            $duty = $this->dutyFor($employee, $open, $internIds);

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->role,
                'role_label' => $employee->roleLabel(),
                'duty' => $duty['status'],
                'duty_label' => $duty['label'],
                'is_floor_admin' => $employee->isFloorAdminFamily(),
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

    public function updateRole(Request $request, Admin $admin)
    {
        if (! $admin->isFloorAdminFamily()) {
            return back()->withErrors(['role' => 'Эту роль нельзя менять здесь.']);
        }

        $data = $request->validate([
            'role' => ['required', 'in:admin,intern'],
        ]);

        $admin->update(['role' => $data['role']]);

        return back()->with('success', $admin->name.': '.$admin->roleLabel());
    }

    /**
     * @param  list<int>  $internIds
     * @return array{status: string, label: string}
     */
    private function dutyFor(Admin $employee, $open, array $internIds): array
    {
        if ($employee->isIntern()) {
            $on = in_array((int) $employee->id, $internIds, true);

            return [
                'status' => 'intern',
                'label' => $on ? 'Стажёр · на смене' : 'Стажёр',
            ];
        }

        if ($open && (int) $open->admin_id === (int) $employee->id) {
            return ['status' => 'active', 'label' => 'Активный'];
        }

        if ($employee->role === Admin::ROLE_ADMIN) {
            return ['status' => 'inactive', 'label' => 'Неактивный'];
        }

        return ['status' => $employee->role, 'label' => $employee->roleLabel()];
    }
}
