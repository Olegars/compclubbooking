<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\ClubFeatureService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Booking::observe(\App\Observers\BookingObserver::class);
        \App\Models\Transaction::observe(\App\Observers\TransactionObserver::class);
    }
}
