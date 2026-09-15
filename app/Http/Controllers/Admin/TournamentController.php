<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentPlayer;
use App\Models\User;
use App\Services\TournamentService;
use App\Support\AdminLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TournamentController extends Controller
{
    public function index()
    {
        $clubId = AdminLocation::id(Auth::guard('admin')->user())
            ?: (int) (Tournament::query()->value('club_id') ?: 0);

        $list = Tournament::query()
            ->with(['game:id,title', 'players.user:id,name,phone', 'matches', 'computers'])
            ->withCount('computers')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Tournament $t) => $this->serialize($t));

        $computers = Computer::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->where(function ($q) {
                $q->where('kind', 'pc')->orWhereNull('kind');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'kind']);

        return Inertia::render('Admin/Tournaments', [
            'tournaments' => $list,
            'games' => Game::query()->orderBy('title')->get(['id', 'title']),
            'computers' => $computers,
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

    public function updateStatus(Request $request, Tournament $tournament, TournamentService $service)
    {
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
        if ($tournament->status === 'active') {
            abort(422, 'Сначала завершите или отмените ивент.');
        }
        $tournament->delete();

        return back();
    }

    public function addPlayer(Request $request, Tournament $tournament, TournamentService $service)
    {
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

        $service->register($tournament, $user, isset($data['computer_id']) ? (int) $data['computer_id'] : null);

        return back();
    }

    public function removePlayer(Tournament $tournament, TournamentPlayer $player)
    {
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
        $service->generateBracket($tournament);

        return back();
    }

    public function reportMatch(Request $request, Tournament $tournament, TournamentMatch $match, TournamentService $service)
    {
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
        $service->finishAndPay($tournament);

        return back();
    }

    private function serialize(Tournament $t): array
    {
        $t->loadMissing(['players.user:id,name,phone', 'matches.player1.user:id,name', 'matches.player2.user:id,name', 'matches.winner.user:id,name', 'computers']);

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
            'players' => $t->players->map(fn (TournamentPlayer $p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
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
        ];
    }
}
