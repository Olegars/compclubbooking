<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Inertia\Inertia;

class TaxController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->input('year', now()->year);

        // 1. Считаем налогооблагаемый доход по кварталам
        $quarters = [];
        $totalIncome = 0;

        for ($q = 1; $q <= 4; $q++) {
            $start = Carbon::create($year)->startOfQuarter($q);
            $end = Carbon::create($year)->endOfQuarter($q);

            // Берем только РЕАЛЬНЫЕ деньги (не бонусы) и только приходы/возвраты
            $income = Transaction::whereBetween('created_at', [$start, $end])
                ->where('is_taxable', true)
                ->whereIn('type', ['deposit', 'refund'])
                ->sum('amount'); // возвраты (минусы) автоматически вычтутся из суммы

            $tax = $income > 0 ? $income * 0.06 : 0; // УСН 6%

            $quarters[$q] = [
                'income' => $income,
                'tax_raw' => $tax,
            ];
            $totalIncome += $income;
        }

        // 2. Расчет страховых взносов (цифры для 2024/2025 года)
        $fixedPremiums = 49500; // Фикс взносы ИП
        $extraPremium = $totalIncome > 300000 ? ($totalIncome - 300000) * 0.01 : 0; // 1% свыше 300к
        // Ограничение максимальной суммы 1% (в 2024 году это 277 571 руб.)
        $extraPremium = min($extraPremium, 277571);

        return Inertia::render('Admin/Taxes', [
            'year' => $year,
            'quarters' => $quarters,
            'total_income' => $totalIncome,
            'premiums' => [
                'fixed' => $fixedPremiums,
                'extra' => $extraPremium,
                'total' => $fixedPremiums + $extraPremium
            ]
        ]);
    }
}
