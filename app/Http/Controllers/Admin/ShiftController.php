<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shift;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function transferPage()
    {
        $products = Product::select('id', 'name', 'stock', 'category')->get();
        return Inertia::render('Admin/ShiftTransfer', [
            'expected' => $products
        ]);
    }

    public function completeTransfer(Request $request)
    {
        $admin = auth()->user();

        return DB::transaction(function () use ($request, $admin) {
            // 1. Создаем новую смену
            $shift = Shift::create([
                'admin_id' => $admin->id,
                'status' => 'open',
                'started_at' => now(),
            ]);

            foreach ($request->items as $item) {
                // 2. Логируем состояние в таблицу передачи
                DB::table('shift_inventory')->insert([
                    'shift_id' => $shift->id,
                    'product_id' => $item['id'],
                    'expected_stock' => $item['stock'],
                    'actual_stock' => $item['actual'],
                    'created_at' => now(),
                ]);

                // 3. Если есть расхождение — пишем в Инциденты
                if ($item['actual'] != $item['stock']) {
                    DB::table('incidents')->insert([
                        'type' => 'inventory_discrepancy',
                        'severity' => 'high',
                        'description' => "РАСХОЖДЕНИЕ ПРИ ПРИЕМКЕ: {$item['name']}. Ожидалось: {$item['stock']}, по факту: {$item['actual']}. Принял: {$admin->name}",
                        'created_at' => now(),
                    ]);

                    // Обновляем реальный остаток в базе до фактического
                    Product::where('id', $item['id'])->update(['stock' => $item['actual']]);
                }
            }

            return redirect()->route('admin.dashboard');
        });
    }
    public function history()
    {
        // Пока передаем пустой массив, чтобы не словить ошибку базы данных,
        // если таблицы shifts у нас еще нет или она пустая.
        // Позже здесь будет что-то вроде: Shift::with('admin')->orderByDesc('closed_at')->get();
        $shifts = [];

        return \Inertia\Inertia::render('Admin/ShiftHistory', [
            'shifts' => $shifts
        ]);
    }
}
