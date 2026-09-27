<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlayerNicknameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SmsAuthController extends Controller
{
    // === НОВЫЙ МЕТОД: ОТПРАВКА КОДА ===
    public function sendCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        // Здесь в будущем будет реальная интеграция с SMS-шлюзом (например, sms.ru или twilio)
        // Пример: SmsGateway::send($request->phone, 'Код доступа в Sector 0451: 0451');

        // Пока просто пишем в лог сервера, что был запрос
        \Illuminate\Support\Facades\Log::info('[Sector 0451] Запрос СМС кода для номера: ' . $request->phone);

        // Возвращаем успешный JSON-ответ, чтобы фронтенд открыл модалку ввода кода
        return response()->json([
            'success' => true,
            'message' => 'Код отправлен'
        ]);
    }

    // === СТАРЫЙ МЕТОД: ПРОВЕРКА КОДА ===
    public function verifyCode(Request $request, PlayerNicknameService $nicks)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
        ]);

        // Твой секретный код для тестов
        if ($request->code !== '0451') {
            return back()->withErrors(['code' => 'Неверный код доступа']);
        }

        $user = User::where('phone', $request->phone)->first();

        // --- СОЗДАНИЕ ЮЗЕРА (БРОНЕБОЙНЫЙ МЕТОД) ---
        if (!$user) {
            // Используем прямое назначение свойств, чтобы обойти блокировку $fillable
            $user = new User();
            $user->phone = $request->phone;
            $user->name = $nicks->assignForNewUser();
            $user->nickname_pending = true;
            $user->email = $request->phone . '@reactor.club';
            $user->password = bcrypt(\Illuminate\Support\Str::random(16));
            $user->avatar = 'avatar_' . rand(1, 10) . '.png';
            $user->offer_accepted_at = now();
            $user->save(); // Жестко пишем в БД
        } elseif (! $user->offer_accepted_at) {
            $user->forceFill(['offer_accepted_at' => now()])->save();
        }

        // --- СОЗДАНИЕ КОШЕЛЬКА ---
        // Проверяем наличие кошелька. Используем first() чтобы точно знать, есть ли запись в БД
        $wallet = \App\Models\Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            $newWallet = new \App\Models\Wallet();
            $newWallet->user_id = $user->id;
            $newWallet->deposit_balance = 0;
            $newWallet->save();
        }

        // --- ЛОГИКА ВХОДА ---
        \Illuminate\Support\Facades\Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        // Вход из бронирования возвращает на ту же страницу с сохранённым выбором,
        // остальные случаи ведут в личный кабинет. Принимаем только локальные пути.
        $returnTo = $this->safeReturn($request->input('redirect_to'));

        // Подсказка DeepSeek уже в users.name. Свой ник гость правит здесь же,
        // до кабинета — не в /account/profile.
        if ($user->nickname_pending) {
            if (is_string($returnTo)) {
                $request->session()->put('nickname_return', $returnTo);
            } else {
                $request->session()->forget('nickname_return');
            }

            return redirect()->route('auth.nickname');
        }

        return inertia()->location($returnTo ?: route('dashboard'));
    }

    public function showNickname(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->nickname_pending) {
            return redirect()->to($this->pullReturnPath($request) ?: route('dashboard'));
        }

        return Inertia::render('Auth/ClaimNickname', [
            'nickname' => $user->name,
        ]);
    }

    public function saveNickname(Request $request, PlayerNicknameService $nicks)
    {
        $user = $request->user();
        if (! $user || ! $user->nickname_pending) {
            return redirect()->route('dashboard');
        }

        $name = $nicks->normalizeGuestChoice((string) $request->input('name', ''));
        if ($name === null) {
            return back()->withErrors([
                'name' => 'От 2 до 50 символов, нужна хотя бы одна буква или цифра.',
            ])->withInput();
        }

        $user->name = $name;
        $user->nickname_pending = false;
        $user->save();

        $returnTo = $this->pullReturnPath($request);

        return redirect()->to($returnTo ?: route('dashboard'));
    }

    private function safeReturn(mixed $redirectTo): ?string
    {
        if (! is_string($redirectTo) || $redirectTo === '') {
            return null;
        }
        if (
            ! str_starts_with($redirectTo, '/')
            || str_starts_with($redirectTo, '//')
            || str_starts_with($redirectTo, '/\\')
            || str_starts_with($redirectTo, '/auth/nickname')
        ) {
            return null;
        }

        return $redirectTo;
    }

    private function pullReturnPath(Request $request): ?string
    {
        return $this->safeReturn($request->session()->pull('nickname_return'));
    }
}
