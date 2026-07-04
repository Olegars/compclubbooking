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
                'terminal_id' => 'required|integer' // Валидация строго как INTEGER!
            ]);

            $user = User::where('phone', $request->phone)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'Пользователь не найден'], 404);
            }

            // Находим бронь по ПИН-коду
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

            // Сохраняем ЧИСЛОВОЙ ID в computer_id брони
            $booking->update([
                'status' => 'active',
                'start_time' => $floatStartTime,
                'pin_code' => null,
                'computer_id' => $request->terminal_id
            ]);

            // Парсим оставшееся время в ЧЧ:ММ:СС для QML таймера
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

    /**
     * МОДИФИЦИРОВАНО: Получение списка товаров со встроенным статусом активного заказа
     */
    /**
     * МОДИФИЦИРОВАНО: Получение списка товаров со встроенным статусом активного заказа
     */
    /**
     * АВТОМАТИЧЕСКИЙ СТАТУС-МЕНЕДЖЕР ДЛЯ ШЕЛЛА ИГРОКА
     */
    /**
     * АВТОМАТИЧЕСКИЙ СТАТУС-МЕНЕДЖЕР ДЛЯ ШЕЛЛА ИГРОКА
     */
    public function getProducts(\Illuminate\Http\Request $request)
    {
        $terminalId = $request->input('terminal_id', 0);

        $hasActiveOrder = false;
        $statusText = '';
        $rawStatus = '';

        if ($terminalId > 0) {
            $order = \Illuminate\Support\Facades\DB::table('orders')
                ->where('pc_name', 'ПК №' . $terminalId)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($order) {
                $now = now();
                $updatedAt = \Illuminate\Support\Carbon::parse($order->updated_at);
                $secondsSinceUpdate = $now->diffInSeconds($updatedAt);

                if ($order->status === 'pending') {
                    // ЗАМЕНЕНО: Сразу переводим в визуальный статус "В РАБОТЕ"
                    $hasActiveOrder = true;
                    $rawStatus = 'pending';
                    $statusText = 'ЗАКАЗ В РАБОТЕ';

                } elseif ($order->status === 'delivered' && $secondsSinceUpdate <= 20) {
                    $hasActiveOrder = true;
                    $rawStatus = 'completed_holding';
                    $statusText = 'ЗАКАЗ ВЫПОЛНЕН';

                } elseif ($order->status === 'cancelled' && $secondsSinceUpdate <= 20) {
                    $hasActiveOrder = true;
                    $rawStatus = 'cancelled_holding';
                    $statusText = 'ЗАКАЗ ОТМЕНЕН';
                }
            }
        }

        $products = \App\Models\Product::where('stock', '>', 0)
            ->select('id', 'name', 'price', 'category', 'image', 'stock')
            ->get();

        return response()->json([
            'has_active_order' => $hasActiveOrder,
            'status_text'      => $statusText,
            'raw_status'       => $rawStatus,
            'products'         => $products
        ]);
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'terminal_id' => 'required|integer'
        ]);

        try {
            // Ищем активную бронь по числовому ID
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
        $computerId = (string)$request->input('computer_id'); // Приводим к строке один раз

        try {
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($computerId) {
                    $query->whereJsonContains('pc_ids', $computerId)
                        ->orWhere('computer_id', $computerId);
                })
                ->orderBy('created_at', 'desc') // Берем последнюю созданную сессию
                ->first();

            if (!$booking) {
                return response()->json(['status' => 'error', 'message' => 'Активная бронь на ПК не найдена.'], 404);
            }

            $newPin = rand(1000, 9999);
            $booking->update(['pin_code' => $newPin]);

            return response()->json(['status' => 'success', 'pin_code' => $newPin]);
        } catch (\Exception $e) {
            \Log::error("Ошибка паузы: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    public function registerTerminal(Request $request)
    {
        try {
            $request->validate([
                'hwid' => 'required|string',
                'type' => 'required|string'
            ]);

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

            $freeComputer = \App\Models\Computer::whereNull('hwid')
                ->where('type', $request->type)
                ->orderBy('id', 'asc')
                ->first();

            if (!$freeComputer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ошибка: На карте в зоне [' . strtoupper($request->type) . '] нет свободных мест!'
                ], 400);
            }

            $freeComputer->update([
                'hwid' => $request->hwid
            ]);

            return response()->json([
                'status' => 'success',
                'terminal_id' => $freeComputer->id,
                'message' => 'Успешная привязка к свободному месту в зоне ' . strtoupper($request->type) . ' (ПК №' . $freeComputer->name . ')'
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
            \Log::error("Shell API Check Terminal Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'computer_id' => 0], 500);
        }
    }
}
