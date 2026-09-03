<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlayerNicknameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        $redirectTo = (string) $request->input('redirect_to', '');
        $isLocalPath = $redirectTo !== ''
            && str_starts_with($redirectTo, '/')
            && ! str_starts_with($redirectTo, '//');

        return inertia()->location($isLocalPath ? $redirectTo : route('dashboard'));
    }
}
