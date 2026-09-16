<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Controlled CSV in and out (Master Spec §5 and A.1).
 *
 * Three properties this class has to have:
 *
 *  - it never crosses shops. Both directions take the vendor as an argument
 *    and every row is read or written through the owner-scoped repository, so
 *    a SKU belonging to another shop is simply not visible here (AC-PRIV);
 *  - a cell can never become a formula. Spreadsheets execute a value that
 *    begins with `=`, `+`, `-`, `@`, a tab or a carriage return, and a product
 *    title is attacker-supplied text, so those values are prefixed with a
 *    single quote on the way out (§12: «جلوگیری از فرمول‌تزریقی در فایل»);
 *  - importing cannot publish anything. Every row goes through the ordinary
 *    save path, which means a live product's sensitive change becomes a
 *    revision exactly as it would in the form, and status is not a column.
 */
final class ProductCsv
{
    /** @var list<string> the fixed columns, in file order */
    public const COLUMNS = [
        'sku', 'title', 'type', 'category', 'brand', 'short_description',
        'price', 'sale_price', 'sale_from', 'sale_to',
        'stock', 'min_purchase', 'max_purchase', 'weight_grams', 'dimensions', 'tax_class',
    ];

    /** Columns the file shows but the importer never reads back. */
    public const READ_ONLY_COLUMNS = ['id', 'status'];

    public const SPEC_PREFIX = 'spec:';

    /** One upload is a batch, and a batch has an end. */
    public const MAX_ROWS = 500;

    /** Anything a spreadsheet would treat as the start of a formula. */
    private const FORMULA_STARTERS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SpecTemplateRepositoryInterface $templates,
        private readonly ManageProducts $manage,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @return array{ok:bool, code:string, csv:string, rows:int}
     */
    public function export(int $actorId, int $vendorUserId): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::View)) {
            return ['ok' => false, 'code' => 'forbidden', 'csv' => '', 'rows' => 0];
        }
        $products = $this->products->forVendor($vendorUserId, null, self::MAX_ROWS);
        $specKeys = $this->specKeysFor($products);
        $header = array_merge(self::READ_ONLY_COLUMNS, self::COLUMNS, array_map(
            static fn (string $key): string => self::SPEC_PREFIX . $key,
            $specKeys
        ));

        $lines = [$this->line($header, false)];
        foreach ($products as $product) {
            $lines[] = $this->line($this->row($product, $specKeys), true);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_CSV_EXPORTED, $actorId, 'vendor', (string) $vendorUserId, [
            'vendor_id' => $vendorUserId,
            'rows' => count($products),
        ]);
        // The BOM is what makes Excel read UTF-8 Persian instead of mojibake.
        return ['ok' => true, 'code' => 'csv_exported', 'csv' => "\u{FEFF}" . implode("\r\n", $lines) . "\r\n", 'rows' => count($products)];
    }

    /**
     * Reads a file and says what WOULD happen (`$apply = false`), or does it.
     *
     * The preview and the run are the same code path with one flag, so the
     * report a vendor approves is the report they get.
     *
     * @return array{ok:bool, code:string, rows:list<array{line:int,sku:string,title:string,action:string,code:string,context:array<string,scalar|null>}>, created:int, updated:int, skipped:int}
     */
    public function import(int $actorId, int $vendorUserId, string $csv, bool $apply): array
    {
        $empty = ['rows' => [], 'created' => 0, 'updated' => 0, 'skipped' => 0];
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return array_merge(['ok' => false, 'code' => 'forbidden'], $empty);
        }
        $table = $this->parse($csv);
        if ($table === []) {
            return array_merge(['ok' => false, 'code' => 'csv_empty'], $empty);
        }
        $header = array_map(
            static fn (string $cell): string => strtolower(trim($cell)),
            array_shift($table)
        );
        if (!in_array('title', $header, true)) {
            return array_merge(['ok' => false, 'code' => 'csv_missing_columns'], $empty);
        }
        if (count($table) > self::MAX_ROWS) {
            return array_merge(['ok' => false, 'code' => 'csv_too_many_rows'], ['rows' => [], 'created' => 0, 'updated' => 0, 'skipped' => count($table)]);
        }

        $rows = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($table as $index => $cells) {
            $line = $index + 2;                      // 1 is the header, and people count from 1
            $values = $this->associate($header, $cells);
            if ($this->isBlank($values)) {
                continue;
            }
            $report = $this->importRow($actorId, $vendorUserId, $values, $apply);
            $report['line'] = $line;
            $rows[] = $report;
            match ($report['action']) {
                'create' => $created++,
                'update', 'revision' => $updated++,
                default => $skipped++,
            };
        }

        if ($apply) {
            $this->audit->log(AuditEventCatalog::PRODUCT_CSV_IMPORTED, $actorId, 'vendor', (string) $vendorUserId, [
                'vendor_id' => $vendorUserId,
                'rows' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);
        }
        return [
            'ok' => true,
            'code' => $apply ? 'csv_imported' : 'csv_previewed',
            'rows' => $rows,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    // ------------------------------------------------------------- one row

    /**
     * @param array<string,string> $values
     * @return array{line:int,sku:string,title:string,action:string,code:string,context:array<string,scalar|null>}
     */
    private function importRow(int $actorId, int $vendorUserId, array $values, bool $apply): array
    {
        $sku = trim($values['sku'] ?? '');
        $title = trim($values['title'] ?? '');
        $existing = $sku === '' ? null : $this->findBySku($vendorUserId, $sku);
        $report = static fn (string $action, string $code, array $context = []): array => [
            'line' => 0,
            'sku' => $sku,
            'title' => $title,
            'action' => $action,
            'code' => $code,
            'context' => $context,
        ];

        if ($title === '' && $existing === null) {
            return $report('skip', 'csv_row_needs_title');
        }
        $details = $this->detailsFrom($values, $existing?->details);
        $specs = $this->specsFrom($values, $existing?->specs ?? [], $details->categoryKey);

        if (!$apply) {
            // The dry run answers "would this be accepted?" using the same
            // checks, without writing: a preview that cannot fail would be
            // worthless as a preview.
            $verdict = $this->wouldBeAccepted($vendorUserId, $details, $existing);
            if ($verdict !== null) {
                return $report('skip', $verdict->code, $verdict->context);
            }
            $action = $existing === null
                ? 'create'
                : ($existing->status->isEditableByVendor() ? 'update' : 'revision');
            return $report($action, 'csv_row_ok');
        }

        // The row's OWN counter, read in the same pass that matched it. A CSV
        // import has no form and therefore no stamp of its own — but it must
        // still be guarded, or a file applied while somebody is editing would
        // be the one write in the system that never checks. Reading it here
        // means the guard is real: if that editor saves between this read and
        // the write, the WHERE clause refuses the row and the report says so.
        $result = $this->manage->save(
            $actorId,
            $vendorUserId,
            $existing?->id ?? 0,
            $details,
            $specs,
            $existing?->imageIds ?? [],
            $existing?->mainImageId ?? 0,
            $existing?->rowVersion ?? ''
        );
        if (!$result->ok) {
            return $report('skip', $result->code, $result->context);
        }
        $action = match ($result->code) {
            'product_created' => 'create',
            'revision_requested' => 'revision',
            default => 'update',
        };
        return $report($action, $result->code, $result->context);
    }

    /** A cheap rehearsal of ManageProducts' own refusals, for the preview. */
    private function wouldBeAccepted(int $vendorUserId, ProductDetails $details, ?Product $existing): ?OperationResult
    {
        if ($existing !== null && !$existing->status->isEditableByVendor()
            && $existing->status->value === 'submitted') {
            return OperationResult::failure('in_review');
        }
        if ($existing !== null && $existing->status->value === 'archived') {
            return OperationResult::failure('product_archived');
        }
        if ($details->priceMinor < 0) {
            return OperationResult::failure('bad_price');
        }
        if ($details->salePriceMinor !== null && $details->salePriceMinor > $details->priceMinor) {
            return OperationResult::failure('bad_sale_price');
        }
        if ($details->stock < 0) {
            return OperationResult::failure('bad_stock');
        }
        if ($details->minPurchase < 1 || ($details->maxPurchase !== null && $details->maxPurchase < $details->minPurchase)) {
            return OperationResult::failure('bad_quantity');
        }
        if ($details->sku !== '' && $this->products->skuTaken($vendorUserId, $details->sku, $existing?->id ?? 0)) {
            return OperationResult::failure('sku_taken', ['sku' => $details->sku]);
        }
        return null;
    }

    private function findBySku(int $vendorUserId, string $sku): ?Product
    {
        foreach ($this->products->forVendor($vendorUserId, null, self::MAX_ROWS) as $product) {
            if ($product->details->sku !== '' && $product->details->sku === $sku) {
                return $product;
            }
        }
        return null;
    }

    /** @param array<string,string> $values */
    private function detailsFrom(array $values, ?ProductDetails $current): ProductDetails
    {
        $current ??= new ProductDetails();
        $text = static fn (string $key, string $fallback): string => array_key_exists($key, $values)
            ? trim($values[$key])
            : $fallback;
        $int = static function (string $key, int $fallback) use ($values): int {
            if (!array_key_exists($key, $values) || trim($values[$key]) === '') {
                return $fallback;
            }
            return (int) round((float) str_replace([',', '٬', ' '], '', trim($values[$key])));
        };
        $nullableInt = static function (string $key, ?int $fallback) use ($values, $int): ?int {
            if (!array_key_exists($key, $values)) {
                return $fallback;
            }
            return trim($values[$key]) === '' ? null : $int($key, 0);
        };
        $nullableText = static function (string $key, ?string $fallback) use ($values): ?string {
            if (!array_key_exists($key, $values)) {
                return $fallback;
            }
            return trim($values[$key]) === '' ? null : trim($values[$key]);
        };

        return new ProductDetails(
            $text('title', $current->title),
            $text('type', $current->type !== '' ? $current->type : 'simple'),
            $text('category', $current->categoryKey),
            $text('brand', $current->brand),
            $text('short_description', $current->shortDescription),
            $int('price', $current->priceMinor),
            $nullableInt('sale_price', $current->salePriceMinor),
            $nullableText('sale_from', $current->saleFrom),
            $nullableText('sale_to', $current->saleTo),
            $text('sku', $current->sku),
            $int('stock', $current->stock),
            $int('min_purchase', $current->minPurchase > 0 ? $current->minPurchase : 1),
            $nullableInt('max_purchase', $current->maxPurchase),
            $int('weight_grams', $current->weightGrams),
            $text('dimensions', $current->dimensions),
            $text('tax_class', $current->taxClass)
        );
    }

    /**
     * Only the fields the category actually asks for are read back. A column
     * for a retired or foreign field is ignored rather than stored, so a file
     * edited by hand cannot invent a specification.
     *
     * @param array<string,string> $values
     * @param array<string,string> $current
     * @return array<string,string>
     */
    private function specsFrom(array $values, array $current, string $categoryKey): array
    {
        $template = $this->templates->findByCategory($categoryKey);
        if ($template === null) {
            return $current;
        }
        $specs = $current;
        foreach ($template->askedFields() as $field) {
            $column = self::SPEC_PREFIX . $field->key;
            if (array_key_exists($column, $values)) {
                $specs[$field->key] = trim($values[$column]);
            }
        }
        return $specs;
    }

    // ------------------------------------------------------------ mechanics

    /** @param list<Product> $products @return list<string> */
    private function specKeysFor(array $products): array
    {
        $keys = [];
        foreach ($products as $product) {
            $template = $this->templates->findByCategory($product->details->categoryKey);
            foreach ($template?->askedFields() ?? [] as $field) {
                if (!in_array($field->key, $keys, true)) {
                    $keys[] = $field->key;
                }
            }
        }
        sort($keys);
        return $keys;
    }

    /** @param list<string> $specKeys @return list<string> */
    private function row(Product $product, array $specKeys): array
    {
        $d = $product->details;
        $row = [
            (string) $product->id,
            $product->status->value,
            $d->sku,
            $d->title,
            $d->type,
            $d->categoryKey,
            $d->brand,
            $d->shortDescription,
            (string) $d->priceMinor,
            $d->salePriceMinor === null ? '' : (string) $d->salePriceMinor,
            $d->saleFrom ?? '',
            $d->saleTo ?? '',
            (string) $d->stock,
            (string) $d->minPurchase,
            $d->maxPurchase === null ? '' : (string) $d->maxPurchase,
            (string) $d->weightGrams,
            $d->dimensions,
            $d->taxClass,
        ];
        foreach ($specKeys as $key) {
            $row[] = $product->specs[$key] ?? '';
        }
        return $row;
    }

    /** @param list<string> $cells */
    private function line(array $cells, bool $defuse): string
    {
        $escaped = [];
        foreach ($cells as $cell) {
            $value = (string) $cell;
            if ($defuse && $value !== '' && in_array($value[0], self::FORMULA_STARTERS, true)) {
                $value = "'" . $value;
            }
            $escaped[] = '"' . str_replace('"', '""', $value) . '"';
        }
        return implode(',', $escaped);
    }

    /** @return list<list<string>> */
    private function parse(string $csv): array
    {
        $csv = str_replace("\u{FEFF}", '', $csv);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;                             // a blank line
            }
            $rows[] = array_map(static fn (mixed $c): string => (string) $c, $cells);
        }
        fclose($handle);
        return $rows;
    }

    /**
     * @param list<string> $header
     * @param list<string> $cells
     * @return array<string,string>
     */
    private function associate(array $header, array $cells): array
    {
        $values = [];
        foreach ($header as $i => $name) {
            if ($name === '') {
                continue;
            }
            $values[$name] = $this->undefuse($cells[$i] ?? '');
        }
        return $values;
    }

    /** Strips the quote the exporter added, so a round trip is lossless. */
    private function undefuse(string $value): string
    {
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], self::FORMULA_STARTERS, true)) {
            return substr($value, 1);
        }
        return $value;
    }

    /** @param array<string,string> $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }
        return true;
    }
}
