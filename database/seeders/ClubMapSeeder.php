<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Space;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Клуб 0451 KOSINO: зоны и комнаты с дампа spaces (15.08.2026). ПК не создаёт.
 */
class ClubMapSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::query()->updateOrCreate(
            ['id' => 1],
            [
                'name' => '0451 KOSINO',
                'slug' => '0451-kosino',
                'type' => 'both',
                'address' => 'Косино',
            ]
        );

        $zoneId = [];
        foreach ($this->zones() as $index => $zone) {
            $row = Zone::query()->updateOrCreate(
                ['slug' => $zone['slug']],
                [
                    'name' => $zone['name'],
                    'color' => $zone['color'],
                    'sort' => ($index + 1) * 10,
                ]
            );
            $zoneId[$zone['slug']] = $row->id;
        }

        Computer::query()->where('club_id', $club->id)->update(['space_id' => null]);
        Space::query()->where('club_id', $club->id)->each(function (Space $space) {
            $space->addons()->detach();
            $space->delete();
        });

        $rects = [];
        foreach ($this->rooms() as $index => $room) {
            $slug = $room['slug'];
            Space::query()->create([
                'club_id' => $club->id,
                'zone_id' => $zoneId[$slug],
                'name' => $room['name'],
                'x' => $room['x'],
                'y' => $room['y'],
                'w' => $room['w'],
                'h' => $room['h'],
                'surcharge_per_hour' => 0,
                'sort' => $index,
            ]);
            $rects[] = [
                'x' => $room['x'],
                'y' => $room['y'],
                'w' => $room['w'],
                'h' => $room['h'],
                'type' => $slug,
            ];
        }

        $club->update([
            'viewbox' => '-10 -10 120 90',
            'map_config' => [
                'viewbox' => '-10 -10 120 90',
                'walls' => [],
                'labels' => [],
                'zoneRects' => $rects,
            ],
        ]);
    }

    /**
     * @return list<array{slug:string,name:string,color:string}>
     */
    private function zones(): array
    {
        return [
            ['slug' => 'singl', 'name' => 'Сингл', 'color' => '#38bdf8'],
            ['slug' => 'duo', 'name' => 'Дуо', 'color' => '#22c55e'],
            ['slug' => 'trio', 'name' => 'Трио', 'color' => '#a3e635'],
            ['slug' => 'kvatro', 'name' => 'Кватро', 'color' => '#fbbf24'],
            ['slug' => 'bootcamp', 'name' => 'Буткамп', 'color' => '#ef4444'],
            ['slug' => 'bootcamp-pro', 'name' => 'Буткамп Pro', 'color' => '#e01b24'],
            ['slug' => 'tv', 'name' => 'ТВ', 'color' => '#a855f7'],
        ];
    }

    /**
     * @return list<array{name:string,slug:string,x:float,y:float,w:float,h:float}>
     */
    private function rooms(): array
    {
        return [
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => -6.6666666666667, 'y' => 10, 'w' => 16.666666666667, 'h' => 10.666666666667],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => -6.6666666666667, 'y' => 20.666666666667, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => -6.6666666666667, 'y' => 30.666666666667, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => -6.6666666666667, 'y' => 40.666666666667, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Буткамп', 'slug' => 'bootcamp', 'x' => 16.666666666667, 'y' => 10, 'w' => 10, 'h' => 30],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 26.666666666667, 'y' => 10, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 26.666666666667, 'y' => 20, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 26.666666666667, 'y' => 30, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 26.666666666667, 'y' => 40, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 26.666666666667, 'y' => 50, 'w' => 16.666666666667, 'h' => 10],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 16.666666666667, 'y' => 40, 'w' => 10, 'h' => 20],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 50, 'y' => 10, 'w' => 10, 'h' => 10],
            ['name' => 'Трио', 'slug' => 'trio', 'x' => 50, 'y' => 20, 'w' => 10, 'h' => 20],
            ['name' => 'Трио', 'slug' => 'trio', 'x' => 50, 'y' => 40, 'w' => 10, 'h' => 20],
            ['name' => 'Трио', 'slug' => 'trio', 'x' => 60, 'y' => 40, 'w' => 10, 'h' => 20],
            ['name' => 'Дуо', 'slug' => 'duo', 'x' => 60, 'y' => 10, 'w' => 10, 'h' => 16.666666666667],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 60, 'y' => 30, 'w' => 10, 'h' => 10],
            ['name' => 'ТВ', 'slug' => 'tv', 'x' => 40, 'y' => -6.6666666666667, 'w' => 23.333333333333, 'h' => 10],
            ['name' => 'ТВ', 'slug' => 'tv', 'x' => 16.666666666667, 'y' => -6.6666666666667, 'w' => 23.333333333333, 'h' => 10],
            ['name' => 'ТВ', 'slug' => 'tv', 'x' => 63.333333333333, 'y' => -6.6666666666667, 'w' => 23.333333333333, 'h' => 10],
            ['name' => 'ТВ', 'slug' => 'tv', 'x' => 86.666666666667, 'y' => -6.6666666666667, 'w' => 20, 'h' => 10],
            ['name' => 'Буткамп Pro', 'slug' => 'bootcamp-pro', 'x' => 76.666666666667, 'y' => 10, 'w' => 26.666666666667, 'h' => 30],
            ['name' => 'Трио', 'slug' => 'trio', 'x' => 76.666666666667, 'y' => 40, 'w' => 26.666666666667, 'h' => 10],
            ['name' => 'Кватро', 'slug' => 'kvatro', 'x' => 76.666666666667, 'y' => 50, 'w' => 26.666666666667, 'h' => 10],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 10, 'y' => 66.666666666667, 'w' => 10, 'h' => 10],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 20, 'y' => 66.666666666667, 'w' => 10, 'h' => 10],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 30, 'y' => 66.666666666667, 'w' => 10, 'h' => 10],
            ['name' => 'Трио', 'slug' => 'trio', 'x' => 40, 'y' => 66.666666666667, 'w' => 20, 'h' => 10],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 60, 'y' => 66.666666666667, 'w' => 10, 'h' => 10],
            ['name' => 'Сингл', 'slug' => 'singl', 'x' => 70, 'y' => 66.666666666667, 'w' => 10, 'h' => 10],
        ];
    }
}
