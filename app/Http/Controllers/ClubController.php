<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ClubController extends Controller
{
    public function show($slug = null)
    {
        // 1. Редирект, если слаг не указан
        if (!$slug) {
            $firstClub = DB::table('clubs')->first();

            if (!$firstClub) {
                abort(404, 'Клубы не найдены в базе данных.');
            }
            return redirect('/booking/' . $firstClub->slug);
        }

        // 2. Ищем клуб
        $club = DB::table('clubs')->where('slug', $slug)->first();

        if (!$club) {
            abort(404, 'Клуб с таким адресом не найден.');
        }

        // 3. Получаем данные
        $computers = DB::table('computers')->where('club_id', $club->id)->get();
        $mapConfig = json_decode($club->map_config, true);
        $zoneRects = $mapConfig['zoneRects'] ?? [];

        // 4. Рендерим страницу (ИСПРАВЛЕННЫЙ БЛОК GIZMO)
        return Inertia::render('Booking/BookingView', [
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [],
            'zoneRectsList' => $zoneRects,

            // Same balance field the shell / user cabinet use (deposit, not raw wallet JSON).
            'gizmo' => auth()->check() ? [
                'balance' => auth()->user()->availableBalance(),
                'bonus' => (float) (auth()->user()->wallet?->bonus_balance ?? 0),
                'current_pc' => 'NONE',
            ] : [
                'balance' => 0,
                'bonus' => 0,
                'current_pc' => 'NONE',
            ]
        ]);
    }
}
