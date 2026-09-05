<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftSlotSetting;
use App\Services\ShiftSlotService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class ClubConfigController extends Controller
{
    public function __construct(
        private readonly ShiftSlotService $slots,
    ) {
    }

    public function index()
    {
        $clubId = AdminLocation::id(auth('admin')->user());

        return Inertia::render('Admin/ClubConfig', [
            'shift_hours' => ShiftSlotSetting::hoursFor($clubId),
            'starts_hour' => ShiftSlotSetting::startsHourFor($clubId),
        ]);
    }

    public function updateShifts(Request $request)
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

        return back()->with('success', 'Конфигурация: '.$label);
    }
}
