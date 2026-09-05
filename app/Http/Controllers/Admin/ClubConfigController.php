<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftSlotSetting;
use App\Models\StaffDocument;
use App\Services\ShiftSlotService;
use App\Services\StaffDocumentService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class ClubConfigController extends Controller
{
    public function __construct(
        private readonly ShiftSlotService $slots,
        private readonly StaffDocumentService $documents,
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

    public function documents()
    {
        return Inertia::render('Admin/ClubDocuments', [
            'documents' => $this->documents->configPayload(),
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

        return back()->with('success', 'Конфигурация смен: '.$label);
    }

    public function saveDocument(Request $request, ?StaffDocument $document = null)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'kind' => ['required', 'string', 'in:employment,fire_safety'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.title' => ['required', 'string', 'max:180'],
            'sections.*.body' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $saved = $this->documents->saveDocument($document, [
                'title' => $data['title'],
                'kind' => $data['kind'],
                'sections' => $data['sections'],
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.config.documents')
            ->with('success', 'Документ «'.$saved->title.'» сохранён');
    }

    public function destroyDocument(StaffDocument $document)
    {
        try {
            $this->documents->deleteDocument($document);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.config.documents')
            ->with('success', 'Документ удалён');
    }
}
