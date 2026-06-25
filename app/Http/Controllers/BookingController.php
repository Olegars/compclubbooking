<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Computer;
use App\Models\Tariff; // Не забудь импортировать модель тарифов
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * Динамический расчет стоимости сессии для фронтенда
     */
    public function calculatePrice(Request $request)
    {
        $request->validate([
            'pc_ids'   => 'required|array',
            'duration' => 'required|numeric',
        ]);

        $originalDuration = (float) $request->duration;
        $countPcs = count($request->pc_ids);

        if ($countPcs === 0 || $originalDuration <= 0) {
            return response()->json(['total_price' => 0]);
        }

        // Вычисляем базовый пакет (округляем вниз до целого часа)
        $hoursForTariff = (int) floor($originalDuration);
        if ($hoursForTariff < 1) {
            $hoursForTariff = 1;
        }

        $tariff = Tariff::where('threshold_hours', $hoursForTariff)->first();

        if ($tariff) {
            // Вычисляем стоимость часа внутри этого пакета (например, 300 руб / 3 часа = 100 руб/час)
            $pricePerHour = $tariff->price_per_package / $tariff->threshold_hours;
        } else {
            $pricePerHour = 250; // Дефолтный тариф, если в БД пусто
        }

        // Считаем итоговую сумму с учетом точного дробного времени
        $totalPrice = (int) round($pricePerHour * $originalDuration * $countPcs);

        return response()->json(['total_price' => $totalPrice]);
    }

    /**
     * Безопасное резервирование узлов
     */
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
        $originalDuration = (float) $request->duration;
        $countPcs = count($request->pc_ids);

        // Повторяем коммерческий расчет на стороне сервера (защита от изменения цены на фронте)
        $hoursForTariff = (int) floor($originalDuration);
        if ($hoursForTariff < 1) {
            $hoursForTariff = 1;
        }

        $tariff = Tariff::where('threshold_hours', $hoursForTariff)->first();

        if ($tariff) {
            $pricePerHour = $tariff->price_per_package / $tariff->threshold_hours;
        } else {
            $pricePerHour = 250;
        }

        $calculatedPrice = $pricePerHour * $originalDuration * $countPcs;
        $totalPrice = (int) round($calculatedPrice); // Округляем до целых рублей для Postgres integer

        // ЗАЩИТА: Сверяем цену сервера с ценой, которую прислал фронт (зазор 1 руб на округление)
        if (abs((int)round($request->price) - $totalPrice) > 1) {
            return response()->json(['message' => 'Ошибка валидации стоимости: сумма не совпадает с тарифом сервера.'], 422);
        }

        if ($user->wallet->deposit_balance < $totalPrice) {
            return response()->json(['message' => 'Недостаточно средств на балансе.'], 422);
        }

        try {
            return DB::transaction(function () use ($user, $request, $totalPrice, $originalDuration, $countPcs) {

                $start = (float) $request->start_h;
                $end   = $start + $originalDuration;

                $generatedPins = [];

                foreach ($request->pc_ids as $pcId) {

                    // Проверка на занятость
                    $isOccupied = Booking::where('computer_id', $pcId)
                        ->where('date', $request->date)
                        ->whereIn('status', ['active', 'paid', 'confirmed'])
                        ->where(function($q) use ($start, $end) {
                            $q->whereRaw('start_time < ? AND (start_time + duration) > ?', [$end, $start]);
                        })->exists();

                    if ($isOccupied) {
                        throw new \Exception("Узел #{$pcId} уже занят на выбранное время.");
                    }

                    $pinCode = rand(1000, 9999);
                    $generatedPins[$pcId] = $pinCode;

                    // Делим общую сумму на количество компов (пишем целое число)
                    $singlePcPrice = (int) floor($totalPrice / $countPcs);

                    // Создаем запись бронирования
                    Booking::create([
                        'user_id'     => $user->id,
                        'computer_id' => $pcId,
                        'pc_ids'      => [$pcId],
                        'date'        => $request->date,
                        'start_time'  => $request->start_h,
                        'duration'    => $originalDuration, // Пишем точное дробное время в базу
                        'price'       => $singlePcPrice,
                        'status'      => 'active',
                        'pin_code'    => $pinCode
                    ]);

                    // Мгновенное обновление статуса железки
                    $nowH = now()->hour + (now()->minute / 60);
                    if ($request->date === now()->toDateString() && $nowH >= $start && $nowH < $end) {
                        DB::table('computers')->where('id', $pcId)->update(['status' => 'busy']);
                    }
                }

                // Списание средств с баланса игрока
                $user->wallet()->decrement('deposit_balance', $totalPrice);

                // Фиксация финансовой транзакции
                Transaction::create([
                    'user_id'     => $user->id,
                    'amount'      => -$totalPrice,
                    'type'        => 'booking',
                    'source'      => 'balance',
                    'description' => 'Резерв узлов: ' . implode(', ', $request->pc_ids),
                    'date'        => now()->format('d.m.Y H:i')
                ]);

                return response()->json([
                    'status' => 'success',
                    'pins'   => $generatedPins
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
