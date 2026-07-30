<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AchievementAdminController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Achievements', [
            'achievements' => Achievement::query()
                ->withCount([
                    'userAchievements as completions_count' => fn ($q) => $q->whereNotNull('rewarded_at'),
                ])
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        Achievement::create($validated);

        return back();
    }

    public function update(Request $request, Achievement $achievement)
    {
        $validated = $this->validated($request, $achievement->id);
        $achievement->update($validated);

        return back();
    }

    public function toggle(Achievement $achievement)
    {
        $achievement->update(['is_active' => ! $achievement->is_active]);

        return back();
    }

    public function destroy(Achievement $achievement)
    {
        $achievement->delete();

        return back();
    }

    protected function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'type' => 'required|in:play_hours,night_visits,visit_count',
            'target_value' => 'required|numeric|min:0.1',
            'period' => 'required|in:once,weekly,monthly',
            'reward_type' => 'required|in:deposit_balance,bonus_balance',
            'reward_value' => 'required|numeric|min:1',
            'night_start' => 'nullable|integer|min:0|max:23',
            'night_end' => 'nullable|integer|min:0|max:23',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $data['night_start'] = $data['night_start'] ?? 22;
        $data['night_end'] = $data['night_end'] ?? 6;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['description'] = $data['description'] ?? '';

        return $data;
    }
}
