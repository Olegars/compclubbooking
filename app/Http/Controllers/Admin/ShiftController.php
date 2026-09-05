<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shift;
use App\Models\ShiftIntern;
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

        if ($admin?->isIntern()) {
            return back()->withErrors([
                'message' => 'Стажёр не принимает смену. Выйдите в смену вместе с активным админом.',
            ]);
        }

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
                ShiftIntern::query()
                    ->whereIn('shift_id', $closingIds)
                    ->whereNull('left_at')
                    ->update(['left_at' => now()]);

                Shift::query()
                    ->whereIn('id', $closingIds)
                    ->with(['admin', 'internSlots.admin'])
                    ->get()
                    ->each(function (Shift $closed) use ($payroll) {
                        $payroll->accrueClosedShift($closed);
                        $payroll->accrueClosedShiftInterns($closed);
                    });
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

    public function internJoin()
    {
        $admin = auth('admin')->user();
        if (! $admin?->isIntern()) {
            abort(403, 'В смену к активному админу выходит только стажёр.');
        }

        if ($admin->needsEmployment()) {
            return back()->withErrors(['message' => 'Сначала завершите устройство на работу.']);
        }

        $shift = Shift::query()
            ->with('admin:id,name')
            ->where('status', '!=', 'closed')
            ->orderByDesc('id')
            ->first();
        if (! $shift) {
            return back()->withErrors(['message' => 'Нет активной смены — сначала админ должен принять смену.']);
        }

        ShiftIntern::query()->firstOrCreate(
            [
                'shift_id' => $shift->id,
                'admin_id' => $admin->id,
            ],
            [
                'joined_at' => now(),
            ]
        );

        ShiftIntern::query()
            ->where('shift_id', $shift->id)
            ->where('admin_id', $admin->id)
            ->whereNotNull('left_at')
            ->update(['left_at' => null, 'joined_at' => now()]);

        return back()->with('success', 'Вы вышли в смену вместе с '.$shift->admin?->name);
    }

    public function internLeave()
    {
        $admin = auth('admin')->user();
        if (! $admin?->isIntern()) {
            abort(403);
        }

        ShiftIntern::query()
            ->where('admin_id', $admin->id)
            ->whereNull('left_at')
            ->whereHas('shift', fn ($q) => $q->where('status', '!=', 'closed'))
            ->update(['left_at' => now()]);

        return back()->with('success', 'Вы ушли со смены.');
    }
}
