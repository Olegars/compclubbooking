<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Models\User;
use App\Models\Booking;
use App\Models\Product;
use App\Models\GameAccount;
use App\Models\GameAccountMachineCache;
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
                ->whereIn('status', ['paid', 'active', 'completed'])
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
        if (!$game) {
            return response()->json(['status' => 'error', 'message' => 'Игра не найдена'], 404);
        }

        $computer = Computer::find($request->terminal_id);
        if (!$computer) {
            return response()->json(['status' => 'error', 'message' => 'Терминал не найден'], 404);
        }

        $account = GameAccount::where('game_id', $request->game_id)->where('status', 'free')->first();
        if (!$account) {
            return response()->json(['status' => 'error', 'message' => 'Все аккаунты заняты'], 200);
        }

        // JWT с сервера для десктопного Steam не работает (ip_subject чужой машины).
        // Авторизация: machine VDF-кэш + fallback логин/пароль в шелле.
        $account->update([
            'status' => 'in_use',
            'current_pc_id' => $request->terminal_id,
        ]);

        $machineCache = $account->cacheForComputer((int) $request->terminal_id);
        $finalArgs = $game->launch_args ?? $game->args ?? '';

        return response()->json([
            'status'       => 'success',
            'login'        => $account->login,
            'password'     => $account->password,
            'persona_name' => $account->persona_name ?? $account->login,
            'steam_id'     => (string) ($account->steam_id ?? ''),
            'exe_path'     => $game->exe_path,
            'args'         => trim($finalArgs),
            'terminal_id'  => (int) $request->terminal_id,
            'vdf_files'    => [
                'config_vdf'     => $machineCache?->config_vdf,
                'loginusers_vdf' => $machineCache?->loginusers_vdf,
                'local_vdf'      => $machineCache?->local_vdf,
            ],
        ], 200);
    }

    public function freeAccount(Request $request)
    {
        $request->validate([
            'game_id' => 'required|integer',
            'terminal_id' => 'required|integer',
        ]);

        $account = GameAccount::where('game_id', $request->game_id)
            ->where('current_pc_id', $request->terminal_id)
            ->where('status', 'in_use')
            ->first();

        if ($account) {
            $account->update([
                'status' => 'free',
                'current_pc_id' => null
            ]);
        }

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

    public function registerTerminal(Request $request)
    {
        try {
            $request->validate([
                'hwid'      => 'required|string',
                'zone_type' => 'required|string',
                'name'      => 'required|string'
            ]);

            $zoneType = strtolower($request->zone_type);
            $computer = Computer::where('hwid', $request->hwid)->first();

            if ($computer) {
                if ($computer->type !== $zoneType || $computer->name !== $request->name) {
                    $computer->update([
                        'type' => $zoneType,
                        'name' => $request->name
                    ]);
                }

                return response()->json([
                    'status' => 'success',
                    'terminal_id' => $computer->id,
                    'message' => 'Конфигурация обновлена. ПК №' . $computer->name . ' изменен на ' . strtoupper($zoneType)
                ], 200);
            }

            $defaultClubId = \App\Models\Club::first()?->id ?? 1;

            $newComputer = Computer::updateOrCreate(
                ['hwid' => $request->hwid],
                [
                    'club_id'   => $defaultClubId,
                    'type'      => $zoneType,
                    'name'      => $request->name
                ]
            );

            return response()->json([
                'status' => 'success',
                'terminal_id' => $newComputer->id,
                'message' => 'Успешная автоматическая генерация ПК №' . $newComputer->name
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Shell API Register Terminal Error: " . $e->getMessage());
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

            $computer = Computer::where('hwid', $request->hwid)->first();

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

    public function logout(Request $request)
    {
        try {
            $request->validate([
                'terminal_id' => 'required'
            ]);

            $termId = (string)$request->terminal_id;
            $booking = Booking::where('status', 'active')
                ->where(function($query) use ($termId) {
                    $query->whereJsonContains('pc_ids', $termId)
                        ->orWhere('computer_id', (int)$termId);
                })->first();

            if ($booking) {
                $booking->update([
                    'status' => 'completed',
                    'end_time' => now()
                ]);

                GameAccount::where('current_pc_id', (int)$termId)
                    ->update(['status' => 'free', 'current_pc_id' => null]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Сессия успешно закрыта. Терминал освобожден.'
                ], 200);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Active session for this terminal not found.'
            ], 404);

        } catch (\Throwable $e) {
            Log::error("Shell API Logout Error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ошибка сервера при выходе: ' . $e->getMessage()
            ], 500);
        }
    }
    public function updateAccountVdf(Request $request)
    {
        try {
            $request->validate([
                'login'          => 'required|string',
                'terminal_id'    => 'required|integer',
                'config_vdf'     => 'nullable|string',
                'loginusers_vdf' => 'nullable|string',
                'local_vdf'      => 'nullable|string',
                'refresh_token'  => 'nullable|string',
                'steam_id'       => 'nullable|string',
            ]);

            $account = GameAccount::where('login', $request->login)->first();

            if (!$account) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Аккаунт Steam не найден в базе данных'
                ], 404);
            }

            $computer = Computer::find($request->terminal_id);
            if (!$computer) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Терминал не найден'
                ], 404);
            }

            // JWT относится к аккаунту (переносим между машинами как plaintext)
            $accountUpdate = [];
            if ($request->filled('refresh_token')) {
                $accountUpdate['refresh_token'] = $request->refresh_token;
                $accountUpdate['refresh_token_updated_at'] = now();
            }
            if ($request->filled('steam_id')) {
                $accountUpdate['steam_id'] = $request->steam_id;
            }
            if (!empty($accountUpdate)) {
                $account->update($accountUpdate);
            }

            // VDF — строго пара аккаунт × компьютер
            $cache = GameAccountMachineCache::firstOrNew([
                'game_account_id' => $account->id,
                'computer_id' => $computer->id,
            ]);

            if ($request->has('config_vdf')) {
                $cache->config_vdf = $request->config_vdf;
            }
            if ($request->has('loginusers_vdf')) {
                $cache->loginusers_vdf = $request->loginusers_vdf;
            }
            if ($request->has('local_vdf')) {
                $cache->local_vdf = $request->local_vdf;
            }
            $cache->save();

            Log::info("[SHELL-API] Обновлен кэш VDF для {$account->login} на PC#{$computer->id}");

            return response()->json([
                'status'  => 'success',
                'message' => 'Кэш авторизации сохранён для пары аккаунт×машина'
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Shell API Update VDF Error: " . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Ошибка сервера при сохранении кэша: ' . $e->getMessage()
            ], 500);
        }
    }
}
