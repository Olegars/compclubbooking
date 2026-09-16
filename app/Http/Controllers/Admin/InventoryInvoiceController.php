<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InventoryInvoiceOcrService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use RuntimeException;

class InventoryInvoiceController extends Controller
{
    public function parse(Request $request, InventoryInvoiceOcrService $ocr)
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp,gif|max:8192',
        ]);

        try {
            $draft = $ocr->parsePhoto(
                $request->file('photo'),
                AdminLocation::id(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($draft);
    }

    public function close(Request $request, InventoryInvoiceOcrService $ocr)
    {
        $data = $request->validate([
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'invoice_number' => 'nullable|string|max:64',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'lines' => 'required|array|min:1|max:80',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.name' => 'nullable|string|max:255',
            'lines.*.product_name' => 'nullable|string|max:255',
            'lines.*.qty' => 'required|integer|min:1|max:9999',
            'lines.*.scanned_qty' => 'required|integer|min:0|max:9999',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'extras' => 'nullable|array|max:80',
        ]);

        try {
            $result = $ocr->close(
                $data['lines'],
                $data['extras'] ?? [],
                (int) auth('admin')->id(),
                isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                $data['invoice_number'] ?? null,
                $data['invoice_date'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'matched',
            'invoice_number' => $result['invoice']?->number,
        ]);
    }
}
