<?php

namespace App\Console\Commands;

use App\Services\StoreAvito\StoreAvitoCatalogAttrService;
use Illuminate\Console\Command;

class ClassifyStoreCatalogParts extends Command
{
    protected $signature = 'store:classify-catalog-parts
                            {--force : Переразметить CPU/GPU/ОЗУ/SSD/платы/БП заново}';

    protected $description = 'Разметить основные комплектующие каталога через DeepSeek (как корпуса). Уже заполненные пропускаются.';

    public function handle(StoreAvitoCatalogAttrService $attrs): int
    {
        if (! $attrs->isConfigured()) {
            $this->error('LLM API-ключ не задан (админка AI или DEEPSEEK_API_KEY) — разметка не выполняется.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $this->info($force ? 'Полная переразметка комплектующих…' : 'Разметка новых/пустых комплектующих…');

        try {
            $result = $attrs->classifyAll(force: $force);
            $this->info("В каталоге: {$result['total']}");
            $this->info("Ждали разметки: {$result['pending_before']}");
            $this->info("Обработано: {$result['classified']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
