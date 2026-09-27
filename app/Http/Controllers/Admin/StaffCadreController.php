<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CadreReport;
use App\Models\StaffCadreEvent;
use App\Services\StaffCadreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffCadreController extends Controller
{
    public function __construct(
        private readonly StaffCadreService $cadre,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/TaxesCadre', $this->cadre->screen());
    }

    public function updateRequisites(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'snils' => ['required', 'string', 'max:20'],
            'inn' => ['required', 'string', 'max:16'],
            'gender' => ['required', 'in:male,female'],
            'okz_code' => ['nullable', 'string', 'max:10'],
            'part_time_code' => ['nullable', 'in:НЕПД,НЕПН'],
        ]);

        try {
            $this->cadre->saveRequisites($admin, $data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['snils' => $e->getMessage()]);
        }

        return back()->with('success', 'Реквизиты для СФР сохранены: '.$admin->name);
    }

    public function generateEfs1(Request $request)
    {
        $data = $request->validate([
            'event_ids' => ['required', 'array', 'min:1'],
            'event_ids.*' => ['integer'],
        ]);

        try {
            $report = $this->cadre->generateEfs1($data['event_ids'], $request->user('admin'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['cadre' => $e->getMessage()]);
        }

        return back()->with('success', 'ЕФС-1 собран, файл '.$report->id.' готов к скачиванию.');
    }

    public function generatePers(Request $request)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $report = $this->cadre->generatePersRecords((int) $data['year'], (int) $data['month'], $request->user('admin'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['cadre' => $e->getMessage()]);
        }

        return back()->with('success', 'Персонифицированные сведения собраны, файл '.$report->id.'.');
    }

    public function download(CadreReport $report): StreamedResponse
    {
        if (! Storage::disk('local')->exists($report->file_path)) {
            abort(404, 'Файл выгрузки не найден.');
        }

        $name = basename($report->file_path);

        return Storage::disk('local')->download($report->file_path, $name, [
            'Content-Type' => 'application/xml',
        ]);
    }

    public function markSubmitted(CadreReport $report)
    {
        $this->cadre->markSubmitted($report);

        return back()->with('success', 'Отчёт отмечен как сданный.');
    }

    public function preview(StaffCadreEvent $event): Response
    {
        return Inertia::render('Admin/CadrePreview', $this->cadre->preview($event));
    }
}
