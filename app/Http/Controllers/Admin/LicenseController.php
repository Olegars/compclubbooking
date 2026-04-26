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
        // Загружаем игры сразу с их аккаунтами
        $games = Game::with('accounts')->get();
        return Inertia::render('Admin/Licenses', ['games' => $games]);
    }

    public function storeGame(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'platform' => 'required|string',
            'category' => 'nullable|string',
            'poster' => 'nullable|string',
            'exe_path' => 'nullable|string',
            'launch_args' => 'nullable|string',
        ]);

        Game::create($validated);
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
