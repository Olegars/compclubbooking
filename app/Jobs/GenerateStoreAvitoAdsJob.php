<?php

namespace App\Jobs;

use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoAdGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateStoreAvitoAdsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 2400;

    public function __construct(public ?int $count = null, public bool $force = false) {}

    public function handle(StoreAvitoAdGenerator $generator): void
    {
        $settings = StoreAvitoSetting::current();
        if (! $this->force && ! $settings->enabled) {
            return;
        }

        try {
            $generator->generate($this->count);
        } catch (\Throwable $e) {
            $settings->forceFill(['last_error' => $e->getMessage()])->save();
            Log::error('Avito ads generate: '.$e->getMessage());
            throw $e;
        }
    }
}
