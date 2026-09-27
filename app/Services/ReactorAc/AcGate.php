<?php

namespace App\Services\ReactorAc;

use App\Models\AcBan;
use App\Models\AcEvent;
use App\Models\AcEvidence;
use App\Models\AcHwid;
use App\Models\AcSession;
use App\Models\AcTicket;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\LanBountyEvent;
use App\Models\User;
use App\Services\ClubFeatureService;
use App\Support\ReactorAcKicks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AcGate
{
    public function __construct(private readonly ClubFeatureService $features)
    {
    }

    public function mode(?int $clubId): string
    {
        if (! $this->features->enabled($clubId, 'reactor_ac')) {
            return 'off';
        }
        $mode = (string) $this->features->setting($clubId, 'reactor_ac', 'mode');

        return in_array($mode, ['telemetry', 'gate'], true) ? $mode : 'off';
    }

    public function clubIdForUser(User $user): int
    {
        $fromUser = (int) ($this->features->clubIdForUser($user) ?? 0);
        if ($fromUser > 0) {
            return $fromUser;
        }

        return (int) (Club::query()->orderBy('id')->value('id') ?? 0);
    }

    public function primaryClubId(): int
    {
        return (int) (Club::query()->orderBy('id')->value('id') ?? 0);
    }

    public function fullBanMessage(User $user): ?string
    {
        if (! Schema::hasTable('ac_bans')) {
            return null;
        }
        if (! $this->banned($user, null, AcBan::SCOPE_FULL)) {
            return null;
        }

        return 'Аккаунт закрыт для клуба. Бронь и бар недоступны, пока управляющий не снимет full_ban.';
    }

    /**
     * @return array<string, mixed>
     */
    public function cabinet(User $user): array
    {
        $clubId = $this->clubIdForUser($user);
        $mode = $this->mode($clubId);
        $ban = $this->activeBan($user, null);
        $session = $this->liveHomeSession($user);

        $status = 'not_installed';
        if ($ban) {
            $status = 'banned';
        } elseif ($session) {
            $status = 'online';
        }

        return [
            'mode' => $mode,
            'status' => $mode === 'off' ? 'off' : $status,
            'scope' => $ban?->scope,
            'download_url' => '/ac/download',
            'about_url' => '/play-from-home',
            'stack' => (string) config('reactor_ac.stack'),
            'notice' => $mode === 'off'
                ? ''
                : 'REACTOR AC пускает на сервер клуба с домашнего Windows. Это пароль на connect, не античит уровня FACEIT.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $path = storage_path('app/ac/reactor-ac.exe');
        $exists = is_file($path);

        return [
            'version' => (string) config('reactor_ac.version'),
            'url' => url('/ac/download'),
            'sha256' => $exists ? hash_file('sha256', $path) : null,
            'min_os' => (string) config('reactor_ac.min_os'),
            'stack' => (string) config('reactor_ac.stack'),
            'available' => $exists,
        ];
    }

    /**
     * @return array{ok:bool,status:int,body:array<string,mixed>}
     */
    public function login(string $phone, string $password): array
    {
        $user = $this->findByPhone($phone);
        if (! $user || ! Hash::check($password, (string) $user->password)) {
            return $this->fail(401, 'invalid_credentials', 'Телефон или пароль не подошли.');
        }

        $clubId = $this->clubIdForUser($user);
        $mode = $this->mode($clubId);
        if ($mode === 'off') {
            return $this->fail(409, 'mode_off', 'REACTOR AC в клубе выключен.');
        }

        AcSession::query()
            ->where('user_id', $user->id)
            ->where('kind', 'home')
            ->where('status', 'online')
            ->update(['status' => 'offline']);

        $plain = bin2hex(random_bytes(32));
        $session = AcSession::query()->create([
            'user_id' => $user->id,
            'club_id' => $clubId > 0 ? $clubId : null,
            'token_hash' => hash('sha256', $plain),
            'kind' => 'home',
            'status' => 'online',
            'integrity_ok' => false,
        ]);
        $this->event($session, 'login', 'Клиент вошёл');

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'token' => $plain,
                'session_id' => $session->id,
                'mode' => $mode,
                'policy' => $this->policy($clubId),
            ],
        ];
    }

    public function sessionFromBearer(?string $token): ?AcSession
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        return AcSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('kind', 'home')
            ->where('status', 'online')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok:bool,status:int,body:array<string,mixed>}
     */
    public function heartbeat(AcSession $session, array $input): array
    {
        $mode = $this->mode($session->club_id ? (int) $session->club_id : null);
        if ($mode === 'off') {
            return $this->fail(409, 'mode_off', 'REACTOR AC в клубе выключен.');
        }

        $steam = $this->steam((string) ($input['steam_id'] ?? ''));
        $hwid = strtolower(trim((string) ($input['hwid'] ?? '')));
        if ($steam === '' || ! preg_match('/^[a-f0-9]{32,64}$/', $hwid)) {
            return $this->fail(422, 'bad_client', 'Нужны steam_id и digest HWID (32–64 hex), не серийники.');
        }

        $testsigning = (bool) ($input['testsigning'] ?? false);
        $vm = (bool) ($input['vm'] ?? false);
        $integrity = ! $testsigning && ! $vm && (bool) ($input['integrity_ok'] ?? true);
        $findings = array_values(array_filter(array_map(
            fn ($row) => mb_substr(trim((string) $row), 0, 120),
            array_slice((array) ($input['findings'] ?? []), 0, 20)
        )));

        $session->fill([
            'steam_id' => $steam,
            'hwid_digest' => $hwid,
            'integrity_ok' => $integrity,
            'integrity_flags' => [
                'testsigning' => $testsigning,
                'vm' => $vm,
                'findings' => $findings,
            ],
            'client_version' => mb_substr(trim((string) ($input['client_version'] ?? '')), 0, 32) ?: null,
            'os' => mb_substr(trim((string) ($input['os'] ?? '')), 0, 64) ?: null,
            'last_heartbeat_at' => now(),
        ])->save();

        $hwidRow = AcHwid::query()->firstOrNew([
            'user_id' => $session->user_id,
            'digest' => $hwid,
        ]);
        if (! $hwidRow->exists) {
            $hwidRow->first_seen_at = now();
        }
        $hwidRow->last_seen_at = now();
        $hwidRow->save();

        if (! $integrity) {
            $this->event($session, 'integrity_denied', ReactorAcKicks::text('deny_integrity'), [
                'testsigning' => $testsigning,
                'vm' => $vm,
            ]);
        }

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'ok' => true,
                'integrity_ok' => $integrity,
                'mode' => $mode,
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:int,body:array<string,mixed>}
     */
    public function issueToken(AcSession $session, string $matchId, string $scope = AcBan::SCOPE_MATCH_MAKING): array
    {
        $clubId = $session->club_id ? (int) $session->club_id : $this->primaryClubId();
        $mode = $this->mode($clubId);
        if ($mode !== 'gate') {
            return $this->fail(409, $mode === 'telemetry' ? 'telemetry' : 'mode_off', 'Пароль на сервер выдаётся только в режиме gate.');
        }
        $matchId = mb_substr(trim($matchId), 0, 64);
        if ($matchId === '') {
            return $this->fail(422, 'bad_match', 'Нужен match_id.');
        }
        if (! $session->integrity_ok) {
            return $this->fail(422, 'deny_integrity', ReactorAcKicks::text('deny_integrity'));
        }
        if (! $this->homeAlive($session, $this->staleSeconds($clubId))) {
            return $this->fail(422, 'deny_stale', ReactorAcKicks::text('deny_stale'));
        }
        $steam = $this->steam((string) $session->steam_id);
        if ($steam === '') {
            return $this->fail(422, 'deny_no_steam', ReactorAcKicks::text('deny_no_steam'));
        }
        $user = $session->user;
        if ($user && $this->blocksConnect($user, $session->hwid_digest, $scope)) {
            return $this->fail(403, 'deny_banned', ReactorAcKicks::text('deny_banned'));
        }

        AcTicket::query()
            ->where('user_id', $session->user_id)
            ->where('match_id', $matchId)
            ->whereNull('consumed_at')
            ->where('connect_expires_at', '>', now())
            ->update(['connect_expires_at' => now()]);

        $plain = $this->connectToken();
        $ttl = $this->intSetting($clubId, 'token_connect_ttl', 'token_connect_ttl');
        AcTicket::query()->create([
            'user_id' => $session->user_id,
            'session_id' => $session->id,
            'club_id' => $clubId > 0 ? $clubId : null,
            'match_id' => $matchId,
            'steam_id' => $steam,
            'token_hash' => hash('sha256', $plain),
            'scope' => $scope === AcBan::SCOPE_TOURNAMENT ? AcBan::SCOPE_TOURNAMENT : AcBan::SCOPE_MATCH_MAKING,
            'connect_expires_at' => now()->addSeconds($ttl),
        ]);
        $session->match_id = $matchId;
        $session->save();

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                'token' => $plain,
                'connect' => $this->connectLine($plain),
                'expires_in' => $ttl,
                'match_id' => $matchId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateToken(string $steamId, string $token): array
    {
        $clubId = $this->primaryClubId();
        $mode = $this->mode($clubId);
        if ($mode === 'off') {
            return $this->allow('mode_off');
        }
        if ($mode === 'telemetry') {
            return $this->allow('telemetry');
        }

        $token = trim($token);
        $steam = $this->steam($steamId);
        if ($token === '' || ! preg_match('/^[A-Z2-9]{10}$/', $token)) {
            return $this->deny('deny_no_token');
        }

        $ticket = AcTicket::query()->where('token_hash', hash('sha256', $token))->first();
        if (! $ticket) {
            return $this->deny('deny_unknown');
        }
        $clubId = $ticket->club_id ? (int) $ticket->club_id : $clubId;
        if ($this->mode($clubId) !== 'gate') {
            return $this->allow($this->mode($clubId) === 'telemetry' ? 'telemetry' : 'mode_off');
        }
        if ($this->steam((string) $ticket->steam_id) !== $steam) {
            return $this->deny('deny_steam');
        }
        if ($ticket->consumed_at) {
            return $this->deny('token_already_used');
        }
        if ($ticket->connect_expires_at && $ticket->connect_expires_at->isPast()) {
            return $this->deny('deny_expired');
        }

        $session = $ticket->session;
        if (! $session || ! $this->homeAlive($session, $this->staleSeconds($clubId))) {
            return $this->deny('deny_stale');
        }
        if (! $session->integrity_ok) {
            return $this->deny('deny_integrity');
        }
        $user = $session->user;
        if ($user && $this->blocksConnect($user, $session->hwid_digest, (string) $ticket->scope)) {
            return $this->deny('deny_banned');
        }

        $ticket->consumed_at = now();
        $ticket->save();
        $session->match_id = $ticket->match_id;
        $session->last_heartbeat_at = $session->last_heartbeat_at ?: now();
        $session->save();
        $this->event($session, 'validated', 'connect_token принят', ['match_id' => $ticket->match_id]);

        return $this->allow('consumed', [
            'match_id' => $ticket->match_id,
            'kind' => 'home',
            'steam_id' => $steam,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateStation(string $clientIp, string $steamId, ?string $matchId = null): array
    {
        $clubId = $this->primaryClubId();
        $mode = $this->mode($clubId);
        if ($mode !== 'gate') {
            return $this->allow($mode === 'telemetry' ? 'telemetry' : 'mode_off');
        }

        $steam = $this->steam($steamId);
        $computer = Computer::query()
            ->where('lan_ip', $clientIp)
            ->orderByDesc('last_seen_at')
            ->first();
        $seen = (int) config('reactor_ac.station_seen_sec', 90);
        if (! $computer || ! $computer->last_seen_at || $computer->last_seen_at->lt(now()->subSeconds($seen))) {
            return $this->deny('deny_no_session');
        }

        $booking = Booking::query()
            ->where('computer_id', $computer->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
        if (! $booking) {
            return $this->deny('deny_no_session');
        }

        $bound = LanBountyEvent::query()
            ->where('computer_id', $computer->id)
            ->where('booking_id', $booking->id)
            ->whereNotNull('steam_id')
            ->where('steam_id', '!=', '')
            ->latest('occurred_at')
            ->first();
        if (! $bound) {
            return $this->deny('deny_no_steam');
        }
        if ($this->steam((string) $bound->steam_id) !== $steam) {
            return $this->deny('deny_steam');
        }

        $user = User::query()->find($booking->user_id);
        if ($user && $this->banned($user, null, AcBan::SCOPE_FULL)) {
            return $this->deny('deny_banned');
        }

        $match = $matchId ? mb_substr(trim($matchId), 0, 64) : null;
        AcSession::query()->updateOrCreate(
            [
                'user_id' => $booking->user_id,
                'kind' => 'station',
                'computer_id' => $computer->id,
            ],
            [
                'club_id' => $computer->club_id,
                'steam_id' => $steam,
                'status' => 'online',
                'match_id' => $match,
                'integrity_ok' => true,
                'last_heartbeat_at' => now(),
            ]
        );

        return $this->allow('station', [
            'kind' => 'station',
            'steam_id' => $steam,
            'match_id' => $match,
        ]);
    }

    /**
     * @param  list<string>  $steamIds
     * @return array<string, mixed>
     */
    public function heartbeatCheck(array $steamIds): array
    {
        $clubId = $this->primaryClubId();
        $mode = $this->mode($clubId);
        $rows = [];
        foreach (array_slice($steamIds, 0, 64) as $raw) {
            $steam = $this->steam((string) $raw);
            if ($steam === '') {
                continue;
            }
            if ($mode !== 'gate') {
                $rows[] = ['steam_id' => $steam, 'allow' => true, 'reason' => $mode === 'telemetry' ? 'telemetry' : 'mode_off'];
                continue;
            }
            $rows[] = $this->verdictForSteam($steam, $clubId);
        }

        return [
            'allow' => true,
            'reason' => 'batch',
            'grace_cycles' => $this->intSetting($clubId, 'keepalive_http_grace_cycles', 'keepalive_http_grace_cycles'),
            'retry_sec' => $this->intSetting($clubId, 'keepalive_http_retry_sec', 'keepalive_http_retry_sec'),
            'players' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activeSessions(string $matchId): array
    {
        $clubId = $this->primaryClubId();
        $mode = $this->mode($clubId);
        $matchId = mb_substr(trim($matchId), 0, 64);
        if ($mode !== 'gate' || $matchId === '') {
            return ['allow' => true, 'reason' => $mode === 'gate' ? 'empty' : $mode, 'sessions' => []];
        }

        $grace = $this->intSetting($clubId, 'disconnect_grace_sec', 'disconnect_grace_sec');
        $since = now()->subSeconds($grace);
        $sessions = AcSession::query()
            ->where('match_id', $matchId)
            ->where('status', 'online')
            ->where('last_heartbeat_at', '>=', $since)
            ->get()
            ->map(fn (AcSession $row) => [
                'steam_id' => (string) $row->steam_id,
                'kind' => (string) $row->kind,
                'match_id' => (string) $row->match_id,
                'user_id' => (int) $row->user_id,
            ])
            ->filter(fn (array $row) => $row['steam_id'] !== '')
            ->values()
            ->all();

        return ['allow' => true, 'reason' => 'refilled', 'sessions' => $sessions];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateSession(string $steamId, string $matchId): array
    {
        $clubId = $this->primaryClubId();
        $mode = $this->mode($clubId);
        if ($mode !== 'gate') {
            return $this->allow($mode === 'telemetry' ? 'telemetry' : 'mode_off');
        }
        $steam = $this->steam($steamId);
        $matchId = mb_substr(trim($matchId), 0, 64);
        $grace = $this->intSetting($clubId, 'disconnect_grace_sec', 'disconnect_grace_sec');
        $session = AcSession::query()
            ->where('steam_id', $steam)
            ->where('match_id', $matchId)
            ->where('status', 'online')
            ->where('last_heartbeat_at', '>=', now()->subSeconds($grace))
            ->latest('id')
            ->first();
        if (! $session) {
            return $this->deny('deny_no_session');
        }
        $user = $session->user;
        if ($user && $session->kind === 'home' && $this->blocksConnect($user, $session->hwid_digest, AcBan::SCOPE_MATCH_MAKING)) {
            return $this->deny('deny_banned');
        }

        return $this->allow('session', [
            'kind' => $session->kind,
            'steam_id' => $steam,
            'match_id' => $matchId,
        ]);
    }

    public function kickAck(string $steamId, string $reason): void
    {
        $steam = $this->steam($steamId);
        $session = AcSession::query()->where('steam_id', $steam)->where('status', 'online')->latest('id')->first();
        if ($session) {
            $this->event($session, 'kick', ReactorAcKicks::text($reason), ['reason' => $reason]);
        }
    }

    /**
     * @return array{ok:bool,status:int,body:array<string,mixed>}
     */
    public function storeEvidence(AcSession $session, string $kind, UploadedFile $file): array
    {
        $mode = $this->mode($session->club_id ? (int) $session->club_id : null);
        if ($mode === 'off') {
            return $this->fail(409, 'mode_off', 'REACTOR AC в клубе выключен.');
        }
        if (! in_array($kind, ['screenshot', 'proclist'], true)) {
            return $this->fail(422, 'bad_evidence', 'Нужен screenshot или proclist.');
        }
        if ($file->getSize() > 3 * 1024 * 1024) {
            return $this->fail(422, 'bad_evidence', 'Файл больше 3 МБ.');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $sha = hash('sha256', $bytes);
        $path = 'ac-evidence/'.$session->user_id.'/'.$sha;
        Storage::disk('local')->put($path, $bytes);
        $days = $this->intSetting($session->club_id ? (int) $session->club_id : null, 'evidence_retention_days', 'evidence_retention_days');
        $row = AcEvidence::query()->create([
            'user_id' => $session->user_id,
            'session_id' => $session->id,
            'club_id' => $session->club_id,
            'kind' => $kind,
            'path' => $path,
            'bytes' => strlen($bytes),
            'sha256' => $sha,
            'expires_at' => now()->addDays($days),
        ]);

        return [
            'ok' => true,
            'status' => 200,
            'body' => ['id' => $row->id, 'sha256' => $sha],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(?int $clubId): array
    {
        return [
            'mode' => $this->mode($clubId),
            'stack' => (string) config('reactor_ac.stack'),
            'min_os' => (string) config('reactor_ac.min_os'),
            'heartbeat_interval' => $this->intSetting($clubId, 'heartbeat_interval', 'heartbeat_interval'),
            'heartbeat_stale_sec' => $this->staleSeconds($clubId),
            'kicks' => ReactorAcKicks::all(),
        ];
    }

    public function secretOk(?string $given): bool
    {
        $expected = (string) config('reactor_ac.server_secret');
        $given = (string) $given;
        if ($expected === '' || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(?int $clubId): array
    {
        $clubId = $clubId && $clubId > 0 ? $clubId : $this->primaryClubId();
        $stale = $this->staleSeconds($clubId);
        $since = now()->subSeconds($stale);

        $sessions = Schema::hasTable('ac_sessions')
            ? AcSession::query()
                ->with('user:id,name,phone')
                ->when($clubId > 0, fn ($q) => $q->where('club_id', $clubId))
                ->latest('id')
                ->limit(40)
                ->get()
                ->map(fn (AcSession $row) => [
                    'id' => $row->id,
                    'user_id' => $row->user_id,
                    'name' => $row->user?->name,
                    'phone' => $row->user?->phone,
                    'kind' => $row->kind,
                    'status' => $row->status,
                    'steam_id' => $row->steam_id,
                    'match_id' => $row->match_id,
                    'integrity_ok' => $row->integrity_ok,
                    'alive' => $row->last_heartbeat_at && $row->last_heartbeat_at->gte($since),
                    'last_heartbeat_at' => optional($row->last_heartbeat_at)->toIso8601String(),
                ])
                ->all()
            : [];

        $bans = Schema::hasTable('ac_bans')
            ? AcBan::query()->with('user:id,name,phone')->latest('id')->limit(40)->get()->map(fn (AcBan $ban) => [
                'id' => $ban->id,
                'user_id' => $ban->user_id,
                'name' => $ban->user?->name,
                'scope' => $ban->scope,
                'reason' => $ban->reason,
                'active' => $ban->pardoned_at === null && ($ban->ends_at === null || $ban->ends_at->isFuture()),
                'ends_at' => optional($ban->ends_at)->toIso8601String(),
                'pardoned_at' => optional($ban->pardoned_at)->toIso8601String(),
            ])->all()
            : [];

        $events = Schema::hasTable('ac_events')
            ? AcEvent::query()->latest('id')->limit(30)->get()->map(fn (AcEvent $event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'steam_id' => $event->steam_id,
                'message' => $event->message,
                'at' => $event->created_at?->toIso8601String(),
            ])->all()
            : [];

        $homeOnline = collect($sessions)->where('kind', 'home')->where('alive', true)->count();

        return [
            'mode' => $this->mode($clubId),
            'stack' => (string) config('reactor_ac.stack'),
            'home_online' => $homeOnline,
            'sessions' => $sessions,
            'bans' => $bans,
            'events' => $events,
            'kicks' => ReactorAcKicks::all(),
        ];
    }

    public function ban(User $user, string $scope, string $reason, int $days, ?int $adminId): AcBan
    {
        if (! in_array($scope, AcBan::SCOPES, true)) {
            $scope = AcBan::SCOPE_MATCH_MAKING;
        }
        $days = max(0, $days);

        return AcBan::query()->create([
            'user_id' => $user->id,
            'scope' => $scope,
            'reason' => mb_substr(trim($reason), 0, 255) ?: null,
            'created_by' => $adminId,
            'starts_at' => now(),
            'ends_at' => $days > 0 ? now()->addDays($days) : null,
        ]);
    }

    public function pardon(AcBan $ban): void
    {
        $ban->pardoned_at = now();
        $ban->save();
    }

    private function liveHomeSession(User $user): ?AcSession
    {
        if (! Schema::hasTable('ac_sessions')) {
            return null;
        }
        $clubId = $this->clubIdForUser($user);

        return AcSession::query()
            ->where('user_id', $user->id)
            ->where('kind', 'home')
            ->where('status', 'online')
            ->where('last_heartbeat_at', '>=', now()->subSeconds($this->staleSeconds($clubId)))
            ->latest('id')
            ->first();
    }

    private function blocksConnect(User $user, ?string $hwid, string $scope): bool
    {
        if ($this->banned($user, $hwid, AcBan::SCOPE_FULL) || $this->banned($user, $hwid, AcBan::SCOPE_MATCH_MAKING)) {
            return true;
        }

        return $scope === AcBan::SCOPE_TOURNAMENT && $this->banned($user, $hwid, AcBan::SCOPE_TOURNAMENT);
    }

    private function banned(User $user, ?string $hwid, string $scope): bool
    {
        return $this->activeBan($user, $hwid, $scope) !== null;
    }

    private function activeBan(User $user, ?string $hwid, ?string $scope = null): ?AcBan
    {
        if (! Schema::hasTable('ac_bans')) {
            return null;
        }
        $query = AcBan::query()
            ->whereNull('pardoned_at')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->where(function ($q) use ($user, $hwid) {
                $q->where('user_id', $user->id);
                if ($hwid) {
                    $q->orWhere('hwid_digest', $hwid);
                }
            });
        if ($scope) {
            $query->where('scope', $scope);
        }

        return $query->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function verdictForSteam(string $steam, int $clubId): array
    {
        $stale = $this->staleSeconds($clubId);
        $home = AcSession::query()
            ->where('kind', 'home')
            ->where('status', 'online')
            ->where('steam_id', $steam)
            ->latest('id')
            ->first();
        if ($home && $this->homeAlive($home, $stale)) {
            $user = $home->user;
            if ($user && $this->blocksConnect($user, $home->hwid_digest, AcBan::SCOPE_MATCH_MAKING)) {
                return ['steam_id' => $steam, 'allow' => false, 'reason' => 'deny_banned', 'kick' => ReactorAcKicks::text('deny_banned')];
            }

            return ['steam_id' => $steam, 'allow' => true, 'reason' => 'home'];
        }

        $station = AcSession::query()
            ->where('kind', 'station')
            ->where('status', 'online')
            ->where('steam_id', $steam)
            ->where('last_heartbeat_at', '>=', now()->subSeconds((int) config('reactor_ac.station_seen_sec', 90)))
            ->latest('id')
            ->first();
        if ($station && $station->computer_id) {
            $booking = Booking::query()
                ->where('computer_id', $station->computer_id)
                ->where('status', 'active')
                ->exists();
            if ($booking) {
                return ['steam_id' => $steam, 'allow' => true, 'reason' => 'station'];
            }

            return ['steam_id' => $steam, 'allow' => false, 'reason' => 'deny_no_session', 'kick' => ReactorAcKicks::text('deny_no_session')];
        }

        return ['steam_id' => $steam, 'allow' => false, 'reason' => 'deny_stale', 'kick' => ReactorAcKicks::text('deny_stale')];
    }

    private function homeAlive(AcSession $session, int $staleSeconds): bool
    {
        return $session->status === 'online'
            && $session->last_heartbeat_at
            && $session->last_heartbeat_at->gte(now()->subSeconds($staleSeconds));
    }

    private function staleSeconds(?int $clubId): int
    {
        return $this->intSetting($clubId, 'heartbeat_stale_sec', 'heartbeat_stale_sec');
    }

    private function intSetting(?int $clubId, string $field, string $configKey): int
    {
        $value = $this->features->setting($clubId, 'reactor_ac', $field);
        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) config('reactor_ac.'.$configKey);
    }

    private function connectToken(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < 10; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $token;
    }

    private function connectLine(string $token): ?string
    {
        $base = trim((string) env('ARENA_CS2_CONNECT', ''));
        $base = (string) preg_replace('/;\s*password\s+\S+.*/i', '', $base);
        $base = rtrim($base, '; ');
        if ($base === '') {
            return null;
        }
        if (! str_starts_with(strtolower($base), 'connect ')) {
            $base = 'connect '.$base;
        }

        return $base.'; password '.$token;
    }

    private function findByPhone(string $phone): ?User
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $local = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

        return User::query()
            ->where('phone', $phone)
            ->orWhere('phone', $digits)
            ->orWhere('phone', '7'.$local)
            ->orWhere('phone', '+7'.$local)
            ->orWhere('phone', '8'.$local)
            ->first();
    }

    private function steam(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) < 15 || strlen($digits) > 20) {
            return '';
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(AcSession $session, string $kind, string $message, array $extra = []): void
    {
        AcEvent::query()->create([
            'user_id' => $session->user_id,
            'session_id' => $session->id,
            'club_id' => $session->club_id,
            'kind' => $kind,
            'steam_id' => $session->steam_id,
            'message' => mb_substr($message, 0, 255),
            'payload' => $extra ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function allow(string $reason, array $extra = []): array
    {
        return array_merge(['allow' => true, 'reason' => $reason, 'kick' => null], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function deny(string $reason): array
    {
        return [
            'allow' => false,
            'reason' => $reason,
            'kick' => ReactorAcKicks::text($reason),
        ];
    }

    /**
     * @return array{ok:bool,status:int,body:array<string,mixed>}
     */
    private function fail(int $status, string $reason, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'body' => [
                'ok' => false,
                'allow' => false,
                'reason' => $reason,
                'message' => $message,
                'kick' => ReactorAcKicks::TEXTS[$reason] ?? null,
            ],
        ];
    }
}
