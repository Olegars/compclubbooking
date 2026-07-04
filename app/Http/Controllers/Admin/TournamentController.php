<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Game;
// use App\Models\Computer; // Раскомментируй, если у тебя уже есть модель Computer
use Illuminate\Http\Request;
use Inertia\Inertia;

class TournamentController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Tournaments', [
            // Загружаем турниры вместе с привязанной игрой и считаем количество ПК
            'tournaments' => Tournament::with('game')->withCount('computers')->get(),

            // Загружаем список игр для выпадающего списка
            'games' => Game::select('id', 'title')->get(),

            // Загружаем список ПК (заглушка или реальные данные, если таблица уже есть)
            'computers' => [] // Замени на Computer::all() когда будет готова таблица ПК
        ]);
    }

    public function store(Request $request)
    {
        // Здесь позже напишем логику сохранения
    }

    public function updateStatus(Request $request, Tournament $tournament)
    {
        // Здесь позже напишем логику смены статуса
    }

    public function destroy(Tournament $tournament)
    {
        // Здесь позже напишем логику удаления
    }
}
