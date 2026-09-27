<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\AchievementSourceSetting;
use App\Models\Booking;
use App\Models\GsiAwardDedup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GsiAchievementService
{
    public function __construct(private readonly BattlePassService $pass)
    {
    }

    /**
     * @return array{granted:bool,duplicate:bool,code:string,light_hint:?array}
     */
    public function award(User $user, Booking $booking, string $code, string $matchRoundKey): array
    {
        $code = strtolower(trim($code));
        $matchRoundKey = trim($matchRoundKey);
        $empty = ['granted' => false, 'duplicate' => false, 'code' => $code, 'light_hint' => null];
        if ($code === '' || $matchRoundKey === '') {
            return $empty;
        }
        $clubId = $booking->computer?->club_id ? (int) $booking->computer->club_id : $this->pass->clubIdFor($user);
        if ($clubId && ! app(ClubFeatureService::class)->enabled($clubId, 'achievements')) {
            return $empty;
        }
        $settings = $clubId
            ? AchievementSourceSetting::query()->where('club_id', $clubId)->first()
            : null;
        if ($settings && ! $settings->gsi_awards) {
            return $empty;
        }
        if ($booking->status !== 'active') {
            return $empty;
        }

        $inserted = 0;
        $granted = false;
        DB::transaction(function () use ($user, $code, $matchRoundKey, $clubId, &$inserted, &$granted) {
            $inserted = DB::table('gsi_award_dedup')->insertOrIgnore([
                'user_id' => $user->id,
                'achievement_code' => $code,
                'match_round_key' => $matchRoundKey,
                'created_at' => now(),
            ]);
            if ($inserted !== 1) {
                return;
            }
            $granted = $this->pass->completeByCode($user, $code, $clubId);
        });

        GsiAwardDedup::query()->where('created_at', '<', now()->subHours(48))->delete();

        $achievement = Achievement::query()->where('code', $code)->first();
        $priority = in_array($code, ['ace', 'knife_or_zeus', 'rampage', 'first_blood'], true) ? 'round' : 'event';

        return [
            'granted' => $granted,
            'duplicate' => $inserted !== 1,
            'code' => $code,
            'title' => $achievement?->title,
            'light_hint' => $granted ? [
                'id' => 'gsi.'.$code,
                'priority' => $priority,
                'hint' => $achievement?->title ?? $code,
            ] : null,
        ];
    }
}
