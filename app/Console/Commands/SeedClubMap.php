<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use function Laravel\Prompts\info;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\task;

class SeedClubMap extends Command
{
    protected $signature = 'reactor:seed-all';
    protected $description = 'Полная инициализация системы REACTOR Protocol';

    public function handle()
    {
        info('🚀 ЗАПУСК ПРОТОКОЛА ИНИЦИАЛИЗАЦИИ REACTOR...');

        if (confirm('Выполнить полную очистку и пересоздание таблиц? (migrate:fresh)')) {
            Artisan::call('migrate:fresh');
            warning('База данных полностью очищена.');
        }

        // 1. Клуб (Фундамент)
        task('Инициализация локации (Club)', function () {
            return Artisan::call('db:seed', ['--class' => 'ClubSeeder']);
        });

        // 2. АДМИН (Ключ доступа)
        task('Создание учетной записи администратора', function () {
            return Artisan::call('db:seed', ['--class' => 'AdminSeeder']);
        });

        // 3. КОМПЬЮТЕРЫ (Узлы связи)
        task('Инсталляция игровых узлов (Computers)', function () {
            return Artisan::call('db:seed', ['--class' => 'ComputerSeeder']);
        });

        // 4. ТОВАРЫ (Маркет)
        task('Загрузка номенклатуры товаров (Products)', function () {
            return Artisan::call('db:seed', ['--class' => 'ProductSeeder']);
        });

        info('✅ СИСТЕМА REACTOR ГОТОВА К РАБОТЕ!');
        info('🔑 LOGIN: admin@reactor.club');

        return Command::SUCCESS;
    }
}
