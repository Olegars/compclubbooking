<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Services\MapPresentationService;
use App\Services\TariffService;

class TerminalController extends Controller
{
    public function index(Request $request, $slug = null)
    {
        if (!$slug) {
            $club = DB::table('clubs')->first();
        } else {
            $club = DB::table('clubs')->where('slug', $slug)->first();
        }

        if (!$club) {
            abort(404, 'Клуб не найден. Проверьте базу данных.');
        }

        $computers = DB::table('computers')->where('club_id', $club->id)->get();

        // Возврат после PIN из SMS: ?seat=&start=&dur= как на /booking.
        $preselectSeatIds = collect(explode(',', (string) $request->query('seat', '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->filter(fn ($id) => $computers->firstWhere('id', $id) !== null)
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
        $start = (string) $request->query('start', '');
        $preselectStart = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $start) ? $start : null;
        $duration = (float) $request->query('dur', 0);
        $preselectDuration = ($duration >= 0.25 && $duration <= 24) ? $duration : null;

        $mapConfig = json_decode($club->map_config, true);
        if (! is_array($mapConfig)) {
            $mapConfig = [];
        }
        $mapConfig = app(MapPresentationService::class)->decorate($mapConfig, (int) $club->id);
        $zoneRects = $mapConfig['zoneRects'] ?? [];
        $club->map_config = $mapConfig;

        return Inertia::render('Booking/BookingView', [
            'isTerminal' => true,
            'clubData' => $club,
            'computersList' => $computers,
            'zonesList' => [],
            'zoneRectsList' => $zoneRects,
            'tariffShowcase' => app(TariffService::class)->showcase((int) $club->id),
            'preselectSeatIds' => $preselectSeatIds,
            'preselectStart' => $preselectStart,
            'preselectDuration' => $preselectDuration,
        ]);
    }
}
