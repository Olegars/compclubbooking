<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Services\TariffService;

class ClubController extends Controller
{
    public function show(Request $request, $slug = null)
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

        // Переход с лендинга и возврат после входа: ?seat=<id>[,<id>] предвыбирает места.
        $preselectSeatIds = collect(explode(',', (string) $request->query('seat', '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->filter(fn ($id) => $computers->firstWhere('id', $id) !== null)
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        // Возврат после входа по SMS: ?start=HH:MM&dur=<часы> восстанавливает выбор времени.
        $start = (string) $request->query('start', '');
        $preselectStart = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $start) ? $start : null;
        $duration = (float) $request->query('dur', 0);
        $preselectDuration = ($duration >= 0.25 && $duration <= 24) ? $duration : null;

        // 4. Рендерим страницу (ИСПРАВЛЕННЫЙ БЛОК GIZMO)
        return Inertia::render('Booking/BookingView', [
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [],
            'zoneRectsList' => $zoneRects,
            'preselectSeatIds' => $preselectSeatIds,
            'preselectStart' => $preselectStart,
            'preselectDuration' => $preselectDuration,
            'tariffShowcase' => app(TariffService::class)->showcase(),

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
