<?php

namespace App\Mcp;

use App\Models\AcBan;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\FaceitIdentity;
use App\Models\GoldenImageRevision;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StoreBuiltPc;
use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreEstimate;
use App\Models\StoreEstimateItem;
use App\Models\StoreOrder;
use App\Models\StoreSupplierCatalogProduct;
use App\Services\BookingSessionTimingService;
use App\Services\ComputerPowerService;
use App\Services\Faceit\FaceitIdentityService;
use App\Services\Faceit\FaceitRateLimited;
use App\Services\OwnerSystemTestService;
use App\Services\ReactorAc\AcGate;
use App\Services\StoreAssemblyCaptureService;
use App\Services\StoreBuildVerifyService;
use App\Services\TaxReportService;
use App\Support\AdminLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class McpCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function resourcesFor(McpActor $actor): array
    {
        return array_values(array_map(
            fn (array $spec) => [
                'uri' => $spec['uri'],
                'name' => $spec['name'],
                'description' => $spec['description'],
                'mimeType' => 'application/json',
            ],
            array_filter($this->resources(), fn (array $spec) => $this->allows($actor, $spec['gate']))
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toolsFor(McpActor $actor): array
    {
        $tools = [];
        foreach ($this->tools() as $spec) {
            if (! $this->allows($actor, $spec['gate'])) {
                continue;
            }
            $schema = $spec['schema'];
            if ($spec['confirm'] !== false) {
                $schema['properties']['confirm'] = [
                    'type' => 'boolean',
                    'description' => 'false — превью без записи, true — выполнить после превью с теми же аргументами.',
                ];
            }
            if ($spec['gate'] !== 'player') {
                $schema['properties']['location_id'] = [
                    'type' => 'integer',
                    'description' => 'Локация. Имеет силу только у владельца.',
                ];
            }
            $tools[] = [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'inputSchema' => $schema,
                'annotations' => [
                    'readOnlyHint' => (bool) $spec['read_only'],
                    'destructiveHint' => (bool) $spec['destructive'],
                    'openWorldHint' => (bool) $spec['open_world'],
                ],
            ];
        }

        return $tools;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function promptsFor(McpActor $actor): array
    {
        $prompts = [];
        foreach ($this->prompts() as $spec) {
            if (! $this->allows($actor, $spec['gate'])) {
                continue;
            }
            $prompts[] = [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'arguments' => $spec['arguments'],
            ];
        }

        return $prompts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTool(string $name): ?array
    {
        foreach ($this->tools() as $spec) {
            if ($spec['name'] === $name) {
                return $spec;
            }
        }

        return null;
    }

    public function allows(McpActor $actor, string $gate): bool
    {
        if ($gate === 'player') {
            return $actor->isPlayer();
        }
        $admin = $actor->admin;
        if ($actor->isPlayer() || ! $admin) {
            return false;
        }

        return match ($gate) {
            'club_ops' => $admin->isOwner()
                || $admin->role === Admin::ROLE_SUPERVISOR
                || ($admin->role === Admin::ROLE_ADMIN && $admin->hasFullClubOps()),
            'owner_supervisor' => $admin->isOwner() || $admin->role === Admin::ROLE_SUPERVISOR,
            'owner' => $admin->isOwner(),
            'store_read' => $admin->canAccessStore() && ($admin->isOwner() || $admin->isStoreRole()),
            'store_manage' => $admin->canManageStoreCatalog(),
            'store_verify' => $admin->isOwner() || in_array($admin->role, ['assembler', 'store_manager', 'senior_manager'], true),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function needsConfirm(string $name, array $args): bool
    {
        $tool = $this->findTool($name);
        if ($tool === null || $tool['confirm'] === false) {
            return false;
        }
        if ($tool['confirm'] === true) {
            return true;
        }

        $contour = strtolower(trim((string) ($args['test_contour'] ?? '')));

        return in_array($contour, ['all', 'fiscal', 'yookassa'], true);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function assertCallable(string $name, array $args): void
    {
        if ($name !== 'run_system_diagnostics') {
            return;
        }
        $contour = strtolower(trim((string) ($args['test_contour'] ?? '')));
        if (str_starts_with($contour, 'phpunit')) {
            throw new McpCallException('PHPUnit из MCP не запускается. Живые проверки — здесь, автотесты только кнопкой на /admin/system-tests.');
        }
    }

    /**
     * @return array{contents: list<array{uri:string,mimeType:string,text:string}>}
     */
    public function read(McpActor $actor, string $uri): array
    {
        $spec = null;
        foreach ($this->resources() as $candidate) {
            if ($candidate['uri'] === $uri) {
                $spec = $candidate;
                break;
            }
        }
        if ($spec === null) {
            throw new McpCallException('Ресурс не найден.');
        }
        if (! $this->allows($actor, $spec['gate'])) {
            $this->logRead($actor, $uri, 'denied');
            throw new McpCallException('Нет доступа к ресурсу.', -32001);
        }

        $payload = match ($uri) {
            'club://status/summary' => $this->clubSummary($actor, []),
            'club://computers/health' => $this->computerHealth($actor, []),
            'club://orders/queue' => $this->ordersQueue($actor, []),
            'club://incidents/open' => $this->openIncidents($actor, []),
            'store://warehouse/stock' => $this->warehouse($actor, []),
            'store://estimates/open' => $this->openEstimates($actor, []),
            'ac://fair-play/status' => $this->fairPlay($actor, []),
            'system://logs/errors' => $this->errorLogs(),
            'player://me' => $this->playerMe($actor),
            'player://club/public' => $this->publicOccupancy(),
            default => throw new McpCallException('Ресурс не найден.'),
        };
        $this->logRead($actor, $uri, 'ok');

        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => $this->json($payload),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function preview(McpActor $actor, string $name, array $args): array
    {
        return match ($name) {
            'restart_computer_session' => $this->previewRelease($actor, $args),
            'trigger_wol' => $this->previewWake($actor, $args),
            'create_store_estimate' => $this->previewEstimate($actor, $args),
            'check_build_verification' => $this->previewVerify($actor, $args),
            'sync_faceit_elo' => $this->previewFaceit($args),
            'run_system_diagnostics' => $this->previewDiagnostics($actor, $args),
            default => ['note' => 'Подтверждение выполнит инструмент без дополнительного превью.'],
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(McpActor $actor, string $name, array $args): array
    {
        return match ($name) {
            'restart_computer_session' => $this->release($actor, $args),
            'trigger_wol' => $this->wake($actor, $args),
            'get_rollback_markers' => $this->rollbackMarkers($actor, $args),
            'create_store_estimate' => $this->createEstimate($actor, $args),
            'check_build_verification' => $this->verifyBuild($actor, $args),
            'validate_ac_session' => $this->validateAc($args),
            'sync_faceit_elo' => $this->syncFaceit($args),
            'run_system_diagnostics' => $this->diagnostics($actor, $args),
            'preview_tax_usn' => $this->tax($args),
            default => throw new McpCallException('Инструмент не найден.'),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function prompt(McpActor $actor, string $name, array $args): array
    {
        $spec = null;
        foreach ($this->prompts() as $candidate) {
            if ($candidate['name'] === $name) {
                $spec = $candidate;
                break;
            }
        }
        if ($spec === null) {
            throw new McpCallException('Промпт не найден.');
        }
        if (! $this->allows($actor, $spec['gate'])) {
            throw new McpCallException('Нет доступа к промпту.', -32001);
        }

        $text = match ($name) {
            'analyze_pc_degradation' => $this->promptDegradation($actor, $args),
            'shift_handover_report' => $this->promptHandover($actor),
            'tax_usn_calculator' => $this->promptTax($args),
            default => throw new McpCallException('Промпт не найден.'),
        };

        return [
            'description' => $spec['description'],
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => $text],
            ]],
        ];
    }

    /**
     * @return list<array{uri:string,name:string,description:string,gate:string}>
     */
    private function resources(): array
    {
        return [
            [
                'uri' => 'club://status/summary',
                'name' => 'Сводка клуба',
                'description' => 'Занятость ПК, фискальные пополнения за сегодня, открытые смены, очередь и инциденты. Без телефонов.',
                'gate' => 'club_ops',
            ],
            [
                'uri' => 'club://computers/health',
                'name' => 'Здоровье станций',
                'description' => 'Линк, flap патч-корда, SMART SSD, кэш, Super Client, последний BSOD. Без MAC и IP.',
                'gate' => 'club_ops',
            ],
            [
                'uri' => 'club://orders/queue',
                'name' => 'Очередь заказов',
                'description' => 'Заказы бара в pending и cooking. Без телефона гостя.',
                'gate' => 'club_ops',
            ],
            [
                'uri' => 'club://incidents/open',
                'name' => 'Открытые инциденты',
                'description' => 'Незакрытые инциденты локации и общие без привязки к ПК. Текст маскируется.',
                'gate' => 'club_ops',
            ],
            [
                'uri' => 'store://warehouse/stock',
                'name' => 'Склад комплектующих',
                'description' => 'Позиции in_stock, reserved и repair. Закупочная цена только у тех, кто ведёт склад.',
                'gate' => 'store_read',
            ],
            [
                'uri' => 'store://estimates/open',
                'name' => 'Открытые сметы',
                'description' => 'Сметы до конвертации. Сборщик видит только ready. Телефон клиента маскируется.',
                'gate' => 'store_read',
            ],
            [
                'uri' => 'ac://fair-play/status',
                'name' => 'REACTOR AC',
                'description' => 'Режим, число онлайн-сессий и активных банов по скоупам. Файлы evidence и connect_token не отдаются.',
                'gate' => 'owner_supervisor',
            ],
            [
                'uri' => 'system://logs/errors',
                'name' => 'Хвост ошибок',
                'description' => 'Последние строки laravel, синка каталога и Avito с error/exception. Секреты вырезаются.',
                'gate' => 'owner_supervisor',
            ],
            [
                'uri' => 'player://me',
                'name' => 'Мой профиль',
                'description' => 'Имя, баланс и своя активная сессия. Телефон и почта не отдаются.',
                'gate' => 'player',
            ],
            [
                'uri' => 'player://club/public',
                'name' => 'Занятость зала',
                'description' => 'Счётчики ПК по локациям, без имён гостей и номеров станций.',
                'gate' => 'player',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        $object = static fn (array $properties, array $required = []): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];

        return [
            [
                'name' => 'restart_computer_session',
                'description' => 'Закрыть залипшую сессию и освободить ПК. Только владелец, как кнопка на дашборде. Причина пишется в журнал MCP, не в карточку гостя.',
                'gate' => 'owner',
                'confirm' => true,
                'read_only' => false,
                'destructive' => true,
                'open_world' => false,
                'schema' => $object([
                    'computer_id' => ['type' => 'integer'],
                    'reason' => ['type' => 'string', 'description' => 'Зачем закрываем, 3–240 символов.'],
                ], ['computer_id', 'reason']),
            ],
            [
                'name' => 'trigger_wol',
                'description' => 'Поставить выключенный ПК в очередь Wake-on-LAN. Пакет шлёт MikroTik, не этот сервер. Онлайн-шелл и ПК с живой сессией не трогаем.',
                'gate' => 'club_ops',
                'confirm' => true,
                'read_only' => false,
                'destructive' => false,
                'open_world' => false,
                'schema' => $object([
                    'computer_id' => ['type' => 'integer'],
                    'all_offline' => ['type' => 'boolean', 'description' => 'Все офлайн-ПК локации без активной сессии, не больше 40.'],
                ]),
            ],
            [
                'name' => 'get_rollback_markers',
                'description' => 'Ревизии золотого образа для отката. Тела файлов манифеста не отдаются.',
                'gate' => 'club_ops',
                'confirm' => false,
                'read_only' => true,
                'destructive' => false,
                'open_world' => false,
                'schema' => $object([
                    'computer_id' => ['type' => 'integer'],
                ], ['computer_id']),
            ],
            [
                'name' => 'create_store_estimate',
                'description' => 'Черновик сметы из каталога QuickFox/ITP по SKU. Клиента по телефону только ищет, карточку не создаёт. Сборщик сметы не пишет.',
                'gate' => 'store_manage',
                'confirm' => true,
                'read_only' => false,
                'destructive' => false,
                'open_world' => false,
                'schema' => $object([
                    'title' => ['type' => 'string'],
                    'client_phone' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                    'components' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'integer'],
                                'qty' => ['type' => 'integer'],
                            ],
                            'required' => ['sku'],
                        ],
                    ],
                ], ['components']),
            ],
            [
                'name' => 'check_build_verification',
                'description' => 'Сверка серийников сборки, тот же путь, что POST /api/build-verify. Сборщик — только своя сборка или свой заказ. Может дописать названия, если это включено у сверки.',
                'gate' => 'store_verify',
                'confirm' => true,
                'read_only' => false,
                'destructive' => true,
                'open_world' => false,
                'schema' => $object([
                    'built_pc_id' => ['type' => 'integer'],
                    'order_id' => ['type' => 'integer'],
                    'components' => ['type' => 'array'],
                ], ['components']),
            ],
            [
                'name' => 'validate_ac_session',
                'description' => 'Проверить живую сессию REACTOR AC для steam_id и match_id. Токен не выдаёт и не показывает.',
                'gate' => 'owner_supervisor',
                'confirm' => false,
                'read_only' => true,
                'destructive' => false,
                'open_world' => false,
                'schema' => $object([
                    'steam_id' => ['type' => 'string'],
                    'match_id' => ['type' => 'string'],
                ], ['steam_id', 'match_id']),
            ],
            [
                'name' => 'sync_faceit_elo',
                'description' => 'Один игрок: подтянуть Elo и бан через Data API. Не гоняет весь reactor:sync-faceit.',
                'gate' => 'owner_supervisor',
                'confirm' => true,
                'read_only' => false,
                'destructive' => false,
                'open_world' => true,
                'schema' => $object([
                    'user_id' => ['type' => 'integer'],
                    'faceit_player_id' => ['type' => 'string'],
                ]),
            ],
            [
                'name' => 'run_system_diagnostics',
                'description' => 'Живые проверки владельца с /admin/system-tests. Контуры: db, cache, fiscal, hardware, all или id проверки. PHPUnit запрещён. fiscal и all ждут confirm, потому что fiscal ходит в ЮKassa.',
                'gate' => 'owner',
                'confirm' => 'conditional',
                'read_only' => true,
                'destructive' => false,
                'open_world' => true,
                'schema' => $object([
                    'test_contour' => ['type' => 'string'],
                ], ['test_contour']),
            ],
            [
                'name' => 'preview_tax_usn',
                'description' => 'Черновик УСН 6% за год из TaxReportService. ФИО сотрудников не отдаются. Это не декларация.',
                'gate' => 'owner',
                'confirm' => false,
                'read_only' => true,
                'destructive' => false,
                'open_world' => false,
                'schema' => $object([
                    'year' => ['type' => 'integer'],
                ]),
            ],
        ];
    }

    /**
     * @return list<array{name:string,description:string,gate:string,arguments:list<array<string,mixed>>}>
     */
    private function prompts(): array
    {
        return [
            [
                'name' => 'analyze_pc_degradation',
                'description' => 'Рекомендация по патч-корду, линку и SSD на цифрах здоровья станций. Без цифр замену не предлагать.',
                'gate' => 'club_ops',
                'arguments' => [[
                    'name' => 'computer_id',
                    'description' => 'Если пусто — все станции, которые уже вышли за порог.',
                    'required' => false,
                ]],
            ],
            [
                'name' => 'shift_handover_report',
                'description' => 'Черновик передачи смены: инциденты, очередь, деградация станций. Смену не закрывает.',
                'gate' => 'club_ops',
                'arguments' => [],
            ],
            [
                'name' => 'tax_usn_calculator',
                'description' => 'Черновик налоговой базы УСН 6% с вычетом взносов. ФИО нет. Не подаёт отчёт.',
                'gate' => 'owner',
                'arguments' => [[
                    'name' => 'year',
                    'description' => 'Год. Пусто — текущий.',
                    'required' => false,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function clubId(McpActor $actor, array $args): int
    {
        $admin = $actor->admin;
        if (! $admin) {
            throw new McpCallException('У токена нет локации.');
        }
        if (! empty($args['location_id']) && ! $admin->isOwner()) {
            throw new McpCallException('location_id доступен только владельцу.');
        }
        if ($admin->isOwner() && ! empty($args['location_id'])) {
            $club = Club::operational()->whereKey((int) $args['location_id'])->first();
            if (! $club) {
                throw new McpCallException('Локация не найдена.');
            }

            return (int) $club->id;
        }

        $id = AdminLocation::id($admin);
        if (! $id) {
            throw new McpCallException('У токена нет локации.');
        }

        return (int) $id;
    }

    private function computerInClub(int $computerId, int $clubId): Computer
    {
        $computer = Computer::query()->whereKey($computerId)->first();
        if (! $computer || (int) $computer->club_id !== $clubId) {
            throw new McpCallException('ПК в этой локации не найден.');
        }

        return $computer;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function clubSummary(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $computers = Computer::query()->where('club_id', $clubId)->get(['id', 'status', 'last_seen_at']);
        $stale = max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
        $online = $computers->filter(function (Computer $pc) use ($stale) {
            return $pc->last_seen_at !== null && $pc->last_seen_at->greaterThan(now()->subSeconds($stale));
        })->count();
        $byStatus = [];
        foreach ($computers as $pc) {
            $status = (string) ($pc->status ?: 'unknown');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }
        $ids = $computers->pluck('id')->all();
        $active = $ids === [] ? 0 : Booking::query()->where('status', 'active')->whereIn('computer_id', $ids)->count();
        $from = now()->startOfDay();
        $topups = app(TaxReportService::class)->incomeBetween($from, now());

        $shifts = Shift::query()
            ->where('status', '!=', 'closed')
            ->with('admin:id,name,role')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (Shift $shift) use ($actor) {
                $row = [
                    'id' => $shift->id,
                    'status' => $shift->status,
                    'admin' => $shift->admin?->name,
                    'role' => $shift->admin?->roleLabel(),
                    'started_at' => $this->iso($shift->started_at),
                ];
                if ($actor->admin && ($actor->admin->isOwner() || $actor->admin->role === Admin::ROLE_SUPERVISOR)) {
                    $row['cash_start'] = $shift->cash_start;
                }

                return $row;
            })
            ->all();

        return [
            'club_id' => $clubId,
            'computers' => count($computers),
            'by_status' => $byStatus,
            'shell_online' => $online,
            'active_sessions' => $active,
            'fiscal_topups_today' => $topups,
            'fiscal_topups_note' => 'Фискализированные пополнения за сегодня, не вся выручка бара.',
            'pending_orders' => $this->ordersQueue($actor, $args)['count'],
            'open_incidents' => $this->openIncidents($actor, $args)['count'],
            'open_shifts' => $shifts,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function computerHealth(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $query = Computer::query()->where('club_id', $clubId)->orderBy('name');
        if (! empty($args['computer_id'])) {
            $query->whereKey((int) $args['computer_id']);
        }
        $rows = $query->limit(80)->get();
        $stations = [];
        $attention = [];
        foreach ($rows as $pc) {
            $row = $this->healthRow($pc);
            $stations[] = $row;
            if ($row['attention'] !== []) {
                $attention[] = ['id' => $pc->id, 'name' => $pc->name, 'attention' => $row['attention']];
            }
        }

        return [
            'rules' => [
                'patch_cord' => 'nic_flap_count >= 2 за смену — кандидат на замену патч-корда',
                'link' => 'nic_link_mbps от 1 до 100 — линк просел',
                'ssd' => 'ssd_wear_pct >= 80 или ошибки чтения/записи — планировать диск',
            ],
            'stations' => $stations,
            'attention' => $attention,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthRow(Computer $pc): array
    {
        $attention = [];
        if ((int) $pc->nic_flap_count >= 2) {
            $attention[] = 'patch_cord';
        }
        $link = $pc->nic_link_mbps;
        if ($link !== null && (int) $link > 0 && (int) $link <= 100) {
            $attention[] = 'link';
        }
        if ((int) $pc->ssd_wear_pct >= 80 || (int) $pc->ssd_read_errors > 0 || (int) $pc->ssd_write_errors > 0 || $pc->ssd_health === 'bad') {
            $attention[] = 'ssd';
        }
        if ($pc->cache_ok === false) {
            $attention[] = 'cache';
        }
        if ($pc->last_crash_at !== null && $pc->last_crash_at->greaterThan(now()->subDay())) {
            $attention[] = 'crash';
        }

        return [
            'id' => $pc->id,
            'name' => $pc->name,
            'status' => $pc->status,
            'power_state' => $pc->power_state,
            'last_seen_at' => $this->iso($pc->last_seen_at),
            'nic_link_mbps' => $pc->nic_link_mbps,
            'nic_flap_count' => $pc->nic_flap_count,
            'ssd_wear_pct' => $pc->ssd_wear_pct,
            'ssd_health' => $pc->ssd_health,
            'ssd_read_errors' => $pc->ssd_read_errors,
            'ssd_write_errors' => $pc->ssd_write_errors,
            'cache_ok' => $pc->cache_ok,
            'super_client' => $pc->super_client,
            'last_crash_at' => $this->iso($pc->last_crash_at),
            'last_crash_reason' => $pc->last_crash_reason,
            'attention' => $attention,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function ordersQueue(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $ids = Computer::query()->where('club_id', $clubId)->pluck('id');
        $names = Computer::query()->where('club_id', $clubId)->pluck('name');
        $ordersQuery = Order::query()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_COOKING])
            ->where(function ($query) use ($ids, $names) {
                $query->whereHas('booking', fn ($booking) => $booking->whereIn('computer_id', $ids));
                if ($names->isNotEmpty()) {
                    $query->orWhere(function ($named) use ($names) {
                        $named->whereNull('booking_id')->whereIn('pc_name', $names->all());
                    });
                }
            });
        $count = (clone $ordersQuery)->count();
        $orders = $ordersQuery->with('user:id,name')->orderBy('id')->limit(40)->get();

        return [
            'count' => $count,
            'orders' => $orders->map(function (Order $order) {
                return [
                    'id' => $order->id,
                    'status' => $order->status,
                    'pc_name' => $order->pc_name,
                    'guest' => $order->user?->name,
                    'items' => array_map(
                        static fn (array $line) => ['name' => $line['name'], 'qty' => $line['qty']],
                        $order->lineItems()
                    ),
                    'created_at' => $this->iso($order->created_at),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function openIncidents(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        if (! Schema::hasTable('incidents')) {
            return ['count' => 0, 'incidents' => []];
        }
        $ids = Computer::query()->where('club_id', $clubId)->pluck('id')->all();
        $names = Computer::query()->where('club_id', $clubId)->pluck('name', 'id');
        $query = DB::table('incidents')->whereNull('resolved_at');
        if (Schema::hasColumn('incidents', 'computer_id')) {
            $query->where(function ($inner) use ($ids) {
                $inner->whereNull('computer_id');
                if ($ids !== []) {
                    $inner->orWhereIn('computer_id', $ids);
                }
            });
        }
        $count = (clone $query)->count();
        $rows = $query->orderByDesc('id')->limit(30)->get();

        return [
            'count' => $count,
            'incidents' => $rows->map(function ($row) use ($names) {
                $computerId = isset($row->computer_id) ? (int) $row->computer_id : 0;

                return [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'severity' => (string) ($row->severity ?? ''),
                    'computer' => $computerId > 0 ? ($names[$computerId] ?? null) : null,
                    'description' => mb_substr(McpMask::text((string) $row->description), 0, 240),
                    'created_at' => (string) $row->created_at,
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function warehouse(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $showCost = (bool) $actor->admin?->canManageStoreInventory();
        $counts = DB::table('store_components')
            ->where('club_id', $clubId)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');
        $rows = StoreComponent::query()
            ->where('club_id', $clubId)
            ->whereIn('status', ['in_stock', 'reserved', 'repair'])
            ->orderBy('type')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return [
            'counts' => $counts,
            'shown' => $rows->count(),
            'items' => $rows->map(function (StoreComponent $item) use ($showCost) {
                $row = [
                    'id' => $item->id,
                    'type' => $item->type,
                    'name' => $item->name,
                    'status' => $item->status,
                    'serials' => $item->serials,
                    'warranty_months' => $item->warranty_months,
                ];
                if ($showCost) {
                    $row['purchase_price'] = $item->purchase_price;
                }

                return $row;
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function openEstimates(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $assembler = $actor->admin?->role === 'assembler';
        $statuses = $assembler
            ? ['ready']
            : ['draft', 'agreed', 'procuring', 'ready'];
        $rows = StoreEstimate::query()
            ->with('client:id,name,phone')
            ->where('club_id', $clubId)
            ->whereIn('status', $statuses)
            ->orderByDesc('id')
            ->limit(40)
            ->get();
        $showCost = (bool) $actor->admin?->canManageStoreInventory();

        return [
            'estimates' => $rows->map(function (StoreEstimate $estimate) use ($showCost) {
                $row = [
                    'id' => $estimate->id,
                    'title' => $estimate->title,
                    'status' => $estimate->status,
                    'client' => $estimate->client?->name,
                    'client_phone' => $estimate->client ? McpMask::phone($estimate->client->phone) : null,
                    'sale_total' => $estimate->sale_total,
                ];
                if ($showCost) {
                    $row['purchase_total'] = $estimate->purchase_total;
                }

                return $row;
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function fairPlay(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $mode = app(AcGate::class)->mode($clubId);
        $sessions = Schema::hasTable('ac_sessions')
            ? DB::table('ac_sessions')->where('club_id', $clubId)->where('status', 'online')->select('kind', DB::raw('count(*) as c'))->groupBy('kind')->pluck('c', 'kind')
            : [];
        $bans = Schema::hasTable('ac_bans')
            ? DB::table('ac_bans')
                ->whereNull('pardoned_at')
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->select('scope', DB::raw('count(*) as c'))
                ->groupBy('scope')
                ->pluck('c', 'scope')
            : [];

        return [
            'mode' => $mode,
            'online_by_kind' => $sessions,
            'active_bans_by_scope' => $bans,
            'scopes' => AcBan::SCOPES,
            'evidence' => 'Файлы evidence в MCP не передаются.',
            'connect_token' => 'Не выдаётся и не показывается.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorLogs(): array
    {
        $files = [
            'laravel.log',
            'catalog-sync.log',
            'avito-ads.log',
            'avito-token.log',
        ];
        $lines = [];
        foreach ($files as $name) {
            foreach ($this->tailErrors(storage_path('logs/'.$name)) as $line) {
                $lines[] = ['file' => $name, 'line' => $line];
            }
        }

        return [
            'lines' => array_slice($lines, -30),
            'note' => 'Хвост 64 КБ каждого файла, только строки с error/exception/critical/SQLSTATE.',
        ];
    }

    /**
     * @return list<string>
     */
    private function tailErrors(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $read = min($size, 65536);
        if ($size > $read) {
            fseek($handle, -$read, SEEK_END);
        }
        $chunk = (string) fread($handle, $read);
        fclose($handle);
        $matched = [];
        foreach (preg_split("/\r\n|\n|\r/", $chunk) ?: [] as $line) {
            if ($line === '' || ! preg_match('/error|exception|critical|SQLSTATE|fail/i', $line)) {
                continue;
            }
            $matched[] = mb_substr(McpMask::text($line), 0, 300);
        }

        return array_slice($matched, -10);
    }

    /**
     * @return array<string, mixed>
     */
    private function playerMe(McpActor $actor): array
    {
        $user = $actor->user;
        if (! $user) {
            throw new McpCallException('Токен не игрока.');
        }
        $booking = Booking::query()->where('user_id', $user->id)->where('status', 'active')->latest('id')->first();
        $computer = $booking?->computer_id ? Computer::query()->find($booking->computer_id) : null;
        $balance = 0.0;
        try {
            $balance = (float) $user->total_balance;
        } catch (\Throwable) {
            $balance = (float) ($user->balance ?? 0);
        }

        return [
            'name' => $user->name,
            'balance' => $balance,
            'active_session' => $booking ? [
                'computer' => $computer?->name,
                'ends_at' => $this->iso($booking->ends_at ?? null),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicOccupancy(): array
    {
        $clubs = Club::operational()->orderBy('id')->get(['id', 'name']);
        $rows = [];
        foreach ($clubs as $club) {
            $counts = Computer::query()
                ->where('club_id', $club->id)
                ->select('status', DB::raw('count(*) as c'))
                ->groupBy('status')
                ->pluck('c', 'status');
            $rows[] = [
                'club' => $club->name,
                'by_status' => $counts,
            ];
        }

        return ['locations' => $rows];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function previewRelease(McpActor $actor, array $args): array
    {
        $pc = $this->releaseTarget($actor, $args);
        $booking = Booking::query()->where('status', 'active')->where('computer_id', $pc->id)->latest('id')->first();

        return [
            'computer_id' => $pc->id,
            'computer' => $pc->name,
            'reason' => $this->reason($args),
            'active_booking_id' => $booking?->id,
            'will_close_session' => $booking !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function release(McpActor $actor, array $args): array
    {
        $pc = $this->releaseTarget($actor, $args);
        $result = app(BookingSessionTimingService::class)->forceReleaseComputer((int) $pc->id);

        return [
            'computer_id' => $pc->id,
            'computer' => $pc->name,
            'had_session' => $result['had_session'],
            'booking_id' => $result['booking_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function releaseTarget(McpActor $actor, array $args): Computer
    {
        $this->reason($args);

        return $this->computerInClub((int) ($args['computer_id'] ?? 0), $this->clubId($actor, $args));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function reason(array $args): string
    {
        $reason = trim((string) ($args['reason'] ?? ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 240) {
            throw new McpCallException('Нужна причина от 3 до 240 символов.');
        }

        return McpMask::text($reason);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function previewWake(McpActor $actor, array $args): array
    {
        return [
            'packet' => 'Облако пакет не шлёт. В очередь /api/power/wol-targets его заберёт MikroTik.',
            'targets' => $this->wakePlans($actor, $args),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function wake(McpActor $actor, array $args): array
    {
        $plans = $this->wakePlans($actor, $args);
        $power = app(ComputerPowerService::class);
        $minutes = (int) config('mcp.wake_hold_minutes', 15);
        $queued = [];
        $skipped = [];
        foreach ($plans as $plan) {
            if ($plan['action'] !== 'queue') {
                $skipped[] = $plan;
                continue;
            }
            $pc = Computer::query()->find($plan['id']);
            if (! $pc) {
                $skipped[] = $plan;
                continue;
            }
            $result = $power->queueManualWake($pc, $minutes);
            if ($result['queued']) {
                $queued[] = [
                    'id' => $pc->id,
                    'name' => $pc->name,
                    'hold_until' => $result['hold_until'],
                ];
            } else {
                $skipped[] = [
                    'id' => $pc->id,
                    'name' => $pc->name,
                    'action' => 'skip',
                    'reason' => $result['reason'],
                ];
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array{id:int,name:string,action:string,reason:string}>
     */
    private function wakePlans(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $all = filter_var($args['all_offline'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $computerId = (int) ($args['computer_id'] ?? 0);
        if ($all === ($computerId > 0)) {
            throw new McpCallException('Укажите computer_id или all_offline, не оба сразу.');
        }
        $query = Computer::query()
            ->where('club_id', $clubId)
            ->where(function ($inner) {
                $inner->whereNull('kind')->orWhere('kind', Computer::KIND_PC);
            })
            ->orderBy('id');
        if (! $all) {
            $query->whereKey($computerId);
        }
        $computers = $query->limit((int) config('mcp.wake_batch_limit', 40) + 5)->get();
        if (! $all && $computers->isEmpty()) {
            throw new McpCallException('ПК в этой локации не найден.');
        }
        $stale = max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
        $plans = [];
        foreach ($computers as $pc) {
            if (count($plans) >= (int) config('mcp.wake_batch_limit', 40)) {
                break;
            }
            $plans[] = $this->wakePlan($pc, $stale);
        }

        return $plans;
    }

    /**
     * @return array{id:int,name:string,action:string,reason:string}
     */
    private function wakePlan(Computer $pc, int $staleSeconds): array
    {
        $base = ['id' => (int) $pc->id, 'name' => (string) $pc->name];
        if ($this->sessionActive((int) $pc->id)) {
            return $base + ['action' => 'skip', 'reason' => 'есть активная сессия'];
        }
        if (trim((string) $pc->mac_address) === '' || trim((string) $pc->hwid) === '') {
            return $base + ['action' => 'skip', 'reason' => 'нет MAC или HWID'];
        }
        if ($pc->last_seen_at !== null && $pc->last_seen_at->greaterThan(now()->subSeconds($staleSeconds))) {
            return $base + ['action' => 'skip', 'reason' => 'шелл уже на связи'];
        }

        return $base + ['action' => 'queue', 'reason' => 'встанет в очередь MikroTik'];
    }

    private function sessionActive(int $computerId): bool
    {
        return Booking::query()->where('status', 'active')->where('computer_id', $computerId)->exists();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function rollbackMarkers(McpActor $actor, array $args): array
    {
        $pc = $this->computerInClub((int) ($args['computer_id'] ?? 0), $this->clubId($actor, $args));
        if (! Schema::hasTable('golden_image_revisions')) {
            return ['computer' => $pc->name, 'revisions' => []];
        }
        $rows = GoldenImageRevision::query()
            ->where('computer_id', $pc->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'status', 'disk_mode', 'steam_count', 'epic_count', 'file_count', 'changed_count', 'note', 'verified_at', 'created_at']);

        return [
            'computer_id' => $pc->id,
            'computer' => $pc->name,
            'revisions' => $rows->map(fn (GoldenImageRevision $revision) => [
                'id' => $revision->id,
                'status' => $revision->status,
                'disk_mode' => $revision->disk_mode,
                'steam_count' => $revision->steam_count,
                'epic_count' => $revision->epic_count,
                'file_count' => $revision->file_count,
                'changed_count' => $revision->changed_count,
                'note' => $revision->note ? McpMask::text((string) $revision->note) : null,
                'verified_at' => $this->iso($revision->verified_at),
                'created_at' => $this->iso($revision->created_at),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function previewEstimate(McpActor $actor, array $args): array
    {
        $built = $this->estimateLines($actor, $args);

        return [
            'title' => $built['title'],
            'client' => $built['client_label'],
            'client_phone' => $built['client_phone_masked'],
            'sale_total' => $built['sale_total'],
            'purchase_total' => $built['purchase_total'],
            'lines' => $built['lines'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function createEstimate(McpActor $actor, array $args): array
    {
        $built = $this->estimateLines($actor, $args);
        $estimate = DB::transaction(function () use ($actor, $built) {
            $estimate = StoreEstimate::query()->create([
                'club_id' => $built['club_id'],
                'store_client_id' => $built['client_id'],
                'created_by' => $actor->admin?->id,
                'title' => $built['title'],
                'status' => 'draft',
                'notes' => $built['notes'],
            ]);
            $sort = 0;
            foreach ($built['lines'] as $line) {
                StoreEstimateItem::query()->create([
                    'store_estimate_id' => $estimate->id,
                    'type' => $line['type'],
                    'name' => $line['name'],
                    'part' => $line['part'],
                    'supplier_sku' => $line['sku'],
                    'supplier_name' => $line['name'],
                    'supplier_price' => $line['supplier_price'],
                    'sale_price' => $line['sale_price'],
                    'qty' => $line['qty'],
                    'status' => 'to_order',
                    'sort_order' => $sort++,
                ]);
            }
            $estimate->recalculateTotals();

            return $estimate->fresh();
        });

        return [
            'id' => $estimate->id,
            'status' => $estimate->status,
            'sale_total' => $estimate->sale_total,
            'purchase_total' => $estimate->purchase_total,
            'client_phone' => $built['client_phone_masked'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function estimateLines(McpActor $actor, array $args): array
    {
        $clubId = $this->clubId($actor, $args);
        $components = $args['components'] ?? null;
        if (! is_array($components) || $components === [] || count($components) > 40) {
            throw new McpCallException('Нужен список components из 1–40 SKU.');
        }
        $wanted = [];
        foreach ($components as $row) {
            if (! is_array($row)) {
                throw new McpCallException('Позиция сметы должна быть объектом со sku.');
            }
            $sku = (int) ($row['sku'] ?? 0);
            if ($sku <= 0) {
                throw new McpCallException('У каждой позиции нужен sku каталога.');
            }
            $wanted[] = ['sku' => $sku, 'qty' => max(1, min(20, (int) ($row['qty'] ?? 1)))];
        }
        $products = StoreSupplierCatalogProduct::query()
            ->whereIn('sku', array_column($wanted, 'sku'))
            ->get()
            ->keyBy(fn (StoreSupplierCatalogProduct $product) => (int) $product->sku);
        $missing = [];
        foreach ($wanted as $row) {
            if (! $products->has($row['sku'])) {
                $missing[] = $row['sku'];
            }
        }
        if ($missing !== []) {
            throw new McpCallException('В каталоге нет SKU: '.implode(', ', $missing));
        }

        $client = null;
        $masked = null;
        if (trim((string) ($args['client_phone'] ?? '')) !== '') {
            $needle = substr(preg_replace('/\D+/', '', (string) $args['client_phone']) ?? '', -10);
            if (strlen($needle) < 10) {
                throw new McpCallException('Телефон клиента слишком короткий.');
            }
            $client = StoreClient::query()
                ->where('club_id', $clubId)
                ->get(['id', 'name', 'phone'])
                ->first(function (StoreClient $candidate) use ($needle) {
                    $digits = preg_replace('/\D+/', '', (string) $candidate->phone) ?? '';

                    return substr($digits, -10) === $needle;
                });
            if (! $client) {
                throw new McpCallException('Клиент магазина не найден. Карточку заводят в разделе клиентов, MCP её не создаёт.');
            }
            $masked = McpMask::phone($client->phone);
        }

        $lines = [];
        $sale = 0.0;
        $buy = 0.0;
        foreach ($wanted as $row) {
            /** @var StoreSupplierCatalogProduct $product */
            $product = $products->get($row['sku']);
            $supplierPrice = $product->price !== null ? (float) $product->price : null;
            $salePrice = $product->rrp !== null ? (float) $product->rrp : $supplierPrice;
            $lines[] = [
                'sku' => $row['sku'],
                'qty' => $row['qty'],
                'name' => $product->name,
                'part' => $product->part,
                'type' => null,
                'supplier_price' => $supplierPrice,
                'sale_price' => $salePrice,
            ];
            $sale += (float) ($salePrice ?? 0) * $row['qty'];
            $buy += (float) ($supplierPrice ?? 0) * $row['qty'];
        }

        $notes = trim((string) ($args['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            throw new McpCallException('Заметка длиннее 500 символов.');
        }

        return [
            'club_id' => $clubId,
            'title' => mb_substr(trim((string) ($args['title'] ?? '')), 0, 255) ?: null,
            'notes' => $notes !== '' ? McpMask::text($notes) : null,
            'client_id' => $client?->id,
            'client_label' => $client?->name,
            'client_phone_masked' => $masked,
            'lines' => $lines,
            'sale_total' => round($sale, 2),
            'purchase_total' => round($buy, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function previewVerify(McpActor $actor, array $args): array
    {
        $pc = $this->builtPc($actor, $args);
        $reported = $this->reportedParts($args);

        return [
            'built_pc_id' => $pc->id,
            'title' => $pc->title,
            'scanned' => count($reported),
            'will_write_names' => (bool) config('store.build_verify_update_names', true),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function verifyBuild(McpActor $actor, array $args): array
    {
        $pc = $this->builtPc($actor, $args);
        $reported = $this->reportedParts($args);
        $apply = (bool) config('store.build_verify_update_names', true);
        $result = app(StoreBuildVerifyService::class)->verify($pc, $reported, $apply);
        $ok = count($result['missing']) === 0 && count($result['conflicts']) === 0;
        $pc->update([
            'verified_at' => now(),
            'verified_ok' => $ok,
        ]);
        if ($pc->store_order_id) {
            StoreOrder::query()->whereKey($pc->store_order_id)->update([
                'verified_at' => now(),
                'verified_ok' => $ok,
            ]);
        }
        try {
            $assembly = app(StoreAssemblyCaptureService::class);
            if ($ok) {
                $assembly->onAssemblyFinished($pc->fresh());
            } else {
                $assembly->onAssemblyStarted($pc->fresh());
            }
        } catch (\Throwable $e) {
            Log::channel('mcp')->warning('mcp.assembly_capture', [
                'built_pc_id' => $pc->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok' => $ok,
            'built_pc_id' => $pc->id,
            'matched' => count($result['matched']),
            'missing' => count($result['missing']),
            'extra' => count($result['extra']),
            'conflicts' => count($result['conflicts']),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function builtPc(McpActor $actor, array $args): StoreBuiltPc
    {
        $clubId = $this->clubId($actor, $args);
        $pc = null;
        if (! empty($args['built_pc_id'])) {
            $pc = StoreBuiltPc::query()->where('club_id', $clubId)->find((int) $args['built_pc_id']);
        } elseif (! empty($args['order_id'])) {
            $order = StoreOrder::query()->where('club_id', $clubId)->find((int) $args['order_id']);
            if ($order) {
                $pc = StoreBuiltPc::query()->where('club_id', $clubId)->where('store_order_id', $order->id)->first();
            }
        } else {
            throw new McpCallException('Нужен built_pc_id или order_id.');
        }
        if (! $pc) {
            throw new McpCallException('Сборка в этой локации не найдена.');
        }
        $this->assertAssemblerOwns($actor, $pc);

        return $pc;
    }

    private function assertAssemblerOwns(McpActor $actor, StoreBuiltPc $pc): void
    {
        $admin = $actor->admin;
        if (! $admin || $admin->role !== 'assembler') {
            return;
        }
        $assignee = $pc->store_order_id
            ? StoreOrder::query()->whereKey($pc->store_order_id)->value('assignee_id')
            : null;
        $ownBuild = (int) $pc->assembled_by === (int) $admin->id;
        $ownOrder = $assignee && (int) $assignee === (int) $admin->id;
        $unassigned = ! $pc->assembled_by && ! $assignee;
        if (! $ownBuild && ! $ownOrder && ! $unassigned) {
            throw new McpCallException('Сборщик сверяет только свою сборку или свой заказ.');
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function reportedParts(array $args): array
    {
        $rows = $args['components'] ?? null;
        if (! is_array($rows) || $rows === [] || count($rows) > 40) {
            throw new McpCallException('Нужен список components из 1–40 позиций.');
        }
        $reported = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new McpCallException('Позиция сверки должна быть объектом.');
            }
            $reported[] = [
                'type' => isset($row['type']) ? mb_substr((string) $row['type'], 0, 32) : null,
                'name' => mb_substr((string) ($row['name'] ?? ''), 0, 255),
                'serial' => mb_substr((string) ($row['serial'] ?? ''), 0, 128),
                'vendor' => mb_substr((string) ($row['vendor'] ?? ''), 0, 128),
                'extra' => [],
            ];
        }

        return $reported;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function validateAc(array $args): array
    {
        $steam = trim((string) ($args['steam_id'] ?? ''));
        $match = trim((string) ($args['match_id'] ?? ''));
        if ($steam === '' || $match === '') {
            throw new McpCallException('Нужны steam_id и match_id.');
        }
        $result = app(AcGate::class)->validateSession($steam, $match);

        return [
            'allow' => (bool) ($result['allow'] ?? false),
            'reason' => (string) ($result['reason'] ?? ''),
            'kind' => $result['kind'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function previewFaceit(array $args): array
    {
        $row = $this->faceitRow($args);

        return [
            'user_id' => $row->user_id,
            'nickname' => $row->nickname,
            'elo' => $row->elo,
            'skill_level' => $row->skill_level,
            'banned' => $row->isBanned(),
            'will_call' => 'FACEIT Data API v4 для этого игрока',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function syncFaceit(array $args): array
    {
        $row = $this->faceitRow($args);
        try {
            app(FaceitIdentityService::class)->sync($row);
        } catch (FaceitRateLimited) {
            throw new McpCallException('FACEIT ответил 429, кэш не затёрт.');
        }
        $row->refresh();

        return [
            'user_id' => $row->user_id,
            'nickname' => $row->nickname,
            'elo' => $row->elo,
            'skill_level' => $row->skill_level,
            'banned' => $row->isBanned(),
            'synced_at' => $this->iso($row->synced_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function faceitRow(array $args): FaceitIdentity
    {
        $row = null;
        if (! empty($args['user_id'])) {
            $row = FaceitIdentity::query()->where('user_id', (int) $args['user_id'])->first();
        } elseif (trim((string) ($args['faceit_player_id'] ?? '')) !== '') {
            $row = FaceitIdentity::query()->where('faceit_player_id', (string) $args['faceit_player_id'])->first();
        } else {
            throw new McpCallException('Нужен user_id или faceit_player_id.');
        }
        if (! $row) {
            throw new McpCallException('Привязка FACEIT не найдена.');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function previewDiagnostics(McpActor $actor, array $args): array
    {
        return [
            'checks' => array_map(
                static fn (string $id) => $id,
                $this->diagnosticIds($actor, $args)
            ),
            'note' => 'Чек не бьётся, ПК не включаются, SMS не уходят.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function diagnostics(McpActor $actor, array $args): array
    {
        $club = Club::query()->find($this->clubId($actor, $args));
        $service = app(OwnerSystemTestService::class);
        $results = [];
        foreach ($this->diagnosticIds($actor, $args) as $id) {
            $results[] = $service->run($id, $club);
        }

        return [
            'contour' => strtolower(trim((string) ($args['test_contour'] ?? ''))),
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function diagnosticIds(McpActor $actor, array $args): array
    {
        $contour = strtolower(trim((string) ($args['test_contour'] ?? '')));
        if ($contour === '') {
            throw new McpCallException('Нужен test_contour.');
        }
        $this->assertCallable('run_system_diagnostics', $args);
        $live = collect(app(OwnerSystemTestService::class)->catalog(Club::query()->find($this->clubId($actor, $args))))
            ->where('kind', 'live')
            ->pluck('id')
            ->all();
        $map = [
            'db' => ['database'],
            'cache' => ['cache'],
            'fiscal' => ['fiscal', 'yookassa', 'legal'],
            'hardware' => ['computers', 'station_health', 'wol_relay', 'diskless'],
        ];
        if ($contour === 'all') {
            return array_values($live);
        }
        if (isset($map[$contour])) {
            return $map[$contour];
        }
        if (in_array($contour, $live, true)) {
            return [$contour];
        }

        throw new McpCallException('Неизвестный контур. Допустимы db, cache, fiscal, hardware, all или id живой проверки.');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function tax(array $args): array
    {
        $year = (int) ($args['year'] ?? now()->year);
        if ($year < 2020 || $year > 2100) {
            throw new McpCallException('Год вне диапазона 2020–2100.');
        }
        $report = app(TaxReportService::class)->forYear($year);
        unset($report['payroll']['employees']);
        $report['payroll']['note'] = 'ФИО сотрудников в MCP не передаются.';
        $report['disclaimer'] = 'Черновик расчёта, не налоговая декларация. Демо-чеки в базу не входят.';

        return $report;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function promptDegradation(McpActor $actor, array $args): string
    {
        $health = $this->computerHealth($actor, $args);
        if (! empty($args['computer_id']) && $health['stations'] === []) {
            throw new McpCallException('ПК в этой локации не найден.');
        }

        return "Разбери здоровье станций клуба и дай рекомендацию только там, где сработали пороги из rules.\n"
            ."flap >= 2 — заменить патч-корд. Линк 1–100 Мбит — проверить кабель и порт. Износ SSD >= 80 или ошибки SMART — планировать диск.\n"
            ."Если порог не достигнут, так и напиши. Не предлагай замену «на всякий случай» и не проси сырой SQL.\n\n"
            .$this->json($health);
    }

    private function promptHandover(McpActor $actor): string
    {
        $summary = $this->clubSummary($actor, []);
        $incidents = $this->openIncidents($actor, []);
        $health = $this->computerHealth($actor, []);

        return "Собери черновик передачи смены для следующего админа. Смену не закрывай и инструменты не вызывай.\n"
            ."Опиши расхождения, которые уже есть в данных: открытые инциденты, очередь бара, станции из attention.\n"
            ."Телефоны гостей не выдумывай и не проси.\n\n"
            .$this->json([
                'summary' => $summary,
                'incidents' => $incidents,
                'attention' => $health['attention'],
            ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function promptTax(array $args): string
    {
        $report = $this->tax($args);

        return "Объясни владельцу черновик УСН 6%: база, вычет взносов, что платить по кварталам и какие предупреждения уже посчитал сервис.\n"
            ."Не выдумывай ставки и не подставляй ФИО. Это не декларация и не платёжка.\n\n"
            .$this->json($report);
    }

    private function json(mixed $payload): string
    {
        return (string) json_encode(
            McpMask::scrub($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function logRead(McpActor $actor, string $uri, string $result): void
    {
        try {
            Log::channel('mcp')->info('mcp.read', [
                'actor' => $actor->auditLabel(),
                'token' => $actor->token->id,
                'uri' => $uri,
                'result' => $result,
            ]);
        } catch (\Throwable) {
            //
        }
    }
}
