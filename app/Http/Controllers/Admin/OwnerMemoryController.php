<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MemoryLoadService;
use App\Support\AdminLocation;
use Inertia\Inertia;
use Inertia\Response;

class OwnerMemoryController extends Controller
{
    public function __construct(
        private readonly MemoryLoadService $memory,
    ) {
    }

    public function index(): Response
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->isOwner(), 403);

        $club = AdminLocation::resolve($admin);
        $board = $this->memory->board($club);

        return Inertia::render('Admin/Memory', [
            'location' => $club ? [
                'id' => $club->id,
                'name' => $club->name,
                'type' => $club->type,
            ] : null,
            'stations' => $board['stations'],
            'summary' => $board['summary'],
            'updated_at' => now()->toIso8601String(),
            'chart_hours' => MemoryLoadService::CHART_HOURS,
            'stale_seconds' => MemoryLoadService::STALE_SECONDS,
        ]);
    }
}
