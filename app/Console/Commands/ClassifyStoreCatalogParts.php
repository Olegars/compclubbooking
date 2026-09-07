<?php

namespace App\Console\Commands;

use App\Services\StoreAvito\StoreAvitoCatalogAttrService;
use Illuminate\Console\Command;

class ClassifyStoreCatalogParts extends Command
{
    protected $signature = 'store:classify-catalog-parts
                            {--force : Переразметить комплектующие сборок заново}
                            {--type= : Только cpu, gpu, motherboard, ram, storage_ssd или psu}
                            {--limit=8000 : Максимум SKU на тип (только с ценой > 0)}';

    protected $description = 'Разметить комплектующие: канон из заголовка сразу, DeepSeek только если канона нет.';

    public function handle(StoreAvitoCatalogAttrService $attrs): int
    {
        if (! $attrs->isConfigured()) {
            $this->error('LLM API-ключ не задан (админка AI или DEEPSEEK_API_KEY) — разметка не выполняется.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $only = $this->option('type');
        $only = is_string($only) && $only !== '' ? $only : null;
        $limit = max(1, (int) $this->option('limit'));

        $this->info($force
            ? 'Переразметка комплектующих сборок: CPU, GPU, плата, ОЗУ, SSD, БП (в наличии). Корпуса и хлам не трогаем.'
            : 'Доразметка комплектующих сборок: CPU, GPU, плата, ОЗУ, SSD, БП. Уже с каноном пропускаются.');
        $this->comment('DeepSeek только если в заголовке нет канона шаблона. Ctrl+C — продолжить без --force.');

        try {
            $result = $attrs->classifyAll(
                force: $force,
                onlyType: $only,
                limit: $limit,
                onType: function (string $type, int $total, int $pending, int $llm) {
                    $this->line(sprintf(
                        '  %s: в наличии %d, к разметке %d, DeepSeek %d',
                        $type,
                        $total,
                        $pending,
                        $llm,
                    ));
                },
            );
            $this->info("В каталоге (с ценой): {$result['total']}");
            $this->info("Ждали разметки: {$result['pending_before']}");
            $this->info("Обработано: {$result['classified']}");
            $this->info("Ушло в DeepSeek: {$result['llm']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
