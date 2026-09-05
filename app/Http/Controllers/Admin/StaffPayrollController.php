<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Models\ShiftSlotSetting;
use App\Services\ShiftSlotService;
use App\Services\StaffEmploymentService;
use App\Services\StaffPayrollService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class StaffPayrollController extends Controller
{
    public function __construct(
        private readonly StaffPayrollService $payroll,
        private readonly ShiftSlotService $slots,
        private readonly StaffEmploymentService $employment,
    ) {
    }

    public function index(Request $request)
    {
        $admin = auth('admin')->user();
        $payload = $this->payroll->snapshot($admin);
        $payload['employment'] = $this->employment->payload($admin);
        $payload['calendar'] = $admin->needsEmployment()
            ? [
                'month' => now()->format('Y-m'),
                'cancel_before_hours' => ShiftSlotService::CANCEL_BEFORE_HOURS,
                'shift_hours' => 12,
                'starts_hour' => 10,
                'can_set_model' => false,
                'days' => [],
                'my_bookings' => [],
            ]
            : $this->slots->calendar($admin, $request->string('month')->toString() ?: null);

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

    public function setShiftModel(Request $request)
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'in:12,24'],
            'starts_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
        ]);

        $admin = auth('admin')->user();
        $clubId = AdminLocation::id($admin);
        $hours = array_key_exists('hours', $data) && $data['hours'] !== null
            ? (int) $data['hours']
            : ShiftSlotSetting::hoursFor($clubId);
        $startsHour = array_key_exists('starts_hour', $data) && $data['starts_hour'] !== null
            ? (int) $data['starts_hour']
            : ShiftSlotSetting::startsHourFor($clubId);

        try {
            $this->slots->setHours($admin, $hours, $startsHour);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        $endHour = ($startsHour + $hours) % 24;
        $label = $hours === 24
            ? sprintf('сутки с %02d:00 до %02d:00', $startsHour, $endHour)
            : sprintf('смены по 12 часов с %02d:00', $startsHour);

        return back()->with('success', 'Модель: '.$label);
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

    public function hire(Request $request)
    {
        $admin = auth('admin')->user();
        $hasScan = filled($this->employment->profile($admin)->passport_scan_path);

        $data = $request->validate($this->employmentRules($hasScan), $this->employmentMessages());

        $scan = $request->file('passport_scan');

        try {
            $this->employment->hire($admin, $data, $scan);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Вы устроены. Добро пожаловать в смену.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function employmentRules(bool $hasScan): array
    {
        return [
            'full_name' => ['required', 'string', 'min:5', 'max:120'],
            'passport_series' => ['required', 'regex:/^\d{4}$/'],
            'passport_number' => ['required', 'regex:/^\d{6}$/'],
            'issued_by' => ['required', 'string', 'min:8', 'max:255'],
            'issued_at' => ['required', 'date', 'before_or_equal:today'],
            'department_code' => ['required', 'regex:/^\d{3}-\d{3}$/'],
            'birth_date' => ['required', 'date', 'before:-16 years', 'after:-80 years'],
            'passport_scan' => [$hasScan ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function employmentMessages(): array
    {
        return [
            'full_name.required' => 'Укажите ФИО',
            'passport_series.regex' => 'Серия паспорта — 4 цифры',
            'passport_number.regex' => 'Номер паспорта — 6 цифр',
            'issued_by.required' => 'Укажите, кем выдан паспорт',
            'issued_at.required' => 'Укажите дату выдачи',
            'department_code.regex' => 'Код подразделения в формате 000-000',
            'birth_date.required' => 'Укажите дату рождения',
            'birth_date.before' => 'Устройство с 16 лет',
            'passport_scan.required' => 'Загрузите скан паспорта',
            'passport_scan.mimes' => 'Скан: JPG, PNG или PDF',
        ];
    }
}
