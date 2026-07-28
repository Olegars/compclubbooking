<?php

namespace App\Http\Controllers;

use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Services\GameBookingService;
use App\Services\MapZoneResolver;
use App\Services\TariffService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private readonly GameBookingService $bookings,
        private readonly TariffService $tariffs,
        private readonly MapZoneResolver $zones,
    ) {
    }

    public function computersAvailability(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date',
        ]);

        [$startsAt, $endsAt] = $this->resolvePeriod($validated);
        $computerIds = Computer::query()
            ->where('club_id', (int) $validated['club_id'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $bookedIds = $this->bookings->occupiedComputerIds($computerIds, $startsAt, $endsAt);
        $offlineIds = Computer::query()
            ->where('club_id', (int) $validated['club_id'])
            ->where('status', '!=', 'available')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->json([
            'occupied_pc_ids' => array_values(array_unique([...$bookedIds, ...$offlineIds])),
            'booked_pc_ids' => $bookedIds,
        ]);
    }

    public function availability(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'pc_ids' => 'required|array|min:1',
            'pc_ids.*' => 'integer|distinct|exists:computers,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date',
        ]);

        [$startsAt, $endsAt] = $this->resolvePeriod($validated);

        return response()->json([
            'games' => $this->bookings->availability(
                (int) $validated['club_id'],
                $validated['pc_ids'],
                $startsAt,
                $endsAt
            ),
        ]);
    }

    public function tariffGrid(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id',
            'pc_ids' => 'nullable|array|min:1',
            'pc_ids.*' => 'integer|distinct|exists:computers,id',
            'category' => 'nullable|string|max:64',
            'starts_at' => 'nullable|date',
        ]);

        $club = Club::query()->findOrFail((int) $validated['club_id']);
        $startsAt = ! empty($validated['starts_at'])
            ? CarbonImmutable::parse($validated['starts_at'])->timezone(config('app.timezone'))
            : null;

        $category = strtolower(trim((string) ($validated['category'] ?? '')));

        if ($category === '' && ! empty($validated['pc_ids'])) {
            $computers = Computer::query()
                ->where('club_id', $club->id)
                ->whereIn('id', $validated['pc_ids'])
                ->get();

            if ($computers->isEmpty()) {
                throw ValidationException::withMessages([
                    'pc_ids' => 'Компьютеры не найдены в этом клубе.',
                ]);
            }

            $resolved = $this->zones->resolveForComputers($club, $computers);
            $categories = array_values(array_unique(array_values($resolved)));
            $category = $categories[0] ?? MapZoneResolver::FALLBACK_CATEGORY;
        }

        if ($category === '') {
            $category = MapZoneResolver::FALLBACK_CATEGORY;
        }

        $grid = $this->tariffs->gridForCategory($category, $startsAt);

        return response()->json([
            'club_id' => $club->id,
            ...$grid,
        ]);
    }

    public function tariffsShowcase()
    {
        return response()->json($this->tariffs->showcase());
    }

    public function calculatePrice(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'pc_ids' => 'required|array|min:1',
            'pc_ids.*' => 'integer|distinct|exists:computers,id',
            'game_ids' => 'nullable|array|max:1',
            'game_ids.*' => 'integer|distinct|exists:games,id',
            'starts_at' => 'nullable|date|required_with:ends_at',
            'ends_at' => 'nullable|date|required_with:starts_at',
            'date' => 'nullable|string',
            'start_h' => 'nullable|numeric',
            'duration' => 'required_without_all:starts_at,ends_at|nullable|numeric|min:0.25',
            'mode' => ['nullable', Rule::in(['hourly', 'packages'])],
            'tariff_id' => 'nullable|integer|exists:tariffs,id',
        ]);

        $mode = ($validated['mode'] ?? 'hourly') === 'packages' ? 'packages' : 'hourly';
        $tariffId = isset($validated['tariff_id']) ? (int) $validated['tariff_id'] : null;

        if (! isset($validated['starts_at']) && ! isset($validated['date'])) {
            $clubId = $this->resolveClubId($validated);
            $club = Club::query()->findOrFail($clubId);
            $computers = Computer::query()
                ->where('club_id', $clubId)
                ->whereIn('id', $validated['pc_ids'])
                ->get();
            $zones = $this->zones->resolveForComputers($club, $computers);
            $seatQuote = $this->tariffs->quoteSeats(
                $zones,
                (float) $validated['duration'],
                $mode,
                $tariffId
            );

            return response()->json([
                'total_price' => $seatQuote['total_rub'],
                'total_minor' => $seatQuote['total_minor'],
                'computers_total_minor' => $seatQuote['total_minor'],
                'games_total_minor' => 0,
                'mode' => $mode,
                'tariff_id' => $tariffId,
                'tariff' => $seatQuote,
                'games' => [],
            ]);
        }

        [$startsAt, $endsAt] = $this->resolvePeriod($validated);
        $clubId = $this->resolveClubId($validated);

        return response()->json($this->bookings->quote(
            $clubId,
            $validated['pc_ids'],
            $validated['game_ids'] ?? [],
            $startsAt,
            $endsAt,
            $mode,
            $tariffId
        ));
    }

    public function reserve(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'pc_ids' => 'required|array|min:1',
            'pc_ids.*' => 'integer|distinct|exists:computers,id',
            'game_ids' => 'nullable|array|max:1',
            'game_ids.*' => 'integer|distinct|exists:games,id',
            'starts_at' => 'nullable|date|required_with:ends_at',
            'ends_at' => 'nullable|date|required_with:starts_at',
            'date' => 'required_without:starts_at|nullable|string',
            'start_h' => 'required_without:starts_at|nullable|numeric',
            'duration' => 'required_without:starts_at|nullable|numeric|min:0.25',
            'mode' => ['nullable', Rule::in(['hourly', 'packages'])],
            'tariff_id' => 'nullable|integer|exists:tariffs,id',
        ]);

        $mode = ($validated['mode'] ?? 'hourly') === 'packages' ? 'packages' : 'hourly';
        $tariffId = isset($validated['tariff_id']) ? (int) $validated['tariff_id'] : null;

        [$startsAt, $endsAt] = $this->resolvePeriod($validated);
        try {
            $group = $this->bookings->reserve(
                $request->user(),
                $this->resolveClubId($validated),
                $validated['pc_ids'],
                $validated['game_ids'] ?? [],
                $startsAt,
                $endsAt,
                $mode,
                $tariffId
            );
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23P01', '23505', '23000'], true)) {
                throw ValidationException::withMessages([
                    'booking' => 'Выбранный компьютер или игровой аккаунт только что заняли. Обновите доступность.',
                ]);
            }

            throw $exception;
        }

        return response()->json([
            'status' => 'success',
            'booking_group_id' => $group->id,
            'pins' => $group->bookings->pluck('pin_code', 'computer_id'),
            'pricing' => $group->pricing_snapshot,
        ], 201);
    }

    public function cancel(Request $request, BookingGroup $bookingGroup)
    {
        $group = $this->bookings->cancel($request->user(), $bookingGroup);

        return response()->json([
            'status' => 'success',
            'booking_group_id' => $group->id,
            'booking_status' => $group->status,
            'payment_status' => $group->payment_status,
            'refunded_total_minor' => $group->refunded_total_minor,
        ]);
    }

    private function resolveClubId(array $validated): int
    {
        if (! empty($validated['club_id'])) {
            return (int) $validated['club_id'];
        }

        return (int) Computer::query()
            ->whereKey((int) $validated['pc_ids'][0])
            ->value('club_id');
    }

    private function resolvePeriod(array $validated): array
    {
        $tz = config('app.timezone');

        if (! empty($validated['starts_at']) && ! empty($validated['ends_at'])) {
            // Переводим в TZ клуба до записи в PG: PDO пишет naive timestamp,
            // а сессия БД (Europe/Moscow) иначе сдвигает UTC-инстант на -3 часа.
            return [
                CarbonImmutable::parse($validated['starts_at'])->timezone($tz),
                CarbonImmutable::parse($validated['ends_at'])->timezone($tz),
            ];
        }

        $date = CarbonImmutable::parse($validated['date'], $tz)->startOfDay();
        $startsAt = $date->addMinutes((int) round(((float) $validated['start_h']) * 60));
        $endsAt = $startsAt->addMinutes((int) round(((float) $validated['duration']) * 60));

        return [$startsAt, $endsAt];
    }
}
