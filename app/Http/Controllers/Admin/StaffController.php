<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Club;
use App\Models\ShiftIntern;
use App\Services\StaffEmploymentService;
use App\Services\StaffPayrollService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffPayrollService $payroll,
        private readonly StaffEmploymentService $employment,
    ) {
    }

    public function index()
    {
        $open = \App\Support\AdminShift::openShift();
        $internIds = $open
            ? $open->activeInterns->pluck('admin_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $actor = auth('admin')->user();
        $ownerCount = Admin::query()->where('role', Admin::ROLE_OWNER)->count();
        $hireRoles = $actor->hireableRoles();

        $staff = Admin::query()->with(['club:id,name', 'employmentProfile'])->orderBy('name')->get()->map(function ($employee) use ($open, $internIds, $actor, $ownerCount) {
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
                'employment' => $this->employment->staffCard($employee),
                'is_fired' => $employee->isFired(),
                'fired_at' => $employee->fired_at?->toIso8601String(),
                'can_fire' => $this->canFire($actor, $employee, $open, $ownerCount),
                'can_restore' => $this->canRestore($actor, $employee),
                'can_delete' => $this->canDelete($actor, $employee, $open, $ownerCount),
            ];
        });

        return Inertia::render('Admin/Staff', [
            'staff' => $staff,
            'can_hire' => true,
            'hire_roles' => collect($hireRoles)->map(fn ($role) => [
                'value' => $role,
                'label' => Admin::labelForRole($role),
            ])->values()->all(),
            'clubs' => Club::query()->orderBy('name')->get(['id', 'name']),
            'default_club_id' => $actor->role === Admin::ROLE_OWNER
                ? AdminLocation::id($actor)
                : $actor->club_id,
        ]);
    }

    public function storeFine(Request $request, Admin $admin)
    {
        if ($admin->isFired()) {
            return back()->withErrors(['reason' => 'Уволенному штраф не начисляется.']);
        }
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

    public function store(Request $request)
    {
        $actor = auth('admin')->user();
        $hireRoles = $actor->hireableRoles();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
            'password' => ['required', 'confirmed', Password::min(6)],
            'role' => ['required', 'in:'.implode(',', $hireRoles)],
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'base_rate' => ['required', 'numeric', 'min:0'],
            'pay_type' => ['required', 'in:shift,monthly'],
            'is_official_employee' => ['nullable', 'boolean'],
        ], [
            'email.unique' => 'Этот email уже занят',
            'password.confirmed' => 'Пароли не совпадают',
            'role.in' => 'Эту должность нельзя назначить',
        ]);

        if ($actor->role !== Admin::ROLE_OWNER) {
            $clubId = $actor->club_id ?: AdminLocation::id($actor);
            if (! $clubId) {
                return back()->withErrors(['club_id' => 'Не задана локация управляющего.']);
            }
            $data['club_id'] = $clubId;
        }

        Admin::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'club_id' => $data['club_id'],
            'base_rate' => $data['base_rate'],
            'pay_type' => $data['pay_type'],
            'is_official_employee' => (bool) ($data['is_official_employee'] ?? false),
            'employment_pending' => false,
            'hired_at' => now(),
        ]);

        return back()->with('success', 'Сотрудник нанят: '.$data['name']);
    }

    public function fire(Admin $admin)
    {
        $actor = auth('admin')->user();
        $open = \App\Support\AdminShift::openShift();
        $ownerCount = Admin::query()->where('role', Admin::ROLE_OWNER)->count();

        if (! $this->canFire($actor, $admin, $open, $ownerCount)) {
            return back()->withErrors(['staff' => $this->fireBlockReason($actor, $admin, $open, $ownerCount)]);
        }

        ShiftIntern::query()
            ->where('admin_id', $admin->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        $admin->update([
            'fired_at' => now(),
            'fired_by' => $actor->id,
        ]);

        return back()->with('success', 'Сотрудник уволен: '.$admin->name);
    }

    public function restore(Admin $admin)
    {
        $actor = auth('admin')->user();
        if (! $this->canRestore($actor, $admin)) {
            return back()->withErrors(['staff' => 'Нельзя вернуть этого сотрудника.']);
        }

        $admin->update([
            'fired_at' => null,
            'fired_by' => null,
        ]);

        return back()->with('success', 'Сотрудник снова в штате: '.$admin->name);
    }

    public function updateRole(Request $request, Admin $admin)
    {
        if ($admin->isFired()) {
            return back()->withErrors(['role' => 'Уволенного нельзя переводить.']);
        }
        if (! $admin->isFloorAdminFamily()) {
            return back()->withErrors(['role' => 'Эту роль нельзя менять здесь.']);
        }

        $data = $request->validate([
            'role' => ['required', 'in:admin,intern'],
        ]);

        $admin->update(['role' => $data['role']]);

        return back()->with('success', $admin->name.': '.$admin->roleLabel());
    }

    public function approveEmployment(Admin $admin)
    {
        try {
            $this->employment->approve(auth('admin')->user(), $admin);
        } catch (RuntimeException $e) {
            return back()->withErrors(['employment' => $e->getMessage()]);
        }

        return back()->with('success', $admin->fresh()->name.': принят на работу');
    }

    public function rejectEmployment(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Укажите причину отклонения',
            'reason.min' => 'Причина слишком короткая',
        ]);

        try {
            $this->employment->reject(auth('admin')->user(), $admin, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', $admin->name.': анкета отклонена');
    }

    public function employmentScan(Admin $admin): Response
    {
        $profile = $admin->employmentProfile;
        if (! $profile || ! filled($profile->passport_scan_path)) {
            abort(404, 'Скан не загружен.');
        }

        if (! Storage::disk('local')->exists($profile->passport_scan_path)) {
            abort(404, 'Файл скана не найден.');
        }

        $path = $profile->passport_scan_path;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->response($path, 'passport.'.$ext, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="passport.'.$ext.'"',
        ]);
    }

    public function destroy(Admin $admin)
    {
        $actor = auth('admin')->user();
        $open = \App\Support\AdminShift::openShift();
        $ownerCount = Admin::query()->where('role', Admin::ROLE_OWNER)->count();

        if (! $this->canDelete($actor, $admin, $open, $ownerCount)) {
            return back()->withErrors(['staff' => $this->deleteBlockReason($actor, $admin, $open, $ownerCount)]);
        }

        $name = $admin->name;
        $admin->load('employmentProfile');

        try {
            DB::transaction(function () use ($admin) {
                $scan = $admin->employmentProfile?->passport_scan_path;
                if (filled($scan)) {
                    Storage::disk('local')->delete($scan);
                }
                Storage::disk('local')->deleteDirectory('employment/'.$admin->id);
                $admin->delete();
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['staff' => 'Не удалось удалить сотрудника.']);
        }

        return back()->with('success', 'Сотрудник удалён: '.$name);
    }

    /**
     * @param  list<int>  $internIds
     * @return array{status: string, label: string}
     */
    private function dutyFor(Admin $employee, $open, array $internIds): array
    {
        if ($employee->isFired()) {
            return ['status' => 'fired', 'label' => 'Уволен'];
        }

        if ($employee->isIntern()) {
            if ($employee->needsEmployment()) {
                $status = $employee->employmentProfile?->status;

                return match ($status) {
                    'review' => ['status' => 'review', 'label' => 'На проверке'],
                    'rejected' => ['status' => 'rejected', 'label' => 'Отклонено'],
                    default => ['status' => 'intern', 'label' => 'Анкета'],
                };
            }

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

    private function canDelete(?Admin $actor, Admin $employee, $open, int $ownerCount): bool
    {
        return $this->deleteBlockReason($actor, $employee, $open, $ownerCount) === null;
    }

    private function deleteBlockReason(?Admin $actor, Admin $employee, $open, int $ownerCount): ?string
    {
        if (! $actor || $actor->role !== Admin::ROLE_OWNER) {
            return 'Удаляет только владелец.';
        }

        if ((int) $actor->id === (int) $employee->id) {
            return 'Нельзя удалить свой аккаунт.';
        }

        if ($employee->role === Admin::ROLE_OWNER && $ownerCount < 2) {
            return 'Нельзя удалить единственного владельца.';
        }

        if ($open && (int) $open->admin_id === (int) $employee->id) {
            return 'Сначала сдайте смену этого сотрудника.';
        }

        return null;
    }

    private function canFire(?Admin $actor, Admin $employee, $open, int $ownerCount): bool
    {
        return $this->fireBlockReason($actor, $employee, $open, $ownerCount) === null;
    }

    private function fireBlockReason(?Admin $actor, Admin $employee, $open, int $ownerCount): ?string
    {
        if (! $actor || ! $actor->canReviewEmployment()) {
            return 'Увольняет владелец или управляющий.';
        }

        if ((int) $actor->id === (int) $employee->id) {
            return 'Нельзя уволить свой аккаунт.';
        }

        if ($employee->isFired()) {
            return 'Сотрудник уже уволен.';
        }

        if (! in_array($employee->role, $actor->hireableRoles(), true)) {
            return 'Эту должность нельзя уволить.';
        }

        if ($employee->role === Admin::ROLE_OWNER && $ownerCount < 2) {
            return 'Нельзя уволить единственного владельца.';
        }

        if ($open && (int) $open->admin_id === (int) $employee->id) {
            return 'Сначала сдайте смену этого сотрудника.';
        }

        return null;
    }

    private function canRestore(?Admin $actor, Admin $employee): bool
    {
        if (! $actor || ! $actor->canReviewEmployment()) {
            return false;
        }

        if (! $employee->isFired()) {
            return false;
        }

        return in_array($employee->role, $actor->hireableRoles(), true);
    }
}
