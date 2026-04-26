<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class BookingObserver
{
    /**
     * Срабатывает при обновлении брони (например, когда увеличили время)
     */
    public function updated(Booking $booking)
    {
        // Проверяем, изменилась ли цена после сохранения
        if ($booking->wasChanged('price')) {
            $oldPrice = (float) $booking->getOriginal('price');
            $newPrice = (float) $booking->price;
            $diff = $newPrice - $oldPrice;

            // Если разница положительная (апгрейд тарифа)
            if ($diff > 0) {
                DB::transaction(function () use ($booking, $diff) {
                    // 1. Списываем разницу с кошелька
                    $booking->user->wallet->decrement('balance', $diff);

                    // 2. Создаем запись в логе для юзера
                    Transaction::create([
                        'user_id'     => $booking->user_id,
                        'amount'      => -$diff,
                        'type'        => 'booking_upgrade',
                        'source'      => 'balance',
                        'description' => "Апгрейд тарифа: ПК №" . $booking->computer_id . " ({$booking->duration} ч.)",
                        'date'        => now()->format('d.m.Y H:i')
                    ]);
                });
            }
        }
    }

    /**
     * Если ты планируешь пересчитывать цену ПЕРЕД созданием,
     * можешь добавить сюда метод creating или saving
     */
}
