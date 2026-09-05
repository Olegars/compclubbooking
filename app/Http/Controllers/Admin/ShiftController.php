<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shift;
use App\Services\ProductStockService;
use App\Services\StaffPayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShiftController extends Controller
{
    // Сколько последних смен показываем в архиве
    private const HISTORY_LIMIT = 100;

    public function transferPage()
    {
        $products = Product::select('id', 'name', 'stock', 'category', 'requires_marking')->get();

        return Inertia::render('Admin/ShiftTransfer', [
            'expected' => $products,
            'expectedCash' => (float) (Shift::where('status', '!=', 'closed')
                ->orderByDesc('started_at')
                ->value('cash_start') ?? 0),
        ]);
    }

    public function completeTransfer(Request $request, ProductStockService $stock)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string'],
            'items.*.stock' => ['required', 'integer'],
            'items.*.actual' => ['required', 'integer', 'min:0'],
            'items.*.requires_marking' => ['nullable', 'boolean'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
            'cash_counted' => ['required', 'numeric', 'min:0'],
        ]);

        $admin = auth()->user();

        foreach ($data['items'] as $item) {
            if ((int) $item['actual'] === (int) $item['stock']) {
                continue;
            }
            $product = Product::find($item['id']);
            if ($product?->requires_marking) {
                return back()->withErrors([
                    'items' => "«{$item['name']}» маркирован — спишите недостачу через КМ на складе, а не правкой пересменки.",
                ]);
            }
            if (trim((string) ($item['reason'] ?? '')) === '') {
                return back()->withErrors([
                    'items' => "Укажите причину расхождения для «{$item['name']}» (бой, просрочка, пересчёт и т.д.).",
                ]);
            }
        }

        return DB::transaction(function () use ($data, $admin, $stock) {
            $cashCounted = (float) $data['cash_counted'];
            $payroll = app(StaffPayrollService::class);

            // 1. Закрываем смену, которую нам сдают. Пересчитанная касса — это одновременно
            // остаток на конец старой смены и начальный остаток новой.
            $closingIds = Shift::where('status', '!=', 'closed')->pluck('id');

            Shift::where('status', '!=', 'closed')->update([
                'status' => 'closed',
                'closed_by' => $admin->id,
                'ended_at' => now(),
                'cash_end' => $cashCounted,
            ]);

            if ($closingIds->isNotEmpty()) {
                Shift::query()
                    ->whereIn('id', $closingIds)
                    ->with('admin')
                    ->get()
                    ->each(fn (Shift $closed) => $payroll->accrueClosedShift($closed));
            }

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

                if ((int) $item['actual'] === (int) $item['stock']) {
                    continue;
                }

                $product = Product::find($item['id']);
                if (! $product) {
                    continue;
                }

                $reasonNote = trim((string) ($item['reason'] ?? ''));

                try {
                    $stock->applyShiftAdjustment(
                        $product,
                        (int) $item['stock'],
                        (int) $item['actual'],
                        (int) $admin->id,
                        (int) $shift->id,
                        $reasonNote
                    );
                } catch (RuntimeException $e) {
                    throw $e;
                }

                // Объяснённая корректировка — medium, не «подозрение в краже»
                DB::table('incidents')->insert([
                    'type' => 'inventory_discrepancy',
                    'severity' => 'medium',
                    'description' => "Пересменка «{$item['name']}»: ожидалось {$item['stock']}, факт {$item['actual']}. Причина: {$reasonNote}. Принял: {$admin->name}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
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
