<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Computer;
use App\Models\DmxNode;
use App\Models\Space;
use App\Models\SpaceLight;
use App\Services\Fan\FanControlService;
use App\Services\Light\LightControlService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Inertia\Inertia;

class LightAdminController extends Controller
{
    public function index(Request $request)
    {
        $clubs = Club::query()->select('id', 'name')->orderBy('name')->get();
        $clubId = (int) ($request->integer('club_id') ?: ($clubs->first()?->id ?? 0));

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

        return Inertia::render('Admin/Lights', [
            'clubs' => $clubs,
            'clubId' => $clubId,
            'nodes' => $nodes,
            'lights' => $lights,
            'spaces' => $spaces,
            'computers' => $computers,
            'defaults' => [
                'port' => (int) config('light.artnet_port', 6454),
                'brightness' => (int) config('light.default_brightness', 80),
            ],
        ]);
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
}
