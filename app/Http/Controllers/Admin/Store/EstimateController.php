<?php

namespace App\Http\Controllers\Admin\Store;

use App\Models\StoreClient;
use App\Models\StoreComponent;
use App\Models\StoreEstimate;
use App\Models\StoreEstimateItem;
use App\Models\StorePurchase;
use App\Models\StoreSupplierCatalogCategory;
use App\Models\StoreSupplierCatalogProduct;
use App\Services\QuickFoxApiClient;
use App\Services\StoreEstimateProcurementService;
use App\Services\StoreSupplierCatalogImageService;
use App\Services\StoreSupplierCatalogSearchService;
use App\Services\StoreSupplierCatalogSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EstimateController extends StoreController
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString();
        $clubId = $this->locationId();

        $query = StoreEstimate::query()
            ->where('club_id', $clubId)
            ->with([
                'client:id,name,phone',
                'items.component:id,name,type,status,purchase_price,serials,warranty_number',
                'purchases.items',
                'order:id,status,total',
            ])
            ->latest();

        if ($status && in_array($status, StoreEstimate::STATUSES, true)) {
            $query->where('status', $status);
        }

        $components = StoreComponent::query()
            ->where('club_id', $clubId)
            ->where('status', 'in_stock')
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'purchase_price', 'warranty_number', 'serials', 'status']);

        $estimates = $query->limit(80)->get();
        $procurement = app(StoreEstimateProcurementService::class);
        foreach ($estimates as $estimate) {
            $healed = false;
            foreach ($estimate->purchases as $purchase) {
                if ($purchase->status === 'received' && $procurement->healReceivedPurchaseLinks($purchase)) {
                    $healed = true;
                }
            }
            if ($healed) {
                $procurement->refreshEstimateReadiness($estimate);
                $estimate->unsetRelation('items');
                $estimate->load([
                    'items.component:id,name,type,status,purchase_price,serials,warranty_number',
                ]);
            }
        }
        $imageUrls = app(StoreSupplierCatalogImageService::class)->urlsForSkus(
            $estimates->flatMap(fn (StoreEstimate $e) => $e->items->pluck('supplier_sku'))->all()
        );
        foreach ($estimates as $estimate) {
            foreach ($estimate->items as $item) {
                $sku = (int) ($item->supplier_sku ?? 0);
                $item->setAttribute('supplier_image_url', $sku > 0 ? ($imageUrls[$sku] ?? null) : null);
            }
        }

        return Inertia::render('Admin/Store/Estimates', [
            'estimates' => $estimates,
            'clients' => StoreClient::query()->where('club_id', $clubId)->orderBy('name')->get(['id', 'name', 'phone']),
            'components' => $components,
            'componentTypes' => StoreComponent::TYPES,
            'statuses' => StoreEstimate::STATUSES,
            'statusLabels' => StoreEstimate::STATUS_LABELS,
            'itemStatusLabels' => StoreEstimateItem::STATUS_LABELS,
            'filters' => ['status' => $status ?: null],
            'canManage' => $this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner',
            'quickfoxConfigured' => app(QuickFoxApiClient::class)->isConfigured(),
            'catalogStats' => [
                'products' => StoreSupplierCatalogProduct::query()->count(),
                'categories' => StoreSupplierCatalogCategory::query()->count(),
                'priced' => StoreSupplierCatalogProduct::query()->whereNotNull('price')->count(),
                'synced_at' => self::formatCatalogTimestamp(StoreSupplierCatalogProduct::query()->max('synced_at')),
                'price_synced_at' => self::formatCatalogTimestamp(StoreSupplierCatalogProduct::query()->max('price_synced_at')),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $data = $this->validateEstimate($request);
        $clubId = $this->locationId();

        if (! empty($data['store_client_id'])) {
            StoreClient::query()->where('club_id', $clubId)->whereKey($data['store_client_id'])->firstOrFail();
        }

        $estimate = DB::transaction(function () use ($data, $clubId) {
            $estimate = StoreEstimate::query()->create([
                'club_id' => $clubId,
                'store_client_id' => $data['store_client_id'] ?? null,
                'created_by' => $this->admin()->id,
                'title' => $data['title'] ?? null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($estimate, $data['items'] ?? []);
            $estimate->recalculateTotals();

            return $estimate;
        });

        return back()->with('success', 'Смета #'.$estimate->id.' создана');
    }

    public function update(Request $request, StoreEstimate $storeEstimate)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);
        abort_unless(in_array($storeEstimate->status, ['draft', 'agreed', 'procuring', 'ready'], true), 422, 'Смету нельзя редактировать.');

        $data = $this->validateEstimate($request);

        if (! empty($data['store_client_id'])) {
            StoreClient::query()->where('club_id', $this->locationId())->whereKey($data['store_client_id'])->firstOrFail();
        }

        DB::transaction(function () use ($storeEstimate, $data) {
            $storeEstimate->update([
                'store_client_id' => $data['store_client_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Не трогаем уже заказанные/принятые строки через полную перезапись — только draft/agreed
            if (in_array($storeEstimate->status, ['draft', 'agreed'], true)) {
                $locked = $storeEstimate->items()->whereIn('status', ['ordered', 'received', 'from_stock'])->pluck('id');
                $storeEstimate->items()->whereNotIn('id', $locked)->delete();
                $this->syncItems($storeEstimate, $data['items'] ?? [], keepIds: $locked->all());
            }

            $storeEstimate->recalculateTotals();
        });

        return back()->with('success', 'Смета обновлена');
    }

    public function updateStatus(Request $request, StoreEstimate $storeEstimate, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'status' => 'required|string|in:draft,agreed,procuring,ready,cancelled',
        ]);

        $to = $data['status'];
        $from = $storeEstimate->status;

        $allowed = [
            'draft' => ['agreed', 'cancelled'],
            'agreed' => ['draft', 'procuring', 'cancelled'],
            'procuring' => ['ready', 'cancelled'],
            'ready' => ['procuring', 'cancelled'],
        ];

        abort_unless(in_array($to, $allowed[$from] ?? [], true), 422, 'Недопустимый переход статуса.');

        if ($to === 'cancelled') {
            foreach ($storeEstimate->items()->where('status', 'from_stock')->get() as $item) {
                $procurement->unlinkStock($item);
            }
        }

        $storeEstimate->update(['status' => $to]);
        if ($to !== 'cancelled') {
            $procurement->refreshEstimateReadiness($storeEstimate->fresh());
        }

        return back();
    }

    public function destroy(StoreEstimate $storeEstimate, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);
        abort_unless(in_array($storeEstimate->status, ['draft', 'cancelled'], true), 422, 'Удалять можно черновик/отменённую.');

        foreach ($storeEstimate->items()->where('status', 'from_stock')->get() as $item) {
            $procurement->unlinkStock($item);
        }

        $storeEstimate->delete();

        return back()->with('success', 'Смета удалена');
    }

    public function searchCatalog(
        Request $request,
        StoreSupplierCatalogSearchService $search,
        StoreSupplierCatalogImageService $images
    ) {
        $q = trim($request->string('q')->toString());
        abort_if(mb_strlen($q) < 2, 422, 'Минимум 2 символа');

        $type = $request->string('type')->toString() ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $inStockOnly = ! $request->boolean('include_oos');

        $products = $search->search($q, $type, $categoryId, 40, $inStockOnly);
        $products = $images->attachToSearchResults($products);

        $typeFilterEmpty = false;
        if ($type && ! $categoryId) {
            $ids = $search->categoryIdsForType($type);
            $typeFilterEmpty = is_array($ids) && $ids === [];
        }

        return response()->json([
            'products' => $products,
            'meta' => [
                'type' => $type,
                'type_filter_empty' => $typeFilterEmpty,
                'count' => count($products),
            ],
        ]);
    }

    /**
     * Список proxy-URL всех картинок sku (для галереи в лайтбоксе).
     */
    public function catalogImages(int $sku, StoreSupplierCatalogImageService $images)
    {
        abort_unless($sku > 0, 404);
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $urls = $images->proxyUrlsForSku($sku);

        return response()->json([
            'sku' => $sku,
            'images' => $urls,
            'count' => count($urls),
        ]);
    }

    /**
     * Прокси картинки поставщика (нужна session QuickFox).
     * ?i=0 — индекс в image_paths (по умолчанию 0).
     */
    public function catalogImage(Request $request, int $sku, QuickFoxApiClient $api, StoreSupplierCatalogImageService $images)
    {
        abort_unless($sku > 0, 404);

        $size = $request->string('size')->toString() ?: 'medium';
        if (! in_array($size, ['medium', 'large', 'original'], true)) {
            $size = 'medium';
        }

        $index = max(0, (int) $request->input('i', 0));
        $paths = $images->pathsForSku($sku);
        abort_unless($paths !== [] && isset($paths[$index]), 404);

        try {
            $file = $api->downloadProductImage((string) $paths[$index], $size);
        } catch (\Throwable $e) {
            abort(502, 'Не удалось загрузить изображение');
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'],
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Живые цены/остатки по sku из API поставщика (не из файла каталога).
     */
    public function catalogPrices(Request $request, QuickFoxApiClient $api)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        $skus = $request->input('skus', []);
        if (is_string($skus)) {
            $skus = array_filter(array_map('trim', explode(',', $skus)));
        }
        if (! is_array($skus)) {
            $skus = [];
        }
        $skus = array_values(array_unique(array_map('intval', $skus)));
        $skus = array_values(array_filter($skus, fn ($s) => $s > 0));
        abort_if($skus === [] || count($skus) > 50, 422, 'Укажите 1–50 sku');

        if (! $api->isConfigured()) {
            return response()->json(['message' => 'QuickFox не настроен', 'products' => []], 422);
        }

        try {
            $rows = $api->getActiveProductsBySkus($skus);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'products' => []], 422);
        }

        $bySku = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['sku'])) {
                continue;
            }
            $sku = (int) $row['sku'];
            $bySku[$sku] = [
                'sku' => $sku,
                'price' => isset($row['price']) ? (float) $row['price'] : null,
                'qty' => $row['real_qty'] ?? $row['qty'] ?? null,
                'delivery_days' => $row['delivery_days'] ?? null,
                'in_stock' => true,
            ];
        }

        // Явно пометить отсутствующие (API отдаёт только в наличии)
        $products = [];
        foreach ($skus as $sku) {
            $products[] = $bySku[$sku] ?? [
                'sku' => $sku,
                'price' => null,
                'qty' => 0,
                'delivery_days' => null,
                'in_stock' => false,
            ];
        }

        return response()->json(['products' => $products]);
    }

    public function categories()
    {
        $cats = StoreSupplierCatalogCategory::query()
            ->orderBy('name')
            ->get(['external_id', 'parent_external_id', 'name', 'leaf']);

        return response()->json(['categories' => $cats]);
    }

    public function syncCatalog(StoreSupplierCatalogSyncService $sync)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        try {
            $result = $sync->sync(withPrices: true);

            return back()->with('success', "Каталог: {$result['categories']} кат., {$result['products']} тов., цен: {$result['priced']}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function syncPrices(StoreSupplierCatalogSyncService $sync)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);

        try {
            $priced = $sync->syncPrices();

            return back()->with('success', "Цены обновлены: {$priced} позиций в наличии");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkSupplier(StoreEstimate $storeEstimate, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);

        try {
            $result = $procurement->refreshSupplierPrices($storeEstimate);

            return back()->with('success', "Обновлено цен: {$result['updated']}".($result['missing'] ? ', нет в наличии: '.count($result['missing']) : ''));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function orderMissing(Request $request, StoreEstimate $storeEstimate, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'confirm' => 'nullable|boolean',
        ]);

        try {
            if (! in_array($storeEstimate->status, ['agreed', 'procuring', 'ready'], true)) {
                $storeEstimate->update(['status' => 'agreed']);
            }
            $purchase = $procurement->orderMissing($storeEstimate, $this->admin()->id, (bool) ($data['confirm'] ?? false));

            return back()->with('success', 'Заказ поставщику #'.$purchase->external_order_id.' создан');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function receivePurchase(StorePurchase $storePurchase, Request $request, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storePurchase->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'items' => 'nullable|array',
            'items.*.id' => 'required|integer',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*' => 'nullable|string|max:128',
            'items.*.notes' => 'nullable|string|max:2000',
            'comment' => 'nullable|string|max:2000',
        ]);

        try {
            $procurement->receivePurchase(
                $storePurchase,
                $this->admin()->id,
                $data['items'] ?? [],
                $data['comment'] ?? null,
            );

            return back()->with('success', 'Закупка принята на склад (резерв)');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function linkStock(Request $request, StoreEstimateItem $storeEstimateItem, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        $estimate = $storeEstimateItem->estimate;
        abort_unless($estimate && $estimate->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'store_component_id' => 'required|integer',
        ]);

        $component = StoreComponent::query()
            ->where('club_id', $this->locationId())
            ->whereKey($data['store_component_id'])
            ->firstOrFail();

        try {
            $procurement->linkFromStock($storeEstimateItem, $component);

            return back()->with('success', 'Позиция взята со склада');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function unlinkStock(StoreEstimateItem $storeEstimateItem, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        $estimate = $storeEstimateItem->estimate;
        abort_unless($estimate && $estimate->club_id === $this->locationId(), 404);

        try {
            $procurement->unlinkStock($storeEstimateItem);

            return back();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function convert(Request $request, StoreEstimate $storeEstimate, StoreEstimateProcurementService $procurement)
    {
        abort_unless($this->admin()->canManageStoreCatalog() || $this->admin()->role === 'owner', 403);
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);

        $data = $request->validate([
            'assignee_id' => 'nullable|integer',
        ]);

        try {
            $order = $procurement->convertToOrder($storeEstimate, $data['assignee_id'] ?? null);

            return redirect()
                ->route('admin.store.orders', ['status' => 'new'])
                ->with('success', 'Создан заказ #'.$order->id.' из сметы #'.$storeEstimate->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Смета A4 → печать / «Сохранить как PDF». */
    public function printPdf(StoreEstimate $storeEstimate)
    {
        abort_unless($storeEstimate->club_id === $this->locationId(), 404);

        $storeEstimate->load([
            'client:id,name,phone',
            'club:id,name,address',
            'items',
        ]);

        $lines = $storeEstimate->items->map(function (StoreEstimateItem $item) {
            $qty = max(1, (int) $item->qty);
            $price = (float) ($item->sale_price ?? 0);

            return [
                'type' => $item->type,
                'type_label' => $item->type
                    ? (StoreComponent::TYPES[$item->type] ?? $item->type)
                    : null,
                'name' => $item->name,
                'part' => $item->part,
                'qty' => $qty,
                'sale_price' => $price,
                'line_total' => round($price * $qty, 2),
            ];
        })->values()->all();

        return Inertia::render('Admin/Store/EstimatePrint', [
            'estimate' => $storeEstimate,
            'lines' => $lines,
            'statusLabel' => StoreEstimate::STATUS_LABELS[$storeEstimate->status] ?? $storeEstimate->status,
            'printedAt' => now()->timezone(config('app.timezone'))->format('d.m.Y H:i'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEstimate(Request $request): array
    {
        return $request->validate([
            'store_client_id' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.type' => 'nullable|string|max:32',
            'items.*.name' => 'required|string|max:500',
            'items.*.part' => 'nullable|string|max:120',
            'items.*.supplier_sku' => 'nullable|integer',
            'items.*.supplier_part' => 'nullable|string|max:120',
            'items.*.supplier_name' => 'nullable|string|max:500',
            'items.*.supplier_price' => 'nullable|numeric|min:0',
            'items.*.sale_price' => 'nullable|numeric|min:0',
            'items.*.qty' => 'nullable|integer|min:1',
            'items.*.status' => 'nullable|string|in:planned,from_stock,to_order,ordered,received',
            'items.*.store_component_id' => 'nullable|integer',
            'items.*.notes' => 'nullable|string|max:500',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $keepIds
     */
    private function syncItems(StoreEstimate $estimate, array $items, array $keepIds = []): void
    {
        $procurement = app(StoreEstimateProcurementService::class);
        $sort = 0;
        foreach ($items as $row) {
            if (! empty($row['id']) && in_array((int) $row['id'], $keepIds, true)) {
                $sort++;
                continue;
            }

            $sku = ! empty($row['supplier_sku']) ? (int) $row['supplier_sku'] : null;
            $componentId = ! empty($row['store_component_id']) ? (int) $row['store_component_id'] : null;
            $status = $sku ? 'to_order' : 'planned';

            $item = StoreEstimateItem::query()->create([
                'store_estimate_id' => $estimate->id,
                'type' => $row['type'] ?? null,
                'name' => $row['name'],
                'part' => $row['part'] ?? null,
                'supplier_sku' => $sku,
                'supplier_part' => $row['supplier_part'] ?? null,
                'supplier_name' => $row['supplier_name'] ?? null,
                'supplier_price' => $row['supplier_price'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
                'status' => $status,
                'store_component_id' => null,
                'sort_order' => $sort++,
                'notes' => $row['notes'] ?? null,
            ]);

            if ($componentId > 0) {
                $component = StoreComponent::query()
                    ->where('club_id', $estimate->club_id)
                    ->whereKey($componentId)
                    ->first();
                if ($component) {
                    $procurement->linkFromStock($item, $component);
                }
            }
        }
    }

    /**
     * @return list<int>
     */
    private function categorySubtreeIds(int $rootExternalId): array
    {
        $all = StoreSupplierCatalogCategory::query()->get(['external_id', 'parent_external_id']);
        $byParent = [];
        foreach ($all as $cat) {
            $pid = $cat->parent_external_id ? (int) $cat->parent_external_id : 0;
            $byParent[$pid][] = (int) $cat->external_id;
        }

        $include = [];
        $stack = [$rootExternalId];
        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if (isset($include[$id])) {
                continue;
            }
            $include[$id] = true;
            foreach ($byParent[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_map('intval', array_keys($include));
    }

    private static function formatCatalogTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        $s = trim((string) $value);
        if ($s === '' || $s === '[object Object]') {
            return null;
        }

        return str_replace('T', ' ', substr($s, 0, 16));
    }
}
