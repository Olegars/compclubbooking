<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameAccount;
use App\Models\Club;
use App\Models\ClubGame;
use App\Models\Computer;
use App\Models\ComputerGame;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LicenseController extends Controller
{
    public function index()
    {
        // Загружаем игры сразу с их аккаунтами, но тянем только те колонки,
        // которые реально использует страница: остальное (steam_id, токены,
        // кэш машин, таймстампы) на фронт отдавать незачем
        $games = Game::query()
            ->select('id', 'title', 'platform', 'category', 'poster', 'exe_path', 'launch_args')
            ->with(['accounts' => function ($query) {
                // game_id обязателен, иначе Eloquent не сможет связать аккаунты с игрой
                $query->select('id', 'game_id', 'club_id', 'login', 'status')->orderBy('id');
            }, 'clubOffers.club:id,name'])
            ->orderBy('title')
            ->get();

        return Inertia::render('Admin/Licenses', [
            'games' => $games,
            'clubs' => Club::query()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function storeGame(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer|exists:games,id',
            'title' => 'required|string',
            'platform' => 'required|string',
            'poster' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048'
        ]);

        // Ищем игру, если передан id, либо создаем пустой экземпляр модели
        $game = $request->id ? \App\Models\Game::findOrFail($request->id) : new \App\Models\Game();

        $game->title = $request->title;
        $game->platform = $request->platform;
        $game->category = $request->category;
        $game->exe_path = $request->exe_path;
        $game->launch_args = $request->launch_args;

        // Если загружен новый файл постера — пересохраняем его
        if ($request->hasFile('poster')) {
            // Если у игры уже был старый файл постера, его можно удалить с диска перед записью нового
            if ($game->poster && file_exists(public_path($game->poster))) {
                @unlink(public_path($game->poster));
            }

            $file = $request->file('poster');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('games/posters'), $filename);
            $game->poster = 'games/posters/' . $filename;
        }

        $game->save();

        Club::query()->pluck('id')->each(fn ($clubId) => ClubGame::firstOrCreate(
            ['club_id' => $clubId, 'game_id' => $game->id],
            ['billing_mode' => 'free', 'unit_price_minor' => 0, 'is_enabled' => true]
        ));
        Computer::query()->pluck('id')->each(fn ($computerId) => ComputerGame::firstOrCreate(
            ['computer_id' => $computerId, 'game_id' => $game->id],
            ['is_installed' => true, 'verified_at' => now()]
        ));

        return back();
    }

    public function storeAccount(Request $request, Game $game)
    {
        $validated = $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
            'status' => 'nullable|string',
            'club_id' => 'nullable|integer|exists:clubs,id',
        ]);
        $validated['club_id'] ??= Club::query()->value('id');
        $game->accounts()->create($validated);
        return back();
    }

    public function updateOffer(Request $request, Game $game, Club $club)
    {
        $validated = $request->validate([
            'billing_mode' => 'required|in:free,per_seat_hour,per_seat_booking,per_booking_hour,fixed',
            'unit_price_rubles' => 'required|numeric|min:0|max:1000000',
            'billing_unit_minutes' => 'required|integer|min:1|max:1440',
            'is_enabled' => 'required|boolean',
        ]);

        ClubGame::updateOrCreate(
            ['club_id' => $club->id, 'game_id' => $game->id],
            [
                'billing_mode' => $validated['billing_mode'],
                'unit_price_minor' => $validated['billing_mode'] === 'free'
                    ? 0
                    : (int) round($validated['unit_price_rubles'] * 100),
                'billing_unit_minutes' => $validated['billing_unit_minutes'],
                'is_enabled' => $validated['is_enabled'],
                'currency' => 'RUB',
            ]
        );

        return back();
    }

    public function destroyAccount(GameAccount $account)
    {
        if ($account->reservations()->exists()) {
            $account->update(['is_enabled' => false, 'status' => 'maintenance']);
        } else {
            $account->delete();
        }
        return back();
    }

    public function destroyGame(Game $game)
    {
        if ($game->accounts()->whereHas('reservations')->exists()) {
            $game->clubOffers()->update(['is_enabled' => false]);
            $game->accounts()->update(['is_enabled' => false, 'status' => 'maintenance']);
        } else {
            $game->delete();
        }
        return back();
    }
}
