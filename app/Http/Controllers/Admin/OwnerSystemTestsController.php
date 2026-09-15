<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OwnerSystemTestService;
use App\Support\AdminLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function printPdf(Request $request): Response|RedirectResponse
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->isOwner(), 403);

        if ($request->isMethod('post')) {
            $data = $request->validate([
                'results' => 'required|array|min:1|max:250',
                'results.*.id' => 'required|string|max:191',
                'results.*.title' => 'required|string|max:191',
                'results.*.group' => 'nullable|string|max:64',
                'results.*.group_title' => 'nullable|string|max:191',
                'results.*.kind' => 'nullable|string|in:live,phpunit',
                'results.*.status' => 'nullable|string|in:pass,fail,warn,skip',
                'results.*.message' => 'nullable|string|max:4000',
                'results.*.details' => 'nullable|array|max:40',
                'results.*.details.*' => 'nullable|string|max:500',
                'results.*.duration_ms' => 'nullable|integer|min:0|max:3600000',
            ]);
            $rows = array_values($data['results']);
            $request->session()->put('owner_system_test_pdf', $rows);
        } else {
            $rows = $request->session()->get('owner_system_test_pdf');
            if (! is_array($rows) || $rows === []) {
                return redirect()->route('admin.system-tests')
                    ->with('error', 'Сначала запустите тесты, затем выгрузите PDF.');
            }
        }

        $club = AdminLocation::resolve($admin);
        $summary = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skip' => 0, 'pending' => 0, 'ran' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] ?? null;
            if (! is_string($status) || $status === '') {
                $summary['pending']++;
                continue;
            }
            $summary['ran']++;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return Inertia::render('Admin/SystemTestsPrint', [
            'location' => $club ? [
                'id' => $club->id,
                'name' => $club->name,
                'type' => $club->type,
            ] : null,
            'printedAt' => now()->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'owner' => $admin->name,
            'results' => $rows,
            'summary' => $summary,
        ]);
    }
}
