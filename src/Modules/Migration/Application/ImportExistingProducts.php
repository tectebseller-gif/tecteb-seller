<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * Bringing products this shop ALREADY HAS into the marketplace — without
 * creating a single WooCommerce product.
 *
 * ---------------------------------------------------------------------------
 * WHAT AN INTAKE IS, AND WHAT IT IS NOT
 * ---------------------------------------------------------------------------
 *
 * It creates one marketplace row POINTING AT the existing WooCommerce product,
 * with `link_ownership = observed`. That is the whole operation. «ورود بدون
 * ساخت محصول جدید ووکامرس. شناسهٔ محصول و شناسهٔ تنوع حفظ شود» is satisfied
 * because nothing is created and nothing is copied: the id in the row IS the
 * product's id, so the URL, the slug, the media, the reviews and every past
 * order line keep pointing at the same post they always did.
 *
 * And `observed` is what makes it safe to run on a live shop: an observed row
 * is invisible to the purchase policy, to the storefront stop, to the
 * projector and to the vendor's catalogue (`LinkOwnership`). So after an
 * intake the product behaves EXACTLY as it did before — a Dokan vendor's
 * product is still Dokan's, the shop's own is still the shop's — and it
 * becomes visible in the manager's list, which is precisely the separation
 * §2 asks for: «محصولات فعلی ابتدا بدون تغییر وضعیت انتشار و مالکیت در فهرست
 * مدیر دیده شوند. پذیرش مدیریت بازارگاه اقدامی صریح و جدا باشد.»
 *
 * Accepting management is `TransferOwnership::take()`, which was already the
 * one road from `observed` to `marketplace`, and it stays the only one.
 *
 * ---------------------------------------------------------------------------
 * WOOCOMMERCE IS THE SOURCE OF THE CURRENT VALUES, SO PROJECTION IS A NO-OP
 * ---------------------------------------------------------------------------
 *
 * The row is filled from what WooCommerce holds NOW — every field the
 * four-step form can express, including the three purchase-quantity rows a
 * Min Max handover may have written. «در اولین ورود، ووکامرس مرجع مقدار فعلی
 * است و projection نباید مقادیر قدیمی بازارگاه را روی آن بنویسد»: a row that
 * already says what the shop says makes the first projection write the same
 * values back, which is the only way an ordinary save and approval afterwards
 * can leave the manager's data alone.
 *
 * The agreement baseline is built from the same values, with the SAME encoders
 * the approval path uses (`StorefrontField::idsToValue()` and
 * `StorefrontImages::encode()`), so the baseline this writes and the one
 * `StorefrontFieldsInterface::storefrontValues()` would produce cannot
 * disagree. What it does NOT write is an ownership stamp: «خواندن اطلاعات را
 * معادل مالکیت تاریخیِ تمام فیلدها یا متاها ندان». Reading a title is not
 * evidence that this plugin wrote it, and `ProjectedFieldOwnership` is
 * deliberately left empty so the first projection treats every field as
 * somebody else's until a decision says otherwise.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT REFUSES, EACH BY NAME
 * ---------------------------------------------------------------------------
 *
 * Nothing is converted and nothing is dropped silently — «حذف یا تبدیل ضمنی
 * نکن». A product type the form has no shape for, a price that is not an
 * exact integer in a shop whose marketplace prices are integers, a SKU
 * already used in that shop, a vendor nobody has named: each is a refusal
 * with a name, recorded against the product, and the batch carries on with
 * the others — «موارد مبهم … مانع ورود موارد سالم و مستقل نشوند».
 */
final class ImportExistingProducts
{
    /** Where each run's manifest lives, so a rollback knows what it made. */
    public const RUNS_OPTION = 'tmc_catalogue_intake_runs';

    /** How many products one batch takes. */
    public const BATCH = 25;

    public function __construct(
        private readonly CatalogueReaderInterface $reader,
        private readonly CatalogueInventory $inventory,
        private readonly ProductRepositoryInterface $products,
        private readonly VendorRepositoryInterface $vendors,
        private readonly OptionStoreInterface $options,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly ?CapabilityCheckerInterface $capabilities = null,
        /**
         * Asked one question: does this product already carry an unanswered
         * proposal? A product with one is left alone and named, because a
         * second row would make the proposal invisible and deleting it would
         * throw away a vendor's unanswered request.
         */
        private readonly ?ProductRevisionRepositoryInterface $revisions = null
    ) {
    }

    /** A run id a manager can read and a rollback can name. */
    public function newRunId(): string
    {
        return 'intake-' . $this->clock->now()->format('Ymd-His');
    }

    /**
     * What an intake WOULD do to each of these products. Writes nothing.
     *
     * Not a count: a verdict per product, with the reason. A preview that
     * answered «19 of 21 would import» would leave a manager hunting for the
     * other two.
     *
     * @param list<int> $wcIds
     * @return list<array<string,mixed>>
     */
    public function preview(array $wcIds, int $assignTo = 0): array
    {
        $out = [];
        foreach ($this->uniqueIds($wcIds) as $wcId) {
            $out[] = $this->judge($wcId, $assignTo) + ['wc_product_id' => $wcId];
        }
        return $out;
    }

    /**
     * Take one batch of named products into the marketplace as observed rows.
     *
     * `$wcIds` is an explicit list and never «everything that scanned clean»:
     * an ambiguous product must not hold up a sound one, and a sound one must
     * not be imported because it happened to be on the same page as a
     * decision somebody has not made.
     *
     * `$assignTo` is the manager naming a vendor for products that have none —
     * the shop's own, mostly. It is refused unless that user really is an
     * approved vendor here, and it is NEVER inferred: «محصول فروشگاه اصلی را
     * به فروشندهٔ تصادفی یا مدیر واردشده نسبت نده».
     *
     * @param list<int> $wcIds
     * @return array<string,mixed>
     */
    public function importBatch(string $runId, array $wcIds, int $assignTo = 0, int $limit = self::BATCH): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return ['ok' => false, 'code' => 'forbidden', 'results' => [], 'done' => true];
        }
        if ($runId === '') {
            return ['ok' => false, 'code' => 'no_run_id', 'results' => [], 'done' => true];
        }
        $ids = $this->uniqueIds($wcIds);
        $page = array_slice($ids, 0, max(1, min(self::BATCH, $limit)));
        $results = [];
        $created = [];
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($page as $wcId) {
            $verdict = $this->judge($wcId, $assignTo);
            if ($verdict['verdict'] !== 'import') {
                $counts['skipped']++;
                $results[] = $verdict + ['wc_product_id' => $wcId];
                continue;
            }
            $outcome = $this->intake($runId, $wcId, (int) $verdict['vendor_user_id']);
            if (($outcome['product_id'] ?? 0) > 0) {
                $created[] = (int) $outcome['product_id'];
                // Written after EVERY product rather than at the end of the
                // batch: a run killed by a timeout half way through has
                // created real rows, and a manifest that only records
                // finished runs cannot undo them (`ImportFromDokan`'s own
                // lesson, measured there).
                $this->rememberRun($runId, $created);
            }
            $counts[($outcome['verdict'] ?? '') === 'imported' ? 'imported' : 'failed']++;
            $results[] = $outcome + ['wc_product_id' => $wcId];
        }

        $this->audit->log(AuditEventCatalog::CATALOGUE_INTAKE, $this->actorId(), 'migration', $runId, [
            'run_id' => $runId,
            'imported' => $counts['imported'],
            'skipped' => $counts['skipped'],
            'failed' => $counts['failed'],
        ]);
        return [
            'ok' => $counts['failed'] === 0,
            'code' => $counts['failed'] === 0 ? 'intake_done' : 'intake_partly_done',
            'run_id' => $runId,
            'counts' => $counts,
            'results' => $results,
            'remaining' => array_values(array_slice($ids, count($page))),
            'done' => count($page) >= count($ids),
        ];
    }

    /**
     * Whether this product can be taken, and if not, why — by name.
     *
     * @return array<string,mixed>
     */
    public function judge(int $wcId, int $assignTo = 0): array
    {
        $row = $this->rowFor($wcId);
        if ($row === []) {
            return ['verdict' => 'skip', 'reason' => 'not_a_product', 'vendor_user_id' => 0, 'risks' => []];
        }
        $classified = $this->inventory->classify($row);
        $links = $classified['marketplace_rows'];
        if ($links !== []) {
            // Already linked. Which one it is matters for what a manager does
            // next, so the reason says, and an open proposal is named
            // because that is the case where somebody might be tempted to
            // delete the row and import again.
            $open = $this->hasOpenProposal((int) $links[0]['product_id']);
            return [
                'verdict' => 'skip',
                'reason' => $open ? 'already_linked_with_open_proposal' : 'already_linked_' . $links[0]['ownership'],
                'vendor_user_id' => (int) $links[0]['vendor_user_id'],
                'product_id' => (int) $links[0]['product_id'],
                'risks' => [],
            ];
        }

        $vendor = $assignTo > 0 ? $assignTo : (int) $classified['vendor_user_id'];
        $reasons = $classified['reasons'];
        if ($assignTo > 0) {
            // The manager has named a vendor, which answers exactly the
            // questions that are about «whose is this» and no others.
            $reasons = array_values(array_filter(
                $reasons,
                static fn (string $r): bool => !in_array($r, [
                    'shop_product_needs_owner_decision',
                    'author_is_not_a_vendor',
                    'author_missing',
                ], true)
            ));
            if ($this->vendors->findProfileByUser($assignTo) === null) {
                return [
                    'verdict' => 'conflict',
                    'reason' => 'assigned_vendor_is_not_a_vendor',
                    'vendor_user_id' => $assignTo,
                    'risks' => [],
                ];
            }
        }
        if ($reasons !== []) {
            return [
                'verdict' => 'skip',
                'reason' => $reasons[0],
                'reasons' => $reasons,
                'vendor_user_id' => $vendor,
                'risks' => [],
            ];
        }
        if ($vendor <= 0) {
            return ['verdict' => 'skip', 'reason' => 'vendor_unknown', 'vendor_user_id' => 0, 'risks' => []];
        }

        $detail = $this->reader->detail($wcId);
        if ($detail === []) {
            return ['verdict' => 'skip', 'reason' => 'product_unreadable', 'vendor_user_id' => $vendor, 'risks' => []];
        }
        $price = $this->exactInteger((string) $detail['regular_price']);
        if ($price === null) {
            // A price with a fraction in a marketplace that holds integer
            // prices. Rounding money is forbidden here (`alpha.39`,
            // `alpha.53`), so the product is refused and the number is left
            // alone.
            return ['verdict' => 'conflict', 'reason' => 'price_not_an_integer', 'vendor_user_id' => $vendor, 'risks' => []];
        }
        if ((string) $detail['sku'] !== '' && $this->products->skuTaken($vendor, (string) $detail['sku'])) {
            return ['verdict' => 'conflict', 'reason' => 'sku_already_used_in_this_shop', 'vendor_user_id' => $vendor, 'risks' => []];
        }
        return [
            'verdict' => 'import',
            'reason' => '',
            'vendor_user_id' => $vendor,
            // Named, not blocking: an observed row changes nothing, so these
            // are what ACCEPTANCE will have to answer for. `TransferOwnership`
            // asks for them again and refuses until they are acknowledged.
            'risks' => $this->reader->preservationRisks($wcId),
        ];
    }

    /**
     * One product in, as an observed row filled from WooCommerce.
     *
     * @return array<string,mixed>
     */
    private function intake(string $runId, int $wcId, int $vendorUserId): array
    {
        $detail = $this->reader->detail($wcId);
        if ($detail === []) {
            return ['verdict' => 'failed', 'reason' => 'product_unreadable'];
        }
        $details = $this->detailsFrom($detail);
        if ($details === null) {
            return ['verdict' => 'failed', 'reason' => 'price_not_an_integer'];
        }
        $productId = $this->products->create(
            $vendorUserId,
            $details,
            // The MARKETPLACE row mirrors WooCommerce's publish state, and
            // that is a safety property rather than a nicety: the projector
            // writes `draft` for any status but `published`, so a row created
            // as a draft would take a live product off sale the first time
            // anybody projected it. Mirroring means the first projection
            // writes the status the product already has.
            (string) $detail['status'] === 'publish' ? ProductStatus::Published : ProductStatus::Draft,
            // OBSERVED. Nothing follows from this row until somebody accepts
            // management explicitly.
            LinkOwnership::Observed,
            $wcId,
            $runId
        );
        if ($productId === 0) {
            return ['verdict' => 'failed', 'reason' => 'row_not_created'];
        }
        $parts = [];
        if (!$this->products->saveBaseline($productId, $this->baselineFrom($detail))) {
            $parts[] = 'baseline';
        }
        if ((string) $detail['brand'] !== '' && !$this->products->setBrandTerm(
            $productId,
            (string) $detail['brand'],
            ((int) $detail['brand_term_id']) > 0 ? (int) $detail['brand_term_id'] : null
        )) {
            $parts[] = 'brand';
        }
        $images = array_values(array_filter(array_map(
            'intval',
            array_merge(
                ((int) $detail['image_id']) > 0 ? [(int) $detail['image_id']] : [],
                (array) $detail['gallery_image_ids']
            )
        )));
        if ($images !== [] && !$this->products->saveImages($productId, $images, (int) $detail['image_id'])) {
            $parts[] = 'images';
        }
        return [
            'verdict' => 'imported',
            'reason' => $parts === [] ? '' : 'partly_written:' . implode(',', $parts),
            'product_id' => $productId,
            'vendor_user_id' => $vendorUserId,
            // Said out loud: the attachments are whoever's they were. Nothing
            // was copied, because copying would make the first projection
            // change the product's picture — the opposite of what an intake
            // is for.
            'media_copied' => false,
        ];
    }

    /**
     * WooCommerce's current values as this marketplace's own fields.
     *
     * Every field the form can express, and nothing invented. `null` when the
     * price is not an exact integer, which is a refusal rather than a round.
     *
     * @param array<string,mixed> $detail
     */
    private function detailsFrom(array $detail): ?ProductDetails
    {
        $price = $this->exactInteger((string) $detail['regular_price']);
        if ($price === null) {
            return null;
        }
        $sale = (string) $detail['sale_price'] === ''
            ? null
            : $this->exactInteger((string) $detail['sale_price']);
        // The three purchase-quantity rows, read as the rule they express —
        // which is what a Min Max handover may have written onto this very
        // product. Reading them here is what makes the row agree with the
        // shop about the rule as well as about the price.
        $quantity = $this->quantityOf((int) $detail['wc_product_id']);
        return new ProductDetails(
            title: (string) $detail['title'],
            type: (string) $detail['type'] === ProductType::VARIABLE ? ProductType::VARIABLE : ProductType::SIMPLE,
            // ONE category, because the row holds one — and the extras are not
            // lost: `WooCommerceProjector::categoryIds()` merges what is
            // already on the product rather than replacing it.
            categoryKey: ((array) $detail['category_ids']) === [] ? '' : (string) ((array) $detail['category_ids'])[0],
            brand: (string) $detail['brand'],
            shortDescription: (string) $detail['short_description'],
            priceMinor: $price,
            salePriceMinor: $sale,
            saleFrom: (string) $detail['date_on_sale_from'] === '' ? null : (string) $detail['date_on_sale_from'],
            saleTo: (string) $detail['date_on_sale_to'] === '' ? null : (string) $detail['date_on_sale_to'],
            sku: (string) $detail['sku'],
            // `null` stock — a product not managing stock — reads as zero in
            // the row, and the risk list says so. Zero is the conservative
            // reading and it changes nothing while the link is observed.
            stock: max(0, (int) ($detail['stock_quantity'] ?? 0)),
            minPurchase: $quantity['min'],
            maxPurchase: $quantity['max'],
            weightGrams: $this->gramsOf((string) $detail['weight']),
            // Deliberately empty: the projector never writes dimensions, so
            // an empty string here cannot overwrite the product's own, and
            // inventing a format for three numbers would be a conversion.
            dimensions: '',
            taxClass: (string) $detail['tax_class'],
            description: (string) $detail['description'],
            purchaseStep: $quantity['step']
        );
    }

    /**
     * The agreement baseline, built with the encoders the approval path uses.
     *
     * Same five fields as the projection's own list and the same
     * encoding, so this baseline and the one `storefrontValues()` produces
     * after acceptance are byte-identical. Two encoders would be two answers.
     *
     * @param array<string,mixed> $detail
     */
    private function baselineFrom(array $detail): ApprovedBaseline
    {
        return ApprovedBaseline::of([
            'title' => (string) $detail['title'],
            'short_description' => (string) $detail['short_description'],
            'description' => (string) $detail['description'],
            'category' => StorefrontField::idsToValue((array) $detail['category_ids']),
            'images' => StorefrontImages::encode(
                (int) $detail['image_id'],
                (array) $detail['gallery_image_ids']
            ),
        ]);
    }

    /**
     * Undo one run: the rows it created, and nothing else.
     *
     * Three things it will not do, and each is in the instruction:
     *
     *  - it never deletes a WooCommerce product, an attachment or an order.
     *    It cannot: the only writer it has is this marketplace's own products
     *    table.
     *  - it never removes a row somebody has since accepted into marketplace
     *    management. That row is not an intake's any more; it is reported and
     *    kept.
     *  - it never removes a row carrying an unanswered proposal. Somebody
     *    asked for something and nobody has answered.
     */
    public function rollback(string $runId): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $runs = $this->runs();
        $manifest = $runs[$runId] ?? null;
        $stamped = $this->products->idsFromImportRun($runId);
        if ($manifest === null && $stamped === []) {
            return OperationResult::failure('intake_run_unknown', ['run_id' => $runId]);
        }
        $productIds = array_values(array_unique(array_merge(
            array_map('intval', $manifest['products'] ?? []),
            $stamped
        )));
        sort($productIds);

        $removed = 0;
        $kept = [];
        foreach ($productIds as $productId) {
            if ($this->products->linkOwnership($productId) === LinkOwnership::Marketplace) {
                $kept[] = $productId . ':accepted';
                $this->products->stampImportRun($productId, '');
                continue;
            }
            if ($this->hasOpenProposal($productId)) {
                $kept[] = $productId . ':open_proposal';
                continue;
            }
            if ($this->products->deleteObservedFromRun($productId, $runId)) {
                $removed++;
                continue;
            }
            $kept[] = $productId . ':not_removed';
            $this->products->stampImportRun($productId, '');
        }
        unset($runs[$runId]);
        $this->options->set(self::RUNS_OPTION, $runs);
        $this->audit->log(AuditEventCatalog::CATALOGUE_INTAKE_ROLLED_BACK, $this->actorId(), 'migration', $runId, [
            'run_id' => $runId,
            'removed' => $removed,
            'kept' => count($kept),
        ]);
        if ($kept !== []) {
            return OperationResult::failure('intake_rollback_kept', [
                'run_id' => $runId,
                'removed' => $removed,
                'kept' => implode('، ', $kept),
            ]);
        }
        return OperationResult::success('intake_rolled_back', [
            'run_id' => $runId,
            'removed' => $removed,
        ]);
    }

    /** @return array<string,array{products:list<int>, at:string}> */
    public function runs(): array
    {
        $stored = $this->options->get(self::RUNS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @param list<int> $created */
    public function rememberRun(string $runId, array $created): void
    {
        $runs = $this->runs();
        $existing = array_map('intval', $runs[$runId]['products'] ?? []);
        $runs[$runId] = [
            'products' => array_values(array_unique(array_merge($existing, $created))),
            'at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $this->options->set(self::RUNS_OPTION, $runs);
    }

    /** The reasons a product was not taken, each with its sentence. */
    public static function reasonSentences(): array
    {
        return CatalogueInventory::reasonSentences() + [
            'not_a_product' => 'این شناسه محصول ووکامرس نیست.',
            'already_linked_marketplace' => 'این محصول از قبل مال بازارگاه است.',
            'already_linked_observed' => 'این محصول از قبل یک ردیف «مشاهده‌شده» دارد؛ ورود دوباره ردیف دوم نمی‌سازد.',
            'already_linked_with_open_proposal' =>
                'این محصول ردیف بازارگاه دارد و روی آن پیشنهاد بی‌پاسخ هست. ردیف دست نمی‌خورد و پیشنهاد پاک نمی‌شود.',
            'assigned_vendor_is_not_a_vendor' =>
                'کاربری که به‌عنوان فروشنده نام برده شد، فروشندهٔ این بازارگاه نیست.',
            'vendor_unknown' => 'فروشندهٔ این محصول نامشخص است و این افزونه حدس نمی‌زند.',
            'price_not_an_integer' =>
                'قیمت این محصول عدد صحیح نیست. قیمت بازارگاه عدد صحیح است و گرد کردن پول ممنوع است.',
            'sku_already_used_in_this_shop' =>
                'این SKU در همین فروشگاه از قبل استفاده شده؛ اینکه کدام محصول واقعی است تصمیم یک انسان است.',
            'product_unreadable' => 'محصول از ووکامرس خوانده نشد.',
            'row_not_created' => 'ردیف بازارگاه ساخته نشد.',
        ];
    }

    private function hasOpenProposal(int $productId): bool
    {
        return $productId > 0 && $this->revisions?->pendingFor($productId) !== null;
    }

    /** @return array<string,mixed> the page row shape, for one id */
    private function rowFor(int $wcId): array
    {
        if ($wcId <= 0) {
            return [];
        }
        $page = $this->reader->productsAfter($wcId - 1, 1);
        $row = $page['rows'][0] ?? [];
        return ((int) ($row['wc_product_id'] ?? 0)) === $wcId ? $row : [];
    }

    /**
     * The purchase-quantity rule the product's own rows express.
     *
     * @return array{min:int, max:?int, step:int}
     */
    private function quantityOf(int $wcId): array
    {
        $rule = $this->reader->quantityRule($wcId);
        return [
            'min' => max(1, (int) ($rule['min'] ?? 1)),
            'max' => ($rule['max'] ?? null) === null ? null : max(0, (int) $rule['max']),
            'step' => max(1, (int) ($rule['step'] ?? 1)),
        ];
    }

    /** Grams from a kilogram string, exactly or not at all. */
    private function gramsOf(string $weight): int
    {
        $weight = trim($weight);
        if ($weight === '' || preg_match('/^\d+(\.\d{1,3})?$/', $weight) !== 1) {
            // Not expressible as whole grams — and the projector only writes
            // a weight when the row has one, so zero leaves the product's own
            // weight exactly where it is. The risk list names it.
            return 0;
        }
        return (int) round(((float) $weight) * 1000);
    }

    /** An exact integer, or null — never a round. */
    private function exactInteger(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^(\d+)(\.0+)?$/', $value, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function uniqueIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out;
    }

    private function actorId(): int
    {
        return $this->capabilities?->currentUserId() ?? 0;
    }
}
