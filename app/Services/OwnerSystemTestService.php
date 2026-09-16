<?php

namespace App\Services;

use App\Models\AiAssistantSetting;
use App\Models\Booking;
use App\Models\Club;
use App\Models\ClubLightSetting;
use App\Models\Computer;
use App\Models\DmxNode;
use App\Models\GuestClip;
use App\Models\Order;
use App\Models\OrderKitchenPrint;
use App\Models\Overlay;
use App\Models\RelayBoard;
use App\Models\SharedFan;
use App\Models\SpaceFan;
use App\Models\SpaceLight;
use App\Models\Tariff;
use App\Models\VideoSurveillanceSetting;
use App\Models\Zone;
use App\Support\AdminLocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Онлайн-проверки контуров клуба + запуск PHPUnit. Только для владельца.
 */
class OwnerSystemTestService
{
    /**
     * @return list<array{id:string,group:string,group_title:string,title:string,description:string,kind:string}>
     */
    public function catalog(?Club $club = null): array
    {
        $items = [];
        foreach ($this->liveMeta() as $id => $meta) {
            $items[] = [
                'id' => $id,
                'group' => $meta['group'],
                'group_title' => $meta['group_title'],
                'title' => $meta['title'],
                'description' => $meta['description'],
                'kind' => 'live',
            ];
        }

        $items[] = [
            'id' => 'phpunit:all',
            'group' => 'phpunit',
            'group_title' => 'Автотесты PHPUnit',
            'title' => 'Весь набор PHPUnit',
            'description' => 'php artisan test — Feature и Unit. Только sqlite :memory:. Идёт в фоне через PHP CLI (не php-fpm), иначе nginx рвёт запрос 504. Прод-базу не трогает. Может занять несколько минут.',
            'kind' => 'phpunit',
        ];

        foreach ($this->phpunitFiles() as $file) {
            $items[] = [
                'id' => 'phpunit:'.$file['id'],
                'group' => 'phpunit',
                'group_title' => 'Автотесты PHPUnit',
                'title' => $file['title'],
                'description' => $file['path'],
                'kind' => 'phpunit',
            ];
        }

        return $items;
    }

    /**
     * @return array{id:string,status:string,message:string,details:list<string>,duration_ms:int,kind:string,title:string}
     */
    public function run(string $id, ?Club $club = null): array
    {
        $club = $club ?: AdminLocation::resolve();
        $started = microtime(true);
        $meta = $this->metaFor($id);

        try {
            if (str_starts_with($id, 'phpunit:')) {
                $outcome = $this->runPhpunit($id);
            } elseif (isset($this->liveMeta()[$id])) {
                $outcome = $this->runLive($id, $club);
            } else {
                $outcome = $this->fail('Неизвестный тест.');
            }
        } catch (Throwable $e) {
            $outcome = $this->fail($e->getMessage(), [class_basename($e)]);
        }

        return [
            'id' => $id,
            'kind' => $meta['kind'] ?? 'live',
            'title' => $meta['title'] ?? $id,
            'status' => $outcome['status'],
            'message' => $outcome['message'],
            'details' => $outcome['details'],
            'duration_ms' => max(
                1,
                isset($outcome['duration_ms']) && (int) $outcome['duration_ms'] > 0
                    ? (int) $outcome['duration_ms']
                    : (int) round((microtime(true) - $started) * 1000)
            ),
        ];
    }

    /**
     * @return array{kind?:string,title?:string}
     */
    private function metaFor(string $id): array
    {
        if (isset($this->liveMeta()[$id])) {
            $row = $this->liveMeta()[$id];

            return ['kind' => 'live', 'title' => $row['title']];
        }
        if ($id === 'phpunit:all') {
            return ['kind' => 'phpunit', 'title' => 'Весь набор PHPUnit'];
        }
        if (str_starts_with($id, 'phpunit:')) {
            $fileId = substr($id, strlen('phpunit:'));
            foreach ($this->phpunitFiles() as $file) {
                if ($file['id'] === $fileId) {
                    return ['kind' => 'phpunit', 'title' => $file['title']];
                }
            }
        }

        return ['kind' => 'live', 'title' => $id];
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function runLive(string $id, ?Club $club): array
    {
        return match ($id) {
            'app' => $this->checkApp(),
            'database' => $this->checkDatabase($club),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'health' => $this->checkHealth(),
            'yookassa' => $this->checkYookassa(),
            'fiscal' => $this->checkFiscal(),
            'legal' => $this->checkLegal(),
            'computers' => $this->checkComputers($club),
            'station_health' => $this->checkStationHealth($club),
            'wol_relay' => $this->checkToken('club.power.wol_relay_token', 'CLUB_WOL_RELAY_TOKEN', 'WOL / isolate'),
            'diskless' => $this->checkDiskless($club),
            'overlays' => $this->checkOverlays(),
            'bookings' => $this->checkBookings($club),
            'tariffs' => $this->checkTariffs(),
            'fans' => $this->checkFans($club),
            'shared_fans' => $this->checkSharedFans($club),
            'lights' => $this->checkLights($club),
            'kitchen_print' => $this->checkKitchenPrint(),
            'video' => $this->checkVideo($club),
            'wifi' => $this->checkWifi(),
            'clips' => $this->checkClips(),
            'ai' => $this->checkAi($club),
            'sms' => $this->checkSms(),
            'avito' => $this->checkAvito(),
            'quickfox' => $this->checkQuickfox(),
            'store_verify' => $this->checkStoreVerify(),
            default => $this->fail('Неизвестный тест.'),
        };
    }

    /**
     * @return array<string, array{group:string,group_title:string,title:string,description:string}>
     */
    private function liveMeta(): array
    {
        return [
            'app' => [
                'group' => 'platform',
                'group_title' => 'Платформа',
                'title' => 'Приложение',
                'description' => 'Ключ, URL, окружение, debug.',
            ],
            'database' => [
                'group' => 'platform',
                'group_title' => 'Платформа',
                'title' => 'База данных',
                'description' => 'Подключение и базовые таблицы текущей локации.',
            ],
            'cache' => [
                'group' => 'platform',
                'group_title' => 'Платформа',
                'title' => 'Кэш',
                'description' => 'Запись и чтение ключа.',
            ],
            'storage' => [
                'group' => 'platform',
                'group_title' => 'Платформа',
                'title' => 'Файлы',
                'description' => 'Диск public: запись и удаление временного файла.',
            ],
            'queue' => [
                'group' => 'platform',
                'group_title' => 'Платформа',
                'title' => 'Очередь',
                'description' => 'Драйвер очереди (sync / redis / database).',
            ],
            'health' => [
                'group' => 'platform',
                'group_title' => 'Платформа',
                'title' => 'Режим обслуживания',
                'description' => 'Не включён ли maintenance.',
            ],
            'yookassa' => [
                'group' => 'payments',
                'group_title' => 'Оплата и чеки',
                'title' => 'ЮKassa',
                'description' => 'Ключи магазина и ping API /me (без создания платежа).',
            ],
            'fiscal' => [
                'group' => 'payments',
                'group_title' => 'Оплата и чеки',
                'title' => 'Касса (KkmServer)',
                'description' => 'FISCAL_ENABLED и URL ККТ. Сам чек не бьём.',
            ],
            'legal' => [
                'group' => 'payments',
                'group_title' => 'Оплата и чеки',
                'title' => 'Юридические реквизиты',
                'description' => 'ИНН, оферта, политика — то, без чего нельзя принимать оплату.',
            ],
            'computers' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'ПК и heartbeat',
                'description' => 'Сколько станций в локации онлайн по last_seen_at.',
            ],
            'station_health' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'Здоровье станций',
                'description' => 'Линк NIC, SMART SSD, мёртвый кэш среди онлайн-ПК.',
            ],
            'wol_relay' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'Токен MikroTik (WOL / isolate)',
                'description' => 'Задан ли CLUB_WOL_RELAY_TOKEN для pull-API роутера.',
            ],
            'diskless' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'Super Client / diskless',
                'description' => 'Зависшие команды CCBoot в очереди heartbeat.',
            ],
            'overlays' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'Оверлеи Shell',
                'description' => 'Есть ли активные экранные блоки терминала.',
            ],
            'bookings' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'Брони',
                'description' => 'Активные и ближайшие сессии по локации.',
            ],
            'tariffs' => [
                'group' => 'shell',
                'group_title' => 'Зал и Shell',
                'title' => 'Тарифы и зоны',
                'description' => 'Есть ли зоны и тарифы для бронирования.',
            ],
            'fans' => [
                'group' => 'climate',
                'group_title' => 'Климат и свет',
                'title' => 'Вентиляторы комнат',
                'description' => 'Платы реле, привязки, desired ≠ applied.',
            ],
            'shared_fans' => [
                'group' => 'climate',
                'group_title' => 'Климат и свет',
                'title' => 'Общая вентиляция',
                'description' => 'Приток/вытяжка и токен shared-реле.',
            ],
            'lights' => [
                'group' => 'climate',
                'group_title' => 'Климат и свет',
                'title' => 'Свет DMX',
                'description' => 'Art-Net узлы, приборы комнат, интерактивные события.',
            ],
            'kitchen_print' => [
                'group' => 'services',
                'group_title' => 'Сервисы зала',
                'title' => 'Кухонный принтер',
                'description' => 'Включена ли автопечать и есть ли зависшие слипы.',
            ],
            'video' => [
                'group' => 'services',
                'group_title' => 'Сервисы зала',
                'title' => 'Видео-метки',
                'description' => 'Настройки NVR и токен LAN-агента меток.',
            ],
            'wifi' => [
                'group' => 'services',
                'group_title' => 'Сервисы зала',
                'title' => 'Гостевой Wi-Fi',
                'description' => 'WIFI_ACCESS_ENABLED и токен grant-targets.',
            ],
            'clips' => [
                'group' => 'services',
                'group_title' => 'Сервисы зала',
                'title' => 'Клипы и Telegram',
                'description' => 'Хранилище хайлайтов и бот для автопостинга.',
            ],
            'ai' => [
                'group' => 'services',
                'group_title' => 'Сервисы зала',
                'title' => 'ИИ-ассистент',
                'description' => 'Тумблер, ключ LLM и речь (без живого вызова модели).',
            ],
            'sms' => [
                'group' => 'services',
                'group_title' => 'Сервисы зала',
                'title' => 'SMS-вход',
                'description' => 'Боевой шлюз пока не подключён — фиксируем тестовый режим.',
            ],
            'avito' => [
                'group' => 'store',
                'group_title' => 'Магазин',
                'title' => 'Avito OAuth',
                'description' => 'Ключи мессенджера Компстор.',
            ],
            'quickfox' => [
                'group' => 'store',
                'group_title' => 'Магазин',
                'title' => 'QuickFox / ITP',
                'description' => 'Домен и логин каталога поставщика.',
            ],
            'store_verify' => [
                'group' => 'store',
                'group_title' => 'Магазин',
                'title' => 'Сверка сборки',
                'description' => 'Токен USB-утилиты check_build.',
            ],
        ];
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkApp(): array
    {
        $details = [
            'env: '.(string) app()->environment(),
            'url: '.(string) config('app.url'),
            'debug: '.(config('app.debug') ? 'on' : 'off'),
            'php: '.PHP_VERSION,
        ];
        if (! filled(config('app.key'))) {
            return $this->fail('Нет APP_KEY.', $details);
        }
        if (app()->isProduction() && config('app.debug')) {
            return $this->fail('На проде включён APP_DEBUG.', $details);
        }
        if (app()->environment('local') || config('app.debug')) {
            return $this->warn('Приложение в dev/debug. Для боя выключите APP_DEBUG.', $details);
        }

        return $this->pass('Ключ и окружение в порядке.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkDatabase(?Club $club): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return $this->fail('Нет соединения с БД: '.$e->getMessage());
        }

        $clubs = Club::query()->count();
        $computers = Computer::query()->when($club, fn ($q) => $q->where('club_id', $club->id))->count();
        $sessionDriver = (string) config('session.driver');
        $sessionTable = (string) config('session.table', 'sessions');
        $details = [
            'driver: '.(string) config('database.default'),
            'клубов: '.$clubs,
            'станций в локации: '.$computers,
            'session: '.$sessionDriver,
        ];
        if ($sessionDriver === 'database' && ! Schema::hasTable($sessionTable)) {
            return $this->fail("Нет таблицы {$sessionTable} (SESSION_DRIVER=database). php artisan migrate.", $details);
        }
        if ($clubs === 0) {
            return $this->fail('Нет ни одной локации (clubs).', $details);
        }

        return $this->pass('БД отвечает.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkCache(): array
    {
        $key = 'owner-system-test:'.bin2hex(random_bytes(4));
        Cache::put($key, 'ok', 30);
        $got = Cache::get($key);
        Cache::forget($key);
        if ($got !== 'ok') {
            return $this->fail('Кэш не вернул записанное значение.', ['store: '.(string) config('cache.default')]);
        }

        return $this->pass('Кэш пишет и читает.', ['store: '.(string) config('cache.default')]);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkStorage(): array
    {
        $path = 'owner-tests/'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.txt';
        try {
            Storage::disk('public')->put($path, 'ok');
            $ok = Storage::disk('public')->get($path) === 'ok';
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            return $this->fail('Диск public недоступен: '.$e->getMessage());
        }
        if (! $ok) {
            return $this->fail('Записанный файл не прочитался.');
        }

        return $this->pass('Диск public доступен на запись.');
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkQueue(): array
    {
        $driver = (string) config('queue.default');
        $details = ['connection: '.$driver];
        if ($driver === 'sync' && app()->isProduction()) {
            return $this->warn('Очередь sync: джобы идут в веб-запросе. Для кассы/Avito лучше redis/database.', $details);
        }

        return $this->pass('Очередь настроена.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkHealth(): array
    {
        if (app()->isDownForMaintenance()) {
            return $this->fail('Сайт в режиме обслуживания.');
        }

        return $this->pass('Сайт не в maintenance. Живой мониторинг WAN — на MikroTik, не здесь.');
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkYookassa(): array
    {
        $shop = trim((string) config('services.yookassa.shop_id'));
        $secret = trim((string) config('services.yookassa.secret_key'));
        if ($shop === '' || $secret === '') {
            return $this->skip('ЮKassa не настроена (YOOKASSA_SHOP_ID / SECRET_KEY).');
        }

        try {
            $response = Http::withBasicAuth($shop, $secret)
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.yookassa.ru/v3/me');
        } catch (Throwable $e) {
            return $this->fail('API ЮKassa недоступен: '.$e->getMessage(), ['shop_id: '.$shop]);
        }

        if (! $response->successful()) {
            return $this->fail('ЮKassa ответила '.$response->status(), [
                'shop_id: '.$shop,
                mb_substr($response->body(), 0, 240),
            ]);
        }

        $account = (string) ($response->json('account_id') ?? $response->json('id') ?? 'ok');

        return $this->pass('ЮKassa отвечает.', ['shop_id: '.$shop, 'account: '.$account]);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkFiscal(): array
    {
        $enabled = (bool) config('fiscal.enabled');
        $url = (string) config('fiscal.kkm.url');
        $details = [
            'enabled: '.($enabled ? 'yes' : 'no'),
            'url: '.$url,
        ];
        if (! $enabled) {
            return $this->warn('Касса выключена — чеки-заглушки. Для боя включите FISCAL_ENABLED.', $details);
        }
        if (! filled($url)) {
            return $this->fail('FISCAL_ENABLED, но нет KKM_SERVER_URL.', $details);
        }

        return $this->pass('Касса включена в конфиге. Чек не отправляли.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkLegal(): array
    {
        $inn = trim((string) config('club.legal.inn'));
        $offer = trim((string) config('club.legal.offer_url'));
        $privacy = trim((string) config('club.legal.privacy_url'));
        $entity = trim((string) config('club.legal.entity'));
        $details = [
            'entity: '.($entity !== '' ? $entity : '—'),
            'inn: '.($inn !== '' ? $inn : '—'),
            'offer: '.($offer !== '' ? $offer : '—'),
            'privacy: '.($privacy !== '' ? $privacy : '—'),
        ];
        $missing = [];
        if ($inn === '') {
            $missing[] = 'ИНН';
        }
        if ($offer === '') {
            $missing[] = 'оферта';
        }
        if ($privacy === '') {
            $missing[] = 'политика';
        }
        if ($missing !== []) {
            return $this->warn('Не заполнено: '.implode(', ', $missing).'.', $details);
        }

        return $this->pass('Реквизиты на месте.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkComputers(?Club $club): array
    {
        if (! $club) {
            return $this->fail('Нет текущей локации.');
        }
        $staleSec = max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
        $cutoff = now()->subSeconds($staleSec);
        $total = Computer::query()->where('club_id', $club->id)->count();
        $online = Computer::query()
            ->where('club_id', $club->id)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $cutoff)
            ->count();
        $booting = Computer::query()->where('club_id', $club->id)->where('power_state', 'booting')->count();
        $error = Computer::query()->where('club_id', $club->id)->where('power_state', 'error')->count();
        $details = [
            'локация: '.$club->name,
            "станций: {$total}, онлайн: {$online}",
            "booting: {$booting}, error WOL: {$error}",
            "stale > {$staleSec} с",
        ];
        if ($total === 0) {
            return $this->warn('В локации нет станций.', $details);
        }
        if ($online === 0) {
            return $this->fail('Ни один шелл не шлёт heartbeat.', $details);
        }
        if ($error > 0) {
            return $this->warn("Онлайн {$online}/{$total}, но есть ошибки WOL.", $details);
        }

        return $this->pass("Онлайн {$online} из {$total}.", $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkStationHealth(?Club $club): array
    {
        if (! $club) {
            return $this->fail('Нет текущей локации.');
        }
        $staleSec = max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
        $cutoff = now()->subSeconds($staleSec);
        $online = Computer::query()
            ->where('club_id', $club->id)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $cutoff)
            ->get(['id', 'name', 'cache_ok', 'nic_link_mbps', 'ssd_health', 'ssd_wear_pct']);
        if ($online->isEmpty()) {
            return $this->skip('Нет онлайн-станций — нечего проверять.');
        }
        $slow = $online->filter(fn ($pc) => $pc->nic_link_mbps !== null && (int) $pc->nic_link_mbps > 0 && (int) $pc->nic_link_mbps <= 100);
        $deadCache = $online->filter(fn ($pc) => $pc->cache_ok === false);
        $badSsd = $online->filter(fn ($pc) => in_array((string) $pc->ssd_health, ['warning', 'unhealthy'], true));
        $details = [
            'онлайн: '.$online->count(),
            'линк ≤100 Мбит: '.$slow->pluck('name')->implode(', ') ?: 'нет',
            'кэш мёртв: '.$deadCache->pluck('name')->implode(', ') ?: 'нет',
            'SMART warning/unhealthy: '.$badSsd->pluck('name')->implode(', ') ?: 'нет',
        ];
        if ($deadCache->isNotEmpty() || $badSsd->isNotEmpty()) {
            return $this->fail('Есть станции с мёртвым кэшем или плохим SSD.', $details);
        }
        if ($slow->isNotEmpty()) {
            return $this->warn('Есть ПК с линком ≤100 Мбит.', $details);
        }

        return $this->pass('У онлайн-станций линк и SSD без аварий.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkToken(string $configKey, string $envName, string $label): array
    {
        $token = trim((string) config($configKey));
        if ($token === '') {
            return $this->fail("Пустой {$envName} — {$label} pull API не пустит агента.");
        }

        return $this->pass("Токен {$label} задан.", ['length: '.strlen($token)]);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkDiskless(?Club $club): array
    {
        $q = Computer::query()->whereNotNull('diskless_command');
        if ($club) {
            $q->where('club_id', $club->id);
        }
        $pending = $q->get(['id', 'name', 'diskless_command', 'diskless_command_at']);
        $stale = $pending->filter(function ($pc) {
            return $pc->diskless_command_at && $pc->diskless_command_at->lt(now()->subMinutes(15));
        });
        $details = [
            'в очереди: '.$pending->count(),
            'старше 15 мин: '.$stale->pluck('name')->implode(', ') ?: 'нет',
        ];
        if ($stale->isNotEmpty()) {
            return $this->fail('Команда Super Client зависла — шелл её не забрал.', $details);
        }
        if ($pending->isNotEmpty()) {
            return $this->warn('Есть незакрытая команда diskless (ещё свежая).', $details);
        }

        return $this->pass('Очередь Super Client пуста.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkOverlays(): array
    {
        $total = Overlay::query()->count();
        $active = Overlay::query()->where('is_active', true)->count();
        $details = ["всего: {$total}, активных: {$active}"];
        if ($total === 0) {
            return $this->warn('Оверлеев нет.', $details);
        }

        return $this->pass('Оверлеи на месте.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkBookings(?Club $club): array
    {
        $q = Booking::query()->where('status', 'active');
        if ($club) {
            $q->whereHas('computer', fn ($c) => $c->where('club_id', $club->id));
        }
        $active = $q->count();
        $bar = Order::query()->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_COOKING])->count();
        $details = [
            'активных сессий: '.$active,
            'бар pending/cooking: '.$bar,
        ];

        return $this->pass('Счётчики броней и бара сняты.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkTariffs(): array
    {
        $zones = Zone::query()->count();
        $tariffs = Tariff::query()->count();
        $details = ["зоны: {$zones}", "тарифы: {$tariffs}"];
        if ($zones === 0 || $tariffs === 0) {
            return $this->fail('Нет зон или тарифов — бронь не собрать.', $details);
        }

        return $this->pass('Зоны и тарифы есть.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkFans(?Club $club): array
    {
        $boards = RelayBoard::query()->when($club, fn ($q) => $q->where('club_id', $club->id));
        $fans = SpaceFan::query()->when($club, fn ($q) => $q->where('club_id', $club->id));
        $boardCount = (clone $boards)->count();
        $fanCount = (clone $fans)->count();
        $mismatch = (clone $fans)
            ->whereNotNull('last_applied_at')
            ->whereColumn('desired_power', '!=', 'applied_power')
            ->count();
        $errors = (clone $fans)->whereNotNull('last_error')->where('last_error', '!=', '')->count();
        $details = [
            "платы: {$boardCount}",
            "вентиляторы комнат: {$fanCount}",
            "desired≠applied: {$mismatch}",
            "с last_error: {$errors}",
        ];
        if ($fanCount === 0) {
            return $this->skip('Вентиляторы комнат не заведены.', $details);
        }
        if ($errors > 0) {
            return $this->fail('Есть вентиляторы с last_error.', $details);
        }
        if ($mismatch > 0) {
            return $this->warn('Желаемая скорость не совпадает с applied — шелл не подтвердил.', $details);
        }

        return $this->pass('Климат комнат без ошибок.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkSharedFans(?Club $club): array
    {
        $token = trim((string) config('fan.shared_relay_token'));
        $count = SharedFan::query()->when($club, fn ($q) => $q->where('club_id', $club->id))->count();
        $details = [
            'приток/вытяжка: '.$count,
            'токен: '.(filled($token) ? 'задан' : 'пусто'),
        ];
        if ($count === 0) {
            return $this->skip('Общих вентиляторов нет.', $details);
        }
        if ($token === '') {
            return $this->fail('Есть shared-вентиляторы, но нет FAN_SHARED / CLUB_WOL токена.', $details);
        }

        return $this->pass('Общая вентиляция и токен на месте.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkLights(?Club $club): array
    {
        $nodes = DmxNode::query()->when($club, fn ($q) => $q->where('club_id', $club->id));
        $lights = SpaceLight::query()->when($club, fn ($q) => $q->where('club_id', $club->id));
        $nodeCount = (clone $nodes)->count();
        $lightCount = (clone $lights)->count();
        $inactive = (clone $nodes)->where('is_active', false)->count();
        $errors = (clone $lights)->whereNotNull('last_error')->where('last_error', '!=', '')->count();
        $events = 0;
        if ($club) {
            $row = ClubLightSetting::query()->where('club_id', $club->id)->first();
            $events = is_array($row?->events) ? count($row->events) : 0;
        }
        $details = [
            "узлы Art-Net: {$nodeCount} (выкл: {$inactive})",
            "приборы комнат: {$lightCount}",
            "с last_error: {$errors}",
            "интерактивных событий: {$events}",
        ];
        if ($lightCount === 0) {
            return $this->skip('Свет не заведён.', $details);
        }
        if ($errors > 0) {
            return $this->fail('Есть приборы с last_error.', $details);
        }

        return $this->pass('DMX узлы и приборы без ошибок.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkKitchenPrint(): array
    {
        $enabled = (bool) config('kitchen_print.enabled');
        $token = trim((string) config('kitchen_print.relay_token'));
        $pending = OrderKitchenPrint::query()
            ->whereIn('status', [OrderKitchenPrint::STATUS_PENDING, OrderKitchenPrint::STATUS_CLAIMED])
            ->count();
        $details = [
            'enabled: '.($enabled ? 'yes' : 'no'),
            'токен: '.(filled($token) ? 'задан' : 'пусто'),
            'очередь pending/claimed: '.$pending,
        ];
        if (! $enabled) {
            return $this->skip('Автопечать кухни выключена.', $details);
        }
        if ($token === '') {
            return $this->fail('Печать включена, но нет токена агента.', $details);
        }
        if ($pending > 8) {
            return $this->warn('Большая очередь слипов — агент, похоже, не забирает.', $details);
        }

        return $this->pass('Кухонная печать настроена.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkVideo(?Club $club): array
    {
        $token = trim((string) config('video_surveillance.relay_token'));
        $settings = $club ? VideoSurveillanceSetting::query()->where('club_id', $club->id)->first() : null;
        $details = [
            'токен агента: '.(filled($token) ? 'задан' : 'пусто'),
            'включено: '.($settings?->is_enabled ? 'yes' : 'no'),
            'провайдер: '.($settings?->provider ?: '—'),
        ];
        if (! $settings || ! $settings->is_enabled) {
            return $this->skip('Видео-метки выключены.', $details);
        }
        if ($token === '') {
            return $this->fail('Метки включены, но нет VIDEO_MARKER / CLUB_WOL токена.', $details);
        }

        return $this->pass('Видео-метки включены, токен есть.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkWifi(): array
    {
        $enabled = (bool) config('wifi_access.enabled');
        $token = trim((string) config('wifi_access.relay_token'));
        $details = [
            'enabled: '.($enabled ? 'yes' : 'no'),
            'station: '.(string) config('wifi_access.station_code'),
            'токен: '.(filled($token) ? 'задан' : 'пусто'),
        ];
        if (! $enabled) {
            return $this->skip('Гостевой Wi-Fi выключен.', $details);
        }
        if ($token === '') {
            return $this->fail('Wi-Fi включён, но нет WIFI_RELAY / CLUB_WOL токена.', $details);
        }

        return $this->pass('Гостевой Wi-Fi настроен.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkClips(): array
    {
        $count = GuestClip::query()->count();
        $bot = trim((string) config('services.telegram.bot_token'));
        $chat = trim((string) config('services.telegram.clips_chat_id'));
        $details = [
            'клипов в БД: '.$count,
            'telegram bot: '.(filled($bot) ? 'задан' : 'нет'),
            'bot username: '.(filled(config('services.telegram.bot_username')) ? (string) config('services.telegram.bot_username') : 'нет'),
            'clips chat: '.(filled($chat) ? 'задан' : 'нет'),
        ];
        if ($bot !== '' && $chat === '' && ! filled(config('services.telegram.bot_username'))) {
            return $this->warn('Бот есть, нет канала и @username для лички гостя.', $details);
        }

        return $this->pass('Контур клипов живой.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkAi(?Club $club): array
    {
        $settings = $club
            ? AiAssistantSetting::query()->where('club_id', $club->id)->first()
            : AiAssistantSetting::query()->orderBy('id')->first();
        $details = [
            'enabled: '.($settings ? (($settings->is_enabled ? 'on' : 'off')) : 'нет записи'),
            'llm: '.($settings?->resolvedLlmProvider() ?: 'env'),
        ];
        if (! $settings) {
            $envKey = trim((string) config('ai_assistant.deepseek.api_key'));
            $speech = trim((string) config('ai_assistant.yandex.api_key'));
            if ($envKey === '') {
                return $this->skip('Нет настроек клуба и нет DEEPSEEK_API_KEY.', $details);
            }
            if ($speech === '') {
                return $this->warn('LLM из .env есть, ключа SpeechKit нет.', $details);
            }

            return $this->pass('Ключи из .env, запись клуба ещё не создавалась.', $details);
        }
        if (! $settings->is_enabled) {
            return $this->warn('Выключен тумблером в админке.', $details);
        }
        if ($settings->resolvedLlmApiKey() === '') {
            return $this->warn('Нет ключа LLM (DeepSeek/OpenAI).', $details);
        }
        if (! $settings->hasSpeechCredentials()) {
            return $this->warn('Нет ключа речи (SpeechKit/OpenAI).', $details);
        }

        return $this->pass('Ключи LLM и речи есть, тумблер включён.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkSms(): array
    {
        return $this->skip('Боевой SMS-шлюз не подключён. Вход по тестовому коду 0451 (см. логи).');
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkAvito(): array
    {
        $id = trim((string) config('store.avito.client_id'));
        $secret = trim((string) config('store.avito.client_secret'));
        $user = (int) config('store.avito.user_id');
        $details = [
            'client_id: '.(filled($id) ? 'задан' : 'нет'),
            'user_id: '.$user,
        ];
        if ($id === '' || $secret === '') {
            return $this->skip('Avito OAuth не задан.', $details);
        }

        return $this->pass('Ключи Avito на месте.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkQuickfox(): array
    {
        $domain = trim((string) config('store.quickfox.domain'));
        $login = trim((string) config('store.quickfox.login'));
        $details = [
            'domain: '.($domain !== '' ? $domain : '—'),
            'login: '.($login !== '' ? $login : '—'),
        ];
        if ($domain === '' || $login === '') {
            return $this->skip('QuickFox не настроен.', $details);
        }

        return $this->pass('Каталог поставщика сконфигурирован.', $details);
    }

    /**
     * @return array{status:string,message:string,details:list<string>}
     */
    private function checkStoreVerify(): array
    {
        $token = trim((string) config('store.build_verify_token'));
        if ($token === '') {
            return $this->skip('STORE_BUILD_VERIFY_TOKEN пуст — USB-сверка сборки не авторизуется.');
        }

        return $this->pass('Токен сверки сборки задан.', ['length: '.strlen($token)]);
    }

    /**
     * HTTP-ветка: на Linux стартуем CLI в фоне и отдаём running, пока nginx не убил запрос 504.
     *
     * @return array{status:string,message:string,details:list<string>,duration_ms?:int}
     */
    private function runPhpunit(string $id): array
    {
        if (app()->runningUnitTests()) {
            return $this->skip('Вложенный PHPUnit изнутри теста не запускаем.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->runPhpunitSync($id);
        }

        return $this->runPhpunitBackground($id);
    }

    /**
     * @return array{status:string,message:string,details:list<string>,duration_ms?:int}
     */
    public function runPhpunitSync(string $id): array
    {
        $prepared = $this->phpunitPrepare($id);
        if ($prepared['error'] !== null) {
            return $prepared['error'];
        }

        $php = (string) $prepared['php'];
        $args = $prepared['args'] ?? [];
        if ($php === '' || $args === []) {
            return $this->fail('Не собран запуск PHPUnit.');
        }
        $timeout = $id === 'phpunit:all' ? 600 : 120;

        $lock = Cache::lock('owner-system-tests-phpunit', $timeout + 30);
        if (! $lock->get()) {
            return $this->warn('Уже идёт другой прогон PHPUnit. Подождите.');
        }

        try {
            $process = new Process($args, base_path(), $this->phpunitProcessEnv(), null, $timeout);
            $process->run();
            $output = trim($process->getOutput()."\n".$process->getErrorOutput());
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
            $tail = array_merge(['php: '.$php], array_slice($lines, -12));
            if ($process->isSuccessful()) {
                return $this->pass('Автотесты прошли.', $tail);
            }

            return $this->fail('Автотесты упали (код '.$process->getExitCode().').', $tail);
        } catch (Throwable $e) {
            return $this->fail('Не удалось запустить PHPUnit: '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status:string,message:string,details:list<string>,duration_ms?:int}
     */
    private function runPhpunitBackground(string $id): array
    {
        $job = Cache::get($this->phpunitJobCacheKey());
        if (is_array($job)) {
            $jobId = (string) ($job['id'] ?? '');
            $pid = (int) ($job['pid'] ?? 0);
            $startedAt = (int) ($job['started_at'] ?? time());

            if ($pid > 0 && $this->phpunitPidIsAlive($pid)) {
                if ($jobId === $id) {
                    return $this->running('PHPUnit идёт в фоне.', [
                        'pid: '.$pid,
                        'секунд: '.(time() - $startedAt),
                    ]);
                }

                return $this->warn('Уже идёт другой прогон PHPUnit. Подождите.', [
                    'id: '.$jobId,
                    'pid: '.$pid,
                ]);
            }

            $result = null;
            $resultKey = $this->phpunitResultCacheKey($jobId !== '' ? $jobId : $id);
            for ($i = 0; $i < 6; $i++) {
                $cached = Cache::get($resultKey);
                if (is_array($cached)) {
                    $result = $cached;
                    Cache::forget($resultKey);
                    break;
                }
                if ($pid > 0 && $this->phpunitPidIsAlive($pid)) {
                    return $this->running('PHPUnit идёт в фоне.', [
                        'pid: '.$pid,
                        'секунд: '.(time() - $startedAt),
                    ]);
                }
                usleep(200000);
            }
            Cache::forget($this->phpunitJobCacheKey());

            if ($jobId === $id && is_array($result) && isset($result['status'], $result['message'])) {
                return [
                    'status' => (string) $result['status'],
                    'message' => (string) $result['message'],
                    'details' => array_values(array_filter(
                        is_array($result['details'] ?? null) ? $result['details'] : [],
                        fn ($line) => is_string($line),
                    )),
                    'duration_ms' => (int) ($result['duration_ms'] ?? 0),
                ];
            }

            if ($jobId === $id) {
                return $this->fail('PHPUnit оборвался без отчёта.', [
                    'pid: '.$pid,
                    'лог: storage/logs/owner-phpunit.log',
                ]);
            }
        }

        $prepared = $this->phpunitPrepare($id);
        if ($prepared['error'] !== null) {
            return $prepared['error'];
        }

        $php = (string) $prepared['php'];
        if ($php === '') {
            return $this->fail('Не найден PHP CLI.');
        }

        Cache::forget($this->phpunitResultCacheKey($id));
        $pid = $this->spawnOwnerPhpunit($php, $id);
        if ($pid < 1) {
            return $this->fail(
                'Не удалось запустить PHPUnit в фоне (nohup/shell_exec).',
                ['php: '.$php],
            );
        }

        Cache::put($this->phpunitJobCacheKey(), [
            'id' => $id,
            'pid' => $pid,
            'started_at' => time(),
        ], 900);

        return $this->running('PHPUnit запущен в фоне — nginx больше не режет 504.', [
            'pid: '.$pid,
            'php: '.$php,
        ]);
    }

    /**
     * @return array{php:string,args:list<string>,error:null}|array{php:?string,args:?list<string>,error:array{status:string,message:string,details:list<string>}}
     */
    private function phpunitPrepare(string $id): array
    {
        $artisan = base_path('artisan');
        if (! is_file($artisan)) {
            return ['php' => null, 'args' => null, 'error' => $this->fail('Не найден artisan.')];
        }

        $phpunit = base_path('vendor/bin/phpunit');
        $phpunitBat = base_path('vendor/bin/phpunit.bat');
        if (! is_file($phpunit) && ! is_file($phpunitBat)) {
            return [
                'php' => null,
                'args' => null,
                'error' => $this->skip('PHPUnit не установлен (нет vendor/bin/phpunit). На сервере без require-dev автотесты не гоняются.'),
            ];
        }

        $php = $this->phpCliBinary();
        if ($php === null) {
            return [
                'php' => null,
                'args' => null,
                'error' => $this->fail(
                    'Не найден PHP CLI. Из php-fpm PHP_BINARY — это FPM, artisan test так не запустить. Задайте PHP_CLI_BINARY в .env (например /usr/bin/php).',
                    array_slice($this->phpCliCandidates(), 0, 8),
                ),
            ];
        }

        $args = [$php, $artisan, 'test', '--no-ansi'];
        if ($id !== 'phpunit:all') {
            $fileId = substr($id, strlen('phpunit:'));
            $match = null;
            foreach ($this->phpunitFiles() as $file) {
                if ($file['id'] === $fileId) {
                    $match = $file;
                    break;
                }
            }
            if (! $match) {
                return ['php' => null, 'args' => null, 'error' => $this->fail('Файл автотеста не найден.')];
            }
            $args[] = $match['path'];
        }

        return ['php' => $php, 'args' => $args, 'error' => null];
    }

    private function spawnOwnerPhpunit(string $php, string $id): int
    {
        if (! function_exists('shell_exec')) {
            return 0;
        }

        $log = storage_path('logs/owner-phpunit.log');
        $cmd = sprintf(
            'nohup %s %s owner:run-phpunit %s >> %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($id),
            escapeshellarg($log),
        );
        $out = @shell_exec($cmd);

        return (int) trim((string) $out);
    }

    public function phpunitJobCacheKey(): string
    {
        return 'owner-system-tests-phpunit-job';
    }

    public function phpunitResultCacheKey(string $id): string
    {
        return 'owner-system-tests-phpunit-result:'.$id;
    }

    public function phpunitPidIsAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (is_dir('/proc/'.$pid)) {
            return true;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }

    /**
     * @return list<array{id:string,title:string,path:string}>
     */
    public function phpunitFiles(): array
    {
        $out = [];
        foreach (['Feature', 'Unit'] as $suite) {
            $dir = base_path('tests/'.$suite);
            if (! is_dir($dir)) {
                continue;
            }
            $files = glob($dir.DIRECTORY_SEPARATOR.'*Test.php') ?: [];
            sort($files);
            foreach ($files as $full) {
                $base = basename($full, '.php');
                $rel = 'tests/'.$suite.'/'.$base.'.php';
                $out[] = [
                    'id' => strtolower($suite).'-'.$base,
                    'title' => $suite.': '.$this->humanTestName($base),
                    'path' => $rel,
                ];
            }
        }

        return $out;
    }

    /**
     * Env дочернего artisan test: перебить pgsql/compclub_db, которые FPM отдал в putenv.
     *
     * @return array<string, string>
     */
    public function phpunitProcessEnv(): array
    {
        $inherited = [];
        $fromEnv = getenv();
        if (is_array($fromEnv)) {
            foreach ($fromEnv as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $inherited[$key] = $value;
                }
            }
        }

        return array_merge($inherited, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'DATABASE_URL' => '',
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ]);
    }

    /**
     * PHP CLI для artisan test. PHP_BINARY под FPM — это php-fpm (код 64 и справка FPM).
     */
    public function phpCliBinary(): ?string
    {
        foreach ($this->phpCliCandidates() as $bin) {
            if (! is_file($bin)) {
                continue;
            }
            $sapi = $this->phpBinarySapi($bin);
            if (in_array($sapi, ['cli', 'phpdbg'], true)) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function phpCliCandidates(?string $phpBinary = null, ?string $sapi = null, ?string $bindir = null): array
    {
        $phpBinary = $phpBinary ?? PHP_BINARY;
        $sapi = strtolower($sapi ?? PHP_SAPI);
        $bindir = $bindir ?? PHP_BINDIR;

        $raw = [];
        $env = trim((string) config('app.php_cli_binary', ''));
        if ($env !== '') {
            $raw[] = $env;
        }

        if (! $this->binaryNameLooksLikeFpmOrCgi($phpBinary)
            && in_array($sapi, ['cli', 'phpdbg', 'cli-server'], true)) {
            $raw[] = $phpBinary;
        }

        foreach ($this->phpCliSiblingsOf($phpBinary) as $sibling) {
            $raw[] = $sibling;
        }

        if ($bindir !== '') {
            $sep = str_contains($bindir, '\\') && ! str_contains($bindir, '/') ? '\\' : '/';
            $raw[] = $bindir.$sep.'php';
            $raw[] = $bindir.$sep.'php.exe';
            $parent = dirname($bindir);
            if ($parent !== '' && $parent !== '.' && $parent !== $bindir) {
                $raw[] = $parent.$sep.'bin'.$sep.'php';
                $raw[] = $parent.$sep.'bin'.$sep.'php.exe';
            }
        }

        $finder = (new PhpExecutableFinder)->find(false);
        if (is_string($finder) && $finder !== '') {
            $raw[] = $finder;
        }

        $raw[] = '/usr/bin/php';
        $raw[] = '/usr/local/bin/php';

        $out = [];
        $seen = [];
        foreach ($raw as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $key = strtolower($path);
            if (isset($seen[$key]) || $this->binaryNameLooksLikeFpmOrCgi($path)) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $path;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function phpCliSiblingsOf(string $binary): array
    {
        if ($binary === '' || ! $this->binaryNameLooksLikeFpmOrCgi($binary)) {
            return [];
        }

        $sep = str_contains($binary, '\\') && ! str_contains($binary, '/') ? '\\' : '/';
        $base = basename(str_replace('\\', '/', $binary));
        $cliName = (string) preg_replace('/php-fpm/i', 'php', $base);
        $cliName = (string) preg_replace('/php-cgi/i', 'php', $cliName);
        $dir = dirname($binary);
        $parent = dirname($dir);

        $dirs = [$dir];
        if ($parent !== '' && $parent !== '.' && $parent !== $dir) {
            $dirs[] = $parent.$sep.'bin';
        }

        $names = array_unique([$cliName, 'php', 'php.exe']);
        $out = [];
        foreach ($dirs as $folder) {
            foreach ($names as $name) {
                $out[] = $folder.$sep.$name;
            }
        }

        return $out;
    }

    private function binaryNameLooksLikeFpmOrCgi(string $path): bool
    {
        $base = strtolower(basename(str_replace('\\', '/', $path)));

        return str_contains($base, 'php-fpm') || str_contains($base, 'php-cgi');
    }

    private function phpBinarySapi(string $path): ?string
    {
        try {
            $process = new Process([$path, '-r', 'echo PHP_SAPI;']);
            $process->setTimeout(8);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $sapi = strtolower(trim($process->getOutput()));

            return $sapi !== '' ? $sapi : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function humanTestName(string $class): string
    {
        $name = preg_replace('/Test$/', '', $class) ?: $class;
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?: $name;

        return $spaced;
    }

    /**
     * @param  list<string>  $details
     * @return array{status:string,message:string,details:list<string>}
     */
    private function pass(string $message, array $details = []): array
    {
        return ['status' => 'pass', 'message' => $message, 'details' => $details];
    }

    /**
     * @param  list<string>  $details
     * @return array{status:string,message:string,details:list<string>}
     */
    private function fail(string $message, array $details = []): array
    {
        return ['status' => 'fail', 'message' => $message, 'details' => $details];
    }

    /**
     * @param  list<string>  $details
     * @return array{status:string,message:string,details:list<string>}
     */
    private function warn(string $message, array $details = []): array
    {
        return ['status' => 'warn', 'message' => $message, 'details' => $details];
    }

    /**
     * @param  list<string>  $details
     * @return array{status:string,message:string,details:list<string>}
     */
    private function skip(string $message, array $details = []): array
    {
        return ['status' => 'skip', 'message' => $message, 'details' => $details];
    }

    /**
     * @param  list<string>  $details
     * @return array{status:string,message:string,details:list<string>}
     */
    private function running(string $message, array $details = []): array
    {
        return ['status' => 'running', 'message' => $message, 'details' => $details];
    }
}
