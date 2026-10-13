<?php

namespace App\Http\Controllers\Auth; // Проверь, чтобы путь совпадал с папкой

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function logout(Request $request)
    {
        // Выход для админа
        if (Auth::guard('admin')->check()) {
            Auth::guard('admin')->logout();
        }

        // Выход для обычного юзера
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Если выходим из-под админа — на логин админа, если юзер — на главную.
        // Киоск передаёт redirect_to=/terminal/…, чтобы следующий гость остался на планшете.
        if ($request->is('admin/*')) {
            return redirect()->route('admin.login');
        }

        $terminal = $this->safeTerminalReturn($request->input('redirect_to'));
        if ($terminal !== null) {
            return redirect($terminal);
        }

        return redirect()->route('home');
    }

    private function safeTerminalReturn(mixed $redirectTo): ?string
    {
        if (! is_string($redirectTo) || $redirectTo === '') {
            return null;
        }
        if (
            ! str_starts_with($redirectTo, '/terminal')
            || str_starts_with($redirectTo, '//')
            || str_contains($redirectTo, '\\')
            || str_contains($redirectTo, '..')
        ) {
            return null;
        }

        $path = parse_url($redirectTo, PHP_URL_PATH);
        $query = parse_url($redirectTo, PHP_URL_QUERY);
        if (! is_string($path) || ! str_starts_with($path, '/terminal')) {
            return null;
        }

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }
}
