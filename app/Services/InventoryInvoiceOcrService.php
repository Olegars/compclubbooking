<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\AiAssistant\DeepSeekChat;
use App\Support\AdminLocation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;

class InventoryInvoiceOcrService
{
    public function __construct(
        private readonly DeepSeekChat $llm,
    ) {}

    /**
     * @return array{
     *   invoice_number:?string,
     *   invoice_date:?string,
     *   supplier_name:?string,
     *   supplier_inn:?string,
     *   supplier_id:?int,
     *   lines: list<array<string, mixed>>,
     *   unmatched: int,
     *   marked: int,
     *   scanned: int
     * }
     */
    public function parsePhoto(UploadedFile $photo, ?int $clubId = null): array
    {
        $encoded = $this->encodeForVision($photo);
        $clubId = $clubId ?: AdminLocation::id();

        $parsed = $this->llm->completeJson(
            $this->systemPrompt(),
            [
                ['type' => 'text', 'text' => $this->userPrompt()],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $encoded['data_url'],
                        'detail' => 'high',
                    ],
                ],
            ],
            $clubId,
            vision: true,
        );

        return $this->hydrateDraft($parsed);
    }

    /**
     * Сверка: документ и отсканированный факт. Склад не трогает.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $extras
     * @return array{ok: true, invoice: ?SupplierInvoice}
     */
    public function close(
        array $lines,
        array $extras,
        int $adminId,
        ?int $supplierId,
        ?string $invoiceNumber,
        ?string $invoiceDate = null,
    ): array {
        if ($extras !== []) {
            throw new RuntimeException('Есть товар не из накладной. Уберите лишние сканы или сверьте документ.');
        }

        if ($lines === []) {
            throw new RuntimeException('Накладная пустая.');
        }

        $total = 0.0;
        $names = [];
        foreach ($lines as $line) {
            $qty = (int) ($line['qty'] ?? 0);
            $scanned = (int) ($line['scanned_qty'] ?? 0);
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId < 1) {
                throw new RuntimeException('Сопоставьте все строки накладной с каталогом.');
            }
            if ($qty < 1 || $qty !== $scanned) {
                throw new RuntimeException('Накладная и факт не совпадают.');
            }

            $unitCost = isset($line['unit_cost']) && $line['unit_cost'] !== null && $line['unit_cost'] !== ''
                ? (float) $line['unit_cost']
                : 0.0;
            $total += $qty * $unitCost;
            $name = trim((string) ($line['product_name'] ?? $line['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name.' × '.$qty;
            }
        }

        $invoice = null;
        if ($supplierId && $total > 0) {
            $supplier = Supplier::query()->find($supplierId);
            $terms = (int) ($supplier?->payment_terms_days ?? 0);
            $issued = $invoiceDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)
                ? $invoiceDate
                : now()->toDateString();

            $invoice = SupplierInvoice::create([
                'supplier_id' => $supplierId,
                'number' => $invoiceNumber ?: ('RCV-INV-'.now()->format('ymdHis')),
                'issued_at' => $issued,
                'due_at' => $terms > 0
                    ? now()->parse($issued)->addDays($terms)->toDateString()
                    : $issued,
                'total_amount' => round($total, 2),
                'paid_amount' => 0,
                'status' => SupplierInvoice::STATUS_OPEN,
                'notes' => 'Сверка накладной: '.implode(', ', array_slice($names, 0, 8)),
                'admin_id' => $adminId,
            ]);
        }

        return ['ok' => true, 'invoice' => $invoice];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{
     *   invoice_number:?string,
     *   invoice_date:?string,
     *   supplier_name:?string,
     *   supplier_inn:?string,
     *   supplier_id:?int,
     *   lines: list<array<string, mixed>>,
     *   unmatched: int,
     *   marked: int,
     *   scanned: int
     * }
     */
    public function hydrateDraft(array $parsed): array
    {
        $products = Product::query()
            ->orderBy('name')
            ->get(['id', 'name', 'barcode', 'cost_price', 'stock', 'requires_marking', 'supplier_id', 'category']);

        $rawLines = $parsed['lines'] ?? $parsed['items'] ?? [];
        if (! is_array($rawLines)) {
            $rawLines = [];
        }

        $lines = [];
        foreach ($rawLines as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapLine($row, $products);
            if ($mapped !== null) {
                $lines[] = $mapped;
            }
        }

        if ($lines === []) {
            throw new RuntimeException('На фото не удалось разобрать строки накладной.');
        }

        $supplierName = $this->cleanString($parsed['supplier_name'] ?? $parsed['supplier'] ?? null);
        $supplierInn = $this->digits((string) ($parsed['supplier_inn'] ?? $parsed['inn'] ?? ''));
        $supplier = $this->matchSupplier($supplierName, $supplierInn);

        $unmatched = 0;
        $marked = 0;
        foreach ($lines as $line) {
            if (! $line['product_id']) {
                $unmatched++;
            }
            if ($line['requires_marking']) {
                $marked++;
            }
        }

        return [
            'invoice_number' => $this->cleanString($parsed['invoice_number'] ?? $parsed['number'] ?? null),
            'invoice_date' => $this->normalizeDate($parsed['invoice_date'] ?? $parsed['date'] ?? null),
            'supplier_name' => $supplierName,
            'supplier_inn' => $supplierInn !== '' ? $supplierInn : null,
            'supplier_id' => $supplier?->id,
            'lines' => $lines,
            'unmatched' => $unmatched,
            'marked' => $marked,
            'scanned' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>|null
     */
    public function mapLine(array $row, Collection $products): ?array
    {
        $name = $this->cleanString($row['name'] ?? $row['title'] ?? $row['product'] ?? null);
        $barcode = $this->normalizeBarcode((string) ($row['barcode'] ?? $row['ean'] ?? $row['gtin'] ?? ''));
        $qty = $this->positiveInt($row['qty'] ?? $row['quantity'] ?? $row['count'] ?? 0);
        $unitCost = $this->money($row['unit_cost'] ?? $row['price'] ?? $row['unit_price'] ?? null);
        $amount = $this->money($row['amount'] ?? $row['sum'] ?? $row['total'] ?? null);

        if ($name === null && $barcode === '') {
            return null;
        }

        if ($qty < 1 && $amount !== null && $unitCost !== null && $unitCost > 0) {
            $qty = max(1, (int) round($amount / $unitCost));
        }
        if ($qty < 1) {
            $qty = 1;
        }

        if ($unitCost === null && $amount !== null && $qty > 0) {
            $unitCost = round($amount / $qty, 2);
        }

        [$product, $match] = $this->matchProduct($name ?? '', $barcode, $products);

        $requiresMarking = (bool) $product?->requires_marking;
        $note = null;
        if ($requiresMarking) {
            $note = 'Сканируйте каждый КМ';
        } elseif (! $product) {
            $note = 'Нет в каталоге — выберите позицию, затем сканируйте';
        }

        return [
            'name' => $name ?? $product?->name ?? 'Позиция',
            'qty' => $qty,
            'scanned_qty' => 0,
            'unit_cost' => $unitCost,
            'barcode' => $barcode !== '' ? $barcode : null,
            'amount' => $amount,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'requires_marking' => $requiresMarking,
            'match' => $match,
            'note' => $note,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{0:?Product, 1: 'barcode'|'name'|'none'}
     */
    public function matchProduct(string $name, string $barcode, Collection $products): array
    {
        if ($barcode !== '') {
            $hit = $products->first(fn (Product $p) => $this->barcodesMatch((string) $p->barcode, $barcode));
            if ($hit) {
                return [$hit, 'barcode'];
            }
        }

        $needle = $this->normalizeName($name);
        if ($needle === '') {
            return [null, 'none'];
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($products as $product) {
            $score = $this->nameScore($needle, $this->normalizeName((string) $product->name));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        if ($best && $bestScore >= 68) {
            return [$best, 'name'];
        }

        return [null, 'none'];
    }

    /**
     * @return array{data_url: string, mime: string}
     */
    public function encodeForVision(UploadedFile $photo): array
    {
        $bytes = file_get_contents($photo->getRealPath() ?: $photo->getPathname());
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Не удалось прочитать фото накладной.');
        }

        $mime = $this->detectMime($bytes, $photo);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException('Нужен JPEG, PNG, WebP или GIF.');
        }

        $packed = $this->compressImage($bytes, $mime);

        return [
            'data_url' => 'data:'.$packed['mime'].';base64,'.base64_encode($packed['bytes']),
            'mime' => $packed['mime'],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты разбираешь фото товарной накладной / УПД / ТОРГ-12 / счёта-фактуры российского поставщика бара или кухни компьютерного клуба.
Верни ТОЛЬКО JSON-объект (слово json обязательно) вида:
{
  "invoice_number": "строка или null",
  "invoice_date": "YYYY-MM-DD или null",
  "supplier_name": "строка или null",
  "supplier_inn": "только цифры или null",
  "lines": [
    {
      "name": "название как в документе",
      "qty": 12,
      "unit_cost": 45.50,
      "barcode": "ean13 или null",
      "amount": 546.00
    }
  ]
}
qty — количество в штуках (не упаковках, если видно штуки). unit_cost — цена за 1 шт без НДС, если в документе с НДС — как в строке.
Игнорируй итоги, НДС, подписи, печати, услуги доставки. Не выдумывай строки, которых нет на фото.
PROMPT;
    }

    private function userPrompt(): string
    {
        return 'Распознай эту накладную и верни json со строками товара.';
    }

    private function matchSupplier(?string $name, string $inn): ?Supplier
    {
        $q = Supplier::query()->where('is_active', true);
        if ($inn !== '') {
            $hit = (clone $q)->where('inn', $inn)->first();
            if ($hit) {
                return $hit;
            }
        }

        if ($name === null || $name === '') {
            return null;
        }

        $needle = $this->normalizeName($name);
        $best = null;
        $bestScore = 0.0;
        foreach ($q->get(['id', 'name', 'inn']) as $supplier) {
            $score = $this->nameScore($needle, $this->normalizeName((string) $supplier->name));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $supplier;
            }
        }

        return $bestScore >= 72 ? $best : null;
    }

    private function barcodesMatch(string $a, string $b): bool
    {
        $a = $this->normalizeBarcode($a);
        $b = $this->normalizeBarcode($b);
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || ltrim($a, '0') === ltrim($b, '0');
    }

    private function normalizeBarcode(string $value): string
    {
        $digits = $this->digits($value);

        return strlen($digits) >= 8 ? $digits : '';
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^a-zа-я0-9\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function nameScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 100.0;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = min(mb_strlen($a), mb_strlen($b));
            $longer = max(mb_strlen($a), mb_strlen($b));

            return $longer > 0 ? round(88 + 12 * ($shorter / $longer), 2) : 88.0;
        }

        similar_text($a, $b, $pct);

        return (float) $pct;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' || strtolower($text) === 'null' ? null : $text;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function positiveInt(mixed $value): int
    {
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], $value);
        }

        return max(0, (int) round((float) $value));
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([' ', "\u{00a0}", '₽', 'руб.', 'руб'], '', $value);
            $value = str_replace(',', '.', $value);
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeDate(mixed $value): ?string
    {
        $text = $this->cleanString($value);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        if (preg_match('/^(\d{1,2})[.](\d{1,2})[.](\d{4})$/', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function detectMime(string $bytes, UploadedFile $photo): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->buffer($bytes));
        if (str_starts_with($mime, 'image/')) {
            return $mime;
        }

        return strtolower((string) ($photo->getMimeType() ?: 'application/octet-stream'));
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    private function compressImage(string $bytes, string $mime): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $maxSide = 1600;
        $scale = max($width, $height) > $maxSide
            ? $maxSide / max($width, $height)
            : 1.0;

        if ($scale < 1) {
            $dstW = max(1, (int) round($width * $scale));
            $dstH = max(1, (int) round($height * $scale));
            $dst = imagecreatetruecolor($dstW, $dstH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);

        if ($jpeg === '' || strlen($jpeg) > strlen($bytes) && $mime === 'image/jpeg' && $scale >= 1) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
    }
}
