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
                DB::table('clubs')->where('id', $clubId)->update([
                    'map_config' => json_encode($config),
                    'updated_at' => now(),
                ]);

                // 2. УДАЛЯЕМ все старые компьютеры этого клуба
                DB::table('computers')->where('club_id', $clubId)->delete();

                // 3. ЗАПИСЫВАЕМ новые компьютеры с нуля
                if (!empty($pcs)) {
                    $insertData = [];
                    foreach ($pcs as $pc) {
                        $insertData[] = [
                            'club_id'    => $clubId,
                            'name'       => $pc['name'],
                            'x'          => $pc['x'],
                            'y'          => $pc['y'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    DB::table('computers')->insert($insertData);
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
