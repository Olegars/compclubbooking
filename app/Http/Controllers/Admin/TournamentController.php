<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\TournamentChallenge;
use App\Models\TournamentMatch;
use App\Models\TournamentPlayer;
use App\Models\User;
use App\Services\TournamentChallengeService;
use App\Services\TournamentService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TournamentController extends Controller
{
    public function index(TournamentChallengeService $challenges)
    {
        $clubId = AdminLocation::id(Auth::guard('admin')->user());

        $list = Tournament::query()
            ->with([
                'game:id,title',
                'club:id,name,city,network_name',
                'opponentClub:id,name,city,network_name',
                'players.user:id,name,phone',
                'players.club:id,name',
                'matches',
                'computers',
            ])
            ->withCount('computers')
            ->when($clubId, function ($q) use ($clubId) {
                $q->where(function ($q) use ($clubId) {
                    $q->where('club_id', $clubId)->orWhere('opponent_club_id', $clubId);
                });
            })
            ->orderByDesc('id')
            ->get()
            ->map(fn (Tournament $t) => $this->serialize($t, $clubId));

        $inbox = TournamentChallenge::query()
            ->with(['hostClub:id,name,slug,city,network_name', 'guestClub:id,name,slug,city,network_name', 'game:id,title'])
            ->when($clubId, function ($q) use ($clubId) {
                $q->where(function ($q) use ($clubId) {
                    $q->where('host_club_id', $clubId)->orWhere('guest_club_id', $clubId);
                });
            })
            ->orderByRaw("case status when 'pending' then 0 when 'agreed' then 1 else 2 end")
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (TournamentChallenge $c) => $challenges->serialize($c, $clubId));

        $computers = Computer::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->where(function ($q) {
                $q->where('kind', 'pc')->orWhereNull('kind');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'kind']);

        $clubs = Club::tournamentRoster()
            ->when($clubId, fn ($q) => $q->where('id', '!=', $clubId))
            ->orderBy('name')
            ->get()
            ->map(fn (Club $club) => $club->circuitCard())
            ->values();

        return Inertia::render('Admin/Tournaments', [
            'tournaments' => $list,
            'challenges' => $inbox,
            'games' => Game::query()->orderBy('title')->get(['id', 'title']),
            'computers' => $computers,
            'clubs' => $clubs,
            'host_club_id' => $clubId,
            'join_url' => url('/clubs/join'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'game_id' => 'required|integer|exists:games,id',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'entry_fee' => 'nullable|numeric|min:0',
            'prize_pool' => 'nullable|string|max:2000',
            'prize_first' => 'nullable|numeric|min:0',
            'prize_second' => 'nullable|numeric|min:0',
            'prize_third' => 'nullable|numeric|min:0',
            'lock_games' => 'nullable|boolean',
            'selected_pcs' => 'nullable|array',
            'selected_pcs.*' => 'integer|exists:computers,id',
        ]);

        $admin = Auth::guard('admin')->user();
        $clubId = AdminLocation::id($admin);
        if (! app(\App\Services\ClubFeatureService::class)->enabled($clubId, 'tournaments')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Турниры выключены в Конфигурация → Фичи',
            ]);
        }

        $tournament = Tournament::query()->create([
            'club_id' => $clubId,
            'name' => $data['name'],
            'game_id' => (int) $data['game_id'],
            'start_at' => $data['start_at'] ?? now(),
            'end_at' => $data['end_at'] ?? now()->addHours(4),
            'entry_fee' => (float) ($data['entry_fee'] ?? 0),
            'prize_pool' => $data['prize_pool'] ?? null,
            'prize_first_minor' => (int) round(((float) ($data['prize_first'] ?? 0)) * 100),
            'prize_second_minor' => (int) round(((float) ($data['prize_second'] ?? 0)) * 100),
            'prize_third_minor' => (int) round(((float) ($data['prize_third'] ?? 0)) * 100),
            'status' => 'planned',
            'format' => 'single_elim',
            'lock_games' => $request->boolean('lock_games', true),
        ]);

        $pcs = array_values(array_unique(array_map('intval', $data['selected_pcs'] ?? [])));
        if ($pcs !== []) {
            $tournament->computers()->sync($pcs);
        }

        return back();
    }

    public function propose(Request $request, TournamentChallengeService $challenges)
    {
        $data = $this->challengeRules($request);
        $admin = Auth::guard('admin')->user();
        $clubId = (int) (AdminLocation::id($admin) ?: 0);
        $challenges->propose($clubId, $admin, $data);

        return back();
    }

    public function counter(Request $request, TournamentChallenge $challenge, TournamentChallengeService $challenges)
    {
        $this->assertVisible($challenge);
        $data = $this->challengeRules($request, false);
        $admin = Auth::guard('admin')->user();
        $clubId = (int) (AdminLocation::id($admin) ?: 0);
        $challenges->counter($challenge, $clubId, $admin, $data);

        return back();
    }

    public function accept(Request $request, TournamentChallenge $challenge, TournamentChallengeService $challenges)
    {
        $this->assertVisible($challenge);
        $admin = Auth::guard('admin')->user();
        $clubId = (int) (AdminLocation::id($admin) ?: 0);
        $challenges->accept($challenge, $clubId, $admin, $request->input('comment'));

        return back();
    }

    public function decline(Request $request, TournamentChallenge $challenge, TournamentChallengeService $challenges)
    {
        $this->assertVisible($challenge);
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);
        $admin = Auth::guard('admin')->user();
        $clubId = (int) (AdminLocation::id($admin) ?: 0);
        $challenges->decline($challenge, $clubId, $admin, $data['reason'] ?? null);

        return back();
    }

    public function cancelChallenge(Request $request, TournamentChallenge $challenge, TournamentChallengeService $challenges)
    {
        $this->assertVisible($challenge);
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);
        $admin = Auth::guard('admin')->user();
        $clubId = (int) (AdminLocation::id($admin) ?: 0);
        $challenges->cancel($challenge, $clubId, $admin, $data['reason'] ?? null);

        return back();
    }

    public function updateStatus(Request $request, Tournament $tournament, TournamentService $service)
    {
        $this->assertVisibleTournament($tournament);
        $data = $request->validate([
            'status' => 'required|in:planned,active,finished,cancelled',
        ]);

        if ($data['status'] === 'active' && $tournament->status === 'planned') {
            if ($tournament->matches()->doesntExist()) {
                $service->generateBracket($tournament);
            }
            $tournament->update(['status' => 'active']);
        } elseif ($data['status'] === 'finished') {
            $service->finishAndPay($tournament);
        } else {
            $tournament->update(['status' => $data['status']]);
        }

        return back();
    }

    public function destroy(Tournament $tournament)
    {
        $this->assertVisibleTournament($tournament);
        if ($tournament->status === 'active') {
            abort(422, 'Сначала завершите или отмените ивент.');
        }
        $tournament->delete();

        return back();
    }

    public function addPlayer(Request $request, Tournament $tournament, TournamentService $service)
    {
        $this->assertVisibleTournament($tournament);
        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'phone' => 'nullable|string|max:32',
            'computer_id' => 'nullable|integer|exists:computers,id',
        ]);

        $user = null;
        if (! empty($data['user_id'])) {
            $user = User::query()->find((int) $data['user_id']);
        } elseif (! empty($data['phone'])) {
            $digits = preg_replace('/\D+/', '', (string) $data['phone']);
            $user = User::query()->where('phone', 'like', '%'.$digits.'%')->first();
        }
        if (! $user) {
            return back()->withErrors(['phone' => 'Гость не найден.']);
        }

        $clubId = AdminLocation::id(Auth::guard('admin')->user());
        $service->register(
            $tournament,
            $user,
            isset($data['computer_id']) ? (int) $data['computer_id'] : null,
            $clubId
        );

        return back();
    }

    public function removePlayer(Tournament $tournament, TournamentPlayer $player)
    {
        $this->assertVisibleTournament($tournament);
        if ((int) $player->tournament_id !== (int) $tournament->id) {
            abort(404);
        }
        if ($tournament->status !== 'planned') {
            abort(422, 'Убирать игроков можно до старта.');
        }
        $player->delete();

        return back();
    }

    public function generateBracket(Tournament $tournament, TournamentService $service)
    {
        $this->assertVisibleTournament($tournament);
        $service->generateBracket($tournament);

        return back();
    }

    public function reportMatch(Request $request, Tournament $tournament, TournamentMatch $match, TournamentService $service)
    {
        $this->assertVisibleTournament($tournament);
        if ((int) $match->tournament_id !== (int) $tournament->id) {
            abort(404);
        }
        $data = $request->validate([
            'score1' => 'required|integer|min:0|max:99',
            'score2' => 'required|integer|min:0|max:99',
        ]);
        $service->reportScore($match, (int) $data['score1'], (int) $data['score2']);

        return back();
    }

    public function payout(Tournament $tournament, TournamentService $service)
    {
        $this->assertVisibleTournament($tournament);
        $service->finishAndPay($tournament);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeRules(Request $request, bool $needGuest = true): array
    {
        return $request->validate([
            'guest_club_id' => $needGuest ? 'required|integer|exists:clubs,id' : 'nullable|integer|exists:clubs,id',
            'name' => 'required|string|max:120',
            'game_id' => 'required|integer|exists:games,id',
            'format' => 'nullable|in:single_elim,double_elim,round_robin',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'roster_size' => 'nullable|integer|min:1|max:32',
            'entry_fee' => 'nullable|numeric|min:0',
            'prize_pool' => 'nullable|string|max:2000',
            'prize_first' => 'nullable|numeric|min:0',
            'prize_second' => 'nullable|numeric|min:0',
            'prize_third' => 'nullable|numeric|min:0',
            'prize_funding' => 'nullable|in:host,guest,split,each',
            'venue' => 'nullable|in:host,guest,split',
            'lock_games' => 'nullable|boolean',
            'rules' => 'nullable|string|max:4000',
            'comment' => 'nullable|string|max:500',
        ]);
    }

    private function assertVisible(TournamentChallenge $challenge): void
    {
        $clubId = AdminLocation::id(Auth::guard('admin')->user());
        if ($clubId && ! $challenge->involvesClub((int) $clubId)) {
            abort(404);
        }
    }

    private function assertVisibleTournament(Tournament $tournament): void
    {
        $clubId = AdminLocation::id(Auth::guard('admin')->user());
        if (! $clubId) {
            return;
        }
        $mine = (int) $clubId;
        if ((int) $tournament->club_id !== $mine && (int) $tournament->opponent_club_id !== $mine) {
            abort(404);
        }
    }

    private function serialize(Tournament $t, ?int $viewerClubId): array
    {
        $t->loadMissing([
            'club:id,name,city,network_name',
            'opponentClub:id,name,city,network_name',
            'players.user:id,name,phone',
            'players.club:id,name',
            'matches.player1.user:id,name',
            'matches.player2.user:id,name',
            'matches.winner.user:id,name',
            'computers',
        ]);

        return [
            'id' => $t->id,
            'name' => $t->name,
            'status' => $t->status,
            'format' => $t->format,
            'lock_games' => (bool) $t->lock_games,
            'entry_fee' => (float) $t->entry_fee,
            'prize_pool' => $t->prize_pool,
            'prize_first' => ((int) $t->prize_first_minor) / 100,
            'prize_second' => ((int) $t->prize_second_minor) / 100,
            'prize_third' => ((int) $t->prize_third_minor) / 100,
            'prizes_paid_at' => optional($t->prizes_paid_at)?->toIso8601String(),
            'computers_count' => $t->computers_count ?? $t->computers->count(),
            'computer_ids' => $t->computers->pluck('id')->values(),
            'game' => $t->game ? ['id' => $t->game->id, 'title' => $t->game->title] : null,
            'host' => $t->club?->circuitCard(),
            'opponent' => $t->opponentClub?->circuitCard(),
            'challenge_id' => $t->challenge_id,
            'roster_size' => $t->roster_size,
            'venue' => $t->venue,
            'prize_funding' => $t->prize_funding,
            'rules' => $t->rules,
            'players' => $t->players->map(fn (TournamentPlayer $p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'club' => $p->club?->name,
                'name' => $p->user?->name,
                'phone' => $p->user?->phone,
                'seed' => $p->seed,
                'placement' => $p->placement,
                'prize_paid' => ((int) $p->prize_paid_minor) / 100,
            ])->values(),
            'matches' => $t->matches->sortBy(fn ($m) => [$m->round, $m->slot])->values()->map(fn (TournamentMatch $m) => [
                'id' => $m->id,
                'round' => $m->round,
                'slot' => $m->slot,
                'status' => $m->status,
                'score1' => $m->score1,
                'score2' => $m->score2,
                'player1' => $m->player1?->user?->name,
                'player2' => $m->player2?->user?->name,
                'winner' => $m->winner?->user?->name,
            ]),
            'viewer_club_id' => $viewerClubId,
        ];
    }
}
