<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TerminalController extends Controller
{
    public function index($slug = null)
    {
        // 1. Если зашли по короткой ссылке /terminal (слаг не передан)
        if (!$slug) {
            // Берем самый первый клуб из базы
            $club = DB::table('clubs')->first();
        } else {
            // Если передан (например, /terminal/reactor), ищем по нему
            $club = DB::table('clubs')->where('slug', $slug)->first();
        }

        // Если база пустая или ввели кривой адрес
        if (!$club) {
            abort(404, 'Клуб не найден. Проверьте базу данных.');
        }

        // 2. Получаем компьютеры именно этого клуба
        $computers = DB::table('computers')->where('club_id', $club->id)->get();

        // 3. Извлекаем геометрию зон
        $mapConfig = json_decode($club->map_config, true);
        $zoneRects = $mapConfig['zoneRects'] ?? [];

        // 4. Рендерим карту в режиме терминала
        return Inertia::render('BookingView', [
            'isTerminal' => true,
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [],
            'zoneRectsList' => $zoneRects
        ]);
    }
}
