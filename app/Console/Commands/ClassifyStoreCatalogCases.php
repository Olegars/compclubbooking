<?php

namespace App\Console\Commands;

use App\Services\StoreCaseCatalogEnrichmentService;
use Illuminate\Console\Command;

class ClassifyStoreCatalogCases extends Command
{
    protected $signature = 'store:classify-cases
                            {--force : Переразметить все корпуса заново}';

    protected $description = 'Разметить корпуса каталога через DeepSeek. Без ключа — ничего не делает. Уже размеченные пропускаются.';

    public function handle(StoreCaseCatalogEnrichmentService $enrichment): int
    {
        if (! $enrichment->isConfigured()) {
            $this->error('LLM API-ключ не задан (админка AI или DEEPSEEK_API_KEY) — разметка не выполняется.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $this->info($force ? 'Полная переразметка корпусов…' : 'Разметка новых/пустых корпусов…');

        try {
            $result = $enrichment->classifyAllCases(force: $force);
            $this->info("Корпусов в каталоге: {$result['total']}");
            $this->info("Ждали разметки: {$result['pending_before']}");
            $this->info("Обработано: {$result['classified']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
