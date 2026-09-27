<?php

namespace App\Http\Controllers;

use App\Services\Faceit\FaceitIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FaceitConnectController extends Controller
{
    public function redirect(FaceitIdentityService $faceit): RedirectResponse
    {
        try {
            return redirect()->away($faceit->beginOauth(auth()->user()));
        } catch (RuntimeException $e) {
            return redirect()->route('profile.edit')->with('error', $e->getMessage());
        }
    }

    public function callback(Request $request, FaceitIdentityService $faceit): RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '') {
            return redirect()->route('profile.edit')->with('error', 'FACEIT не вернул код');
        }
        try {
            $faceit->completeOauth(auth()->user(), $code, $state);
        } catch (RuntimeException $e) {
            return redirect()->route('profile.edit')->with('error', $e->getMessage());
        }

        return redirect()->route('profile.edit')->with('success', 'FACEIT привязан');
    }

    public function unlink(FaceitIdentityService $faceit): RedirectResponse
    {
        $faceit->unlink(auth()->user());

        return back()->with('success', 'FACEIT отвязан');
    }
}
