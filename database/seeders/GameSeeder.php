<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\ClubGame;
use App\Models\Game;
use App\Models\QuickApp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GameSeeder extends Seeder
{
    private const STEAM_EXE = 'C:\\Program Files (x86)\\Steam\\steam.exe';

    public function run(): void
    {
        $clubIds = Club::query()->pluck('id');

        foreach ($this->games() as $row) {
            $appId = $row['appid'] ?? null;
            $slug = Str::slug($row['title']);
            $poster = $this->resolvePoster($slug, $appId, $row['art'] ?? null);

            $game = Game::query()->updateOrCreate(
                ['title' => $row['title'], 'platform' => $row['platform']],
                [
                    'category' => $row['category'],
                    'poster' => $poster,
                    'exe_path' => $row['exe'] ?? ($appId ? self::STEAM_EXE : ''),
                    'launch_args' => $row['args'] ?? ($appId ? '-applaunch '.$appId.' -novid' : ''),
                ]
            );

            foreach ($clubIds as $clubId) {
                ClubGame::query()->firstOrCreate(
                    ['club_id' => $clubId, 'game_id' => $game->id],
                    ['billing_mode' => 'free', 'unit_price_minor' => 0, 'is_enabled' => true]
                );
            }
        }

        foreach ($this->programs() as $index => $app) {
            QuickApp::query()->updateOrCreate(
                ['title' => $app['title']],
                [
                    'exe_path' => $app['exe'],
                    'launch_args' => $app['args'] ?? '',
                    'sort_order' => ($index + 1) * 10,
                    'is_enabled' => true,
                ]
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function games(): array
    {
        return [
            ['title' => 'Counter-Strike 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '730'],
            ['title' => 'Dota 2', 'platform' => 'Steam', 'category' => 'MOBA', 'appid' => '570'],
            ['title' => 'PUBG: BATTLEGROUNDS', 'platform' => 'Steam', 'category' => 'Battle Royale', 'appid' => '578080'],
            ['title' => 'Apex Legends', 'platform' => 'Steam', 'category' => 'Battle Royale', 'appid' => '1172470'],
            ['title' => 'Rust', 'platform' => 'Steam', 'category' => 'Выживание', 'appid' => '252490'],
            ['title' => 'Deadlock', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '1422450'],
            ['title' => 'Marvel Rivals', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '2767030'],
            ['title' => 'The Finals', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '2073850'],
            ['title' => 'Delta Force', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '2507950'],
            ['title' => 'Tom Clancy\'s Rainbow Six Siege', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '359550'],
            ['title' => 'Call of Duty', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '1938090'],
            ['title' => 'Battlefield 2042', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '1517290'],
            ['title' => 'Helldivers 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '553850'],
            ['title' => 'Hunt: Showdown 1896', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '594650'],
            ['title' => 'Team Fortress 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '440'],
            ['title' => 'Left 4 Dead 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '550'],
            ['title' => 'War Thunder', 'platform' => 'Steam', 'category' => 'Симуляторы', 'appid' => '236390'],
            ['title' => 'World of Tanks', 'platform' => 'Steam', 'category' => 'Симуляторы', 'appid' => '1407200'],
            ['title' => 'Euro Truck Simulator 2', 'platform' => 'Steam', 'category' => 'Симуляторы', 'appid' => '227300'],
            ['title' => 'Assetto Corsa', 'platform' => 'Steam', 'category' => 'Гонки', 'appid' => '244210'],
            ['title' => 'Forza Horizon 5', 'platform' => 'Steam', 'category' => 'Гонки', 'appid' => '1551360'],
            ['title' => 'Rocket League', 'platform' => 'Steam', 'category' => 'Спорт', 'appid' => '252950'],
            ['title' => 'EA SPORTS FC 25', 'platform' => 'Steam', 'category' => 'Спорт', 'appid' => '2669320'],
            ['title' => 'Grand Theft Auto V', 'platform' => 'Steam', 'category' => 'Приключения', 'appid' => '271590'],
            ['title' => 'Red Dead Redemption 2', 'platform' => 'Steam', 'category' => 'Приключения', 'appid' => '1174180'],
            ['title' => 'Cyberpunk 2077', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '1091500'],
            ['title' => 'Baldur\'s Gate 3', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '1086940'],
            ['title' => 'The Witcher 3: Wild Hunt', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '292030'],
            ['title' => 'Elden Ring', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '1245620'],
            ['title' => 'Black Myth: Wukong', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '2358720'],
            ['title' => 'The Elder Scrolls V: Skyrim Special Edition', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '489830'],
            ['title' => 'Path of Exile', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '238960'],
            ['title' => 'Path of Exile 2', 'platform' => 'Steam', 'category' => 'RPG', 'appid' => '2694490'],
            ['title' => 'Lost Ark', 'platform' => 'Steam', 'category' => 'MMORPG', 'appid' => '1599340'],
            ['title' => 'New World: Aeternum', 'platform' => 'Steam', 'category' => 'MMORPG', 'appid' => '1063730'],
            ['title' => 'Final Fantasy XIV', 'platform' => 'Steam', 'category' => 'MMORPG', 'appid' => '39210'],
            ['title' => 'Guild Wars 2', 'platform' => 'Steam', 'category' => 'MMORPG', 'appid' => '1284210'],
            ['title' => 'Destiny 2', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '1085660'],
            ['title' => 'Warframe', 'platform' => 'Steam', 'category' => 'Шутеры', 'appid' => '230410'],
            ['title' => 'Palworld', 'platform' => 'Steam', 'category' => 'Выживание', 'appid' => '1623730'],
            ['title' => 'ARK: Survival Evolved', 'platform' => 'Steam', 'category' => 'Выживание', 'appid' => '346110'],
            ['title' => 'DayZ', 'platform' => 'Steam', 'category' => 'Выживание', 'appid' => '221100'],
            ['title' => 'Once Human', 'platform' => 'Steam', 'category' => 'Выживание', 'appid' => '2139460'],
            ['title' => 'Don\'t Starve Together', 'platform' => 'Steam', 'category' => 'Выживание', 'appid' => '322330'],
            ['title' => 'Terraria', 'platform' => 'Steam', 'category' => 'Песочница', 'appid' => '105600'],
            ['title' => 'Stardew Valley', 'platform' => 'Steam', 'category' => 'Песочница', 'appid' => '413150'],
            ['title' => 'Lethal Company', 'platform' => 'Steam', 'category' => 'Хоррор', 'appid' => '1966720'],
            ['title' => 'Dead by Daylight', 'platform' => 'Steam', 'category' => 'Хоррор', 'appid' => '381210'],
            ['title' => 'Phasmophobia', 'platform' => 'Steam', 'category' => 'Хоррор', 'appid' => '739630'],
            ['title' => 'Tekken 8', 'platform' => 'Steam', 'category' => 'Файтинг', 'appid' => '1778820'],
            ['title' => 'Street Fighter 6', 'platform' => 'Steam', 'category' => 'Файтинг', 'appid' => '1364780'],
            ['title' => 'Age of Empires IV', 'platform' => 'Steam', 'category' => 'Стратегии', 'appid' => '1466860'],
            ['title' => 'Sid Meier\'s Civilization VI', 'platform' => 'Steam', 'category' => 'Стратегии', 'appid' => '289070'],

            ['title' => 'Valorant', 'platform' => 'Riot', 'category' => 'Шутеры',
                'exe' => 'C:\\Riot Games\\Riot Client\\RiotClientServices.exe',
                'args' => '--launch-product=valorant --launch-patchline=live',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Valorant_cover_art.jpg'],
            ['title' => 'League of Legends', 'platform' => 'Riot', 'category' => 'MOBA',
                'exe' => 'C:\\Riot Games\\Riot Client\\RiotClientServices.exe',
                'args' => '--launch-product=league_of_legends --launch-patchline=live',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/League_of_Legends_2019_cover.jpg'],
            ['title' => 'Fortnite', 'platform' => 'Epic', 'category' => 'Battle Royale',
                'exe' => 'C:\\Program Files (x86)\\Epic Games\\Launcher\\Portal\\Binaries\\Win64\\EpicGamesLauncher.exe',
                'args' => 'com.epicgames.launcher://apps/fn%3A4fe75bbc5a674f4f9b356b5c90567da5%3AFortnite?action=launch',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Fortnite_cover_art.jpg'],
            ['title' => 'Overwatch 2', 'platform' => 'Battle.net', 'category' => 'Шутеры',
                'exe' => 'C:\\Program Files (x86)\\Battle.net\\Battle.net Launcher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Overwatch_2_cover_art.jpg'],
            ['title' => 'World of Warcraft', 'platform' => 'Battle.net', 'category' => 'MMORPG',
                'exe' => 'C:\\Program Files (x86)\\Battle.net\\Battle.net Launcher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/World_of_Warcraft.png'],
            ['title' => 'Diablo IV', 'platform' => 'Battle.net', 'category' => 'RPG',
                'exe' => 'C:\\Program Files (x86)\\Battle.net\\Battle.net Launcher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Diablo_IV_cover_art.png'],
            ['title' => 'StarCraft II', 'platform' => 'Battle.net', 'category' => 'Стратегии',
                'exe' => 'C:\\Program Files (x86)\\Battle.net\\Battle.net Launcher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/StarCraft_II_-_Box_Art.jpg'],
            ['title' => 'Hearthstone', 'platform' => 'Battle.net', 'category' => 'Стратегии',
                'exe' => 'C:\\Program Files (x86)\\Battle.net\\Battle.net Launcher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Hearthstone_logo.png'],
            ['title' => 'Genshin Impact', 'platform' => 'HoYoPlay', 'category' => 'RPG',
                'exe' => 'C:\\Program Files\\HoYoPlay\\launcher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Genshin_Impact_cover.jpg'],
            ['title' => 'Minecraft', 'platform' => 'Minecraft', 'category' => 'Песочница',
                'exe' => 'C:\\Program Files (x86)\\Minecraft Launcher\\MinecraftLauncher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Minecraft_cover.png'],
            ['title' => 'Escape from Tarkov', 'platform' => 'Battlestate', 'category' => 'Шутеры',
                'exe' => 'C:\\Battlestate Games\\BsgLauncher\\BsgLauncher.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Escape_from_Tarkov.jpg'],
            ['title' => 'Roblox', 'platform' => 'Roblox', 'category' => 'Песочница',
                'exe' => 'C:\\Program Files (x86)\\Roblox\\RobloxPlayerBeta.exe',
                'art' => 'https://en.wikipedia.org/wiki/Special:FilePath/Roblox_logo_and_wordmark.svg'],
        ];
    }

    /**
     * @return list<array{title:string,exe:string,args?:string}>
     */
    private function programs(): array
    {
        return [
            ['title' => 'Discord', 'exe' => 'C:\\Users\\user\\AppData\\Local\\Discord\\Update.exe', 'args' => '--processStart Discord.exe'],
            ['title' => 'Telegram', 'exe' => 'C:\\Users\\user\\AppData\\Roaming\\Telegram Desktop\\Telegram.exe'],
            ['title' => 'Google Chrome', 'exe' => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'],
            ['title' => 'Яндекс Браузер', 'exe' => 'C:\\Users\\user\\AppData\\Local\\Yandex\\YandexBrowser\\Application\\browser.exe'],
            ['title' => 'FACEIT', 'exe' => 'C:\\Users\\user\\AppData\\Local\\FACEIT\\FACEIT.exe'],
            ['title' => 'Spotify', 'exe' => 'C:\\Users\\user\\AppData\\Roaming\\Spotify\\Spotify.exe'],
            ['title' => 'OBS Studio', 'exe' => 'C:\\Program Files\\obs-studio\\bin\\64bit\\obs64.exe'],
            ['title' => 'TeamSpeak 3', 'exe' => 'C:\\Program Files\\TeamSpeak 3 Client\\ts3client_win64.exe'],
            ['title' => 'Steam', 'exe' => self::STEAM_EXE],
            ['title' => 'Epic Games Launcher', 'exe' => 'C:\\Program Files (x86)\\Epic Games\\Launcher\\Portal\\Binaries\\Win64\\EpicGamesLauncher.exe'],
            ['title' => 'Battle.net', 'exe' => 'C:\\Program Files (x86)\\Battle.net\\Battle.net Launcher.exe'],
            ['title' => 'MSI Afterburner', 'exe' => 'C:\\Program Files (x86)\\MSI Afterburner\\MSIAfterburner.exe'],
            ['title' => '7-Zip', 'exe' => 'C:\\Program Files\\7-Zip\\7zFM.exe'],
            ['title' => 'VLC', 'exe' => 'C:\\Program Files\\VideoLAN\\VLC\\vlc.exe'],
        ];
    }

    private function resolvePoster(string $slug, ?string $appId, ?string $artUrl): ?string
    {
        $urls = [];
        if ($appId) {
            $urls[] = 'https://cdn.cloudflare.steamstatic.com/steam/apps/'.$appId.'/library_600x900.jpg';
            $urls[] = 'https://cdn.cloudflare.steamstatic.com/steam/apps/'.$appId.'/header.jpg';
        }
        if ($artUrl) {
            $urls[] = $artUrl;
        }
        if ($urls === []) {
            return null;
        }

        if (app()->environment('testing')) {
            return $urls[0];
        }

        $dir = public_path('games/posters');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $rel = 'games/posters/'.$slug.'.jpg';
        $abs = public_path($rel);
        if (is_file($abs) && filesize($abs) > 2000) {
            return $rel;
        }

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 CompClubGameSeeder'])
                    ->get($url);
                if (! $response->successful() || strlen($response->body()) < 2000) {
                    continue;
                }
                file_put_contents($abs, $response->body());

                return $rel;
            } catch (Throwable) {
                continue;
            }
        }

        return $urls[0];
    }
}
