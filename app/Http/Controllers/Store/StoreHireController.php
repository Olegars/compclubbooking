<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\StaffEmploymentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class StoreHireController extends Controller
{
    public function __construct(
        private readonly StaffEmploymentService $employment,
    ) {
    }

    public function index()
    {
        $admin = auth('admin')->user();
        if (! $admin->isStoreRole()) {
            return redirect()->route($admin->homeRoute() ?: 'admin.salary');
        }
        if (! $admin->needsEmployment()) {
            return redirect()->route('admin.salary');
        }

        return Inertia::render('Auth/StoreHire', [
            'employment' => $this->employment->payload($admin),
        ]);
    }

    public function acceptRule(Request $request)
    {
        $this->assertStoreApplicant();
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        try {
            $this->employment->acceptRule(auth('admin')->user(), (int) $data['rule_id']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back();
    }

    public function acceptFireRule(Request $request)
    {
        $this->assertStoreApplicant();
        $data = $request->validate([
            'rule_id' => ['required', 'integer'],
        ]);

        try {
            $this->employment->acceptFireRule(auth('admin')->user(), (int) $data['rule_id']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        $admin = auth('admin')->user()->fresh();
        if (! $admin->needsEmployment()) {
            return redirect()->route('admin.salary')->with('success', 'Вы приняты. Кабинет магазина открыт.');
        }

        return back();
    }

    public function hire(Request $request)
    {
        $admin = $this->assertStoreApplicant();
        $hasScan = filled($this->employment->profile($admin)->passport_scan_path);
        $data = $request->validate($this->employment->hireRules($hasScan), $this->employment->hireMessages());

        try {
            $this->employment->submit($admin, $data, $request->file('passport_scan'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('success', 'Анкета отправлена на проверку.');
    }

    private function assertStoreApplicant()
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->isStoreRole(), 403);

        return $admin;
    }
}
