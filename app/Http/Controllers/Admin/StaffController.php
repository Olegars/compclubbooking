<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffController extends Controller
{
    public function index()
    {
        // Достаем весь персонал из базы
        $staff = Admin::query()->with('club:id,name')->orderBy('name')->get()->map(function ($employee) {
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
            ];
        });

        // Отдаем во Vue-компонент
        return Inertia::render('Admin/Staff', [
            'staff' => $staff
        ]);
    }
}
