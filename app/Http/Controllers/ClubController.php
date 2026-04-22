<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ClubController extends Controller
{
    public function show($slug = null)
    {
        // 1. Если зашли просто на /booking (без слага)
        if (!$slug) {
            $firstClub = DB::table('clubs')->first();

            if (!$firstClub) {
                abort(404, 'Клубы не найдены в базе данных. Проверьте сидеры.');
            }
            return redirect('/booking/' . $firstClub->slug);
        }

        // 2. Ищем клуб по слагу
        $club = DB::table('clubs')->where('slug', $slug)->first();

        if (!$club) {
            abort(404, 'Клуб с таким адресом не найден.');
        }

        // 3. Получаем компьютеры
        $computers = DB::table('computers')->where('club_id', $club->id)->get();

        // 4. Извлекаем zoneRects из БД
        // Декодируем строку JSON в ассоциативный массив
        $mapConfig = json_decode($club->map_config, true);

        // Ищем ключ 'zoneRects'. Если его нет в базе, отдаем пустой массив, чтобы не сломать фронтенд
        $zoneRects = $mapConfig['zoneRects'] ?? [];

        // 5. Рендерим страницу
        return Inertia::render('BookingView', [
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [],
            'zoneRectsList' => $zoneRects // Данные теперь летят из базы!
        ]);
    }
}
