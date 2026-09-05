<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftIntern;
use App\Services\ShiftHandoverService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class ShiftController extends Controller
{
    private const HISTORY_LIMIT = 100;

    public function transferPage(ShiftHandoverService $handover)
    {
        $admin = auth('admin')->user();
        if ($admin?->isIntern()) {
            return redirect()->route('admin.salary')
                ->with('error', 'Стажёр не принимает смену. Выйдите в смену вместе с активным админом.');
        }

        return Inertia::render('Admin/ShiftTransfer', $handover->pagePayload($admin));
    }

    public function status(ShiftHandoverService $handover)
    {
        return response()->json($handover->statusPayload(auth('admin')->user()));
    }

    public function begin(Request $request, ShiftHandoverService $handover)
    {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
            'camera' => ['nullable', 'string', 'max:64'],
            'face_detected' => ['nullable', 'boolean'],
        ]);

        try {
            $handover->begin(auth('admin')->user(), $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($handover->pagePayload(auth('admin')->user()));
    }

    public function scan(Request $request, ShiftHandoverService $handover)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:512'],
        ]);

        try {
            $product = $handover->lookup(auth('admin')->user(), $data['code']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['product' => $product]);
    }

    public function countItem(Request $request, ShiftHandoverService $handover)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $handover->recordCount(auth('admin')->user(), (int) $data['product_id'], (int) $data['qty']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($handover->pagePayload(auth('admin')->user()));
    }

    public function completeTransfer(Request $request, ShiftHandoverService $handover)
    {
        $data = $request->validate([
            'cash_counted' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $handover->complete(auth('admin')->user(), (float) $data['cash_counted']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return redirect()->route('admin.dashboard');
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
            ->where('status', 'open')
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
