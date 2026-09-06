<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoSetting;

class StoreAvitoCopywriter
{
    /**
     * @param  list<array{config_id:string, components:list<array<string,mixed>>, price:int, xml:array<string,string>}>  $jobs
     * @return array<string, array{title: string, description: string}>
     */
    public function writeMany(array $jobs): array
    {
        $out = [];
        foreach ($jobs as $job) {
            $id = (string) $job['config_id'];
            $out[$id] = $this->fallback(
                $id,
                $job['components'],
                (int) $job['price'],
                $job['xml'],
                StoreAvitoSetting::configPhrase($id),
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{title: string, description: string}
     */
    public function write(string $configId, array $components, int $price, array $xml): array
    {
        $many = $this->writeMany([[
            'config_id' => $configId,
            'components' => $components,
            'price' => $price,
            'xml' => $xml,
        ]]);

        return $many[$configId];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, string>  $xml
     * @return array{title: string, description: string}
     */
    public function fallback(string $configId, array $components, int $price, array $xml, string $phrase): array
    {
        $cpu = trim((string) ($xml['CodeProcessor'] ?? ''));
        $gpu = trim((string) ($xml['CodeVideocard'] ?? ''));
        $ram = trim((string) ($xml['RamSize'] ?? ''));
        $title = $this->clampTitle(trim("ПК {$cpu} {$gpu} {$ram} {$configId}"), $configId);

        $lines = [
            'Игровой системный блок. Цена '.$price.' ₽.',
            '',
            'Комплектация:',
        ];
        foreach ($this->bomLines($components) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = $phrase;

        return [
            'title' => $title,
            'description' => implode("\n", $lines),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return list<string>
     */
    private function bomLines(array $components): array
    {
        $rank = [
            'cpu' => 1,
            'motherboard' => 2,
            'ram' => 3,
            'gpu' => 4,
            'ssd' => 5,
            'storage_ssd' => 5,
            'psu' => 6,
        ];
        $rows = [];
        foreach ($components as $row) {
            $type = (string) ($row['type'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || ! isset($rank[$type])) {
                continue;
            }
            $rows[] = ['rank' => $rank[$type], 'name' => $name];
        }
        usort($rows, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return array_map(fn (array $r) => '• '.$r['name'], $rows);
    }

    public function clampTitle(string $title, string $configId): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = str_replace(['«', '»', '"'], '', $title);
        if ($title === '') {
            return $configId;
        }
        if (! str_contains($title, $configId)) {
            $title = trim(mb_substr($title, 0, 50 - mb_strlen($configId) - 1)).' '.$configId;
        }
        if (mb_strlen($title) <= 50) {
            return $title;
        }
        $keep = 50 - mb_strlen($configId) - 1;
        $head = trim(mb_substr($title, 0, $keep));
        $head = preg_replace('/[\s\-]+$/u', '', $head) ?? $head;

        return $head.' '.$configId;
    }
}
