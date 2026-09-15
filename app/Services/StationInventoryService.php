<?php

namespace App\Services;

use App\Models\Computer;
use App\Models\ComputerGame;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

/**
 * Сверка каталога игр с Steam/Epic-манифестами с диска (heartbeat).
 * Совпадение по названию только поднимает verified_at — is_installed не сбрасываем.
 */
class StationInventoryService
{
    /**
     * @param  list<array{p?:string,id?:string,b?:string,n?:string}>  $inventory
     */
    public function sync(Computer $computer, array $inventory): void
    {
        $names = [];
        foreach ($inventory as $row) {
            $n = mb_strtolower(trim((string) ($row['n'] ?? '')));
            if ($n !== '') {
                $names[] = $n;
            }
        }
        if ($names === []) {
            return;
        }

        $gameIds = ComputerGame::query()
            ->where('computer_id', $computer->id)
            ->pluck('game_id');
        if ($gameIds->isEmpty()) {
            return;
        }

        $titles = Game::query()
            ->whereIn('id', $gameIds)
            ->pluck('title', 'id');

        $matched = [];
        foreach ($titles as $gameId => $title) {
            $needle = mb_strtolower(trim((string) $title));
            if ($needle === '' || mb_strlen($needle) < 3) {
                continue;
            }
            foreach ($names as $have) {
                if (str_contains($have, $needle) || str_contains($needle, $have)) {
                    $matched[] = (int) $gameId;
                    break;
                }
            }
        }
        if ($matched === []) {
            return;
        }

        DB::table('computer_games')
            ->where('computer_id', $computer->id)
            ->whereIn('game_id', $matched)
            ->update([
                'is_installed' => true,
                'verified_at' => DB::raw('NOW()'),
                'updated_at' => DB::raw('NOW()'),
            ]);
    }
}
