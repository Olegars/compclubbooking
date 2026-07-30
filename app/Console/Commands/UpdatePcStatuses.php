<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Services\BookingSessionTimingService;

class UpdatePcStatuses extends Command
{
    protected $signature = 'reactor:update-statuses';
    protected $description = 'Обновление статусов ПК на основе активных бронирований';

    public function handle()
    {
        $now = now();
        $nowIso = $now->utc()->toIso8601String();
        $nowH = $now->hour + ($now->minute / 60);
        $today = $now->toDateString();

        $timing = app(BookingSessionTimingService::class);

        $noShows = $timing->cancelNoShows();
        if ($noShows > 0) {
            $this->info('No-show отменено: '.$noShows);
        }

        $closed = $timing->completeExpiredSessions();
        if ($closed > 0) {
            $this->info('Закрыто просроченных сессий: '.$closed);
        }

        // 1. Сначала сбрасываем всех в available (кто не занят прямо сейчас)
        DB::table('computers')->update(['status' => 'available']);

        // 2. Ищем все активные брони на текущий момент (новая и legacy-схема).
        // timestamptz: не использовать Eloquent where(ends_at, '>', $now) — ложные промахи.
        $activeBookings = Booking::where('status', 'active')
            ->where(function ($query) use ($nowIso, $today, $nowH) {
                $query->where(function ($modern) use ($nowIso) {
                    $modern->whereNotNull('ends_at')
                        ->whereRaw('ends_at > ?::timestamptz', [$nowIso]);
                })->orWhere(function ($legacy) use ($today, $nowH) {
                    $legacy->whereNull('ends_at')
                        ->where('date', $today)
                        ->where('start_time', '<=', $nowH)
                        ->whereRaw('(start_time + duration) > ?', [$nowH]);
                });
            })
            ->pluck('computer_id');

        if ($activeBookings->isNotEmpty()) {
            DB::table('computers')
                ->whereIn('id', $activeBookings)
                ->update(['status' => 'busy']);

            $this->info('Обновлено узлов: ' . $activeBookings->count());
        }

        return Command::SUCCESS;
    }
}
