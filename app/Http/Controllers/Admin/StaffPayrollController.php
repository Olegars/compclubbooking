<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StaffPayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class StaffPayrollController extends Controller
{
    public function __construct(
        private readonly StaffPayrollService $payroll,
    ) {
    }

    public function index()
    {
        $admin = auth('admin')->user();

        return Inertia::render('Admin/Salary', $this->payroll->snapshot($admin));
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
}
