<?php

namespace App\Jobs;

use App\Models\UserIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class SyncIdentityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $userId,
        public string $provider,
    ) {
    }

    public static function dispatchClub(int $clubId): void
    {
        $ids = \App\Models\Booking::query()
            ->whereIn('computer_id', \App\Models\Computer::query()->where('club_id', $clubId)->select('id'))
            ->pluck('user_id');
        UserIdentity::query()
            ->whereNull('unlinked_at')
            ->whereIn('user_id', $ids)
            ->orderBy('id')
            ->each(function (UserIdentity $row) {
                self::dispatch($row->user_id, $row->provider)->onQueue('identities-low');
            });
    }

    public function handle(): void
    {
        $gate = 'identity-sync:'.$this->userId.':'.$this->provider;
        if (Cache::has($gate)) {
            return;
        }
        $limit = match ($this->provider) {
            'opendota' => 50,
            'riot' => 20,
            'pubg' => 10,
            'tracker' => 20,
            'steam' => 40,
            default => 30,
        };
        $bucket = 'loyalty-'.$this->provider;
        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            $this->release(min(300, max(60, RateLimiter::availableIn($bucket))));

            return;
        }
        if (! $this->configured()) {
            return;
        }
        RateLimiter::hit($bucket, 60);
        Cache::put($gate, 1, now()->addMinutes(30));
        UserIdentity::query()
            ->where('user_id', $this->userId)
            ->where('provider', $this->provider)
            ->whereNull('unlinked_at')
            ->update(['synced_at' => now()]);
    }

    private function configured(): bool
    {
        return match ($this->provider) {
            'steam' => filled(config('services.steam.web_api_key')),
            'opendota' => (bool) config('services.loyalty.opendota'),
            'riot' => filled(config('services.loyalty.riot_api_key')),
            'pubg' => filled(config('services.loyalty.pubg_api_key')),
            'tracker' => filled(config('services.loyalty.tracker_api_key')),
            default => false,
        };
    }
}
