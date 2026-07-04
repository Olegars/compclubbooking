<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            // --- STEAM ---
            ['title' => 'Counter-Strike 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'id_app' => '730'],
            ['title' => 'Dota 2', 'platform' => 'Steam', 'category' => 'MOBA', 'id_app' => '570'],
            ['title' => 'PUBG: BATTLEGROUNDS', 'platform' => 'Steam', 'category' => 'Battle Royale', 'id_app' => '578080'],
            ['title' => 'Apex Legends', 'platform' => 'Steam', 'category' => 'Battle Royale', 'id_app' => '1172470'],
            ['title' => 'Rust', 'platform' => 'Steam', 'category' => 'Выживание', 'id_app' => '252490'],
            ['title' => 'War Thunder', 'platform' => 'Steam', 'category' => 'Симуляторы', 'id_app' => '236390'],
            ['title' => 'Team Fortress 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'id_app' => '440'],
            ['title' => 'Left 4 Dead 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'id_app' => '550'],
            ['title' => 'Baldur\'s Gate 3', 'platform' => 'Steam', 'category' => 'RPG', 'id_app' => '1086940'],
            ['title' => 'Cyberpunk 2077', 'platform' => 'Steam', 'category' => 'RPG', 'id_app' => '1091500'],

            // --- RIOT ---
            ['title' => 'League of Legends', 'platform' => 'Riot', 'category' => 'MOBA', 'exe' => 'C:\Riot Games\League of Legends\LeagueClient.exe'],
            ['title' => 'Valorant', 'platform' => 'Riot', 'category' => 'Шутеры', 'exe' => 'C:\Riot Games\Riot Client\RiotClientServices.exe', 'args' => '--launch-product=valorant --launch-patchline=live'],

            // --- BATTLE.NET ---
            ['title' => 'World of Warcraft', 'platform' => 'Battle.net', 'category' => 'MMORPG', 'exe' => 'D:\Games\World of Warcraft\_retail_\WoW.exe'],
            ['title' => 'Overwatch 2', 'platform' => 'Battle.net', 'category' => 'Шутеры', 'exe' => 'D:\Games\Overwatch\_retail_\Overwatch.exe'],
            ['title' => 'Diablo IV', 'platform' => 'Battle.net', 'category' => 'RPG', 'exe' => 'D:\Games\Diablo IV\Diablo IV.exe'],

            // --- EPIC ---
            ['title' => 'Fortnite', 'platform' => 'Epic', 'category' => 'Battle Royale', 'exe' => 'D:\Games\Fortnite\FortniteGame\Binaries\Win64\FortniteClient-Win64-Shipping.exe'],
            ['title' => 'Grand Theft Auto V', 'platform' => 'Epic', 'category' => 'Экшен', 'exe' => 'D:\Games\GTAV\PlayGTAV.exe'],
            ['title' => 'Rocket League', 'platform' => 'Epic', 'category' => 'Спорт', 'exe' => 'D:\Games\rocketleague\Binaries\Win64\RocketLeague.exe'],

            // --- ДРУГОЕ / УТИЛИТЫ ---
            ['title' => 'World of Tanks', 'platform' => 'Lesta', 'category' => 'Симуляторы', 'exe' => 'D:\Games\World_of_Tanks_RU\win64\WoT.exe'],
            ['title' => 'Genshin Impact', 'platform' => 'HoYoverse', 'category' => 'RPG', 'exe' => 'C:\Program Files\Genshin Impact\Genshin Impact Game\GenshinImpact.exe'],
            ['title' => 'Telegram', 'platform' => 'Desktop', 'category' => 'Утилиты', 'exe' => 'C:\Users\Admin\AppData\Roaming\Telegram Desktop\Telegram.exe'],
            ['title' => 'Discord', 'platform' => 'Desktop', 'category' => 'Утилиты', 'exe' => 'C:\Users\Admin\AppData\Local\Discord\Update.exe', 'args' => '--processStart Discord.exe'],
            ['title' => 'Google Chrome', 'platform' => 'Desktop', 'category' => 'Браузеры', 'exe' => 'C:\Program Files\Google\Chrome\Application\chrome.exe'],
            ['title' => 'OBS Studio', 'platform' => 'Desktop', 'category' => 'Утилиты', 'exe' => 'C:\Program Files\obs-studio\bin\64bit\obs64.exe'],
            ['title' => 'Miracle Box', 'platform' => 'Desktop', 'category' => 'Утилиты', 'exe' => 'C:\Program Files\Miracle\Miracle.exe'],
        ];

        foreach ($games as $game) {
            DB::table('games')->insert([
                'title'       => $game['title'],
                'platform'    => $game['platform'],
                'category'    => $game['category'],
                'poster'      => 'games/posters/' . strtolower(str_replace([' ', ':', '\''], ['_', '', ''], $game['title'])) . '.jpg',
                'icon'        => 'games/icons/' . strtolower(str_replace([' ', ':', '\''], ['_', '', ''], $game['title'])) . '.png',
                // Для стима формируем путь через steam.exe, для остальных берем прямо из массива
                'exe_path'    => $game['platform'] === 'Steam' ? 'C:\Program Files (x86)\Steam\steam.exe' : ($game['exe'] ?? ''),
                'launch_args' => $game['platform'] === 'Steam' ? "-applaunch {$game['id_app']} -novid" : ($game['args'] ?? ''),
                'created_at'  => now(),
            ]);
        }
    }
}
