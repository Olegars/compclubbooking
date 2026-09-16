<?php

namespace App\Http\Controllers;

use App\Models\StoreWarranty;
use App\Services\StorePcPassportService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StorePcPassportController extends Controller
{
    public function show(string $token, StorePcPassportService $passports)
    {
        $warranty = $this->resolve($token);
        $passport = $passports->present($warranty);

        return response()
            ->view('store.pc-passport', ['passport' => $passport])
            ->header('Cache-Control', 'private, max-age=60');
    }

    public function video(string $token): BinaryFileResponse
    {
        $warranty = $this->resolve($token);
        $pc = $warranty->builtPc;
        abort_unless($pc && $pc->hasAssemblyClip(), 404);

        $path = (string) $pc->assembly_clip_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $full = Storage::disk('local')->path($path);

        return response()->file($full, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="assembly.mp4"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function resolve(string $token): StoreWarranty
    {
        $token = strtolower(trim($token));
        abort_unless(preg_match('/^[a-z0-9]{24,40}$/', $token) === 1, 404);

        return StoreWarranty::query()
            ->where('public_token', $token)
            ->with([
                'club:id,name',
                'builtPc.assembler:id,name,role',
                'builtPc.componentLinks.component',
            ])
            ->firstOrFail();
    }
}
