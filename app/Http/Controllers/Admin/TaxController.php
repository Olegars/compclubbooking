<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StaffCadreService;
use App\Services\TaxReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function index(Request $request, TaxReportService $taxes): Response
    {
        $year = (int) $request->integer('year', now()->year);
        $year = max(2024, min(2100, $year));

        $payload = $taxes->forYear($year);
        $payload['cadre'] = ['alerts' => app(StaffCadreService::class)->alerts()];

        return Inertia::render('Admin/Taxes', $payload);
    }

    public function kudir(Request $request, TaxReportService $taxes): Response
    {
        $year = (int) $request->integer('year', now()->year);
        $year = max(2024, min(2100, $year));

        return Inertia::render('Admin/TaxesKudir', $taxes->kudir($year));
    }
}
