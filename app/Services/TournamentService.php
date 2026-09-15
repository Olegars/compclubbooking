<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentPlayer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TournamentService
{
    public function register(Tournament $tournament, User $user, ?int $computerId = null): TournamentPlayer
    {
        if ($tournament->status !== 'planned') {
            throw ValidationException::withMessages([
                'tournament' => 'Запись только пока ивент в статусе planned.',
            ]);
        }
        if (TournamentPlayer::query()
            ->where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'Этот гость уже в сетке.',
            ]);
        }

        $seed = (int) TournamentPlayer::query()->where('tournament_id', $tournament->id)->max('seed') + 1;

        return TournamentPlayer::query()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'seed' => $seed,
            'computer_id' => $computerId,
        ]);
    }

    public function generateBracket(Tournament $tournament): void
    {
        $players = TournamentPlayer::query()
            ->where('tournament_id', $tournament->id)
            ->orderBy('seed')
            ->get();
        if ($players->count() < 2) {
            throw ValidationException::withMessages([
                'players' => 'Нужно минимум двое участников.',
            ]);
        }

        TournamentMatch::query()->where('tournament_id', $tournament->id)->delete();

        $list = $players->values()->all();
        $n = count($list);
        $size = 1;
        while ($size < $n) {
            $size *= 2;
        }
        $padded = $list;
        while (count($padded) < $size) {
            $padded[] = null;
        }

        $rounds = (int) log($size, 2);
        for ($round = 1; $round <= $rounds; $round++) {
            $matchCount = (int) ($size / (2 ** $round));
            for ($slot = 0; $slot < $matchCount; $slot++) {
                $p1 = null;
                $p2 = null;
                if ($round === 1) {
                    $p1 = $padded[$slot * 2] ?? null;
                    $p2 = $padded[$slot * 2 + 1] ?? null;
                }
                $status = TournamentMatch::STATUS_PENDING;
                $winnerId = null;
                if ($round === 1 && $p1 && ! $p2) {
                    $status = TournamentMatch::STATUS_DONE;
                    $winnerId = $p1->id;
                } elseif ($round === 1 && $p1 && $p2) {
                    $status = TournamentMatch::STATUS_READY;
                }

                TournamentMatch::query()->create([
                    'tournament_id' => $tournament->id,
                    'round' => $round,
                    'slot' => $slot,
                    'player1_id' => $p1?->id,
                    'player2_id' => $p2?->id,
                    'winner_id' => $winnerId,
                    'status' => $status,
                ]);
            }
        }

        foreach (TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('round', 1)
            ->where('status', TournamentMatch::STATUS_DONE)
            ->get() as $bye) {
            $this->advanceWinner($bye);
        }
    }

    public function reportScore(TournamentMatch $match, int $score1, int $score2): TournamentMatch
    {
        if ($match->status === TournamentMatch::STATUS_DONE) {
            throw ValidationException::withMessages(['match' => 'Матч уже закрыт.']);
        }
        if (! $match->player1_id || ! $match->player2_id) {
            throw ValidationException::withMessages(['match' => 'Нет обоих игроков.']);
        }
        if ($score1 === $score2) {
            throw ValidationException::withMessages(['score' => 'Нужен победитель, ничья не подходит.']);
        }

        $winnerId = $score1 > $score2 ? $match->player1_id : $match->player2_id;
        $match->update([
            'score1' => $score1,
            'score2' => $score2,
            'winner_id' => $winnerId,
            'status' => TournamentMatch::STATUS_DONE,
        ]);
        $match->refresh();
        $this->advanceWinner($match);

        return $match;
    }

    public function finishAndPay(Tournament $tournament): void
    {
        $this->assignPlacements($tournament);
        $this->payPrizes($tournament);
        $tournament->update(['status' => 'finished']);
    }

    public function lockGameIdForComputer(int $computerId): ?int
    {
        $row = DB::table('tournaments')
            ->join('tournament_computer', 'tournament_computer.tournament_id', '=', 'tournaments.id')
            ->where('tournament_computer.computer_id', $computerId)
            ->where('tournaments.status', 'active')
            ->where('tournaments.lock_games', true)
            ->orderByDesc('tournaments.id')
            ->select('tournaments.game_id')
            ->first();

        return $row ? (int) $row->game_id : null;
    }

    private function advanceWinner(TournamentMatch $match): void
    {
        if (! $match->winner_id) {
            return;
        }
        $nextRound = $match->round + 1;
        $nextSlot = intdiv((int) $match->slot, 2);
        $next = TournamentMatch::query()
            ->where('tournament_id', $match->tournament_id)
            ->where('round', $nextRound)
            ->where('slot', $nextSlot)
            ->first();
        if (! $next) {
            return;
        }

        $field = ((int) $match->slot % 2 === 0) ? 'player1_id' : 'player2_id';
        $next->{$field} = $match->winner_id;
        if ($next->player1_id && $next->player2_id && $next->status !== TournamentMatch::STATUS_DONE) {
            $next->status = TournamentMatch::STATUS_READY;
        }
        $next->save();
    }

    private function assignPlacements(Tournament $tournament): void
    {
        $finalRound = (int) TournamentMatch::query()->where('tournament_id', $tournament->id)->max('round');
        $final = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('round', $finalRound)
            ->first();
        if (! $final || ! $final->winner_id) {
            return;
        }

        TournamentPlayer::query()->where('tournament_id', $tournament->id)->update(['placement' => null]);
        TournamentPlayer::query()->whereKey($final->winner_id)->update(['placement' => 1]);
        $secondId = $final->player1_id === $final->winner_id ? $final->player2_id : $final->player1_id;
        if ($secondId) {
            TournamentPlayer::query()->whereKey($secondId)->update(['placement' => 2]);
        }
        if ($finalRound >= 2) {
            $semis = TournamentMatch::query()
                ->where('tournament_id', $tournament->id)
                ->where('round', $finalRound - 1)
                ->get();
            foreach ($semis as $semi) {
                $loser = $semi->winner_id && $semi->player1_id === $semi->winner_id
                    ? $semi->player2_id
                    : ($semi->winner_id ? $semi->player1_id : null);
                if ($loser) {
                    TournamentPlayer::query()->whereKey($loser)->update(['placement' => 3]);
                }
            }
        }
    }

    private function payPrizes(Tournament $tournament): void
    {
        if ($tournament->prizes_paid_at) {
            return;
        }

        $map = [
            1 => (int) $tournament->prize_first_minor,
            2 => (int) $tournament->prize_second_minor,
            3 => (int) $tournament->prize_third_minor,
        ];
        $thirds = TournamentPlayer::query()
            ->where('tournament_id', $tournament->id)
            ->where('placement', 3)
            ->get();
        $thirdEach = $thirds->count() > 1
            ? intdiv($map[3], $thirds->count())
            : $map[3];

        $players = TournamentPlayer::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('placement', [1, 2, 3])
            ->get();

        DB::transaction(function () use ($tournament, $players, $map, $thirdEach) {
            foreach ($players as $player) {
                $minor = $player->placement === 3 ? $thirdEach : ($map[(int) $player->placement] ?? 0);
                if ($minor <= 0 || $player->prize_paid_minor > 0) {
                    continue;
                }
                $user = User::query()->find($player->user_id);
                if (! $user) {
                    continue;
                }
                $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
                $amount = $minor / 100;
                $wallet->creditSpendable($amount);
                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'type' => 'deposit',
                    'source' => 'tournament',
                    'description' => "Приз турнира {$tournament->name} ({$player->placement} место)",
                    'payload' => [
                        'tournament_id' => $tournament->id,
                        'placement' => $player->placement,
                        'minor' => $minor,
                    ],
                ]);
                $player->update(['prize_paid_minor' => $minor]);
            }
            $tournament->update(['prizes_paid_at' => now()]);
        });
    }
}
