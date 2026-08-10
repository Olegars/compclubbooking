<?php

namespace App\Observers;

use App\Models\Booking;

class BookingObserver
{
    /**
     * Ранее здесь автосписывался diff price → type=booking_upgrade.
     * Это давало двойное списание при пересадке/продлении (сервис уже создаёт purchase).
     * Биллинг цены — только в сервисах (GameBooking / SeatTransfer / SessionExtend).
     */
    public function updated(Booking $booking): void
    {
        //
    }
}
