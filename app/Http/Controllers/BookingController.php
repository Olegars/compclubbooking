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
            'pc_ids'   => 'required|array',
            'price'    => 'required|numeric',
            'date'     => 'required|string',
            'start_h'  => 'required|numeric',
            'duration' => 'required|numeric',
        ]);

        $user = auth()->user();
        $totalPrice = $request->price;

        if ($user->wallet->balance < $totalPrice) {
            return response()->json(['message' => 'Недостаточно средств на балансе.'], 422);
        }

        try {
            return DB::transaction(function () use ($user, $request, $totalPrice) {

                $start = (float) $request->start_h;
                $end   = $start + (float) $request->duration;

                foreach ($request->pc_ids as $pcId) {

                    // 2. ПРОВЕРКА НА ЗАНЯТОСТЬ
                    $isOccupied = Booking::where('computer_id', $pcId)
                        ->where('date', $request->date)
                        ->whereIn('status', ['active', 'paid', 'confirmed']) // Добавил статусы для надежности
                        ->where(function($q) use ($start, $end) {
                            $q->whereRaw('start_time < ? AND (start_time + duration) > ?', [$end, $start]);
                        })->exists();

                    if ($isOccupied) {
                        throw new \Exception("Узел #{$pcId} уже занят на выбранное время.");
                    }

                    // 3. СОЗДАЕМ ЗАПИСЬ (Записываем и в computer_id, и в pc_ids!)
                    Booking::create([
                        'user_id'     => $user->id,
                        'computer_id' => $pcId, // Для внутренней логики занятости
                        'pc_ids'      => [$pcId], // ДЛЯ ФРОНТЕНДА И ДАШБОРДА (в формате массива)
                        'date'        => $request->date,
                        'start_time'  => $request->start_h,
                        'duration'    => $request->duration,
                        'price'       => $totalPrice / count($request->pc_ids),
                        'status'      => 'active'
                    ]);

                    // 4. МГНОВЕННОЕ ОБНОВЛЕНИЕ СТАТУСА
                    $nowH = now()->hour + (now()->minute / 60);
                    if ($request->date === now()->toDateString() && $nowH >= $start && $nowH < $end) {
                        DB::table('computers')->where('id', $pcId)->update(['status' => 'busy']);
                    }
                }

                // 5. СПИСАНИЕ СРЕДСТВ
                $user->wallet()->decrement('balance', $totalPrice);

                // 6. ТРАНЗАКЦИЯ
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
