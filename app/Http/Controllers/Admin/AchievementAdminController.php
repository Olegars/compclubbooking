<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\AchievementBadge;
use App\Models\Admin;
use App\Models\ArenaRating;
use App\Models\BattlePassLevel;
use App\Models\BattlePassSeason;
use App\Models\ClubStatus;
use App\Models\CosmeticFrame;
use App\Models\LadderRewardTier;
use App\Models\Product;
use App\Models\UserBattlePass;
use App\Services\BattlePassService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AchievementAdminController extends Controller
{
    public function index(Request $request, BattlePassService $pass)
    {
        $clubId = $this->clubId();
        $tab = (string) $request->query('tab', 'quests');
        if (! in_array($tab, ['quests', 'catalog', 'pass', 'cosmetics', 'ladder', 'sources'], true)) {
            $tab = 'quests';
        }
        if (! $this->canManage() && $tab !== 'quests') {
            $tab = 'quests';
        }
        $season = $clubId
            ? BattlePassSeason::query()->where('club_id', $clubId)->orderByDesc('id')->first()
            : null;
        $histogram = [];
        if ($season) {
            $histogram = UserBattlePass::query()
                ->where('season_id', $season->id)
                ->selectRaw('level, count(*) as guests')
                ->groupBy('level')
                ->orderBy('level')
                ->get();
        }

        return Inertia::render('Admin/Achievements', [
            'tab' => $tab,
            'can_manage' => $this->canManage(),
            'achievements' => Achievement::query()
                ->withCount([
                    'userAchievements as completions_count' => fn ($q) => $q->whereNotNull('rewarded_at'),
                ])
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get(),
            'templates' => Achievement::query()->whereNotNull('code')->orderBy('sort_order')->get(),
            'season' => $season,
            'levels' => $season ? $season->levels()->get() : [],
            'histogram' => $histogram,
            'frames' => $clubId ? CosmeticFrame::query()->where('club_id', $clubId)->orderBy('name')->get() : [],
            'statuses' => $clubId ? ClubStatus::query()->where('club_id', $clubId)->orderByDesc('priority')->get() : [],
            'badges' => $clubId ? AchievementBadge::query()->where('club_id', $clubId)->orderBy('name')->get() : [],
            'ladder' => $clubId ? LadderRewardTier::query()->where('club_id', $clubId)->orderBy('sort_order')->get() : [],
            'elo_top' => $clubId
                ? ArenaRating::query()->where('club_id', $clubId)->orderByDesc('rating')->limit(10)->get(['user_id', 'rating'])
                : [],
            'sources' => $clubId ? $pass->settings($clubId) : null,
            'source_keys' => [
                'steam' => filled(config('services.steam.web_api_key')),
                'faceit' => filled(config('services.faceit.api_key')),
                'opendota' => true,
                'stratz' => filled(config('services.loyalty.stratz_token')),
                'riot' => filled(config('services.loyalty.riot_api_key')),
                'pubg' => filled(config('services.loyalty.pubg_api_key')),
                'tracker' => filled(config('services.loyalty.tracker_api_key')),
            ],
            'products' => Product::query()->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name', 'category', 'stock']),
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
            'xp' => 'nullable|integer|min:0|max:500',
            'badge_id' => 'nullable|integer|exists:achievement_badges,id',
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
        $data['xp'] = (int) ($data['xp'] ?? 0);
        $data['badge_id'] = $data['badge_id'] ?? null;
        $data['source_kind'] = $data['source_kind'] ?? 'club';

        return $data;
    }

    public function seedCatalog(BattlePassService $pass)
    {
        $this->assertManager();
        $pass->seedRecommended($this->clubId());

        return back()->with('success', 'Набор сезона накатан');
    }

    public function saveSeason(Request $request)
    {
        $this->assertManager();
        $data = $request->validate([
            'title' => 'required|string|max:80',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'claim_grace_days' => 'required|integer|min:7|max:14',
            'is_active' => 'nullable|boolean',
        ]);
        $clubId = $this->clubId();
        $active = (bool) ($data['is_active'] ?? false);
        if ($active && BattlePassSeason::query()->where('club_id', $clubId)->where('is_active', true)->where('status', BattlePassSeason::LIVE)->exists()) {
            throw ValidationException::withMessages(['title' => 'Сначала закройте текущий сезон']);
        }
        BattlePassSeason::query()->create([
            'club_id' => $clubId,
            'title' => $data['title'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'claim_grace_days' => $data['claim_grace_days'],
            'is_active' => $active,
            'status' => BattlePassSeason::LIVE,
        ]);

        return back();
    }

    public function closeSeason(BattlePassService $pass)
    {
        $this->assertManager();
        $season = BattlePassSeason::query()
            ->where('club_id', $this->clubId())
            ->where('status', BattlePassSeason::LIVE)
            ->first();
        if ($season) {
            $pass->close($season);
        }

        return back();
    }

    public function saveLevel(Request $request)
    {
        $this->assertManager();
        $data = $request->validate([
            'season_id' => 'required|integer|exists:battle_pass_seasons,id',
            'level' => 'required|integer|min:1|max:100',
            'xp_required' => 'required|integer|min:1',
            'reward_kind' => 'required|string|max:32',
            'reward_payload' => 'nullable|array',
        ]);
        BattlePassLevel::query()->updateOrCreate(
            ['season_id' => $data['season_id'], 'level' => $data['level']],
            [
                'xp_required' => $data['xp_required'],
                'reward_kind' => $data['reward_kind'],
                'reward_payload' => $data['reward_payload'] ?? [],
            ],
        );

        return back();
    }

    public function saveSources(Request $request, BattlePassService $pass)
    {
        $this->assertManager();
        $data = $request->validate([
            'gsi_awards' => 'nullable|boolean',
            'steam' => 'nullable|boolean',
            'opendota' => 'nullable|boolean',
            'riot' => 'nullable|boolean',
            'pubg' => 'nullable|boolean',
            'tracker' => 'nullable|boolean',
            'faceit_skill' => 'nullable|boolean',
            'telegram_ace' => 'nullable|boolean',
            'tracker_city' => 'nullable|string|max:64',
            'showcase_slots' => 'nullable|integer|min:3|max:6',
        ]);
        $row = $pass->settings($this->clubId());
        $row->fill($data);
        $row->save();

        return back();
    }

    public function syncNow()
    {
        $this->assertManager();
        \App\Jobs\SyncIdentityJob::dispatchClub($this->clubId());

        return back()->with('success', 'Синхронизация в очереди');
    }

    private function canManage(): bool
    {
        $role = (string) (auth('admin')->user()->role ?? '');

        return in_array($role, [Admin::ROLE_SUPERVISOR, Admin::ROLE_OWNER], true);
    }

    private function assertManager(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }

    private function clubId(): int
    {
        $id = (int) (auth('admin')->user()->club_id ?? 0);
        if ($id < 1) {
            $id = (int) \App\Models\Club::query()->orderBy('id')->value('id');
        }

        return $id;
    }
}
