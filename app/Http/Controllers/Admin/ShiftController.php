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
    // Сколько последних смен показываем в архиве
    private const HISTORY_LIMIT = 100;

    public function transferPage()
    {
        $products = Product::select('id', 'name', 'stock', 'category')->get();

        return Inertia::render('Admin/ShiftTransfer', [
            'expected' => $products,
            'expectedCash' => (float) (Shift::where('status', '!=', 'closed')
                ->orderByDesc('started_at')
                ->value('cash_start') ?? 0),
        ]);
    }

    public function completeTransfer(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string'],
            'items.*.stock' => ['required', 'integer'],
            'items.*.actual' => ['required', 'integer', 'min:0'],
            'cash_counted' => ['required', 'numeric', 'min:0'],
        ]);

        $admin = auth()->user();

        return DB::transaction(function () use ($data, $admin) {
            $cashCounted = (float) $data['cash_counted'];

            // 1. Закрываем смену, которую нам сдают. Пересчитанная касса — это одновременно
            // остаток на конец старой смены и начальный остаток новой.
            Shift::where('status', '!=', 'closed')->update([
                'status' => 'closed',
                'closed_by' => $admin->id,
                'ended_at' => now(),
                'cash_end' => $cashCounted,
            ]);

            // 2. Создаем новую смену
            $shift = Shift::create([
                'admin_id' => $admin->id,
                'status' => 'open',
                'started_at' => now(),
                'cash_start' => $cashCounted,
            ]);

            foreach ($data['items'] as $item) {
                // 3. Логируем состояние в таблицу передачи
                DB::table('shift_inventory')->insert([
                    'shift_id' => $shift->id,
                    'product_id' => $item['id'],
                    'expected_stock' => $item['stock'],
                    'actual_stock' => $item['actual'],
                    'created_at' => now(),
                ]);

                // 4. Если есть расхождение — пишем в Инциденты
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
        $shifts = Shift::with('admin:id,name')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'admin' => $shift->admin ? ['name' => $shift->admin->name] : null,
                'opened_at' => $shift->started_at?->toIso8601String(),
                'closed_at' => $shift->ended_at?->toIso8601String(),
                'cash_balance' => (float) ($shift->cash_end ?? $shift->cash_start),
                'status' => $shift->status,
            ]);

        return Inertia::render('Admin/ShiftHistory', [
            'shifts' => $shifts,
        ]);
    }
}
