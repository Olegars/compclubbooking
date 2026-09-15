<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemDocs;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemDocsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/SystemDocs', [
            'sections' => SystemDocs::sections(),
        ]);
    }

    /** Справка A4 → печать / «Сохранить как PDF». */
    public function printPdf(Request $request): Response
    {
        $section = (string) $request->query('section', 'all');
        $query = trim((string) $request->query('q', ''));

        return Inertia::render('Admin/SystemDocsPrint', [
            'sections' => SystemDocs::filtered($section, $query),
            'printedAt' => now()->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'section' => $section !== '' ? $section : 'all',
            'query' => $query,
        ]);
    }
}
