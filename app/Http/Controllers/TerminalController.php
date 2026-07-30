<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Services\MapPresentationService;
use App\Services\TariffService;

class TerminalController extends Controller
{
    public function index($slug = null)
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
        ]);
    }
}
