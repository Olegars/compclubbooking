<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reactor:check-quality')->everyMinute();
Schedule::command('reactor:update-statuses')->everyMinute()->withoutOverlapping();
Schedule::command('reactor:check-reviews')->dailyAt('10:00')->withoutOverlapping();
Schedule::command('reactor:sync-payments')->everyFiveMinutes()->withoutOverlapping();
