<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\OwnerCabinetService;
use Inertia\Inertia;
use Inertia\Response;

class OwnerCabinetController extends Controller
{
    public function __construct(
        private readonly OwnerCabinetService $cabinet,
    ) {
    }

    public function index(): Response
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->isOwner(), 403);

        return Inertia::render('Admin/OwnerCabinet', $this->cabinet->snapshot($admin));
    }
}
