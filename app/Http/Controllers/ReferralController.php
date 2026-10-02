<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;

class ReferralController extends Controller
{
    public function open(string $code, ReferralService $referrals): RedirectResponse
    {
        $normalized = $referrals->normalizeCode($code);
        if ($normalized !== '' && User::query()->where('referral_code', $normalized)->exists()) {
            session(['referral_code' => $normalized]);
        }

        return redirect()->route('home');
    }
}
