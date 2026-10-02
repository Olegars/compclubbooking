<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\Light\WledCueService;

class BookingObserver
{
    /**
     * Ранее здесь автосписывался diff price → type=booking_upgrade.
     * Это давало двойное списание при пересадке/продлении (сервис уже создаёт purchase).
     * Биллинг цены — только в сервисах (GameBooking / SeatTransfer / SessionExtend).
     */
    public function created(Booking $booking): void
    {
        try {
            app(WledCueService::class)->fireForBooking($booking);
        } catch (\Throwable $e) {
            WledCueService::reportFailure($e, 'booking');
        }
    }

    public function updated(Booking $booking): void
    {
        //
    }
}
