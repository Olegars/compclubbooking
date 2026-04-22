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

        // 4. Рендерим страницу (ИСПРАВЛЕН ПУТЬ)
        // Теперь смотрим в папку resources/js/Pages/Booking/BookingView.vue
        return Inertia::render('Booking/BookingView', [
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [], // Сюда потом прилетит список зон из Гизмо
            'zoneRectsList' => $zoneRects,
            // Добавляем заглушку для gizmo, чтобы фронт не падал при чтении props.gizmo.balance
            'gizmo' => [
                'balance' => "0.00",
                'bonus' => 0,
                'current_pc' => 'NONE'
            ]
        ]);
    }
}
