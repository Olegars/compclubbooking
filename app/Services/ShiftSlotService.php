<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Models\ShiftSlotSetting;
use App\Models\ShiftSlotTemplate;
use App\Support\AdminLocation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShiftSlotService
{
    public const CANCEL_BEFORE_HOURS = 48;

    /**
     * @return array{month: string, cancel_before_hours: int, shift_hours: int, starts_hour: int, can_set_model: bool, days: array<string, mixed>, my_bookings: list<array<string, mixed>>}
     */
    public function calendar(Admin $admin, ?string $month = null): array
    {
        $monthStart = $this->parseMonth($month);
        $rangeStart = $monthStart->copy();
        $rangeEnd = $monthStart->copy()->endOfMonth();

        $clubId = AdminLocation::id($admin);
        $hours = ShiftSlotSetting::hoursFor($clubId);
        $startsHour = ShiftSlotSetting::startsHourFor($clubId);
        $this->syncTemplates($clubId, $hours, $startsHour);
        $this->pruneStaleUnbooked($clubId);
        $this->ensureSlots($clubId, $rangeStart->copy()->subDay(), $rangeEnd->copy()->addWeeks(2));

        $slots = ShiftSlot::query()
            ->with(['template:id,name,duration_hours', 'activeBookings.admin:id,name'])
            ->whereHas('template', fn ($q) => $q->where('is_active', true))
            ->tap(fn (Builder $q) => $this->scopeClub($q, $clubId))
            ->where('starts_at', '>=', $rangeStart)
            ->where('starts_at', '<=', $rangeEnd)
            ->orderBy('starts_at')
            ->get();

        $days = [];
        for ($cursor = $rangeStart->copy(); $cursor->lte($rangeEnd); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $days[$key] = [
                'date' => $key,
                'has_free' => false,
                'slots' => [],
            ];
        }

        foreach ($slots as $slot) {
            $key = $slot->starts_at->toDateString();
            if (! isset($days[$key])) {
                continue;
            }
            $payload = $this->serializeSlot($slot, $admin);
            $days[$key]['slots'][] = $payload;
            if ($payload['can_book']) {
                $days[$key]['has_free'] = true;
            }
        }

        $my = ShiftSlotBooking::query()
            ->with(['slot.template:id,name'])
            ->where('admin_id', $admin->id)
            ->where('status', ShiftSlotBooking::STATUS_BOOKED)
            ->whereHas('slot', fn ($q) => $q->where('starts_at', '>=', now()->subDay()))
            ->get()
            ->sortBy(fn (ShiftSlotBooking $row) => $row->slot?->starts_at)
            ->values()
            ->map(function (ShiftSlotBooking $row) {
                $slot = $row->slot;

                return [
                    'id' => $row->id,
                    'slot_id' => $row->shift_slot_id,
                    'name' => $slot?->template?->name ?: 'Смена',
                    'starts_at' => $slot?->starts_at?->toIso8601String(),
                    'ends_at' => $slot?->ends_at?->toIso8601String(),
                    'kind' => $row->kind,
                    'can_cancel' => $slot ? $this->canCancel($slot->starts_at) : false,
                ];
            })
            ->all();

        return [
            'month' => $monthStart->format('Y-m'),
            'cancel_before_hours' => self::CANCEL_BEFORE_HOURS,
            'shift_hours' => $hours,
            'starts_hour' => $startsHour,
            'can_set_model' => $admin->canSetShiftModel(),
            'days' => $days,
            'my_bookings' => $my,
        ];
    }

    public function setHours(Admin $admin, int $hours, ?int $startsHour = null): void
    {
        if (! $admin->canSetShiftModel()) {
            throw new RuntimeException('Модель смен задаёт владелец или управляющий.');
        }

        $clubId = AdminLocation::id($admin);
        $startsHour ??= ShiftSlotSetting::startsHourFor($clubId);
        ShiftSlotSetting::put($clubId, $hours, $startsHour);
        $this->syncTemplates($clubId, $hours, $startsHour);
        $this->pruneStaleUnbooked($clubId);
        $this->ensureSlots($clubId, now(), now()->addMonths(6)->endOfMonth());
    }

    public function book(Admin $admin, ShiftSlot $slot): ShiftSlotBooking
    {
        return DB::transaction(function () use ($admin, $slot) {
            /** @var ShiftSlot $locked */
            $locked = ShiftSlot::query()->lockForUpdate()->findOrFail($slot->id);
            $locked->load('activeBookings');
            $this->assertSlotForClub($admin, $locked);

            if ($locked->starts_at->lte(now())) {
                throw new RuntimeException('Эта смена уже началась или прошла.');
            }

            $kind = $admin->isIntern()
                ? ShiftSlotBooking::KIND_INTERN
                : ShiftSlotBooking::KIND_LEAD;

            $existing = $locked->activeBookings->first(
                fn (ShiftSlotBooking $row) => (int) $row->admin_id === (int) $admin->id
            );
            if ($existing) {
                throw new RuntimeException('Вы уже записаны на эту смену.');
            }

            $this->assertNoOverlap($admin, $locked);

            if ($kind === ShiftSlotBooking::KIND_LEAD) {
                if ($locked->activeBookings->contains('kind', ShiftSlotBooking::KIND_LEAD)) {
                    throw new RuntimeException('Слот уже занят. Выберите другую смену.');
                }
            } else {
                $taken = $locked->activeBookings->where('kind', ShiftSlotBooking::KIND_INTERN)->count();
                if ($taken >= (int) $locked->intern_capacity) {
                    throw new RuntimeException('Мест для стажёра на этой смене нет.');
                }
            }

            return ShiftSlotBooking::query()->create([
                'shift_slot_id' => $locked->id,
                'admin_id' => $admin->id,
                'kind' => $kind,
                'status' => ShiftSlotBooking::STATUS_BOOKED,
            ]);
        });
    }

    public function cancel(Admin $admin, ShiftSlotBooking $booking): void
    {
        if ((int) $booking->admin_id !== (int) $admin->id) {
            throw new RuntimeException('Можно отменить только свою смену.');
        }

        if ($booking->status !== ShiftSlotBooking::STATUS_BOOKED) {
            throw new RuntimeException('Смена уже отменена.');
        }

        $booking->loadMissing('slot');
        $slot = $booking->slot;
        if (! $slot) {
            throw new RuntimeException('Слот не найден.');
        }

        $this->assertSlotForClub($admin, $slot);

        if (! $this->canCancel($slot->starts_at)) {
            throw new RuntimeException('Отменить смену можно не позднее чем за '.self::CANCEL_BEFORE_HOURS.' часов до начала.');
        }

        $booking->update([
            'status' => ShiftSlotBooking::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function canCancel(CarbonInterface $startsAt): bool
    {
        return now()->lte($startsAt->copy()->subHours(self::CANCEL_BEFORE_HOURS));
    }

    public function syncTemplates(?int $clubId, ?int $hours = null, ?int $startsHour = null): void
    {
        $hours = $hours ?? ShiftSlotSetting::hoursFor($clubId);
        $startsHour = $startsHour ?? ShiftSlotSetting::startsHourFor($clubId);
        $wanted = $this->templateDefs($hours, $startsHour);
        $wantedNames = array_column($wanted, 'name');

        foreach ($wanted as $row) {
            $template = ShiftSlotTemplate::query()->firstOrNew([
                'club_id' => $clubId,
                'name' => $row['name'],
            ]);
            $template->fill([
                'starts_time' => $row['starts_time'],
                'duration_hours' => $row['duration_hours'],
                'intern_capacity' => $row['intern_capacity'],
                'is_active' => true,
            ]);
            $template->save();
        }

        ShiftSlotTemplate::query()
            ->tap(fn (Builder $q) => $this->scopeClub($q, $clubId))
            ->whereNotIn('name', $wantedNames)
            ->update(['is_active' => false]);
    }

    public function ensureSlots(?int $clubId, CarbonInterface $from, CarbonInterface $to): void
    {
        $templates = ShiftSlotTemplate::query()
            ->where('is_active', true)
            ->tap(fn (Builder $q) => $this->scopeClub($q, $clubId))
            ->get();

        $startDay = Carbon::parse($from)->startOfDay();
        $endDay = Carbon::parse($to)->startOfDay();

        for ($day = $startDay->copy(); $day->lte($endDay); $day->addDay()) {
            foreach ($templates as $template) {
                $starts = $day->copy()->setTimeFromTimeString((string) $template->starts_time);
                $ends = $starts->copy()->addHours((int) $template->duration_hours);
                if ($ends->lte(now())) {
                    continue;
                }

                if ($this->hasBookedOverlap($clubId, $starts, $ends, (int) $template->id)) {
                    continue;
                }

                ShiftSlot::query()->firstOrCreate(
                    [
                        'club_id' => $clubId,
                        'template_id' => $template->id,
                        'starts_at' => $starts,
                    ],
                    [
                        'ends_at' => $ends,
                        'intern_capacity' => (int) $template->intern_capacity,
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSlot(ShiftSlot $slot, Admin $admin): array
    {
        $bookings = $slot->relationLoaded('activeBookings')
            ? $slot->activeBookings
            : $slot->activeBookings()->with('admin:id,name')->get();

        $lead = $bookings->firstWhere('kind', ShiftSlotBooking::KIND_LEAD);
        $interns = $bookings->where('kind', ShiftSlotBooking::KIND_INTERN);
        $mine = $bookings->first(fn (ShiftSlotBooking $row) => (int) $row->admin_id === (int) $admin->id);
        $internTaken = $interns->count();
        $internCap = (int) $slot->intern_capacity;
        $started = $slot->starts_at->lte(now());
        $isIntern = $admin->isIntern();
        $canBook = ! $started && ! $mine;
        if ($canBook) {
            $canBook = $isIntern
                ? $internTaken < $internCap
                : $lead === null;
        }

        $bookingId = $mine?->id;
        $canCancel = (bool) ($mine && $this->canCancel($slot->starts_at));
        $duration = (int) ($slot->template?->duration_hours
            ?: round($slot->starts_at->diffInHours($slot->ends_at)));

        return [
            'id' => $slot->id,
            'name' => $slot->template?->name ?: 'Смена',
            'starts_at' => $slot->starts_at->toIso8601String(),
            'ends_at' => $slot->ends_at->toIso8601String(),
            'duration_hours' => $duration,
            'lead_taken' => (bool) $lead,
            'lead_name' => $lead?->admin?->name,
            'intern_taken' => $internTaken,
            'intern_capacity' => $internCap,
            'is_mine' => (bool) $mine,
            'my_kind' => $mine?->kind,
            'booking_id' => $bookingId,
            'can_book' => $canBook,
            'can_cancel' => $canCancel,
            'started' => $started,
        ];
    }

    /**
     * @return list<array{name: string, starts_time: string, duration_hours: int, intern_capacity: int}>
     */
    private function templateDefs(int $hours, int $startsHour): array
    {
        $start = $this->hourTime($startsHour);

        if ($hours === ShiftSlotSetting::HOURS_24) {
            return [
                ['name' => 'Сутки', 'starts_time' => $start, 'duration_hours' => 24, 'intern_capacity' => 1],
            ];
        }

        $night = $this->hourTime(($startsHour + 12) % 24);

        return [
            ['name' => 'День', 'starts_time' => $start, 'duration_hours' => 12, 'intern_capacity' => 1],
            ['name' => 'Ночь', 'starts_time' => $night, 'duration_hours' => 12, 'intern_capacity' => 1],
        ];
    }

    private function hourTime(int $hour): string
    {
        return sprintf('%02d:00:00', $hour);
    }

    private function pruneStaleUnbooked(?int $clubId): void
    {
        $slots = ShiftSlot::query()
            ->with('template:id,starts_time,is_active,duration_hours')
            ->tap(fn (Builder $q) => $this->scopeClub($q, $clubId))
            ->where('starts_at', '>', now())
            ->whereDoesntHave('activeBookings')
            ->get();

        foreach ($slots as $slot) {
            $template = $slot->template;
            if (! $template || ! $template->is_active) {
                $slot->delete();

                continue;
            }

            $wantTime = Carbon::parse((string) $template->starts_time)->format('H:i:s');
            $haveTime = $slot->starts_at->format('H:i:s');
            $wantHours = (int) $template->duration_hours;
            $haveHours = (int) round($slot->starts_at->diffInHours($slot->ends_at));
            if ($wantTime !== $haveTime || $wantHours !== $haveHours) {
                $slot->delete();
            }
        }
    }

    private function hasBookedOverlap(?int $clubId, CarbonInterface $starts, CarbonInterface $ends, int $templateId): bool
    {
        return ShiftSlot::query()
            ->tap(fn (Builder $q) => $this->scopeClub($q, $clubId))
            ->where('template_id', '!=', $templateId)
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->whereHas('activeBookings')
            ->exists();
    }

    private function scopeClub(Builder $query, ?int $clubId): Builder
    {
        return $clubId === null
            ? $query->whereNull('club_id')
            : $query->where('club_id', $clubId);
    }

    private function assertSlotForClub(Admin $admin, ShiftSlot $slot): void
    {
        $clubId = AdminLocation::id($admin);
        if ($slot->club_id === null || $clubId === null) {
            return;
        }

        if ((int) $slot->club_id !== (int) $clubId) {
            throw new RuntimeException('Слот другого клуба.');
        }
    }

    private function assertNoOverlap(Admin $admin, ShiftSlot $slot): void
    {
        $overlap = ShiftSlotBooking::query()
            ->where('admin_id', $admin->id)
            ->where('status', ShiftSlotBooking::STATUS_BOOKED)
            ->whereHas('slot', function ($q) use ($slot) {
                $q->where('starts_at', '<', $slot->ends_at)
                    ->where('ends_at', '>', $slot->starts_at);
            })
            ->exists();

        if ($overlap) {
            throw new RuntimeException('На это время у вас уже есть смена.');
        }
    }

    private function parseMonth(?string $month): Carbon
    {
        try {
            $parsed = $month
                ? Carbon::createFromFormat('Y-m', $month)->startOfMonth()
                : now()->startOfMonth();
        } catch (\Throwable) {
            $parsed = now()->startOfMonth();
        }

        $min = now()->startOfMonth()->subMonths(1);
        $max = now()->startOfMonth()->addMonths(6);
        if ($parsed->lt($min)) {
            return $min;
        }
        if ($parsed->gt($max)) {
            return $max;
        }

        return $parsed;
    }
}
