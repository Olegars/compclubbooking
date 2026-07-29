<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\CalendarDayOverride;
use App\Models\Club;
use App\Models\DayGroup;
use App\Models\Tariff;
use App\Models\TariffPrice;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TariffController extends Controller
{
    public function index(Request $request)
    {
        $clubs = Club::query()->orderBy('name')->get(['id', 'name']);
        $clubId = (int) ($request->query('club') ?: $clubs->first()?->id);
        $tariffId = $request->query('tariff') ? (int) $request->query('tariff') : null;

        $tariffs = Tariff::query()
            ->orderBy('threshold_hours')
            ->orderBy('name')
            ->get(['id', 'name', 'threshold_hours', 'is_active']);

        if (! $tariffId && $tariffs->isNotEmpty()) {
            $tariffId = (int) $tariffs->first()->id;
        }

        $rules = $tariffId
            ? TariffPrice::query()
                ->with(['zone:id,name,slug,color', 'dayGroup:id,name,color,weekdays'])
                ->where('club_id', $clubId)
                ->where('tariff_id', $tariffId)
                ->orderBy('zone_id')
                ->orderBy('day_group_id')
                ->orderBy('time_start')
                ->get()
                ->map(fn (TariffPrice $row) => [
                    'id' => $row->id,
                    'zone_id' => (int) $row->zone_id,
                    'zone' => $row->zone?->only(['id', 'name', 'slug', 'color']),
                    'day_group_id' => (int) $row->day_group_id,
                    'day_group' => $row->dayGroup?->only(['id', 'name', 'color', 'weekdays']),
                    'time_start' => (int) $row->time_start,
                    'time_end' => (int) $row->time_end,
                    'price' => (float) $row->price,
                ])
            : collect();

        return Inertia::render('Admin/Tariffs', [
            'clubs' => $clubs,
            'selectedClubId' => $clubId,
            'selectedTariffId' => $tariffId,
            'tariffs' => $tariffs,
            'zones' => Zone::query()->orderBy('sort')->orderBy('name')->get(['id', 'name', 'slug', 'color']),
            'dayGroups' => DayGroup::query()->orderBy('sort')->orderBy('id')->get(['id', 'name', 'color', 'weekdays', 'sort']),
            'rules' => $rules,
            'overrides' => CalendarDayOverride::query()
                ->with('dayGroup:id,name,color')
                ->orderBy('date')
                ->get()
                ->map(fn (CalendarDayOverride $row) => [
                    'id' => $row->id,
                    'date' => $row->date->toDateString(),
                    'day_group_id' => (int) $row->day_group_id,
                    'day_group' => $row->dayGroup?->only(['id', 'name', 'color']),
                    'note' => $row->note,
                ]),
            'addons' => Addon::query()
                ->orderBy('sort')
                ->orderBy('name')
                ->get()
                ->map(fn (Addon $addon) => [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'slug' => $addon->slug,
                    'color' => $addon->color,
                    'billing_mode' => $addon->billing_mode,
                    'sort' => $addon->sort,
                    'is_active' => $addon->is_active,
                    'price_per_hour' => AddonPrice::query()
                        ->where('addon_id', $addon->id)
                        ->where('club_id', $clubId)
                        ->value('price_per_hour'),
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'threshold_hours' => 'required|integer|min:1',
        ]);

        Tariff::create([
            'name' => $data['name'],
            'threshold_hours' => (int) $data['threshold_hours'],
            'price_per_package' => 0,
            'is_active' => true,
        ]);

        return back();
    }

    public function update(Request $request, Tariff $tariff)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'threshold_hours' => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        $tariff->update($data);

        return back();
    }

    public function destroy(Tariff $tariff)
    {
        $tariff->delete();

        return back();
    }

    public function storeRule(Request $request, Tariff $tariff)
    {
        $data = $this->validatedRule($request);
        $this->assertNoOverlap($tariff->id, $data);

        TariffPrice::create([
            'tariff_id' => $tariff->id,
            'club_id' => $data['club_id'],
            'zone_id' => $data['zone_id'],
            'day_group_id' => $data['day_group_id'],
            'time_start' => $data['time_start'],
            'time_end' => $data['time_end'],
            'price' => $data['price'],
        ]);

        return back();
    }

    public function updateRule(Request $request, TariffPrice $tariffPrice)
    {
        $data = $this->validatedRule($request);
        $this->assertNoOverlap((int) $tariffPrice->tariff_id, $data, (int) $tariffPrice->id);

        $tariffPrice->update([
            'club_id' => $data['club_id'],
            'zone_id' => $data['zone_id'],
            'day_group_id' => $data['day_group_id'],
            'time_start' => $data['time_start'],
            'time_end' => $data['time_end'],
            'price' => $data['price'],
        ]);

        return back();
    }

    public function destroyRule(TariffPrice $tariffPrice)
    {
        $tariffPrice->delete();

        return back();
    }

    public function storeDayGroup(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'color' => 'nullable|string|max:20',
            'weekdays' => 'required|array|min:1',
            'weekdays.*' => 'integer|min:1|max:7',
            'sort' => 'nullable|integer',
        ]);

        DayGroup::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#22c55e',
            'weekdays' => array_values(array_unique(array_map('intval', $data['weekdays']))),
            'sort' => (int) ($data['sort'] ?? 100),
        ]);

        return back();
    }

    public function updateDayGroup(Request $request, DayGroup $dayGroup)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:80',
            'color' => 'nullable|string|max:20',
            'weekdays' => 'sometimes|array|min:1',
            'weekdays.*' => 'integer|min:1|max:7',
            'sort' => 'nullable|integer',
        ]);

        if (isset($data['weekdays'])) {
            $data['weekdays'] = array_values(array_unique(array_map('intval', $data['weekdays'])));
        }

        $dayGroup->update($data);

        return back();
    }

    public function destroyDayGroup(DayGroup $dayGroup)
    {
        if ($dayGroup->prices()->exists()) {
            throw ValidationException::withMessages([
                'day_group' => 'Сначала удалите правила цен с этой группой дней.',
            ]);
        }

        $dayGroup->delete();

        return back();
    }

    public function storeOverride(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'day_group_id' => 'required|integer|exists:day_groups,id',
            'note' => 'nullable|string|max:120',
        ]);

        CalendarDayOverride::updateOrCreate(
            ['date' => $data['date']],
            [
                'day_group_id' => (int) $data['day_group_id'],
                'note' => $data['note'] ?? null,
            ]
        );

        return back();
    }

    public function destroyOverride(CalendarDayOverride $calendarDayOverride)
    {
        $calendarDayOverride->delete();

        return back();
    }

    public function storeAddon(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'slug' => 'nullable|string|max:80|unique:addons,slug',
            'color' => 'nullable|string|max:20',
            'billing_mode' => ['required', Rule::in(['always', 'optional'])],
            'club_id' => 'required|integer|exists:clubs,id',
            'price_per_hour' => 'required|numeric|min:0',
            'sort' => 'nullable|integer',
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name']);
        if ($slug === '' || Addon::query()->where('slug', $slug)->exists()) {
            $slug = Str::slug($data['name']).'-'.Str::random(4);
        }

        $addon = Addon::create([
            'name' => $data['name'],
            'slug' => $slug,
            'color' => $data['color'] ?? '#22c55e',
            'billing_mode' => $data['billing_mode'],
            'sort' => (int) ($data['sort'] ?? 100),
            'is_active' => true,
        ]);

        AddonPrice::create([
            'addon_id' => $addon->id,
            'club_id' => (int) $data['club_id'],
            'price_per_hour' => (float) $data['price_per_hour'],
        ]);

        return back();
    }

    public function updateAddon(Request $request, Addon $addon)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:80',
            'color' => 'nullable|string|max:20',
            'billing_mode' => ['sometimes', Rule::in(['always', 'optional'])],
            'is_active' => 'sometimes|boolean',
            'sort' => 'nullable|integer',
            'club_id' => 'nullable|integer|exists:clubs,id',
            'price_per_hour' => 'nullable|numeric|min:0',
        ]);

        $addon->update(collect($data)->only([
            'name', 'color', 'billing_mode', 'is_active', 'sort',
        ])->filter(fn ($v) => $v !== null)->all());

        if (isset($data['club_id']) && array_key_exists('price_per_hour', $data)) {
            if ($data['price_per_hour'] === null || $data['price_per_hour'] === '') {
                AddonPrice::query()
                    ->where('addon_id', $addon->id)
                    ->where('club_id', (int) $data['club_id'])
                    ->delete();
            } else {
                AddonPrice::updateOrCreate(
                    ['addon_id' => $addon->id, 'club_id' => (int) $data['club_id']],
                    ['price_per_hour' => (float) $data['price_per_hour']]
                );
            }
        }

        return back();
    }

    public function destroyAddon(Addon $addon)
    {
        $addon->delete();

        return back();
    }

    /**
     * @return array{club_id: int, zone_id: int, day_group_id: int, time_start: int, time_end: int, price: float}
     */
    private function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'zone_id' => 'required|integer|exists:zones,id',
            'day_group_id' => 'required|integer|exists:day_groups,id',
            'time_start' => 'required|integer|min:0|max:1439',
            'time_end' => 'required|integer|min:1|max:1440',
            'price' => 'required|numeric|min:0',
        ]);

        if ((int) $data['time_start'] === (int) $data['time_end']) {
            throw ValidationException::withMessages([
                'time_end' => 'Начало и конец интервала не должны совпадать.',
            ]);
        }

        return [
            'club_id' => (int) $data['club_id'],
            'zone_id' => (int) $data['zone_id'],
            'day_group_id' => (int) $data['day_group_id'],
            'time_start' => (int) $data['time_start'],
            'time_end' => (int) $data['time_end'],
            'price' => (float) $data['price'],
        ];
    }

    /**
     * @param  array{club_id: int, zone_id: int, day_group_id: int, time_start: int, time_end: int}  $data
     */
    private function assertNoOverlap(int $tariffId, array $data, ?int $ignoreId = null): void
    {
        $existing = TariffPrice::query()
            ->where('tariff_id', $tariffId)
            ->where('club_id', $data['club_id'])
            ->where('zone_id', $data['zone_id'])
            ->where('day_group_id', $data['day_group_id'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'time_start', 'time_end']);

        foreach ($existing as $row) {
            if ($this->rangesOverlap(
                $data['time_start'],
                $data['time_end'],
                (int) $row->time_start,
                (int) $row->time_end
            )) {
                throw ValidationException::withMessages([
                    'time_start' => 'Интервал пересекается с другим правилом для этой зоны и группы дней.',
                ]);
            }
        }
    }

    private function rangesOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        $expand = function (int $start, int $end): array {
            if ($start < $end) {
                return [[$start, $end]];
            }

            // Через полночь → два отрезка в пределах суток.
            return [[$start, 1440], [0, $end]];
        };

        foreach ($expand($aStart, $aEnd) as [$as, $ae]) {
            foreach ($expand($bStart, $bEnd) as [$bs, $be]) {
                if ($as < $be && $bs < $ae) {
                    return true;
                }
            }
        }

        return false;
    }
}
