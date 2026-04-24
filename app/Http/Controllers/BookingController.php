<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Computer;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function reserve(Request $request)
    {
        $request->validate([
            'pc_ids'   => 'required|array', // Приходит массив [1, 2, 5]
            'price'    => 'required|numeric',
            'date'     => 'required|string',
            'start_h'  => 'required|numeric',
            'duration' => 'required|numeric',
        ]);

        $user = auth()->user();
        $totalPrice = $request->price;

        // 1. Проверка баланса
        if ($user->wallet->balance < $totalPrice) {
            return response()->json(['message' => 'Недостаточно средств на балансе.'], 422);
        }

        try {
            return DB::transaction(function () use ($user, $request, $totalPrice) {

                $start = (float) $request->start_h;
                $end   = $start + (float) $request->duration;

                foreach ($request->pc_ids as $pcId) {

                    // 2. ПРОВЕРКА НА ЗАНЯТОСТЬ (уже по новой структуре)
                    $isOccupied = Booking::where('computer_id', $pcId)
                        ->where('date', $request->date)
                        ->where('status', 'active')
                        ->where(function($q) use ($start, $end) {
                            // Проверяем пересечение отрезков времени
                            $q->whereRaw('start_time < ? AND (start_time + duration) > ?', [$end, $start]);
                        })->exists();

                    if ($isOccupied) {
                        throw new \Exception("Узел #{$pcId} уже занят на выбранное время.");
                    }

                    // 3. СОЗДАЕМ ЗАПИСЬ (по одной на каждый ПК)
                    Booking::create([
                        'user_id'     => $user->id,
                        'computer_id' => $pcId,
                        'date'        => $request->date,
                        'start_time'  => $request->start_h,
                        'duration'    => $request->duration,
                        'price'       => $totalPrice / count($request->pc_ids), // делим общую сумму на кол-во мест
                        'status'      => 'active'
                    ]);

                    // 4. МГНОВЕННОЕ ОБНОВЛЕНИЕ СТАТУСА (если бронь на сейчас)
                    $nowH = now()->hour + (now()->minute / 60);
                    if ($request->date === now()->toDateString() && $nowH >= $start && $nowH < $end) {
                        DB::table('computers')->where('id', $pcId)->update(['status' => 'busy']);
                    }
                }

                // 5. СПИСАНИЕ СРЕДСТВ
                $user->wallet()->decrement('balance', $totalPrice);

                // 6. ТРАНЗАКЦИЯ (одна общая для истории)
                Transaction::create([
                    'user_id'     => $user->id,
                    'amount'      => -$totalPrice,
                    'type'        => 'booking',
                    'source'      => 'balance',
                    'description' => 'Резерв узлов: ' . implode(', ', $request->pc_ids),
                    'date'        => now()->format('d.m.Y H:i')
                ]);

                return response()->json(['status' => 'success']);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
