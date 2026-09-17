<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\ClubOpenRegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ClubOpenRegisterController extends Controller
{
    public function create(Request $request)
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.tournaments.index');
        }

        return Inertia::render('Club/Join', [
            'clubs' => Club::query()
                ->tournamentRoster()
                ->orderBy('name')
                ->get()
                ->map(fn (Club $club) => $club->circuitCard())
                ->values(),
            'blocked_in_app' => $this->blockedInApp($request),
        ]);
    }

    public function store(Request $request, ClubOpenRegisterService $register)
    {
        if (Auth::guard('admin')->check()) {
            throw ValidationException::withMessages([
                'email' => 'Выйдите из текущей учётки, чтобы завести свой клуб.',
            ]);
        }

        if ($this->blockedInApp($request)) {
            throw ValidationException::withMessages([
                'name' => 'Регистрация клуба — в обычном браузере, не в приложении гостя или зала.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'network_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:180'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ], [
            'email.unique' => 'Этот email уже зарегистрирован',
            'password.confirmed' => 'Пароли не совпадают',
        ]);

        $admin = $register->register($data);
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.tournaments.index');
    }

    private function blockedInApp(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        return str_contains($ua, 'CompClubClient')
            || str_contains($ua, 'CompClubAdmin')
            || str_contains($ua, 'CompClubBoss')
            || str_contains($ua, 'CompClubStore');
    }
}
