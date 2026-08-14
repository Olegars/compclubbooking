<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceSetting;
use App\Services\VideoMarkerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VideoSurveillanceController extends Controller
{
    public function index(Request $request, VideoMarkerService $markers)
    {
        $clubId = (int) ($request->input('club_id') ?: Club::query()->value('id'));
        $settings = VideoSurveillanceSetting::forClub($clubId);

        $events = VideoSurveillanceEvent::query()
            ->where('club_id', $clubId)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (VideoSurveillanceEvent $e) => $e->toAdminArray())
            ->values();

        return Inertia::render('Admin/VideoSurveillance', [
            'settings' => $settings->toAdminArray(),
            'events' => $events,
            'providers' => VideoSurveillanceSetting::PROVIDERS,
            'triggers' => VideoSurveillanceSetting::TRIGGERS,
            'clubs' => Club::query()->select('id', 'name')->orderBy('name')->get(),
            'pending_jobs' => $markers->pendingCount($clubId),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'is_enabled' => 'required|boolean',
            'provider' => 'required|string|in:'.implode(',', array_keys(VideoSurveillanceSetting::PROVIDERS)),
            'api_base_url' => 'nullable|string|max:512',
            'api_login' => 'nullable|string|max:191',
            'api_secret' => 'nullable|string|max:2000',
            'clear_api_secret' => 'nullable|boolean',
            'marker_duration_sec' => 'required|integer|min:5|max:600',
            'marker_pre_sec' => 'required|integer|min:0|max:300',
            'default_channel' => 'nullable|string|max:128',
            'webhook_path' => 'nullable|string|max:255',
            'webhook_method' => 'required|string|in:POST,PUT,PATCH',
            'notes' => 'nullable|string|max:2000',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        $row = VideoSurveillanceSetting::forClub($clubId);

        $payload = [
            'is_enabled' => (bool) $data['is_enabled'],
            'provider' => $data['provider'],
            'api_base_url' => $data['api_base_url'] ?: null,
            'api_login' => $data['api_login'] ?: null,
            'marker_duration_sec' => (int) $data['marker_duration_sec'],
            'marker_pre_sec' => (int) $data['marker_pre_sec'],
            'default_channel' => $data['default_channel'] ?: null,
            'webhook_path' => $data['webhook_path'] ?: null,
            'webhook_method' => $data['webhook_method'],
            'notes' => $data['notes'] ?: null,
        ];

        if (! empty($data['clear_api_secret'])) {
            $payload['api_secret'] = null;
        } elseif (array_key_exists('api_secret', $data) && filled($data['api_secret'])) {
            $payload['api_secret'] = $data['api_secret'];
        }

        $row->update($payload);

        return back()->with('success', 'Настройки видеонаблюдения сохранены');
    }

    public function storeEvent(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:64|regex:/^[a-z0-9_]+$/',
            'description' => 'nullable|string|max:500',
            'is_enabled' => 'nullable|boolean',
            'trigger_key' => 'nullable|string|max:64',
            'channel' => 'nullable|string|max:128',
            'marker_title' => 'nullable|string|max:191',
            'sort' => 'nullable|integer|min:0|max:9999',
        ]);

        $clubId = (int) ($data['club_id'] ?? Club::query()->value('id'));
        VideoSurveillanceSetting::forClub($clubId);

        $code = $data['code'] ?? null;
        if (! filled($code)) {
            $code = VideoSurveillanceEvent::makeCode($data['name']);
        }

        if (VideoSurveillanceEvent::query()->where('club_id', $clubId)->where('code', $code)->exists()) {
            return back()->withErrors(['code' => 'Код события уже занят']);
        }

        $trigger = $data['trigger_key'] ?? null;
        if ($trigger && ! array_key_exists($trigger, VideoSurveillanceSetting::TRIGGERS)) {
            return back()->withErrors(['trigger_key' => 'Неизвестный триггер']);
        }

        VideoSurveillanceEvent::query()->create([
            'club_id' => $clubId,
            'code' => $code,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'trigger_key' => $trigger,
            'channel' => $data['channel'] ?? null,
            'marker_title' => $data['marker_title'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ]);

        return back()->with('success', 'Событие создано');
    }

    public function updateEvent(Request $request, VideoSurveillanceEvent $event)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'required|string|max:64|regex:/^[a-z0-9_]+$/',
            'description' => 'nullable|string|max:500',
            'is_enabled' => 'required|boolean',
            'trigger_key' => 'nullable|string|max:64',
            'channel' => 'nullable|string|max:128',
            'marker_title' => 'nullable|string|max:191',
            'sort' => 'nullable|integer|min:0|max:9999',
        ]);

        $trigger = $data['trigger_key'] ?? null;
        if ($trigger && ! array_key_exists($trigger, VideoSurveillanceSetting::TRIGGERS)) {
            return back()->withErrors(['trigger_key' => 'Неизвестный триггер']);
        }

        $dup = VideoSurveillanceEvent::query()
            ->where('club_id', $event->club_id)
            ->where('code', $data['code'])
            ->where('id', '!=', $event->id)
            ->exists();
        if ($dup) {
            return back()->withErrors(['code' => 'Код события уже занят']);
        }

        $event->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'is_enabled' => (bool) $data['is_enabled'],
            'trigger_key' => $trigger,
            'channel' => $data['channel'] ?? null,
            'marker_title' => $data['marker_title'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ]);

        return back()->with('success', 'Событие обновлено');
    }

    public function destroyEvent(VideoSurveillanceEvent $event)
    {
        $event->delete();

        return back()->with('success', 'Событие удалено');
    }

    public function test(Request $request, VideoMarkerService $markers)
    {
        $clubId = (int) ($request->input('club_id') ?: Club::query()->value('id'));
        $s = $markers->settings($clubId);

        if (! $s->is_enabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сначала включите интеграцию и сохраните',
            ], 422);
        }

        $ok = $markers->placeMarker([
            'event' => 'admin_test',
            'title' => 'Reactor test marker',
            'meta' => ['source' => 'admin_test'],
        ], $clubId);

        if (! $ok) {
            return response()->json([
                'status' => 'error',
                'message' => 'Сервер не принял запрос (см. laravel.log)',
            ], 422);
        }

        $msg = filled($s->api_base_url)
            ? ($s->provider === 'hikvision'
                ? 'Метка в очереди LAN-агента (ISAPI → NVR)'
                : 'Тестовая метка отправлена')
            : 'Dry-run OK (URL API пуст — метка только в лог)';

        return response()->json(['status' => 'ok', 'message' => $msg]);
    }
}
