<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\InventoryCostService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function index(InventoryCostService $costs)
    {
        $suppliers = Supplier::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $debt = $costs->debtReport();
        $margins = $costs->marginSnapshot();

        return Inertia::render('Admin/Suppliers', [
            'suppliers' => $suppliers,
            'debt' => $debt,
            'margins' => $margins,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'inn' => 'nullable|string|max:32',
            'phone' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:191',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $data['payment_terms_days'] = (int) ($data['payment_terms_days'] ?? 0);
        $data['is_active'] = filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        Supplier::create($data);

        return back()->with('success', 'Поставщик добавлен');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'inn' => 'nullable|string|max:32',
            'phone' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:191',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $data['payment_terms_days'] = (int) ($data['payment_terms_days'] ?? 0);
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $supplier->update($data);

        return back()->with('success', 'Поставщик обновлён');
    }

    public function storeInvoice(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'number' => 'nullable|string|max:64',
            'issued_at' => 'required|date',
            'due_at' => 'nullable|date',
            'total_amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:2000',
        ]);

        $admin = auth('admin')->user();
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $terms = (int) $supplier->payment_terms_days;

        SupplierInvoice::create([
            'supplier_id' => $supplier->id,
            'number' => $data['number'] ?: null,
            'issued_at' => $data['issued_at'],
            'due_at' => $data['due_at'] ?? ($terms > 0
                ? Carbon::parse($data['issued_at'])->addDays($terms)->toDateString()
                : $data['issued_at']),
            'total_amount' => round((float) $data['total_amount'], 2),
            'paid_amount' => 0,
            'status' => SupplierInvoice::STATUS_OPEN,
            'notes' => $data['notes'] ?? null,
            'admin_id' => $admin?->id,
        ]);

        return back()->with('success', 'Счёт добавлен');
    }

    public function payInvoice(Request $request, SupplierInvoice $invoice, InventoryCostService $costs)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:500',
        ]);

        $admin = auth('admin')->user();

        try {
            $costs->addPayment(
                $invoice,
                (float) $data['amount'],
                (int) $admin->id,
                $data['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Оплата записана');
    }
}
