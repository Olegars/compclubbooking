<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemDocs;
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
}
