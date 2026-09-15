<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SeedClubMap extends Command
{
    protected $signature = 'reactor:seed-all';

    protected $description = 'Клуб 0451 KOSINO: карта зала, владелец и админ клуба';

    public function handle(): int
    {
        Artisan::call('db:seed', ['--force' => true]);
        $this->info(Artisan::output());
        $this->info('0451 KOSINO. Вход /admin/login: boss@0451.space / 123, admin@0451.space / 123');

        return Command::SUCCESS;
    }
}
