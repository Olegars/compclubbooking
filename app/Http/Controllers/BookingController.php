<?php

namespace App\Http\Controllers;

use App\Models\BookingGroup;
use App\Models\Computer;
use App\Models\Tariff;
use App\Services\GameBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(private readonly GameBookingService $bookings)
    {
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

    public function calculatePrice(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'pc_ids' => 'required|array|min:1',
            'pc_ids.*' => 'integer|distinct|exists:computers,id',
            'game_ids' => 'nullable|array',
            'game_ids.*' => 'integer|distinct|exists:games,id',
            'starts_at' => 'nullable|date|required_with:ends_at',
            'ends_at' => 'nullable|date|required_with:starts_at',
            'date' => 'nullable|string',
            'start_h' => 'nullable|numeric',
            'duration' => 'required_without_all:starts_at,ends_at|nullable|numeric|min:0.25',
        ]);

        if (!isset($validated['starts_at']) && !isset($validated['date'])) {
            $duration = (float) $validated['duration'];
            $hoursForTariff = max(1, (int) floor($duration));
            $tariff = Tariff::query()
                ->where('is_active', true)
                ->where('threshold_hours', $hoursForTariff)
                ->first();
            $pricePerHour = $tariff
                ? ((float) $tariff->price_per_package / $tariff->threshold_hours)
                : 250;
            $totalPrice = (int) round($pricePerHour * $duration * count($validated['pc_ids']));

            return response()->json([
                'total_price' => $totalPrice,
                'total_minor' => $totalPrice * 100,
                'computers_total_minor' => $totalPrice * 100,
                'games_total_minor' => 0,
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
            $endsAt
        ));
    }

    public function reserve(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'nullable|integer|exists:clubs,id',
            'pc_ids' => 'required|array|min:1',
            'pc_ids.*' => 'integer|distinct|exists:computers,id',
            'game_ids' => 'nullable|array',
            'game_ids.*' => 'integer|distinct|exists:games,id',
            'starts_at' => 'nullable|date|required_with:ends_at',
            'ends_at' => 'nullable|date|required_with:starts_at',
            'date' => 'required_without:starts_at|nullable|string',
            'start_h' => 'required_without:starts_at|nullable|numeric',
            'duration' => 'required_without:starts_at|nullable|numeric|min:0.25',
        ]);

        [$startsAt, $endsAt] = $this->resolvePeriod($validated);
        try {
            $group = $this->bookings->reserve(
                $request->user(),
                $this->resolveClubId($validated),
                $validated['pc_ids'],
                $validated['game_ids'] ?? [],
                $startsAt,
                $endsAt
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
        if (!empty($validated['club_id'])) {
            return (int) $validated['club_id'];
        }

        return (int) Computer::query()
            ->whereKey((int) $validated['pc_ids'][0])
            ->value('club_id');
    }

    private function resolvePeriod(array $validated): array
    {
        $tz = config('app.timezone');

        if (!empty($validated['starts_at']) && !empty($validated['ends_at'])) {
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
