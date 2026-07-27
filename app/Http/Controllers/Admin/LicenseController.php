<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameAccount;
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
                $query->select('id', 'game_id', 'login', 'status')->orderBy('id');
            }])
            ->orderBy('title')
            ->get();

        return Inertia::render('Admin/Licenses', ['games' => $games]);
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

        return back();
    }

    public function storeAccount(Request $request, Game $game)
    {
        $game->accounts()->create($request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
            'status' => 'nullable|string'
        ]));
        return back();
    }

    public function destroyAccount(GameAccount $account)
    {
        $account->delete();
        return back();
    }

    public function destroyGame(Game $game)
    {
        $game->delete(); // Каскадно удалит и аккаунты
        return back();
    }
}
