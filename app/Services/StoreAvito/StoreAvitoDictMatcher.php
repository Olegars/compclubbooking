<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoDictValue;
use App\Support\AvitoPcXmlDict;

/**
 * Подбор значения строго из справочника Avito (как в характеристиках объявления).
 */
class StoreAvitoDictMatcher
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function hasCatalog(string $tag): bool
    {
        return StoreAvitoDictValue::query()->where('tag', $tag)->exists();
    }

    public function match(string $tag, string $hay, ?string $parent = null): ?string
    {
        $hay = trim($hay);
        if ($hay === '') {
            return null;
        }

        $values = $this->values($tag, $parent);
        if ($values === [] && $parent) {
            $values = $this->values($tag, null);
        }

        $strict = in_array($tag, [
            'CodeProcessor', 'ModelVideocard', 'CodeVideocard', 'ModelMotherboard',
        ], true);

        return $this->pick($values, $hay, $strict);
    }

    /**
     * @return list<string>
     */
    public function values(string $tag, ?string $parent = null): array
    {
        $key = $tag.'|'.($parent ?? '*');
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $q = StoreAvitoDictValue::query()->where('tag', $tag);
        if ($parent !== null && $parent !== '') {
            $q->where('parent_value', $parent);
        }
        $fromDb = $q->orderBy('sort_order')->pluck('value')->unique()->values()->all();
        $list = $fromDb !== [] ? $fromDb : $this->builtin($tag);
        $this->cache[$key] = $list;

        return $list;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $counts = StoreAvitoDictValue::query()
            ->selectRaw('tag, count(*) as c')
            ->groupBy('tag')
            ->pluck('c', 'tag')
            ->all();

        $tags = [
            'BrandProcessor', 'ModelProcessor', 'CodeProcessor',
            'BrandVideocard', 'ModelVideocard', 'CodeVideocard',
            'BrandMotherboard', 'ModelMotherboard', 'RamSize', 'Type',
        ];
        $out = [];
        foreach ($tags as $tag) {
            $out[$tag] = (int) ($counts[$tag] ?? 0);
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function pick(array $allowed, string $hay, bool $strictSku = false): ?string
    {
        $hay = trim($hay);
        if ($hay === '' || $allowed === []) {
            return null;
        }

        foreach ($allowed as $item) {
            if (mb_strtolower((string) $item) === mb_strtolower($hay)) {
                return (string) $item;
            }
        }

        $hayTokens = $this->tokens($hay);
        $skuTokens = array_values(array_filter(
            $hayTokens,
            fn (string $t) => (bool) preg_match('/[0-9]{3,}/', $t)
        ));
        $memory = ['4', '8', '12', '16', '24', '32'];

        $best = $this->score($allowed, $hay, $hayTokens, $strictSku ? $skuTokens : []);
        if ($best !== null) {
            return $best;
        }

        if ($strictSku && $skuTokens !== []) {
            $relaxed = array_values(array_filter($skuTokens, fn (string $t) => ! in_array($t, $memory, true)));
            if ($relaxed !== $skuTokens) {
                return $this->score($allowed, $hay, $hayTokens, $relaxed);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $hayTokens
     * @param  list<string>  $required
     */
    private function score(array $allowed, string $hay, array $hayTokens, array $required): ?string
    {
        $hayCompact = AvitoPcXmlDict::compact($hay);
        $best = null;
        $bestScore = 0;

        foreach ($allowed as $item) {
            $item = (string) $item;
            $itemTokens = $this->tokens($item);
            $itemCompact = AvitoPcXmlDict::compact($item);
            $skip = false;
            foreach ($required as $num) {
                if (! in_array($num, $itemTokens, true) && ! str_contains($itemCompact, $num)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $overlap = array_intersect($hayTokens, $itemTokens);
            $score = 0;
            foreach ($overlap as $token) {
                $score += mb_strlen((string) $token) * 3;
            }
            if ($itemTokens !== []) {
                $score += (int) round(50 * count($overlap) / count($itemTokens));
            }
            if ($hayCompact !== '' && $itemCompact !== '') {
                if ($hayCompact === $itemCompact) {
                    $score += 200;
                } elseif (str_contains($itemCompact, $hayCompact) || str_contains($hayCompact, $itemCompact)) {
                    $score += min(mb_strlen($itemCompact), mb_strlen($hayCompact));
                }
            }
            $score += (int) min(25, mb_strlen($item) / 8);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        return $bestScore >= 9 ? $best : null;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\b(geforce|radeon|видеокарта|процессор|материнская|плата|память)\b/u', ' ', $value) ?? $value;
        preg_match_all('/[a-zа-яё0-9]+/u', $value, $m);
        $stop = ['gb', 'гб', 'oem', 'box', 'rtl', 'ret', 'the', 'for', 'and', 'rev'];

        return array_values(array_filter(
            $m[0] ?? [],
            fn (string $t) => mb_strlen($t) >= 2 && ! in_array($t, $stop, true)
        ));
    }

    /**
     * @return list<string>
     */
    private function builtin(string $tag): array
    {
        return match ($tag) {
            'BrandProcessor' => AvitoPcXmlDict::processorBrands(),
            'ModelProcessor' => AvitoPcXmlDict::processorModels(),
            'CodeProcessor' => AvitoPcXmlDict::processorCodes(),
            'BrandVideocard' => AvitoPcXmlDict::videocardBrands(),
            'ModelVideocard' => [],
            'BrandMotherboard' => AvitoPcXmlDict::motherboardBrands(),
            'RamSize' => AvitoPcXmlDict::ramSizes(),
            'Type' => AvitoPcXmlDict::pcTypes(),
            default => [],
        };
    }
}
