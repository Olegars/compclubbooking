<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\GameAccount;
use App\Models\GameAccountReservation;

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

        // 2. Ищем все активные брони на текущий момент (новая и legacy-схема).
        $activeBookings = Booking::where('status', 'active')
            ->where(function ($query) use ($now, $today, $nowH) {
                $query->where(function ($modern) use ($now) {
                    $modern->whereNotNull('starts_at')
                        ->where('starts_at', '<=', $now)
                        ->where('ends_at', '>', $now);
                })->orWhere(function ($legacy) use ($today, $nowH) {
                    $legacy->whereNull('starts_at')
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

        // 3. Закрываем старые брони и принудительно освобождаем игровые аккаунты.
        $nowIso = $now->utc()->toIso8601String();
        $expiredIds = Booking::whereIn('status', ['confirmed', 'active'])
            ->where(function ($query) use ($nowIso, $today, $nowH) {
                $query->where(function ($modern) use ($nowIso) {
                    $modern->whereNotNull('ends_at')
                        ->whereRaw('ends_at <= ?::timestamptz', [$nowIso]);
                })->orWhere(function ($legacy) use ($today, $nowH) {
                    $legacy->whereNull('ends_at')
                        ->where('date', '<=', $today)
                        ->whereRaw('(start_time + duration) <= ?', [$nowH]);
                });
            })
            ->pluck('id');

        if ($expiredIds->isNotEmpty()) {
            Booking::whereIn('id', $expiredIds)
                ->update(['status' => 'completed', 'actual_ended_at' => $now]);

            $reservations = GameAccountReservation::query()
                ->whereIn('booking_id', $expiredIds)
                ->whereIn('status', ['held', 'confirmed', 'active'])
                ->get();

            foreach ($reservations as $reservation) {
                GameAccount::query()
                    ->whereKey($reservation->game_account_id)
                    ->where('current_pc_id', $reservation->booking?->computer_id)
                    ->update(['status' => 'free', 'current_pc_id' => null]);
                $reservation->update(['status' => 'completed', 'released_at' => $now]);
            }
        }

        Booking::whereNull('starts_at')
            ->where('date', '<=', $today)
            ->where('status', 'active')
            ->whereRaw('(start_time + duration) <= ?', [$nowH])
            ->update(['status' => 'completed']);

        BookingGroup::query()
            ->whereIn('status', ['confirmed', 'active'])
            ->whereRaw('ends_at <= ?::timestamptz', [$nowIso])
            ->update(['status' => 'completed']);

        return Command::SUCCESS;
    }
}
