<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MapController extends Controller
{
    public function save(Request $request)
    {
        $clubId = $request->input('club_id', 4);
        $config = $request->input('config');
        $pcs = $request->input('pcs'); // Массив компьютеров из Vue

        try {
            DB::transaction(function () use ($clubId, $config, $pcs) {
                // 1. Обновляем конфиг стен и зон в таблице клубов
                $viewbox = is_array($config) ? ($config['viewbox'] ?? null) : null;
                DB::table('clubs')->where('id', $clubId)->update(array_filter([
                    'map_config' => json_encode($config),
                    'viewbox' => is_string($viewbox) && $viewbox !== '' ? $viewbox : null,
                    'updated_at' => now(),
                ], fn ($v) => $v !== null));

                // 2. УДАЛЯЕМ все старые компьютеры этого клуба
                DB::table('computers')->where('club_id', $clubId)->delete();

                // 3. ЗАПИСЫВАЕМ новые компьютеры с нуля
                if (!empty($pcs)) {
                    $insertData = [];
                    foreach ($pcs as $pc) {
                        $kind = in_array($pc['kind'] ?? 'pc', ['pc', 'tv', 'ps5'], true)
                            ? $pc['kind']
                            : 'pc';
                        $insertData[] = [
                            'club_id'    => $clubId,
                            'name'       => $pc['name'],
                            'x'          => $pc['x'],
                            'y'          => $pc['y'],
                            'kind'       => $kind,
                            'booth_id'   => !empty($pc['booth_id']) ? (string) $pc['booth_id'] : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    DB::table('computers')->insert($insertData);

                    $now = now();
                    $installations = [];
                    foreach (DB::table('computers')->where('club_id', $clubId)->pluck('id') as $computerId) {
                        foreach (DB::table('games')->pluck('id') as $gameId) {
                            $installations[] = [
                                'computer_id' => $computerId,
                                'game_id' => $gameId,
                                'is_installed' => true,
                                'verified_at' => $now,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                    if ($installations !== []) {
                        DB::table('computer_games')->insert($installations);
                    }
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Старые данные удалены, новые записаны успешно!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function getMap(Request $request)
    {
        $clubId = $request->query('club_id');

        // Получаем клуб (конфиг стен и зон)
        $club = DB::table('clubs')->where('id', $clubId)->first();

        // Получаем список компьютеров этого клуба
        $pcs = DB::table('computers')->where('club_id', $clubId)->get();

        if (!$club) {
            return response()->json(['error' => 'Club not found'], 404);
        }
        return response()->json([
            'config' => json_decode($club->map_config),
            'pcs' => $pcs
        ]);
    }
}
