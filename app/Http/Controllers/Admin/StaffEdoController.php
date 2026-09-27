<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffDisciplinaryIncident;
use App\Models\StaffEdoDocument;
use App\Models\StaffEdoOtp;
use App\Services\StaffEdoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StaffEdoController extends Controller
{
    public function __construct(private readonly StaffEdoService $edo)
    {
    }

    public function sendOtp(Request $request)
    {
        $admin = auth('admin')->user();
        $data = $request->validate([
            'purpose' => ['required', 'in:'.StaffEdoOtp::PURPOSE_AGREEMENT.','.StaffEdoOtp::PURPOSE_EXPLANATION],
            'phone' => ['nullable', 'string', 'max:20'],
            'telegram_id' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $this->edo->sendOtp(
                $admin,
                $data['purpose'],
                $data['phone'] ?? null,
                $data['telegram_id'] ?? null
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', $result['message']);
    }

    public function signAgreement(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'telegram_id' => ['nullable', 'string', 'max:32'],
            'code' => ['required', 'digits:6'],
            'scrolled' => ['accepted'],
        ]);

        try {
            $this->edo->signAgreement(
                auth('admin')->user(),
                $data['phone'],
                $data['telegram_id'] ?? null,
                $data['code'],
                $request
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('success', 'Соглашение о КЭДО подписано.');
    }

    public function submitExplanation(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:10', 'max:5000'],
            'code' => ['required', 'digits:6'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:8192', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        try {
            $this->edo->submitExplanation(
                auth('admin')->user(),
                $data['text'],
                $data['code'],
                $request->file('files', []) ?? [],
                $request
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Объяснительная подписана и отправлена.');
    }

    public function document(StaffEdoDocument $document)
    {
        $admin = auth('admin')->user();
        abort_unless($this->edo->canReadDocument($admin, $document), 403);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return response(Storage::disk('local')->get($document->storage_path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function signAct(Request $request, StaffDisciplinaryIncident $incident)
    {
        try {
            $count = $this->edo->signActs(auth('admin')->user(), $incident, $request);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', $count > 0 ? 'Подпись поставлена.' : 'Новых документов для подписи нет.');
    }

    public function resolve(Request $request, StaffDisciplinaryIncident $incident)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:excuse,confirm_dismissal,dismiss'],
        ]);

        try {
            $this->edo->resolve(auth('admin')->user(), $incident, $data['decision']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        $text = $data['decision'] === 'excuse'
            ? 'Причина признана уважительной.'
            : 'Докладная записка подготовлена на подпись. Доступ не закрыт, начисления не списаны.';

        return back()->with('success', $text);
    }

    public function deliver(StaffDisciplinaryIncident $incident)
    {
        $this->edo->deliverManually($incident);

        return back()->with('success', 'Вручение требования зафиксировано, срок пошёл.');
    }

    public function dossier(StaffDisciplinaryIncident $incident)
    {
        try {
            $path = $this->edo->exportDossier($incident);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return response()->download($path, 'incident-'.$incident->id.'-dossier.zip')->deleteFileAfterSend(true);
    }
}
