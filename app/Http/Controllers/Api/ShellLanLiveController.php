<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Computer;
use App\Models\LanBounty;
use App\Models\User;
use App\Services\LanLive\GhostCoachService;
use App\Services\LanLive\LanBountyService;
use App\Services\LanLive\LanMatchmakingService;
use App\Services\LanLive\PartyEnergyPoolService;
use App\Services\LanLive\PcThroneService;
use App\Services\LanLive\ShellGsiStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShellLanLiveController extends Controller
{
    public function __construct(
        private readonly LanBountyService $bounties,
        private readonly PartyEnergyPoolService $energy,
        private readonly GhostCoachService $coach,
        private readonly ShellGsiStore $gsi,
        private readonly PcThroneService $thrones,
        private readonly LanMatchmakingService $lfg,
    ) {
    }

    public function snapshot(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);

        return response()->json($this->livePayload($computer, $booking, $user));
    }

    public function createBounty(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $data = $request->validate([
            'target_computer_id' => 'required|integer',
            'kind' => 'nullable|in:frag,duel',
            'game' => 'nullable|in:cs2,dota',
            'weapon' => 'nullable|string|max:32',
            'title' => 'nullable|string|max:180',
            'stake_type' => 'nullable|in:deposit,product',
            'stake_amount' => 'nullable|numeric|min:0|max:5000',
            'product_id' => 'nullable|integer|exists:products,id',
        ]);

        try {
            $bounty = $this->bounties->create($user, $computer, $booking, $data);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            $this->livePayload($computer, $booking, $user->fresh()),
            [
                'status' => 'success',
                'message' => 'Охота объявлена',
                'bounty' => $this->bounties->payload($bounty, $booking),
            ]
        ));
    }

    public function cancelBounty(Request $request, int $id): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $bounty = LanBounty::query()->findOrFail($id);

        try {
            $this->bounties->cancel($user, $bounty);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            $this->livePayload($computer, $booking, $user->fresh()),
            ['status' => 'success', 'message' => 'Охота снята']
        ));
    }

    public function setAutoFuel(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $on = $request->boolean('auto_fuel');

        try {
            $this->energy->setAutoFuel($booking, $user, $on);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            $this->livePayload($computer, $booking, $user),
            ['status' => 'success']
        ));
    }

    public function contribute(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $data = $request->validate([
            'minutes' => 'required|integer|min:5|max:60',
            'source' => 'nullable|in:deposit,time',
        ]);

        try {
            $this->energy->contribute(
                $booking,
                $user,
                (int) $data['minutes'],
                $data['source'] ?? 'deposit'
            );
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            $this->livePayload($computer, $booking, $user->fresh()),
            ['status' => 'success', 'message' => 'Минуты в котле']
        ));
    }

    public function setCoach(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $on = $request->boolean('enabled', true);
        $this->coach->setEnabled($user, $on);

        return response()->json(array_merge(
            $this->livePayload($computer, $booking, $user->fresh()),
            ['status' => 'success']
        ));
    }

    public function gsi(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $data = $request->validate([
            'event' => 'nullable|string|max:24',
            'game' => 'nullable|string|max:16',
            'steam_id' => 'nullable|string|max:32',
            'weapon' => 'nullable|string|max:64',
            'map' => 'nullable|string|max:64',
            'match_id' => 'nullable|string|max:48',
            'round' => 'nullable|integer',
            'team' => 'nullable|string|max:24',
            'money' => 'nullable|integer',
            'in_match' => 'nullable|boolean',
            'hero' => 'nullable|string|max:64',
            'ult_ready' => 'nullable|boolean',
            'ult_name' => 'nullable|string|max:64',
            'phase' => 'nullable|string|max:24',
            'alive' => 'nullable|boolean',
            'bomb' => 'nullable|string|max:24',
            'game_time' => 'nullable|integer',
            'player_name' => 'nullable|string|max:48',
            'clock' => 'nullable|integer',
        ]);

        $snap = array_merge($data, [
            'event' => strtolower((string) ($data['event'] ?? 'heartbeat')),
            'game' => ($data['game'] ?? '') === 'dota' ? 'dota' : 'cs2',
            'in_match' => $request->boolean('in_match'),
            'pc_name' => (string) $computer->name,
            'user_id' => $user->id,
            'player_name' => trim((string) ($data['player_name'] ?? '')),
        ]);

        $this->gsi->put((int) $computer->id, (int) ($computer->club_id ?? 0), $snap);

        $settled = null;
        $crowned = null;
        $event = $snap['event'];
        if (in_array($event, ['kill', 'death', 'round_win', 'round_loss', 'match_win', 'match_loss'], true)) {
            try {
                $ingested = $this->bounties->ingest($computer, $user, $booking, $snap);
                $settled = $ingested['settled'] ?? null;
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                $throne = $this->thrones->observe($computer, $user, $booking, $snap);
                if ($throne && (int) $throne->user_id === (int) $user->id
                    && in_array($event, ['kill', 'match_win', 'round_win'], true)) {
                    $crowned = $this->thrones->payload($computer, $user);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $this->energy->maybeSiphon($booking, true);
        } catch (\Throwable $e) {
            report($e);
        }

        $whisper = null;
        if (in_array($event, ['heartbeat', 'coach', 'kill', 'death', 'bomb', 'round_win', 'round_loss', 'freezetime'], true)
            || ! empty($snap['in_match'])) {
            try {
                $whisper = $this->coach->maybeWhisper($computer, $user, $snap);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $booking = $booking->fresh() ?? $booking;

        try {
            $payload = $this->livePayload($computer, $booking, $user->fresh() ?? $user);
        } catch (\Throwable $e) {
            report($e);
            $payload = ['status' => 'success'];
        }

        return response()->json(array_merge(
            $payload,
            [
                'status' => 'success',
                'settled' => $settled,
                'whisper' => $whisper,
                'throne_crowned' => $crowned && ($crowned['mine'] ?? false) ? $crowned : null,
            ]
        ));
    }

    public function enqueueLfg(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $data = $request->validate([
            'game' => 'required|in:cs2,dota,valorant',
            'rank' => 'required|string|max:32',
        ]);

        try {
            $queue = $this->lfg->enqueue($user, $computer, $booking, $data['game'], $data['rank']);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $extra = [
            'status' => 'success',
            'message' => $queue['message'] ?? $queue['line'] ?? 'Ищем пати в зале',
            'lfg_queue' => $queue,
        ];
        if (! empty($queue['moved'])) {
            $extra['moved'] = true;
            $extra['auto_sat'] = true;
            $extra['pin_code'] = $queue['pin_code'] ?? null;
            $extra['to'] = $queue['to'] ?? null;
        }

        return response()->json(array_merge(
            $this->livePayload($computer, $booking->fresh() ?? $booking, $user->fresh()),
            $extra
        ));
    }

    public function cancelLfg(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);
        $this->lfg->cancel($user, $booking);

        return response()->json(array_merge(
            $this->livePayload($computer, $booking, $user),
            ['status' => 'success', 'message' => 'Поиск пати выключен']
        ));
    }

    public function sitLfg(Request $request): JsonResponse
    {
        [$computer, $booking, $user] = $this->session($request);

        try {
            $moved = $this->lfg->sitTogether($user, $booking);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            ['status' => 'success'],
            $moved,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function livePayload(Computer $computer, Booking $booking, User $user): array
    {
        $this->energy->maybeSiphon($booking, true);
        $booking = $booking->fresh() ?? $booking;
        $timing = app(\App\Services\BookingSessionTimingService::class);

        return [
            'status' => 'success',
            'bounties' => $this->bounties->boardForComputer($computer, $booking),
            'bounty_targets' => $this->bounties->targets($computer, $booking),
            'bounty_products' => $this->bounties->stakeProducts(),
            'party_energy' => $this->energy->payload($booking, $user),
            'ghost_coach' => $this->coach->enabled($user),
            'throne' => $this->thrones->payload($computer, $user),
            'lfg' => $this->lfg->payload($booking, $user),
            'in_match' => $this->gsi->inMatch((int) $computer->id),
            'time_remaining' => $timing->formatRemainingHms($booking),
            'balance' => $user->availableBalance(),
            'deposit_balance' => $user->availableBalance(),
        ];
    }

    /**
     * @return array{0: Computer, 1: Booking, 2: User}
     */
    private function session(Request $request): array
    {
        $terminalId = (int) $request->input('terminal_id', 0);
        $bookingId = (int) $request->input('booking_id', 0);
        if ($terminalId < 1) {
            abort(response()->json(['status' => 'error', 'message' => 'Нет terminal_id'], 422));
        }
        $computer = Computer::query()->find($terminalId);
        if (! $computer) {
            abort(response()->json(['status' => 'error', 'message' => 'Терминал не найден'], 404));
        }

        $booking = null;
        if ($bookingId > 0) {
            $booking = Booking::query()
                ->where('id', $bookingId)
                ->where('status', 'active')
                ->first();
        }
        if (! $booking) {
            $booking = Booking::query()
                ->where('status', 'active')
                ->where(function ($q) use ($terminalId) {
                    $q->where('computer_id', $terminalId);
                    $term = (string) $terminalId;
                    if (DB::connection()->getDriverName() === 'pgsql') {
                        $q->orWhereJsonContains('pc_ids', $term);
                    } else {
                        $q->orWhere('pc_ids', 'like', '%"'.$term.'"%');
                    }
                })
                ->latest('id')
                ->first();
        }
        if (! $booking) {
            abort(response()->json(['status' => 'error', 'message' => 'Нужна активная сессия'], 403));
        }
        $user = User::query()->find($booking->user_id);
        if (! $user) {
            abort(response()->json(['status' => 'error', 'message' => 'Игрок не найден'], 404));
        }

        return [$computer, $booking, $user];
    }
}
