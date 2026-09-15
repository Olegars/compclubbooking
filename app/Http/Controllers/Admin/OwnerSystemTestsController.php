<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OwnerSystemTestService;
use App\Support\AdminLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OwnerSystemTestsController extends Controller
{
    public function __construct(
        private readonly OwnerSystemTestService $tests,
    ) {
    }

    public function index(): Response
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->isOwner(), 403);

        $club = AdminLocation::resolve($admin);

        return Inertia::render('Admin/SystemTests', [
            'location' => $club ? [
                'id' => $club->id,
                'name' => $club->name,
                'type' => $club->type,
            ] : null,
            'tests' => $this->tests->catalog($club),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->isOwner(), 403);

        $data = $request->validate([
            'id' => 'required|string|max:191',
        ]);

        $id = (string) $data['id'];
        $known = collect($this->tests->catalog())->contains(fn ($row) => $row['id'] === $id);
        abort_unless($known, 422, 'Неизвестный тест.');

        if (str_starts_with($id, 'phpunit:')) {
            set_time_limit($id === 'phpunit:all' ? 600 : 180);
        } else {
            set_time_limit(60);
        }

        return response()->json($this->tests->run($id, AdminLocation::resolve($admin)));
    }
}
