<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Computer;
use App\Models\DmxNode;
use App\Models\Space;
use App\Models\SpaceLight;
use App\Models\ClubLightSetting;
use App\Models\WledController;
use App\Services\Fan\FanControlService;
use App\Services\Light\LightControlService;
use App\Services\Light\LightEventCatalog;
use App\Services\Light\WledCorridorCatalog;
use App\Services\Light\WledCueService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Inertia\Inertia;

class LightAdminController extends Controller
{
    public function index(Request $request, LightEventCatalog $catalog)
    {
        $clubs = Club::visibleToAdmin(AdminLocation::id())->select('id', 'name')->orderBy('name')->get();
        $clubId = (int) ($request->integer('club_id')
            ?: (AdminLocation::id() ?: ($clubs->first()?->id ?? 0)));

        $nodes = DmxNode::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('name')
            ->get();

        $lights = SpaceLight::query()
            ->with(['dmxNode:id,name,host,port,universe', 'space:id,name,zone_id'])
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
                'zone_name' => $s->zone?->name,
                'has_light' => $lights->contains(fn (SpaceLight $l) => (int) $l->space_id === (int) $s->id),
            ]);

        $computers = Computer::query()
            ->with('space:id,name')
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('name')
            ->get(['id', 'club_id', 'name', 'space_id'])
            ->map(fn (Computer $pc) => [
                'id' => (int) $pc->id,
                'name' => (string) $pc->name,
                'space_id' => $pc->space_id ? (int) $pc->space_id : null,
                'space_name' => $pc->space?->name,
            ]);

        $wled = WledController::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderBy('name')
            ->get()
            ->map(fn (WledController $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'host' => (string) $c->host,
                'http_port' => (int) $c->http_port,
                'is_active' => (bool) $c->is_active,
                'idle_on' => (bool) $c->idle_on,
                'idle_color' => (string) $c->idle_color,
                'idle_brightness' => (int) $c->idle_brightness,
                'bindings' => WledCorridorCatalog::forAdmin(is_array($c->bindings) ? $c->bindings : []),
                'last_error' => $c->last_error,
                'last_played_at' => $c->last_played_at?->toIso8601String(),
            ]);

        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['interactive', 'corridor'], true)) {
            $tab = 'nodes';
        }

        return Inertia::render('Admin/Lights', [
            'clubs' => $clubs,
            'clubId' => $clubId,
            'nodes' => $nodes,
            'lights' => $lights,
            'spaces' => $spaces,
            'computers' => $computers,
            'tab' => $tab,
            'interactiveEvents' => $catalog->adminPayload($clubId ?: null),
            'wledControllers' => $wled,
            'colorOptions' => array_merge(LightEventCatalog::EVENT_COLORS, [SpaceLight::EFFECT_RAINBOW]),
            'defaults' => [
                'port' => (int) config('light.artnet_port', 6454),
                'brightness' => (int) config('light.default_brightness', 80),
            ],
        ]);
    }

    public function saveEvents(Request $request, LightEventCatalog $catalog)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'events' => 'required|array',
        ]);

        $events = $catalog->sanitizeIncoming($data['events']);
        ClubLightSetting::query()->updateOrCreate(
            ['club_id' => (int) $data['club_id']],
            ['events' => $events],
        );

        return redirect()
            ->route('admin.lights', [
                'club_id' => (int) $data['club_id'],
                'tab' => 'interactive',
            ])
            ->with('success', 'Интерактивный свет сохранён');
    }

    public function storeNode(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'name' => 'required|string|max:120',
            'host' => 'required|string|max:120',
            'port' => 'nullable|integer|min:1|max:65535',
            'universe' => 'nullable|integer|min:0|max:32767',
            'is_active' => 'nullable|boolean',
        ]);

        $node = DmxNode::create([
            'club_id' => $data['club_id'],
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => $data['port'] ?? (int) config('light.artnet_port', 6454),
            'universe' => $data['universe'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'Art-Net узел создан #'.$node->id);
    }

    public function updateNode(Request $request, DmxNode $node)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'host' => 'required|string|max:120',
            'port' => 'nullable|integer|min:1|max:65535',
            'universe' => 'nullable|integer|min:0|max:32767',
            'is_active' => 'nullable|boolean',
        ]);

        $node->update([
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => $data['port'] ?? $node->port,
            'universe' => $data['universe'] ?? $node->universe,
            'is_active' => $data['is_active'] ?? $node->is_active,
        ]);

        return back()->with('success', 'Узел обновлён');
    }

    public function destroyNode(DmxNode $node)
    {
        $node->delete();

        return back()->with('success', 'Узел удалён');
    }

    public function storeLight(Request $request, LightControlService $lights, FanControlService $fans)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'computer_id' => [
                'nullable',
                'integer',
                Rule::exists('computers', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
            ],
            'space_id' => [
                'nullable',
                'integer',
                Rule::exists('spaces', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
            ],
            'dmx_node_id' => [
                'required',
                'integer',
                Rule::exists('dmx_nodes', 'id')->where(fn ($q) => $q->where('club_id', $request->integer('club_id'))),
            ],
            'start_channel' => 'required|integer|min:1|max:512',
            'fixture_count' => 'nullable|integer|min:1|max:170',
            'layout' => 'nullable|string|in:rgb,dimmer_rgb,rgbw',
        ]);

        $spaceId = (int) ($data['space_id'] ?? 0);
        if (! empty($data['computer_id'])) {
            $computer = Computer::query()
                ->where('club_id', $data['club_id'])
                ->find((int) $data['computer_id']);
            if (! $computer) {
                return back()->withErrors(['computer_id' => 'ПК не найден в этом клубе']);
            }
            $spaceId = (int) ($fans->ensureSpaceForComputer($computer) ?? 0);
            if ($spaceId <= 0) {
                return back()->withErrors(['computer_id' => 'У ПК нет комнаты. Зона задаётся в setup шелла.']);
            }
        }
        if ($spaceId <= 0) {
            return back()->withErrors(['space_id' => 'Выберите комнату или ПК']);
        }

        if (SpaceLight::query()->where('space_id', $spaceId)->exists()) {
            return back()->withErrors(['space_id' => 'В этой комнате уже есть свет']);
        }

        $layout = SpaceLight::normalizeLayout((string) ($data['layout'] ?? SpaceLight::LAYOUT_RGB));
        $count = max(1, (int) ($data['fixture_count'] ?? 1));
        $start = (int) $data['start_channel'];
        $end = $start + ($count * SpaceLight::channelsPerFixture($layout)) - 1;

        try {
            $lights->assertChannelsFree((int) $data['dmx_node_id'], $start, $end);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['start_channel' => $e->getMessage()]);
        }

        SpaceLight::create([
            'club_id' => $data['club_id'],
            'space_id' => $spaceId,
            'dmx_node_id' => $data['dmx_node_id'],
            'start_channel' => $start,
            'fixture_count' => $count,
            'layout' => $layout,
            'desired_color' => config('light.default_color', 'white'),
            'desired_brightness' => 0,
            'desired_effect' => SpaceLight::EFFECT_NONE,
            'last_on_color' => config('light.default_color', 'white'),
            'last_on_brightness' => (int) config('light.default_brightness', 80),
            'last_on_effect' => SpaceLight::EFFECT_NONE,
            'vacant' => true,
        ]);

        return back()->with('success', 'Свет привязан к комнате');
    }

    public function updateLight(Request $request, SpaceLight $light, LightControlService $lights)
    {
        $data = $request->validate([
            'dmx_node_id' => [
                'required',
                'integer',
                Rule::exists('dmx_nodes', 'id')->where(fn ($q) => $q->where('club_id', $light->club_id)),
            ],
            'start_channel' => 'required|integer|min:1|max:512',
            'fixture_count' => 'nullable|integer|min:1|max:170',
            'layout' => 'nullable|string|in:rgb,dimmer_rgb,rgbw',
        ]);

        $layout = SpaceLight::normalizeLayout((string) ($data['layout'] ?? $light->layout));
        $count = max(1, (int) ($data['fixture_count'] ?? $light->fixture_count));
        $start = (int) $data['start_channel'];
        $end = $start + ($count * SpaceLight::channelsPerFixture($layout)) - 1;

        try {
            $lights->assertChannelsFree((int) $data['dmx_node_id'], $start, $end, (int) $light->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['start_channel' => $e->getMessage()]);
        }

        $light->update([
            'dmx_node_id' => $data['dmx_node_id'],
            'start_channel' => $start,
            'fixture_count' => $count,
            'layout' => $layout,
        ]);

        return back()->with('success', 'Свет обновлён');
    }

    public function destroyLight(SpaceLight $light)
    {
        $light->delete();

        return back()->with('success', 'Свет отвязан');
    }

    public function storeWled(Request $request)
    {
        $this->normalizeWledHost($request);
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'name' => 'required|string|max:120',
            'host' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'http_port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'nullable|boolean',
        ]);

        $node = WledController::create([
            'club_id' => (int) $data['club_id'],
            'name' => $data['name'],
            'host' => $data['host'],
            'http_port' => (int) ($data['http_port'] ?? 80),
            'is_active' => $data['is_active'] ?? true,
            'idle_on' => false,
            'idle_color' => 'white',
            'idle_brightness' => 15,
            'bindings' => WledCorridorCatalog::defaultMap(),
        ]);

        return redirect()
            ->route('admin.lights', ['club_id' => (int) $data['club_id'], 'tab' => 'corridor'])
            ->with('success', 'Контроллер GLEDOPTO добавлен #'.$node->id);
    }

    public function updateWled(Request $request, WledController $wled)
    {
        $this->normalizeWledHost($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'host' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'http_port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'nullable|boolean',
            'idle_on' => 'nullable|boolean',
            'idle_color' => 'nullable|string|max:32',
            'idle_brightness' => 'nullable|integer|min:1|max:100',
            'bindings' => 'required|array',
        ]);

        $color = (string) ($data['idle_color'] ?? $wled->idle_color);
        if (! in_array($color, LightEventCatalog::EVENT_COLORS, true)) {
            $color = 'white';
        }

        $wled->update([
            'name' => $data['name'],
            'host' => $data['host'],
            'http_port' => (int) ($data['http_port'] ?? $wled->http_port),
            'is_active' => (bool) ($data['is_active'] ?? $wled->is_active),
            'idle_on' => (bool) ($data['idle_on'] ?? false),
            'idle_color' => $color,
            'idle_brightness' => (int) ($data['idle_brightness'] ?? $wled->idle_brightness),
            'bindings' => WledCorridorCatalog::sanitizeMap($data['bindings']),
        ]);

        return redirect()
            ->route('admin.lights', ['club_id' => (int) $wled->club_id, 'tab' => 'corridor'])
            ->with('success', 'Коридорный контроллер сохранён');
    }

    public function destroyWled(WledController $wled)
    {
        $clubId = (int) $wled->club_id;
        $wled->delete();

        return redirect()
            ->route('admin.lights', ['club_id' => $clubId, 'tab' => 'corridor'])
            ->with('success', 'Контроллер удалён');
    }

    public function testWled(WledController $wled, WledCueService $wledCues)
    {
        $wledCues->flashTest($wled);

        return redirect()
            ->route('admin.lights', ['club_id' => (int) $wled->club_id, 'tab' => 'corridor'])
            ->with('success', 'Тест в очереди — мигнёт, когда шелл в сети');
    }

    private function normalizeWledHost(Request $request): void
    {
        $host = trim((string) $request->input('host'));
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = trim($host, "/ \t");
        if (preg_match('#^([^/:]+):(\d+)$#', $host, $m)) {
            $host = $m[1];
            $port = (int) $request->input('http_port', 80);
            if ($port === 80) {
                $request->merge(['http_port' => (int) $m[2]]);
            }
        }
        $request->merge(['host' => $host]);
    }
}
