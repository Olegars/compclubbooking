<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клиент API поставщика (QuickFox /api/2).
 */
class QuickFoxApiClient
{
    private const SESSION_CACHE_KEY = 'store.quickfox.session';

    public function isConfigured(): bool
    {
        return filled(config('store.quickfox.domain'))
            && filled(config('store.quickfox.login'))
            && filled(config('store.quickfox.password'));
    }

    public function baseUrl(): string
    {
        $domain = trim((string) config('store.quickfox.domain'));
        $domain = rtrim($domain, '/');
        // Часто вставляют полный endpoint — клиент сам добавляет /api/2
        $domain = preg_replace('#/api/2$#i', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        if ($domain === '') {
            throw new RuntimeException('STORE_QUICKFOX_DOMAIN не задан.');
        }
        if (! str_starts_with($domain, 'http://') && ! str_starts_with($domain, 'https://')) {
            $domain = 'https://'.$domain;
        }

        return $domain;
    }

    public function login(bool $force = false): string
    {
        if (! $force) {
            $cached = Cache::get(self::SESSION_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $payload = [
            'data' => [
                'login' => (string) config('store.quickfox.login'),
                'password' => (string) config('store.quickfox.password'),
            ],
            'request' => [
                'method' => 'login',
                'model' => 'auth',
                'module' => 'quickfox',
            ],
        ];

        $json = $this->postJson($payload, withSession: false);
        if (empty($json['success']) || empty($json['session'])) {
            throw new RuntimeException('QuickFox: ошибка авторизации.');
        }

        $session = (string) $json['session'];
        Cache::put(self::SESSION_CACHE_KEY, $session, now()->addHours(6));

        return $session;
    }

    public function clearSession(): void
    {
        Cache::forget(self::SESSION_CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function api(array $payload, bool $retryOnAuthFail = true): array
    {
        $session = $this->login();
        $payload['session'] = $session;
        $json = $this->postJson($payload, withSession: true);

        if ($retryOnAuthFail && empty($json['success'])) {
            $this->clearSession();
            $payload['session'] = $this->login(force: true);
            $json = $this->postJson($payload, withSession: true);
        }

        if (empty($json['success'])) {
            $message = is_string($json['message'] ?? null) ? $json['message'] : 'запрос отклонён';
            throw new RuntimeException('QuickFox: '.$message);
        }

        return $json;
    }

    /**
     * @param  list<int>  $skus
     * @return list<array<string, mixed>>
     */
    public function getActiveProductsBySkus(array $skus): array
    {
        $skus = array_values(array_unique(array_map('intval', $skus)));
        if ($skus === []) {
            return [];
        }

        $json = $this->api([
            'request' => [
                'method' => 'get_active_products',
                'model' => 'client_api',
                'module' => 'platform',
            ],
            'filter' => [
                [
                    'property' => 'sku',
                    'operator' => 'IN',
                    'value' => $skus,
                ],
            ],
        ]);

        $products = $json['data']['products'] ?? [];

        return is_array($products) ? array_values($products) : [];
    }

    /**
     * Все товары в наличии с ценами (лимит API: 10/час).
     *
     * @return list<array<string, mixed>>
     */
    public function getAllActiveProducts(): array
    {
        $json = $this->api([
            'request' => [
                'method' => 'get_active_products',
                'model' => 'client_api',
                'module' => 'platform',
            ],
        ]);

        $products = $json['data']['products'] ?? [];

        return is_array($products) ? array_values($products) : [];
    }

    /**
     * @return array{id: int}
     */
    public function createOrder(?string $comment = null, int $logisticCenter = 1): array
    {
        $row = ['logistic_center' => $logisticCenter];
        if ($comment !== null && $comment !== '') {
            $row['partner_comment'] = $comment;
        }

        $json = $this->api([
            'request' => [
                'method' => 'create',
                'model' => 'orders',
                'module' => 'platform',
            ],
            'data' => [$row],
        ]);

        $order = $json['data']['orders'][0] ?? null;
        if (! is_array($order) || empty($order['id'])) {
            throw new RuntimeException('QuickFox: не удалось создать заказ.');
        }

        return ['id' => (int) $order['id']];
    }

    /**
     * @param  list<array{sku: int, qty: int, wish_price?: float|null}>  $lines
     * @return list<array<string, mixed>>
     */
    public function updateOrderItems(int $orderId, array $lines): array
    {
        $update = [];
        foreach ($lines as $line) {
            $row = [
                'sku' => (int) $line['sku'],
                'qty' => max(1, (int) $line['qty']),
            ];
            if (array_key_exists('wish_price', $line) && $line['wish_price'] !== null) {
                $row['wish_price'] = (float) $line['wish_price'];
                $row['wish_price_comment'] = '';
            }
            $update[] = $row;
        }

        $json = $this->api([
            'data' => [
                'doc_id' => $orderId,
                'update' => $update,
                'destroy' => [],
            ],
            'request' => [
                'method' => 'client_update',
                'model' => 'order_items',
                'module' => 'platform',
            ],
        ]);

        $items = $json['data']['order_items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    public function confirmOrder(int $orderId): void
    {
        $this->api([
            'data' => [
                [
                    'confirmed' => true,
                    'id' => $orderId,
                ],
            ],
            'request' => [
                'method' => 'update',
                'model' => 'orders',
                'module' => 'platform',
            ],
        ]);
    }

    /**
     * Все картинки товаров (до 100 sku), по убыванию priority. Лимит API: 2/с.
     *
     * @param  list<int>  $skus
     * @return array<int, list<string>> sku => относительные image path
     */
    public function getProductImagePathLists(array $skus): array
    {
        $skus = array_values(array_unique(array_map('intval', $skus)));
        $skus = array_values(array_filter($skus, fn ($s) => $s > 0));
        if ($skus === []) {
            return [];
        }
        if (count($skus) > 100) {
            $skus = array_slice($skus, 0, 100);
        }

        $filter = count($skus) === 1
            ? [['operator' => '=', 'property' => 'sku', 'value' => $skus[0]]]
            : [['operator' => 'IN', 'property' => 'sku', 'value' => $skus]];

        $json = $this->api([
            'filter' => $filter,
            'request' => [
                'method' => 'read_new',
                'model' => 'products_clients_images',
                'module' => 'platform',
            ],
        ]);

        $rows = $json['data']['product_images'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        /** @var array<int, list<array{path: string, priority: int}>> $bySku */
        $bySku = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['sku']) || empty($row['url'])) {
                continue;
            }
            if (! empty($row['deleted'])) {
                continue;
            }
            $sku = (int) $row['sku'];
            $path = ltrim((string) $row['url'], '/');
            $priority = (int) ($row['priority'] ?? 0);
            $bySku[$sku][] = ['path' => $path, 'priority' => $priority];
        }

        $out = [];
        foreach ($bySku as $sku => $items) {
            usort($items, fn ($a, $b) => $b['priority'] <=> $a['priority']);
            $paths = [];
            foreach ($items as $item) {
                if (! in_array($item['path'], $paths, true)) {
                    $paths[] = $item['path'];
                }
            }
            if ($paths !== []) {
                $out[$sku] = $paths;
            }
        }

        return $out;
    }

    /**
     * Лучшая картинка по sku (первый path из списка).
     *
     * @param  list<int>  $skus
     * @return array<int, string> sku => относительный image path
     */
    public function getProductImagePaths(array $skus): array
    {
        $lists = $this->getProductImagePathLists($skus);
        $out = [];
        foreach ($lists as $sku => $paths) {
            if ($paths !== []) {
                $out[$sku] = $paths[0];
            }
        }

        return $out;
    }

    /**
     * Абсолютный URL картинки на стороне поставщика (нужна session-cookie).
     */
    public function productImageAbsoluteUrl(string $relativePath, string $size = 'medium'): string
    {
        $path = ltrim($relativePath, '/');
        $size = in_array($size, ['medium', 'large', 'original'], true) ? $size : 'medium';

        return $this->baseUrl().'/'.$path.'?size='.$size;
    }

    /**
     * Скачать байты картинки с сессией поставщика.
     *
     * @return array{body: string, content_type: string}
     */
    public function downloadProductImage(string $relativePath, string $size = 'medium'): array
    {
        $url = $this->productImageAbsoluteUrl($relativePath, $size);
        $session = $this->login();

        $response = Http::timeout(60)
            ->withHeaders(['Cookie' => 'session='.$session])
            ->get($url);

        if ($response->status() === 404 || $response->status() === 401 || $response->status() === 403) {
            $this->clearSession();
            $session = $this->login(force: true);
            $response = Http::timeout(60)
                ->withHeaders(['Cookie' => 'session='.$session])
                ->get($url);
        }

        if (! $response->successful()) {
            throw new RuntimeException('QuickFox image HTTP '.$response->status());
        }

        $contentType = $response->header('Content-Type') ?: 'image/jpeg';

        return [
            'body' => $response->body(),
            'content_type' => $contentType,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function downloadCatalogTree(): array
    {
        $path = (string) config('store.quickfox.catalog_tree_path');
        $data = $this->downloadJson($path);

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function downloadProducts(): array
    {
        $path = (string) config('store.quickfox.products_path');
        $data = $this->downloadJson($path);

        return is_array($data) ? $data : [];
    }

    /**
     * HTTP timeout for large catalog / full price dump.
     */
    private function postJson(array $payload, bool $withSession): array
    {
        $url = $this->baseUrl().'/api/2';
        $request = Http::timeout(300)
            ->acceptJson()
            ->asJson();

        if ($withSession && ! empty($payload['session'])) {
            $request = $request->withHeaders([
                'Cookie' => 'session='.$payload['session'],
            ]);
        }

        $response = $request->post($url, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('QuickFox HTTP '.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function downloadJson(string $path): mixed
    {
        $session = $this->login();
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $response = Http::timeout(180)
            ->withHeaders(['Cookie' => 'session='.$session])
            ->get($url);

        if ($response->status() === 404) {
            $this->clearSession();
            $session = $this->login(force: true);
            $response = Http::timeout(180)
                ->withHeaders(['Cookie' => 'session='.$session])
                ->get($url);
        }

        if (! $response->successful()) {
            throw new RuntimeException('QuickFox download HTTP '.$response->status().' '.$path);
        }

        return $response->json();
    }
}
