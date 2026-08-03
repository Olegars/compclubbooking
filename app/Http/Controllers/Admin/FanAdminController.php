<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\RelayBoard;
use App\Models\Space;
use App\Models\SpaceFan;
use App\Services\Fan\FanControlService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FanAdminController extends Controller
{
    public function index(Request $request)
    {
        $clubs = Club::query()->select('id', 'name')->orderBy('name')->get();
        $clubId = (int) ($request->integer('club_id') ?: ($clubs->first()?->id ?? 0));

        $boards = RelayBoard::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('name')
            ->get();

        $fans = SpaceFan::query()
            ->with(['relayBoard:id,name,host,port,driver', 'space:id,name,zone_id'])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('space_id')
            ->get();

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
            ]);

        $club = $clubId ? Club::query()->find($clubId) : null;
        $mapConfig = is_array($club?->map_config) ? $club->map_config : [];

        $mapPreview = [
            'viewbox' => $mapConfig['viewbox'] ?? null,
            'walls' => is_array($mapConfig['walls'] ?? null) ? $mapConfig['walls'] : [],
            'labels' => is_array($mapConfig['labels'] ?? null) ? $mapConfig['labels'] : [],
        ];

        return Inertia::render('Admin/Fans', [
            'clubs' => $clubs,
            'clubId' => $clubId,
            'boards' => $boards,
            'fans' => $fans,
            'spaces' => $spaces,
            'mapPreview' => $mapPreview,
            'defaults' => [
                'port' => (int) config('fan.w5100_default_port', 30000),
                'thermal_on_c' => (int) config('fan.thermal_on_c', 75),
                'thermal_off_c' => (int) config('fan.thermal_off_c', 65),
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

    public function storeFan(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'space_id' => [
                'required',
                'integer',
                Rule::exists('spaces', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
                Rule::unique('space_fans', 'space_id'),
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

        $this->assertChannelsFree(
            (int) $data['relay_board_id'],
            (int) $data['channel'],
            (int) $data['channel2'],
        );

        SpaceFan::create([
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

    public function destroyFan(SpaceFan $fan)
    {
        $fan->delete();

        return back()->with('success', 'Вентилятор удалён');
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

    private function assertChannelsFree(int $boardId, int $ch1, int $ch2, ?int $ignoreFanId = null): void
    {
        $q = SpaceFan::query()->where('relay_board_id', $boardId);
        if ($ignoreFanId) {
            $q->where('id', '!=', $ignoreFanId);
        }

        foreach ($q->get(['id', 'channel', 'channel2']) as $row) {
            $used = [(int) $row->channel, (int) $row->channel2];
            if (in_array($ch1, $used, true) || in_array($ch2, $used, true)) {
                abort(422, 'Канал K1/K2 уже занят на этой плате другим вентилятором');
            }
        }
    }
}
