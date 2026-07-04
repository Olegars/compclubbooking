<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;

class UpdatePcStatuses extends Command
{
    protected $signature = 'reactor:update-statuses';
    protected $description = 'Обновление статусов ПК на основе активных бронирований';

    public function handle()
    {
        $now = now();
        $nowH = $now->hour + ($now->minute / 60);
        $today = $now->toDateString();

        // 1. Сначала сбрасываем всех в available (кто не занят прямо сейчас)
        DB::table('computers')->update(['status' => 'available']);

        // 2. Ищем все активные брони на текущий момент
        $activeBookings = Booking::where('date', $today)
            ->where('status', 'active')
            ->where('start_time', '<=', $nowH)
            ->whereRaw('(start_time + duration) > ?', [$nowH])
            ->pluck('computer_id');

        if ($activeBookings->isNotEmpty()) {
            DB::table('computers')
                ->whereIn('id', $activeBookings)
                ->update(['status' => 'busy']);

            $this->info('Обновлено узлов: ' . $activeBookings->count());
        }

        // 3. Закрываем старые брони (которые уже закончились)
        Booking::where('date', '<=', $today)
            ->where('status', 'active')
            ->whereRaw('(start_time + duration) <= ?', [$nowH])
            ->update(['status' => 'completed']);

        return Command::SUCCESS;
    }
}
