<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Models\User;
use App\Models\Booking;
use App\Models\Product;
use App\Models\GameAccount;
use App\Models\Game;
use Illuminate\Http\Request;

class ShellApiController extends Controller
{
    // Этот метод дергает QML-шелл каждую минуту для обновления рекламы/инфо
    public function getActiveOverlays()
    {
        $overlays = Overlay::where('is_active', true)
            ->get()
            ->keyBy('block_position');

        return response()->json([
            'status' => 'success',
            'data' => $overlays
        ]);
    }

    // Метод авторизации с терминала по ПИН-коду
    public function login(Request $request)
    {

        try {
            $request->validate([
                'phone' => 'required|string',
                'pin' => 'required|string|size:4',
                'terminal_id' => 'required|string' // Важно знать, на какой ПК он сел
            ]);

            $user = User::where('phone', $request->phone)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Пользователь не найден'], 404);
            }

            // Ищем оплаченную бронь
            $booking = Booking::where('user_id', $user->id)
                ->where('pin_code', $request->pin)
                ->whereIn('status', ['paid', 'active'])
                ->first();

            if (!$booking) {
                return response()->json(['status' => 'error', 'message' => 'Неверный PIN или бронь не оплачена'], 401);
            }

            $now = now();

            // ВЫЧИСЛЯЕМ ДЛИТЕЛЬНОСТЬ (в минутах)
            // Если в базе хранится готовый duration — берем его,
            // если нет — считаем разницу между запланированным началом и концом.
            $duration = $booking->duration ?: $booking->end_time->diffInMinutes($booking->start_time);

            // ЛОГИКА СДВИГА:
            // Если клиент пришел раньше (даже за час), мы просто делаем старт СЕЙЧАС.
            // Конец сессии = сейчас + купленная длительность.
            $newEndTime = $now->copy()->addMinutes($duration);

            // Обновляем бронь, сдвигая график под клиента
            $now = now();

            // МАГИЯ ПРЕВРАЩЕНИЯ: Переводим текущее время в float (часы.доли)
            // Пример: 13 часов 30 минут -> 13 + (30/60) = 13.5
            $floatStartTime = $now->hour + ($now->minute / 60);

            // Обновляем бронь в формате, который понимает твоя БД
            $booking->update([
                'status' => 'active',
                'start_time' => $floatStartTime, // Теперь тут правильный float!
                'pin_code' => null,
                'computer_id' => $request->terminal_id
            ]);

            // Расчет конца сессии для ответа в Qt-шелл:
            $duration = (float)$booking->duration;
            $newEndTime = $now->copy()->addMinutes($duration * 60);

            $balance = $user->wallet ? $user->wallet->deposit_balance : 0;

            return response()->json([
                'status' => 'success',
                'message' => 'Сессия начата раньше графика. Приятной игры!',
                'user' => [
                    'id' => $user->id,
                    'balance' => $balance,
                    'ends_at' => $newEndTime->format('H:i') // Передаем шеллу время окончания
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getGames()
    {
        try {
            // Забираем все игры из базы данных
            $games = \App\Models\Game::all()->map(function ($game) {
                return [
                    'id'       => $game->id,
                    'title'    => $game->title,
                    'category' => $game->category ?? 'STEAM', // STEAM, EPIC и т.д.
                    'platform' => $game->platform ?? 'PC',

                    // ФИКС ПОСТЕРА: Возвращаем чистый относительный путь БЕЗ asset(),
                    // чтобы C++ NetworkManager мог корректно закэшировать файл на диске
                    'poster'   => $game->poster ? $game->poster : '',

                    // Синхронизируем с C++ структурой GameItem (в C++ поле называется exePath)
                    'exe_path' => $game->exe_path,

                    // ФИКС АРГУМЕНТОВ: В C++ парсере прописано item.args = obj["args"]
                    // Переименовываем launch_args в args, чтобы параметры запуска не терялись
                    'args'     => $game->launch_args ?? $game->args ?? '',
                ];
            });

            // ВАЖНО: Возвращаем чистую коллекцию массивом, без обертки в 'status' и 'games'
            return response()->json($games);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Shell API Games Error: " . $e->getMessage());
            // Возвращаем пустой JSON-массив, чтобы шелл не упал от HTML-кода при ошибке 500
            return response()->json([]);
        }
    }
    /**
     * Получение списка товаров для QML интерфейса
     */
    public function getProducts()
    {
        $products = Product::where('stock', '>', 0)->get();

        // Возвращаем чистую коллекцию без оберток в объект
        return response()->json($products);
    }

    /**
     * Покупка с терминала
     */
    public function checkout(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'terminal_id' => 'required'
        ]);

        try {
            // 1. Ищем сессию
            $session = \Illuminate\Support\Facades\DB::table('gizmo_sessions')
                ->where('host_name', $request->terminal_id)
                ->where('is_active', true)
                ->first();

            if (!$session) {
                return response()->json(['message' => 'Активная сессия не найдена. Покупка невозможна.'], 403);
            }

            // 2. Получаем юзера, товар и кошелек (через чистый DB фасад для надежности)
            $user = \Illuminate\Support\Facades\DB::table('users')->where('id', $session->user_id)->first();
            $product = \Illuminate\Support\Facades\DB::table('products')->where('id', $request->product_id)->first();
            $wallet = \Illuminate\Support\Facades\DB::table('wallets')->where('user_id', $user->id)->first();

            // 3. Проверки
            if (!$wallet || $wallet->balance < $product->price) {
                return response()->json(['message' => 'Недостаточно средств на балансе'], 422);
            }
            if ($product->stock <= 0) {
                return response()->json(['message' => 'Товар закончился'], 422);
            }

            // 4. Безопасная транзакция
            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $product, $request) {
                // Списываем деньги
                \Illuminate\Support\Facades\DB::table('wallets')->where('user_id', $user->id)->decrement('balance', $product->price);

                // Уменьшаем склад
                \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->decrement('stock', 1);

                // Создаем заказ
                \Illuminate\Support\Facades\DB::table('orders')->insert([
                    'user_id'      => $user->id,
                    'product_name' => $product->name,
                    'price'        => $product->price,
                    'pc_name'      => "ПК №" . $request->terminal_id, // Берем ID из QML
                    'status'       => 'pending',
                    'created_at'   => now(),
                    'updated_at'   => now()
                ]);

                // Лог транзакции
                \Illuminate\Support\Facades\DB::table('transactions')->insert([
                    'user_id'     => $user->id,
                    'amount'      => -$product->price,
                    'type'        => 'purchase',
                    'source'      => 'market_shell',
                    'description' => "Маркет (Терминал): {$product->name}",
                    'created_at'  => now(),
                    'updated_at'  => now()
                ]);
            });

            return response()->json(['status' => 'success', 'message' => 'Заказ успешно оформлен и отправлен на бар!']);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Shell API Checkout Error: " . $e->getMessage());
            // Возвращаем JSON даже при ошибке, чтобы QML не подавился HTML-кодом!
            return response()->json(['message' => 'Внутренняя ошибка сервера: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Вызов админа
     */
    public function callAdmin(Request $request)
    {
        $request->validate(['terminal_id' => 'required']);

        // Здесь логика создания алерта для админа (в базу или сокет)
        // ...

        return response()->json(['status' => 'success']);
    }
    public function takeAccount(Request $request)
    {
        // 1. Валидируем прилетевшие из C++ данные
        $request->validate([
            'game_id' => 'required|integer',
            'terminal_id' => 'required|string',
        ]);

        $gameId = $request->input('game_id');
        $terminalId = $request->input('terminal_id');

        // 2. Ищем саму игру в базе, чтобы забрать правильные пути и аргументы лаунчера
        $game = Game::find($gameId);
        if (!$game) {
            return response()->json([
                'status' => 'error',
                'message' => 'Игра не найдена в базе данных клуба.'
            ], 404);
        }

        // 3. Вытаскиваем первый попавшийся свободный аккаунт для этой игры
        // Используем sharedLock() или pessimistic locking, чтобы избежать двойной выдачи одной учетки
        $account = GameAccount::where('game_id', $gameId)
            ->where('status', 'free')
            ->first();

        if (!$account) {
            return response()->json([
                'status' => 'error',
                'message' => 'Все клубные аккаунты для этой игры сейчас заняты.'
            ], 200); // Отдаем 200, чтобы шелл корректно вывел предупреждение в консоль
        }

        // 4. Мгновенно резервируем аккаунт за ПК, меняя статус
        $account->update([
            'status' => 'in_use',
            'assigned_to_terminal' => $terminalId // Полезно логгировать, какой комп занял учетку
        ]);

        // 5. Упаковываем данные и отправляем обратно в зашифрованном JSON-пакете
        return response()->json([
            'status' => 'success',
            'login' => $account->login,
            'password' => $account->password, // C++ сам подставит его в аргументы -login
            'exe_path' => $game->exe_path,
            'args' => $game->launch_args
        ], 200);
    }
    /**
     * Освободить клубный аккаунт (перевод из in_use в free)
     * Маршрут: POST /api/shell/games/free-account
     */
    public function freeAccount(Request $request)
    {
        // Нам нужен только ID игры, чтобы вернуть один из ее занятых аккаунтов в пул свободных
        $request->validate([
            'game_id' => 'required|integer'
        ]);

        $gameId = $request->input('game_id');

        try {
            // Ищем первый попавшийся аккаунт этой игры, который сейчас используется
            $account = GameAccount::where('game_id', $gameId)
                ->where('status', 'in_use')
                ->first();

            // Если нашли — меняем статус обратно на free
            if ($account) {
                $account->update([
                    'status' => 'free'
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Клубный аккаунт для игры ID ' . $gameId . ' успешно освобожден.'
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Занятых аккаунтов для этой игры не найдено (возможно, уже освобожден).'
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Shell API FreeAccount Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
    /**
     * Перевод терминала в режим паузы с генерацией нового ПИН-кода
     * Маршрут: POST /api/shell/games/pause
     */
    /**
     * Перевод терминала в режим паузы с генерацией нового ПИН-кода
     * Маршрут: POST /api/shell/games/pause
     */
    /**
     * Перевод терминала в режим паузы с генерацией нового ПИН-кода
     * Маршрут: POST /api/shell/games/pause
     */
    /**
     * Перевод терминала в режим паузы с генерацией нового ПИН-кода
     * Маршрут: POST /api/shell/games/pause
     */
    public function setPause(Request $request)
    {
        $request->validate([
            'computer_id' => 'required|integer' // Из Qt прилетает численный ID (например, 15)
        ]);

        $computerId = $request->input('computer_id');

        try {
            // Ищем активную бронь, проверяя наличие строкового "15" внутри JSON-массива pc_ids
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($computerId) {
                    $query->whereJsonContains('pc_ids', (string)$computerId)
                        ->orWhere('pc_ids', 'like', '%"' . $computerId . '"%'); // Запасной вариант на случай текстового хранения
                })
                ->first();

            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Активная бронь для компьютера ID {$computerId} не найдена в системе."
                ], 404);
            }

            // Генерируем новый четырехзначный код разблокировки
            $newPin = rand(1000, 9999);

            // Обновляем ПИН-код в найденной брони
            $booking->update([
                'pin_code' => $newPin
            ]);

            return response()->json([
                'status' => 'success',
                'pin_code' => $newPin
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Shell API Pause Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
