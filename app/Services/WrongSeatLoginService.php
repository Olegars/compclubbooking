<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\GameAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Вход не на том ПК, который в брони: либо отказ (место занято на окно сессии),
 * либо подтверждённый перенос брони на свободный терминал.
 */
class WrongSeatLoginService
{
    public const STATUS_OCCUPIED = 'wrong_pc_occupied';

    public const STATUS_AVAILABLE = 'wrong_pc_available';

    public const STATUS_PROCEED = 'proceed';

    public function __construct(
        private readonly GameBookingService $bookings,
        private readonly BookingSessionTimingService $timing,
        private readonly ComputerStatusService $statuses,
    ) {
    }

    public function findPinBookingElsewhere(User $user, string $pin, int $terminalId): ?Booking
    {
        return Booking::query()
            ->with('computer:id,name,club_id,kind,status,maintenance,maintenance_until')
            ->where('user_id', $user->id)
            ->where('pin_code', $pin)
            ->where('computer_id', '!=', $terminalId)
            ->whereIn('status', ['paid', 'confirmed', 'active'])
            ->orderByDesc('id')
            ->first();
    }

    public function findUserBookingElsewhere(User $user, int $terminalId): ?Booking
    {
        return Booking::query()
            ->with('computer:id,name,club_id,kind,status,maintenance,maintenance_until')
            ->where('user_id', $user->id)
            ->where('computer_id', '!=', $terminalId)
            ->whereIn('status', ['paid', 'confirmed', 'active'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{
     *   status: string,
     *   message?: string,
     *   booked_computer?: array{id:int,name:string},
     *   this_computer?: array{id:int,name:string},
     *   booking?: Booking
     * }
     */
    public function decide(Booking $booking, int $targetComputerId, bool $acceptSeatChange): array
    {
        $probe = $this->probe($booking, $targetComputerId);
        $payload = [
            'booked_computer' => $probe['booked_computer'],
            'this_computer' => $probe['this_computer'],
        ];

        if (! $probe['free']) {
            return $payload + [
                'status' => self::STATUS_OCCUPIED,
                'message' => $probe['occupied_message'],
            ];
        }

        if (! $acceptSeatChange) {
            return $payload + [
                'status' => self::STATUS_AVAILABLE,
                'message' => $probe['offer_message'],
            ];
        }

        try {
            $moved = $this->reseat($booking, $targetComputerId);
        } catch (RuntimeException) {
            return $payload + [
                'status' => self::STATUS_OCCUPIED,
                'message' => $probe['occupied_message'],
            ];
        }

        return [
            'status' => self::STATUS_PROCEED,
            'booking' => $moved,
        ];
    }

    /**
     * @return array{
     *   free: bool,
     *   booked_computer: array{id:int,name:string},
     *   this_computer: array{id:int,name:string},
     *   occupied_message: string,
     *   offer_message: string
     * }
     */
    public function probe(Booking $booking, int $targetComputerId): array
    {
        $from = $booking->relationLoaded('computer')
            ? $booking->computer
            : Computer::query()->find((int) $booking->computer_id);
        $to = Computer::query()->find($targetComputerId);

        $bookedName = $this->pcLabel($from, (int) $booking->computer_id);
        $thisName = $this->pcLabel($to, $targetComputerId);
        $bookedComputer = [
            'id' => (int) ($from?->id ?? $booking->computer_id),
            'name' => $bookedName,
        ];
        $thisComputer = [
            'id' => (int) ($to?->id ?? $targetComputerId),
            'name' => $thisName,
        ];

        return [
            'free' => $this->isFreeForBooking($booking, $to),
            'booked_computer' => $bookedComputer,
            'this_computer' => $thisComputer,
            'occupied_message' => $this->occupiedMessage($bookedName),
            'offer_message' => $this->offerMessage($bookedName),
        ];
    }

    public function reseat(Booking $booking, int $targetComputerId): Booking
    {
        return DB::transaction(function () use ($booking, $targetComputerId) {
            /** @var Booking $booking */
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $to = Computer::query()->lockForUpdate()->find($targetComputerId);

            if (! $this->isFreeForBooking($booking, $to)) {
                throw new RuntimeException('target occupied');
            }

            $oldId = (int) $booking->computer_id;
            $newId = (int) $to->id;

            Booking::withoutEvents(function () use ($booking, $newId) {
                $booking->update([
                    'computer_id' => $newId,
                    'pc_ids' => [(string) $newId],
                ]);
            });

            if ($oldId > 0 && $oldId !== $newId) {
                GameAccount::query()
                    ->where('current_pc_id', $oldId)
                    ->update(['status' => 'free', 'current_pc_id' => null]);
                $this->statuses->syncFor($oldId);
            }
            $this->statuses->syncFor($newId);

            return $booking->fresh(['computer']);
        });
    }

    private function isFreeForBooking(Booking $booking, ?Computer $to): bool
    {
        if (! $to) {
            return false;
        }
        if ((int) $to->id === (int) $booking->computer_id) {
            return true;
        }

        $fromClub = (int) (
            $booking->computer?->club_id
            ?? Computer::query()->whereKey($booking->computer_id)->value('club_id')
            ?? 0
        );
        if ($fromClub > 0 && (int) $to->club_id !== $fromClub) {
            return false;
        }
        if ($to->isTvBoothSeat()) {
            return false;
        }
        if ($to->isInMaintenance()) {
            return false;
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $booking = $this->timing->healSkewedWindow($booking);
        [$from, $until] = $this->occupancyWindow($booking, $now);
        if ($until <= $from) {
            return false;
        }

        $occupied = $this->bookings->occupiedComputerIds(
            [(int) $to->id],
            $from,
            $until,
            [(int) $booking->id]
        );

        return $occupied === [];
    }

    /**
     * Окно, которое сессия реально займёт после входа (ранний старт сдвигает слот на «сейчас»).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function occupancyWindow(Booking $booking, CarbonImmutable $now): array
    {
        $wall = $this->timing->wallClockWindow($booking);
        $paidMinutes = $this->timing->paidDurationMinutes($booking, $wall['start'], $wall['end']);

        if ($now->lt($wall['start'])) {
            return [$now, $now->addMinutes($paidMinutes)];
        }

        $remaining = $this->timing->remainingSeconds($booking, $now);
        if ($remaining <= 0) {
            return [$now, $now];
        }

        return [$now, $now->addSeconds($remaining)];
    }

    private function occupiedMessage(string $bookedName): string
    {
        return sprintf(
            'Вы забронировали компьютер «%s». Перейдите на своё место — этот компьютер занят.',
            $bookedName
        );
    }

    private function offerMessage(string $bookedName): string
    {
        return sprintf(
            'Вы забронировали компьютер «%s», однако этот свободен на время вашей брони. Хотите использовать его?',
            $bookedName
        );
    }

    private function pcLabel(?Computer $pc, int $id): string
    {
        $name = trim((string) ($pc?->name ?? ''));

        return $name !== '' ? $name : ('№'.$id);
    }
}
