<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoDictValue;
use App\Models\StoreAvitoSetting;
use App\Support\AvitoPcXmlDict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Качает официальные справочники автозагрузки Avito (user-docs) и кладёт значения тегов в БД.
 */
class StoreAvitoDictSyncService
{
    public const TAGS = [
        'Type', 'Condition', 'GoodsSubType', 'AdType', 'Brand',
        'BrandProcessor', 'ModelProcessor', 'CodeProcessor',
        'BrandMotherboard', 'ModelMotherboard',
        'BrandVideocard', 'ModelVideocard', 'CodeVideocard',
        'RamSize',
    ];

    public function __construct(
        private readonly StoreAvitoMessengerService $messenger,
    ) {}

    /**
     * @return array{ok:bool, already:bool, message:string}
     */
    public function launch(?StoreAvitoSetting $settings = null): array
    {
        $settings ??= StoreAvitoSetting::current();
        if (! $settings->hasAvitoApi()) {
            return ['ok' => false, 'already' => false, 'message' => 'Сначала сохраните client_id и client_secret Avito.'];
        }

        $result = is_array($settings->last_dict_sync_result) ? $settings->last_dict_sync_result : [];
        if (($result['status'] ?? '') === 'running') {
            $started = $result['started_at'] ?? null;
            if (is_string($started) && $started !== '') {
                try {
                    if (\Illuminate\Support\Carbon::parse($started)->gt(now()->subMinutes(40))) {
                        return ['ok' => true, 'already' => true, 'message' => 'Синк справочников уже идёт.'];
                    }
                } catch (\Throwable) {
                    return ['ok' => true, 'already' => true, 'message' => 'Синк справочников уже идёт.'];
                }
            }
        }

        $settings->forceFill([
            'last_error' => null,
            'last_dict_sync_result' => [
                'status' => 'running',
                'queued' => true,
                'started_at' => now()->toIso8601String(),
            ],
        ])->save();

        if (app()->environment('testing')) {
            return ['ok' => true, 'already' => false, 'message' => 'Синк справочников запущен.'];
        }

        $php = (new PhpExecutableFinder)->find() ?: 'php';
        $artisan = base_path('artisan');
        $log = storage_path('logs/avito-dicts.log');
        $cmd = sprintf('%s %s store:sync-avito-dicts', escapeshellarg($php), escapeshellarg($artisan));

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen('start /B '.$cmd.' >> '.escapeshellarg($log).' 2>&1', 'r'));
            } else {
                exec('nohup '.$cmd.' >> '.escapeshellarg($log).' 2>&1 &');
            }
        } catch (\Throwable $e) {
            $settings->forceFill([
                'last_error' => $e->getMessage(),
                'last_dict_sync_result' => ['status' => 'error', 'error' => $e->getMessage()],
            ])->save();

            return ['ok' => false, 'already' => false, 'message' => 'Не удалось запустить синк: '.$e->getMessage()];
        }

        return ['ok' => true, 'already' => false, 'message' => 'Качаю справочники Avito. Это может занять несколько минут.'];
    }

    /**
     * @return array{ok:bool, slug:?string, counts:array<string,int>, error:?string}
     */
    public function sync(?StoreAvitoSetting $settings = null): array
    {
        $settings ??= StoreAvitoSetting::current();
        $settings->forceFill([
            'last_dict_sync_result' => [
                'status' => 'running',
                'started_at' => now()->toIso8601String(),
            ],
        ])->save();

        try {
            $token = $this->messenger->accessToken($settings);
            $tree = $this->getJson($token, 'https://api.avito.ru/autoload/v1/user-docs/tree');
            $slug = $this->findPcSlug($tree);
            if ($slug === null) {
                throw new \RuntimeException('В дереве Avito не найдена категория «Системные блоки».');
            }

            $fields = $this->getJson($token, 'https://api.avito.ru/autoload/v1/user-docs/node/'.$slug.'/fields');
            $ingested = [];
            foreach ($this->walkFields($fields['fields'] ?? []) as $field) {
                $tag = (string) ($field['tag'] ?? '');
                if (! in_array($tag, self::TAGS, true)) {
                    continue;
                }
                $ingested[$tag] = ($ingested[$tag] ?? 0) + $this->ingestField($token, $tag, $field);
            }

            $this->seedBuiltinsIfEmpty($ingested);

            $counts = [];
            foreach (self::TAGS as $tag) {
                $counts[$tag] = StoreAvitoDictValue::query()->where('tag', $tag)->count();
            }

            $result = [
                'status' => 'ok',
                'slug' => $slug,
                'counts' => $counts,
                'ingested' => $ingested,
                'finished_at' => now()->toIso8601String(),
            ];
            $settings->forceFill([
                'last_dict_sync_at' => now(),
                'last_dict_sync_result' => $result,
                'last_error' => null,
            ])->save();

            return ['ok' => true, 'slug' => $slug, 'counts' => $counts, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Avito dict sync: '.$e->getMessage());
            $this->seedBuiltinsIfEmpty([]);
            $settings->forceFill([
                'last_dict_sync_result' => [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'finished_at' => now()->toIso8601String(),
                ],
                'last_error' => $e->getMessage(),
            ])->save();

            return ['ok' => false, 'slug' => null, 'counts' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function ingestField(string $token, string $tag, array $field): int
    {
        $rows = [];
        foreach ($field['content'] ?? [] as $content) {
            if (! is_array($content)) {
                continue;
            }
            foreach ($this->valuesFromContent($token, $content) as $row) {
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $parent = trim((string) ($row['parent'] ?? ''));
                $key = mb_strtolower($parent.'|'.$value);
                $rows[$key] = [
                    'tag' => $tag,
                    'value' => mb_substr($value, 0, 255),
                    'parent_value' => mb_substr($parent, 0, 255),
                ];
            }
        }

        if ($rows === []) {
            return 0;
        }

        $now = now();
        $insert = [];
        $i = 0;
        foreach ($rows as $row) {
            $insert[] = [
                'tag' => $row['tag'],
                'value' => $row['value'],
                'parent_value' => $row['parent_value'],
                'sort_order' => $i++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($tag, $insert) {
            StoreAvitoDictValue::query()->where('tag', $tag)->delete();
            foreach (array_chunk($insert, 400) as $chunk) {
                StoreAvitoDictValue::query()->insert($chunk);
            }
        });

        return count($insert);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{value:string, parent:string}>
     */
    private function valuesFromContent(string $token, array $content): array
    {
        $inline = $content['values'] ?? null;
        if (is_array($inline) && $inline !== []) {
            return $this->flatten($inline, '');
        }

        foreach (['values_link_json', 'values_link_xml'] as $key) {
            $url = $content[$key] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            $url = $this->absoluteUrl($url);
            try {
                if ($key === 'values_link_json') {
                    $json = $this->getJson($token, $url);

                    return $this->flatten($json, '');
                }
                $xml = $this->getRaw($token, $url);

                return $this->flattenXml($xml);
            } catch (\Throwable $e) {
                Log::warning('Avito dict '.$key.': '.$e->getMessage());
            }
        }

        return [];
    }

    /**
     * @return list<array{value:string, parent:string}>
     */
    private function flatten(mixed $node, string $parent): array
    {
        $out = [];
        if (! is_array($node)) {
            if (is_string($node) && trim($node) !== '') {
                $out[] = ['value' => trim($node), 'parent' => $parent];
            }

            return $out;
        }

        if ($this->isAssoc($node)) {
            $value = $node['value'] ?? $node['name'] ?? $node['label'] ?? $node['title'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $value = trim($value);
                $out[] = ['value' => $value, 'parent' => $parent];
                $parentForChildren = $value;
            } else {
                $parentForChildren = $parent;
            }
            foreach (['children', 'values', 'items', 'nested', 'models', 'modifications', 'codes'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $out = array_merge($out, $this->flatten($node[$key], $parentForChildren));
                }
            }
            foreach ($node as $key => $child) {
                if (in_array($key, ['value', 'name', 'label', 'title', 'children', 'values', 'items', 'nested', 'description'], true)) {
                    continue;
                }
                if (is_array($child)) {
                    $out = array_merge($out, $this->flatten($child, $parentForChildren));
                }
            }

            return $out;
        }

        foreach ($node as $child) {
            $out = array_merge($out, $this->flatten($child, $parent));
        }

        return $out;
    }

    /**
     * @return list<array{value:string, parent:string}>
     */
    private function flattenXml(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($xml);
            if ($sx === false) {
                return [];
            }

            return $this->flattenXmlNode($sx, '');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * @return list<array{value:string, parent:string}>
     */
    private function flattenXmlNode(\SimpleXMLElement $node, string $parent): array
    {
        $out = [];
        $attrs = $node->attributes();
        $value = null;
        foreach (['value', 'name', 'label', 'title'] as $attr) {
            if (isset($attrs[$attr]) && trim((string) $attrs[$attr]) !== '') {
                $value = trim((string) $attrs[$attr]);
                break;
            }
        }
        $text = trim((string) $node);
        if ($value === null && $text !== '' && $node->count() === 0) {
            $value = $text;
        }
        $nextParent = $parent;
        if ($value !== null && $value !== '') {
            $out[] = ['value' => $value, 'parent' => $parent];
            $nextParent = $value;
        }
        foreach ($node->children() as $child) {
            $out = array_merge($out, $this->flattenXmlNode($child, $nextParent));
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $fields
     * @return \Generator<int, array<string, mixed>>
     */
    private function walkFields(array $fields): \Generator
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            yield $field;
            $children = $field['children'] ?? [];
            if (is_array($children) && $children !== []) {
                yield from $this->walkFields($children);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function findPcSlug(array $tree): ?string
    {
        $exact = null;
        $fallback = null;
        $this->walkTree($tree, function (array $node) use (&$exact, &$fallback) {
            $name = trim((string) ($node['name'] ?? ''));
            $slug = trim((string) ($node['slug'] ?? ''));
            if ($slug === '') {
                return;
            }
            if ($name === 'Системные блоки' || str_contains(mb_strtolower($slug), 'sistemnye_blok')) {
                $exact = $slug;
            }
            if ($fallback === null && ($name === 'Настольные компьютеры' || str_contains(mb_strtolower($slug), 'nastolnye_komp'))) {
                $fallback = $slug;
            }
        });

        return $exact ?: $fallback;
    }

    /**
     * @param  callable(array<string, mixed>): void  $fn
     */
    private function walkTree(mixed $node, callable $fn): void
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['name']) || isset($node['slug'])) {
            $fn($node);
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walkTree($child, $fn);
            }
        }
    }

    /**
     * @param  array<string, int>  $ingested
     */
    private function seedBuiltinsIfEmpty(array $ingested): void
    {
        $map = [
            'BrandProcessor' => AvitoPcXmlDict::processorBrands(),
            'ModelProcessor' => AvitoPcXmlDict::processorModels(),
            'CodeProcessor' => AvitoPcXmlDict::processorCodes(),
            'BrandVideocard' => AvitoPcXmlDict::videocardBrands(),
            'BrandMotherboard' => AvitoPcXmlDict::motherboardBrands(),
            'RamSize' => AvitoPcXmlDict::ramSizes(),
            'Type' => AvitoPcXmlDict::pcTypes(),
        ];
        foreach ($map as $tag => $values) {
            $has = StoreAvitoDictValue::query()->where('tag', $tag)->exists();
            if ($has || ($ingested[$tag] ?? 0) > 0) {
                continue;
            }
            $now = now();
            $rows = [];
            foreach (array_values($values) as $i => $value) {
                $rows[] = [
                    'tag' => $tag,
                    'value' => (string) $value,
                    'parent_value' => '',
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                StoreAvitoDictValue::query()->insert($chunk);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $token, string $url): array
    {
        $response = Http::timeout(120)
            ->withToken($token)
            ->acceptJson()
            ->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().' '.$url.' '.$response->body());
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function getRaw(string $token, string $url): string
    {
        $response = Http::timeout(120)
            ->withToken($token)
            ->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().' '.$url);
        }

        return (string) $response->body();
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return 'https://api.avito.ru'.(str_starts_with($url, '/') ? $url : '/'.$url);
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function isAssoc(array $arr): bool
    {
        return $arr !== [] && array_keys($arr) !== range(0, count($arr) - 1);
    }
}
