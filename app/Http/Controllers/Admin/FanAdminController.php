<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\RelayBoard;
use App\Models\SharedFan;
use App\Models\SharedFanLink;
use App\Models\SharedFanMap;
use App\Models\Space;
use App\Models\SpaceFan;
use App\Services\Fan\FanControlService;
use App\Services\Fan\SharedFanControlService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FanAdminController extends Controller
{
    public function index(Request $request, SharedFanControlService $shared)
    {
        $clubs = Club::query()->select('id', 'name')->orderBy('name')->get();
        $clubId = (int) ($request->integer('club_id') ?: ($clubs->first()?->id ?? 0));

        $boards = RelayBoard::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('name')
            ->get();

        $fans = SpaceFan::query()
            ->with([
                'relayBoard:id,name,host,port,driver',
                'space:id,name,zone_id',
                'sharedFanLink.sharedFan:id,name,kind',
            ])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('space_id')
            ->get();

        $sharedFans = SharedFan::query()
            ->with([
                'relayBoard:id,name,host,port,driver',
                'maps',
                'spaceFans:id,space_id,channel,channel2,desired_power',
                'spaceFans.space:id,name',
            ])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('kind')
            ->orderBy('id')
            ->get()
            ->map(function (SharedFan $sf) use ($shared) {
                $loadPct = $sf->isSupply()
                    ? $shared->loadPctFromSpaceFans(
                        SpaceFan::query()->where('club_id', $sf->club_id)->get(['desired_power'])
                    )
                    : $shared->loadPctFromSpaceFans($sf->spaceFans);

                return [
                    'id' => $sf->id,
                    'club_id' => $sf->club_id,
                    'kind' => $sf->kind,
                    'name' => $sf->name,
                    'relay_board_id' => $sf->relay_board_id,
                    'channel' => $sf->channel,
                    'channel2' => $sf->channel2,
                    'desired_power' => $sf->desired_power,
                    'applied_power' => $sf->applied_power,
                    'load_pct' => $loadPct,
                    'relay_board' => $sf->relayBoard,
                    'maps' => $sf->maps->sortBy('load_pct')->values()->map(fn (SharedFanMap $m) => [
                        'load_pct' => $m->load_pct,
                        'output_pct' => $m->output_pct,
                    ]),
                    'linked_fans' => $sf->spaceFans->map(fn (SpaceFan $f) => [
                        'id' => $f->id,
                        'space_id' => $f->space_id,
                        'space_name' => $f->space?->name,
                        'label' => 'K'.$f->channel.'+K'.$f->channel2,
                        'desired_power' => $f->desired_power,
                    ]),
                ];
            });

        $spaces = Space::query()
            ->with('zone:id,name,color')
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('name')
            ->get()
            ->map(fn (Space $s) => [
                'id' => $s->id,
                'name' => $s->name ?: ('Space #'.$s->id),
                'x' => (float) $s->x,
                'y' => (float) $s->y,
                'w' => (float) $s->w,
                'h' => (float) $s->h,
                'zone_name' => $s->zone?->name,
                'zone_color' => $s->zone?->color,
                'has_fan' => $fans->contains(fn (SpaceFan $f) => (int) $f->space_id === (int) $s->id),
                'fans_count' => $fans->where('space_id', $s->id)->count(),
            ]);

        $club = $clubId ? Club::query()->find($clubId) : null;
        $mapConfig = is_array($club?->map_config) ? $club->map_config : [];

        $mapPreview = [
            'viewbox' => $mapConfig['viewbox'] ?? null,
            'walls' => is_array($mapConfig['walls'] ?? null) ? $mapConfig['walls'] : [],
            'labels' => is_array($mapConfig['labels'] ?? null) ? $mapConfig['labels'] : [],
        ];

        $linkedPersonalIds = SharedFanLink::query()
            ->whereIn('shared_fan_id', $sharedFans->pluck('id'))
            ->pluck('space_fan_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return Inertia::render('Admin/Fans', [
            'clubs' => $clubs,
            'clubId' => $clubId,
            'boards' => $boards,
            'fans' => $fans,
            'sharedFans' => $sharedFans,
            'linkedPersonalIds' => $linkedPersonalIds,
            'spaces' => $spaces,
            'mapPreview' => $mapPreview,
            'defaults' => [
                'port' => (int) config('fan.w5100_default_port', 30000),
                'thermal_on_c' => (int) config('fan.thermal_on_c', 75),
                'thermal_off_c' => (int) config('fan.thermal_off_c', 65),
                'max_per_space' => (int) config('fan.max_per_space', 2),
                'load_steps' => SharedFan::LOAD_STEPS,
            ],
        ]);
    }

    public function storeBoard(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'name' => 'required|string|max:120',
            'host' => 'required|string|max:120',
            'port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'nullable|boolean',
        ]);

        $board = RelayBoard::create([
            'club_id' => $data['club_id'],
            'name' => $data['name'],
            'driver' => RelayBoard::DRIVER_W5100_HTTP,
            'host' => $data['host'],
            'port' => $data['port'] ?? (int) config('fan.w5100_default_port', 30000),
            'meta' => null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'Плата реле создана #'.$board->id);
    }

    public function updateBoard(Request $request, RelayBoard $board)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'host' => 'required|string|max:120',
            'port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'nullable|boolean',
        ]);

        $board->update([
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => $data['port'] ?? $board->port,
            'driver' => RelayBoard::DRIVER_W5100_HTTP,
            'is_active' => $data['is_active'] ?? $board->is_active,
        ]);

        return back()->with('success', 'Плата обновлена');
    }

    public function destroyBoard(RelayBoard $board)
    {
        $board->delete();

        return back()->with('success', 'Плата удалена');
    }

    public function storeFan(Request $request, SharedFanControlService $shared)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'space_id' => [
                'required',
                'integer',
                Rule::exists('spaces', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
            ],
            'relay_board_id' => [
                'required',
                'integer',
                Rule::exists('relay_boards', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
            ],
            'channel' => 'required|integer|min:1|max:16',
            'channel2' => 'required|integer|min:1|max:16|different:channel',
            'thermal_on_c' => 'nullable|integer|min:40|max:120',
            'thermal_off_c' => 'nullable|integer|min:30|max:110',
        ]);

        if (! SpaceFan::isCascadePair((int) $data['channel'], (int) $data['channel2'])) {
            return back()->withErrors(['channel' => 'Только парные реле: 1+2, 3+4, … 15+16']);
        }

        $max = max(1, (int) config('fan.max_per_space', 2));
        $existing = SpaceFan::query()
            ->where('space_id', $data['space_id'])
            ->where('club_id', $data['club_id'])
            ->count();
        if ($existing >= $max) {
            return back()->withErrors(['space_id' => "В комнате уже максимум {$max} вентилятора"]);
        }

        $this->assertChannelsFree(
            (int) $data['relay_board_id'],
            (int) $data['channel'],
            (int) $data['channel2'],
        );

        $fan = SpaceFan::create([
            'club_id' => $data['club_id'],
            'space_id' => $data['space_id'],
            'relay_board_id' => $data['relay_board_id'],
            'channel' => $data['channel'],
            'channel2' => $data['channel2'],
            'manual_mode' => SpaceFan::MODE_AUTO,
            'desired_power' => SpaceFan::SPEED_NIGHT,
            'applied_power' => SpaceFan::SPEED_NIGHT,
            'default_on_power' => SpaceFan::SPEED_HIGH,
            'thermal_on_c' => $data['thermal_on_c'] ?? (int) config('fan.thermal_on_c', 75),
            'thermal_off_c' => $data['thermal_off_c'] ?? (int) config('fan.thermal_off_c', 65),
        ]);

        $shared->recomputeAfterPersonalChange((int) $fan->club_id, [(int) $fan->id]);

        return back()->with('success', 'Вентилятор привязан к комнате (K1+K2)');
    }

    public function updateFan(Request $request, SpaceFan $fan)
    {
        $data = $request->validate([
            'relay_board_id' => [
                'required',
                'integer',
                Rule::exists('relay_boards', 'id')->where(fn ($q) => $q->where('club_id', $fan->club_id)),
            ],
            'channel' => 'required|integer|min:1|max:16',
            'channel2' => 'required|integer|min:1|max:16|different:channel',
            'thermal_on_c' => 'nullable|integer|min:40|max:120',
            'thermal_off_c' => 'nullable|integer|min:30|max:110',
            'manual_mode' => 'nullable|string|in:auto,force_on,force_off',
        ]);

        if (! SpaceFan::isCascadePair((int) $data['channel'], (int) $data['channel2'])) {
            return back()->withErrors(['channel' => 'Только парные реле: 1+2, 3+4, … 15+16']);
        }

        $this->assertChannelsFree(
            (int) $data['relay_board_id'],
            (int) $data['channel'],
            (int) $data['channel2'],
            (int) $fan->id,
        );

        $fan->update([
            'relay_board_id' => $data['relay_board_id'],
            'channel' => $data['channel'],
            'channel2' => $data['channel2'],
            'thermal_on_c' => $data['thermal_on_c'] ?? $fan->thermal_on_c,
            'thermal_off_c' => $data['thermal_off_c'] ?? $fan->thermal_off_c,
            'manual_mode' => $data['manual_mode'] ?? $fan->manual_mode,
        ]);

        return back()->with('success', 'Вентилятор обновлён');
    }

    public function destroyFan(SpaceFan $fan, SharedFanControlService $shared)
    {
        $clubId = (int) $fan->club_id;
        $fanId = (int) $fan->id;
        $fan->delete();
        $shared->recomputeAfterPersonalChange($clubId, [$fanId]);

        return back()->with('success', 'Вентилятор удалён');
    }

    public function storeSharedFan(Request $request, SharedFanControlService $shared)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'kind' => 'required|string|in:supply,exhaust',
            'name' => 'required|string|max:120',
            'relay_board_id' => [
                'required',
                'integer',
                Rule::exists('relay_boards', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
            ],
            'channel' => 'required|integer|min:1|max:16',
            'channel2' => 'required|integer|min:1|max:16|different:channel',
        ]);

        if (! SpaceFan::isCascadePair((int) $data['channel'], (int) $data['channel2'])) {
            return back()->withErrors(['channel' => 'Только парные реле: 1+2, 3+4, … 15+16']);
        }

        $this->assertChannelsFree(
            (int) $data['relay_board_id'],
            (int) $data['channel'],
            (int) $data['channel2'],
        );

        $sf = SharedFan::create([
            'club_id' => $data['club_id'],
            'kind' => $data['kind'],
            'name' => $data['name'],
            'relay_board_id' => $data['relay_board_id'],
            'channel' => $data['channel'],
            'channel2' => $data['channel2'],
            'desired_power' => SpaceFan::SPEED_NIGHT,
            'applied_power' => SpaceFan::SPEED_NIGHT,
        ]);
        $sf->seedDefaultMaps();
        $shared->recomputeSharedFan((int) $sf->id);

        return back()->with('success', 'Общий вентилятор создан #'.$sf->id);
    }

    public function updateSharedMaps(Request $request, SharedFan $sharedFan, SharedFanControlService $shared)
    {
        $data = $request->validate([
            'maps' => 'required|array',
            'maps.*.load_pct' => 'required|integer|in:50,60,70,80,90,100',
            'maps.*.output_pct' => 'required|integer|in:50,100',
        ]);

        foreach ($data['maps'] as $row) {
            SharedFanMap::query()->updateOrCreate(
                [
                    'shared_fan_id' => $sharedFan->id,
                    'load_pct' => (int) $row['load_pct'],
                ],
                ['output_pct' => (int) $row['output_pct']]
            );
        }

        $shared->recomputeSharedFan((int) $sharedFan->id);

        return back()->with('success', 'Сопоставление обновлено');
    }

    public function linkSharedFan(Request $request, SharedFan $sharedFan, SharedFanControlService $shared)
    {
        if (! $sharedFan->isExhaust()) {
            return back()->withErrors(['kind' => 'Привязка только для вытяжки']);
        }

        $data = $request->validate([
            'space_fan_id' => [
                'required',
                'integer',
                Rule::exists('space_fans', 'id')->where(fn ($q) => $q->where('club_id', $sharedFan->club_id)),
            ],
        ]);

        $spaceFanId = (int) $data['space_fan_id'];
        $existing = SharedFanLink::query()->where('space_fan_id', $spaceFanId)->first();
        if ($existing && (int) $existing->shared_fan_id !== (int) $sharedFan->id) {
            return back()->withErrors(['space_fan_id' => 'Уже привязан к другой вытяжке']);
        }

        SharedFanLink::query()->updateOrCreate(
            ['space_fan_id' => $spaceFanId],
            ['shared_fan_id' => $sharedFan->id]
        );

        $shared->recomputeSharedFan((int) $sharedFan->id);

        return back()->with('success', 'Персональный вентилятор привязан к вытяжке');
    }

    public function unlinkSharedFan(Request $request, SharedFan $sharedFan, SharedFanControlService $shared)
    {
        $data = $request->validate([
            'space_fan_id' => 'required|integer',
        ]);

        SharedFanLink::query()
            ->where('shared_fan_id', $sharedFan->id)
            ->where('space_fan_id', (int) $data['space_fan_id'])
            ->delete();

        $shared->recomputeSharedFan((int) $sharedFan->id);

        return back()->with('success', 'Привязка снята');
    }

    public function destroySharedFan(SharedFan $sharedFan)
    {
        $sharedFan->delete();

        return back()->with('success', 'Общий вентилятор удалён');
    }

    public function forceOff(SpaceFan $fan, FanControlService $fans)
    {
        $result = $fans->adminForceOff((int) $fan->id);

        return response()->json([
            'status' => 'success',
            'fan_id' => $fan->id,
            'wol_computer_id' => $result['wol_computer_id'],
            'manual_mode' => $result['fan']?->manual_mode,
            'applied_power' => $result['fan']?->applied_power,
            'message' => $result['wol_computer_id']
                ? 'Дежурный режим (120В), будим ПК #'.$result['wol_computer_id']
                : 'Дежурный режим (120В) задан',
        ]);
    }

    private function assertChannelsFree(
        int $boardId,
        int $ch1,
        int $ch2,
        ?int $ignoreSpaceFanId = null,
        ?int $ignoreSharedFanId = null,
    ): void {
        $q = SpaceFan::query()->where('relay_board_id', $boardId);
        if ($ignoreSpaceFanId) {
            $q->where('id', '!=', $ignoreSpaceFanId);
        }
        foreach ($q->get(['id', 'channel', 'channel2']) as $row) {
            $used = [(int) $row->channel, (int) $row->channel2];
            if (in_array($ch1, $used, true) || in_array($ch2, $used, true)) {
                abort(422, 'Канал K1/K2 уже занят персональным вентилятором');
            }
        }

        $sq = SharedFan::query()->where('relay_board_id', $boardId);
        if ($ignoreSharedFanId) {
            $sq->where('id', '!=', $ignoreSharedFanId);
        }
        foreach ($sq->get(['id', 'channel', 'channel2']) as $row) {
            $used = [(int) $row->channel, (int) $row->channel2];
            if (in_array($ch1, $used, true) || in_array($ch2, $used, true)) {
                abort(422, 'Канал K1/K2 уже занят общим вентилятором');
            }
        }
    }
}
