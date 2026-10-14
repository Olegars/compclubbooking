<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FireDetector;
use App\Models\FirePanel;
use App\Models\Space;
use App\Services\Fire\FireAlarmService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FireAlarmController extends Controller
{
    public function index()
    {
        $clubId = AdminLocation::id();
        $panel = $clubId
            ? FirePanel::query()->firstOrCreate(
                ['club_id' => $clubId],
                ['port' => 40000, 'panel_address' => 1, 'enabled' => false]
            )
            : null;

        return Inertia::render('Admin/Fire', [
            'panel' => $panel,
            'spaces' => $clubId
                ? Space::query()->where('club_id', $clubId)->orderBy('name')->get(['id', 'name'])
                : [],
            'detectors' => $clubId
                ? FireDetector::query()->where('club_id', $clubId)->orderBy('device_address')->orderBy('loop_address')->get()
                : [],
            'live' => app(FireAlarmService::class)->liveForClub($clubId),
        ]);
    }

    public function savePanel(Request $request)
    {
        $clubId = AdminLocation::id();
        if (! $clubId) {
            return back()->withErrors(['host' => 'Локация не выбрана.']);
        }

        $request->merge(['enabled' => $request->boolean('enabled')]);

        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:120'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'panel_address' => ['required', 'integer', 'min:1', 'max:127'],
            'enabled' => ['required', 'boolean'],
        ]);

        FirePanel::query()->updateOrCreate(
            ['club_id' => $clubId],
            [
                'host' => trim((string) ($data['host'] ?? '')) ?: null,
                'port' => (int) $data['port'],
                'panel_address' => (int) $data['panel_address'],
                'enabled' => (bool) $data['enabled'] && trim((string) ($data['host'] ?? '')) !== '',
            ]
        );

        return back();
    }

    public function saveDetectors(Request $request)
    {
        $clubId = AdminLocation::id();
        if (! $clubId) {
            return back()->withErrors(['detectors' => 'Локация не выбрана.']);
        }

        $data = $request->validate([
            'detectors' => ['present', 'array'],
            'detectors.*.space_id' => ['required', 'integer'],
            'detectors.*.device_address' => ['required', 'integer', 'min:1', 'max:127'],
            'detectors.*.loop_address' => ['required', 'integer', 'min:1', 'max:127'],
        ]);

        $spaceIds = Space::query()->where('club_id', $clubId)->pluck('id')->all();
        $seen = [];
        $rows = [];
        foreach ($data['detectors'] as $row) {
            $spaceId = (int) $row['space_id'];
            if (! in_array($spaceId, $spaceIds, true)) {
                return back()->withErrors(['detectors' => 'Комната не из этой локации.']);
            }
            $key = $row['device_address'].':'.$row['loop_address'];
            if (isset($seen[$key])) {
                return back()->withErrors(['detectors' => 'Два датчика с одним адресом.']);
            }
            $seen[$key] = true;
            $rows[] = [
                'space_id' => $spaceId,
                'device_address' => (int) $row['device_address'],
                'loop_address' => (int) $row['loop_address'],
            ];
        }

        $existing = FireDetector::query()
            ->where('club_id', $clubId)
            ->get()
            ->keyBy(fn (FireDetector $detector) => $detector->device_address.':'.$detector->loop_address);

        $keep = [];
        foreach ($rows as $row) {
            $key = $row['device_address'].':'.$row['loop_address'];
            $detector = $existing->get($key);
            if ($detector) {
                $detector->space_id = $row['space_id'];
                $detector->save();
            } else {
                $detector = FireDetector::query()->create([
                    'club_id' => $clubId,
                    'space_id' => $row['space_id'],
                    'device_address' => $row['device_address'],
                    'loop_address' => $row['loop_address'],
                ]);
            }
            $keep[] = $detector->id;
        }

        FireDetector::query()
            ->where('club_id', $clubId)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('id', $keep))
            ->delete();

        return back();
    }

    public function live()
    {
        return response()->json(app(FireAlarmService::class)->liveForClub(AdminLocation::id()));
    }

    public function reset(FireAlarmService $alarms)
    {
        $clubId = AdminLocation::id();
        if (! $clubId) {
            return response()->json(['message' => 'Локация не выбрана.'], 422);
        }

        $alarms->requestReset($clubId);

        return response()->json([
            'status' => 'queued',
            'live' => $alarms->liveForClub($clubId),
        ]);
    }
}
