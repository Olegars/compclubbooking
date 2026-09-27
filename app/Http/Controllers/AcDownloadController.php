<?php

namespace App\Http\Controllers;

use App\Services\ReactorAc\AcGate;
use App\Services\StaffDocumentService;
use App\Models\StaffDocument;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AcDownloadController extends Controller
{
    public function __construct(
        private readonly AcGate $gate,
        private readonly StaffDocumentService $documents,
    ) {
    }

    public function manifest()
    {
        return response()->json($this->gate->manifest());
    }

    public function about(): Response
    {
        return Inertia::render('Public/PlayFromHome', [
            'manifest' => $this->gate->manifest(),
            'sections' => $this->documents->sectionsFor(StaffDocument::KIND_REACTOR_AC),
        ]);
    }

    public function download(): Response
    {
        $user = Auth::user();

        return Inertia::render('User/AcDownload', [
            'manifest' => $this->gate->manifest(),
            'reactor_ac' => $user ? $this->gate->cabinet($user) : ['mode' => 'off', 'status' => 'off'],
            'sections' => $this->documents->sectionsFor(StaffDocument::KIND_REACTOR_AC),
        ]);
    }
}
