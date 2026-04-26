<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckServiceQuality extends Command
{
    // Название команды для запуска вручную или через крон
    protected $signature = 'reactor:check-quality';
    protected $description = 'Аудит качества обслуживания: поиск задержек и нарушений';

    public function handle()
    {
        $this->info('🔍 Запуск сканера нарушений...');

        // 1. ПРОВЕРКА ЗАБЫТЫХ ЗАКАЗОВ (более 5 минут в статусе pending)
        $lateOrders = DB::table('orders')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(5))
            ->get();

        foreach ($lateOrders as $order) {
            // Проверяем, не фиксировали ли мы этот косяк ранее
            $exists = DB::table('incidents')
                ->where('type', 'late_order')
                ->where('order_id', $order->id)
                ->exists();

            if (!$exists) {
                DB::table('incidents')->insert([
                    'type' => 'late_order',
                    'order_id' => $order->id,
                    'severity' => 'high',
                    'description' => "КРИТИЧЕСКАЯ ЗАДЕРЖКА: Заказ #{$order->id} ({$order->product_name}) не обработан за 5+ минут.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->warn("Фиксация косяка: Заказ #{$order->id} просрочен.");
            }
        }

        // 2. СЮДА МОЖНО ДОБАВИТЬ ДРУГИЕ ПРОВЕРКИ
        // Например: если в зале есть люди, а админ не заходил в панель 30 минут

        $this->info('✅ Аудит завершен.');
        return Command::SUCCESS;
    }
}
