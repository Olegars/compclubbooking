<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TerminalController extends Controller
{
    public function index($slug = null)
    {
        // 1. Поиск клуба
        if (!$slug) {
            $club = DB::table('clubs')->first();
        } else {
            $club = DB::table('clubs')->where('slug', $slug)->first();
        }

        if (!$club) {
            abort(404, 'Клуб не найден. Проверьте базу данных.');
        }

        // 2. Получаем компьютеры
        $computers = DB::table('computers')->where('club_id', $club->id)->get();

        // 3. Извлекаем геометрию зон
        $mapConfig = json_decode($club->map_config, true);
        $zoneRects = $mapConfig['zoneRects'] ?? [];

        // 4. Рендерим карту (ИСПРАВЛЕН ПУТЬ К ВЬЮШКЕ)
        return Inertia::render('Booking/BookingView', [
            'isTerminal' => true,
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [],
            'zoneRectsList' => $zoneRects,
            // Добавляем заглушку для gizmo, чтобы терминал не падал
            'gizmo' => [
                'balance' => "0.00",
                'bonus' => 0,
                'current_pc' => 'NONE'
            ]
        ]);
    }
}
