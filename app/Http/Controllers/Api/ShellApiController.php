<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Models\User;
use App\Models\Booking;
use App\Models\Product;
use App\Models\GameAccount;
use App\Models\Game;
use App\Models\Computer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShellApiController extends Controller
{
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

    public function login(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'pin' => 'required|string|size:4',
                'terminal_id' => 'required|integer'
            ]);

            $user = User::where('phone', $request->phone)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Пользователь не найден'], 404);
            }

            $booking = Booking::where('user_id', $user->id)
                ->where('pin_code', $request->pin)
                ->whereIn('status', ['paid', 'active'])
                ->first();

            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Неверный PIN-код, либо сессия уже была активирована.'
                ], 401);
            }

            $now = now();
            $duration = $booking->duration ?: $booking->end_time->diffInMinutes($booking->start_time);
            $durationMinutes = (float)$duration * 60;

            $floatStartTime = $now->hour + ($now->minute / 60);

            $booking->update([
                'status' => 'active',
                'start_time' => $floatStartTime,
                'pin_code' => null,
                'computer_id' => $request->terminal_id
            ]);

            $hours = floor($durationMinutes / 60);
            $minutes = floor($durationMinutes % 60);
            $formattedTime = sprintf('%02d:%02d:%02d', $hours, $minutes, 0);

            $balance = $user->wallet ? $user->wallet->deposit_balance : 0;

            return response()->json([
                'status' => 'success',
                'message' => 'Авторизация успешна.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Игрок',
                    'balance' => $balance,
                    'time_remaining' => $formattedTime
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error("Shell API Login Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getGames()
    {
        try {
            $games = Game::all()->map(function ($game) {
                return [
                    'id'       => $game->id,
                    'title'    => $game->title,
                    'category' => $game->category ?? 'STEAM',
                    'platform' => $game->platform ?? 'PC',
                    'poster'   => $game->poster ? $game->poster : '',
                    'exe_path' => $game->exe_path,
                    'args'     => $game->launch_args ?? $game->args ?? '',
                ];
            });
            return response()->json($games);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }

    public function getProducts()
    {
        return response()->json(Product::where('stock', '>', 0)->get());
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'terminal_id' => 'required|integer'
        ]);

        try {
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($request) {
                    $query->whereJsonContains('pc_ids', (string)$request->terminal_id)
                        ->orWhere('computer_id', $request->terminal_id);
                })->first();

            if (!$booking) {
                return response()->json(['message' => 'Активная сессия не найдена.'], 403);
            }

            $user = User::find($booking->user_id);
            $product = Product::find($request->product_id);
            $wallet = $user->wallet;

            if (!$wallet || $wallet->deposit_balance < $product->price) {
                return response()->json(['message' => 'Недостаточно средств'], 422);
            }

            DB::transaction(function () use ($user, $product, $wallet, $request) {
                $wallet->decrement('deposit_balance', $product->price);
                $product->decrement('stock', 1);

                DB::table('orders')->insert([
                    'user_id'      => $user->id,
                    'product_name' => $product->name,
                    'price'        => $product->price,
                    'pc_name'      => "ПК №" . $request->terminal_id,
                    'status'       => 'pending',
                    'created_at'   => now(),
                    'updated_at'   => now()
                ]);
            });

            return response()->json(['status' => 'success', 'message' => 'Заказ оформлен!']);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function callAdmin(Request $request)
    {
        return response()->json(['status' => 'success']);
    }

    public function takeAccount(Request $request)
    {
        $request->validate([
            'game_id' => 'required|integer',
            'terminal_id' => 'required|integer',
        ]);

        $game = Game::find($request->game_id);
        if (!$game) return response()->json(['status' => 'error'], 404);

        $account = GameAccount::where('game_id', $request->game_id)->where('status', 'free')->first();
        if (!$account) return response()->json(['status' => 'error', 'message' => 'Занято'], 200);

        $account->update([
            'status' => 'in_use',
            'assigned_to_terminal' => (string)$request->terminal_id
        ]);

        return response()->json([
            'status' => 'success',
            'login' => $account->login,
            'password' => $account->password,
            'exe_path' => $game->exe_path,
            'args' => $game->launch_args
        ], 200);
    }

    public function freeAccount(Request $request)
    {
        $account = GameAccount::where('game_id', $request->game_id)->where('status', 'in_use')->first();
        if ($account) $account->update(['status' => 'free']);
        return response()->json(['status' => 'success']);
    }

    public function setPause(Request $request)
    {
        $request->validate(['computer_id' => 'required|integer']);
        $computerId = (string)$request->input('computer_id');

        try {
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($computerId) {
                    $query->whereJsonContains('pc_ids', $computerId)
                        ->orWhere('computer_id', $computerId);
                })
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$booking) {
                return response()->json(['status' => 'error', 'message' => 'Активная бронь на ПК не найдена.'], 404);
            }

            $newPin = rand(1000, 9999);
            $booking->update(['pin_code' => $newPin]);

            return response()->json(['status' => 'success', 'pin_code' => $newPin]);
        } catch (\Exception $e) {
            Log::error("Ошибка паузы: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    // МЕТОД РЕГИСТРАЦИИ ТЕРМИНАЛА (ПРИВЯЗКА НАПРЯМУЮ, СОЗДАЕТ С НУЛЯ)[cite: 2]
    public function registerTerminal(Request $request)
    {
        try {
            $request->validate([
                'hwid' => 'required|string',
                'type' => 'required|string'
            ]);

            // 1. Проверяем, может этот HWID уже привязан к какому-то ПК
            $computer = \App\Models\Computer::where('hwid', $request->hwid)->first();

            if ($computer) {
                if ($computer->type !== $request->type) {
                    $computer->update([
                        'type' => $request->type
                    ]);
                }

                return response()->json([
                    'status' => 'success',
                    'terminal_id' => $computer->id,
                    'message' => 'Конфигурация обновлена. ПК №' . $computer->name . ' изменен на ' . strtoupper($request->type)
                ], 200);
            }

            // 2. ЖЕЛЕЗО НОВОЕ — Автоматически создаем запись в таблице computers
            $nextNumber = \App\Models\Computer::max('id') + 1;

            // Берем ID первого доступного клуба из базы REACTOR
            $defaultClubId = \App\Models\Club::first()?->id ?? 1;

            // Создаем запись (поля x, y и status Laravel/PostgreSQL подхватят по дефолту из миграции)
            $newComputer = \App\Models\Computer::create([
                'hwid' => $request->hwid,
                'type' => $request->type,
                'name' => (string)$nextNumber,
                'club_id' => $defaultClubId // Фикс: передаем правильную переменную
            ]);

            return response()->json([
                'status' => 'success',
                'terminal_id' => $newComputer->id,
                'message' => 'Успешная автоматическая генерация ПК №' . $newComputer->name
            ], 200);

        } catch (\Throwable $e) {
            \Log::error("Shell API Register Terminal Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера привязки: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkTerminalBooking(Request $request)
    {
        try {
            $request->validate([
                'hwid' => 'required|string'
            ]);

            $computer = \App\Models\Computer::where('hwid', $request->hwid)->first();

            if ($computer) {
                return response()->json([
                    'status' => 'success',
                    'computer_id' => $computer->id,
                    'name' => $computer->name,
                    'type' => $computer->type ?? 'standard'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'computer_id' => 0,
                'message' => 'Оборудование не зарегистрировано'
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Shell API Check Terminal Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'computer_id' => 0], 500);
        }
    }

    // МЕТОД ПОЛНОГО ЗАКРЫТИЯ СЕССИИ ИГРОКА (УДАЛЕНИЕ ИЗ АКТИВНЫХ БРОНЕЙ)[cite: 4]
    public function logout(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'required|integer'
            ]);

            // Находим активную сессию, привязанную к этому терминалу
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($request) {
                    $query->whereJsonContains('pc_ids', (string)$request->terminal_id)
                        ->orWhere('computer_id', $request->terminal_id);
                })->first();

            if ($booking) {
                // Переводим бронь в архивный статус (завершена)
                $booking->update([
                    'status' => 'completed',
                    'end_time' => now()->hour + (now()->minute / 60)
                ]);

                // Если за терминалом были закреплены клубные аккаунты, освобождаем их
                GameAccount::where('assigned_to_terminal', (string)$request->terminal_id)
                    ->update(['status' => 'free', 'assigned_to_terminal' => null]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Сессия успешно закрыта. Терминал освобожден.'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Активная сессия для данного терминала не найдена.'
            ], 404);

        } catch (\Throwable $e) {
            Log::error("Shell API Logout Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера при выходе: ' . $e->getMessage()
            ], 500);
        }
    }
}
