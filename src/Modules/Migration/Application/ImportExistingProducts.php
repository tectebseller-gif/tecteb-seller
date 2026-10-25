<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryLinkReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;
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
        private readonly ?ProductRevisionRepositoryInterface $revisions = null,
        /**
         * The unit of work an intake is. Without one the intake REFUSES
         * rather than writing the first half: `alpha.55` wrote the row, then
         * the baseline, the brand and the gallery, and reported `imported`
         * whichever of the three failed — measured on its own bytes as
         * `imported=1 failed=0 reason=partly_written:baseline` with
         * `baseline=__empty__`. «نیمهٔ اولِ یک کار که تنها انجام شود، از
         * انجام‌نشدن هر دو نیمه بدتر است» (`alpha.49`).
         */
        private readonly ?TransactionInterface $transaction = null,
        /**
         * Asked exactly ONE question: which term is
         * `default_product_cat`?
         *
         * WooCommerce substitutes that term on any product saved with no
         * categories, so «this product is in Uncategorized» and «somebody
         * chose Uncategorized» look identical in `category_ids` — and
         * measured on `alpha.81`'s bytes the intake stored it as a real
         * decision: `row_none_category_key=15`. `CategoryLink` has told the
         * two apart since `alpha.81` (`DEFAULT_ONLY`) and the intake did not.
         *
         * Optional, and its absence is stated rather than relied on: without
         * it the default term is recorded like any other, which is exactly
         * `alpha.81`'s behaviour.
         */
        private readonly ?ProductCategoryLinkReaderInterface $links = null
    ) {
    }

    /**
     * The `product_cat` terms to RECORD for a product, read from what the
     * catalogue reported.
     *
     * The whole set, because the owner's instruction is that it survive —
     * «مجموعهٔ کامل دسته‌ها باید حفظ شود، حتی وقتی والد و فرزند هم‌زمان
     * متصل‌اند» — and because the single column could not hold it: measured
     * on `alpha.81`, a product with a parent and its child stored `244` and
     * recorded `245` nowhere, and the first acceptance then DELETED `245`
     * from WooCommerce.
     *
     * WooCommerce's own default term is excluded, for the reason
     * `CategoryLink` excludes it: it is a placeholder the platform writes,
     * not a category anybody chose. Excluded rather than filtered out of the
     * list, so «only Uncategorized» and «Uncategorized plus two real ones»
     * stay different facts.
     *
     * @param array<string,mixed> $detail
     * @return list<int>
     */
    private function categoryIdsFrom(array $detail): array
    {
        $default = $this->links?->defaultTermId() ?? 0;
        $ids = CategorySet::of((array) $detail['category_ids']);
        if ($default <= 0) {
            return $ids;
        }
        return array_values(array_filter($ids, static fn (int $id): bool => $id !== $default));
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
        $versions = [];
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
                $versions[(int) $outcome['product_id']] = (string) ($outcome['row_version'] ?? '');
                // Written after EVERY product rather than at the end of the
                // batch: a run killed by a timeout half way through has
                // created real rows, and a manifest that only records
                // finished runs cannot undo them (`ImportFromDokan`'s own
                // lesson, measured there).
                $this->rememberRun($runId, $created, $versions);
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
        // A STATUS THIS MARKETPLACE CANNOT HOLD is refused here, by name,
        // before the price is even looked at — because the alternative is to
        // pick the nearest status, and for `private` and `future` the nearest
        // one is «منتشرشده», which the first projection would act on.
        if (ProductStatus::forIntakeOf((string) $detail['status']) === null) {
            return [
                'verdict' => 'skip',
                'reason' => 'status_not_expressible',
                'status' => (string) $detail['status'],
                'vendor_user_id' => $vendor,
                'risks' => [],
            ];
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
        // THE STATUS, through the one total mapping — and a refusal for every
        // WooCommerce status this marketplace has no field for. `judge()` asks
        // the same question first, so reaching this is a state that arrived
        // between the two reads.
        $mirrored = ProductStatus::forIntakeOf((string) $detail['status']);
        if ($mirrored === null) {
            return [
                'verdict' => 'failed',
                'reason' => 'status_not_expressible',
                'status' => (string) $detail['status'],
            ];
        }
        // THREE REFUSALS BEFORE THE FIRST WRITE (`alpha.54`'s shape): no unit
        // of work, a caller who already opened one, or one that will not
        // open. Each is a refusal rather than a reason to do the first half.
        if ($this->transaction === null) {
            return ['verdict' => 'failed', 'reason' => 'intake_no_transaction'];
        }
        if ($this->transaction->inTransaction()) {
            return ['verdict' => 'failed', 'reason' => 'intake_caller_transaction'];
        }
        if (!$this->transaction->begin()) {
            return ['verdict' => 'failed', 'reason' => 'intake_not_atomic'];
        }
        $productId = $this->products->create(
            $vendorUserId,
            $details,
            // The MARKETPLACE row mirrors WooCommerce's publish state, and
            // that is a safety property rather than a nicety: the projector
            // writes `draft` for any status but `published`, so a row created
            // as a draft would take a live product off sale the first time
            // anybody projected it.
            //
            // ⛔ `alpha.66` added «mirroring means the first projection writes
            // the status the product already has» here, and that sentence is
            // true for `publish` and `draft` and FALSE for `pending` — the
            // one status with no lifecycle state of its own. The projection
            // wrote `draft` over it, measured. The row's value below is still
            // right; what the row ALSO needs is the separate record passed as
            // the last argument.
            //
            // Through `ProductStatus::forIntakeOf()` rather than a ternary —
            // see its docblock for why five statuses collapsing into one, and
            // two of them collapsing the wrong way, was the §1-د finding.
            $mirrored,
            // OBSERVED. Nothing follows from this row until somebody accepts
            // management explicitly.
            LinkOwnership::Observed,
            $wcId,
            $runId,
            '',
            // THE STATUS THE SHOP ACTUALLY HOLDS, recorded as observed — so
            // the projector knows it has never written this product's status
            // and leaves it alone. The marketplace row still carries its own
            // lifecycle value above (`pending` → «پیش‌نویس»), because the
            // lifecycle is a fixed six and `pending` is not one of them; what
            // this records is the separate fact that the SHOP's status is
            // somebody else's.
            //
            // Measured on `alpha.66`, which did not record it: a `pending`
            // product survived the intake, the acceptance and an ordinary
            // save, and the first projection wrote `draft` and dropped it out
            // of the catalogue and out of search.
            (string) $detail['status']
        );
        if ($productId === 0) {
            $this->transaction->rollback();
            return ['verdict' => 'failed', 'reason' => 'row_not_created'];
        }
        // The row, the agreement baseline, the brand and the gallery are ONE
        // intake. The baseline is the one that would hurt most on its own:
        // it is what the approval path compares against, so a row carrying an
        // empty one makes every field read as somebody else's change for
        // ever — and nothing on any page said so.
        foreach ($this->partsOf($productId, $detail) as $part => $write) {
            if (!$write()) {
                $this->transaction->rollback();
                return [
                    'verdict' => 'failed',
                    'reason' => 'intake_not_stored',
                    'part' => (string) $part,
                ];
            }
        }
        if (!$this->transaction->commit()) {
            return ['verdict' => 'failed', 'reason' => 'intake_not_committed'];
        }
        return [
            'verdict' => 'imported',
            'reason' => '',
            'product_id' => $productId,
            'vendor_user_id' => $vendorUserId,
            'row_version' => (string) ($this->products->find($productId)?->rowVersion ?? ''),
            // Said out loud: the attachments are whoever's they were. Nothing
            // was copied, because copying would make the first projection
            // change the product's picture — the opposite of what an intake
            // is for.
            'media_copied' => false,
        ];
    }

    /**
     * The three writes that go WITH the row, each as a named closure.
     *
     * One list, used by the intake and by the repair, so «what an intake
     * consists of» has a single answer. Two implementations of that list
     * would agree until the day they did not (`alpha.14`).
     *
     * @param array<string,mixed> $detail
     * @return array<string,callable():bool>
     */
    private function partsOf(int $productId, array $detail): array
    {
        $parts = [
            'baseline' => fn (): bool => $this->products->saveBaseline($productId, $this->baselineFrom($detail)),
        ];
        if ((string) $detail['brand'] !== '') {
            $parts['brand'] = fn (): bool => $this->products->setBrandTerm(
                $productId,
                (string) $detail['brand'],
                ((int) $detail['brand_term_id']) > 0 ? (int) $detail['brand_term_id'] : null
            );
        }
        $images = $this->imagesOf($detail);
        if ($images !== []) {
            $parts['images'] = fn (): bool => $this->products->saveImages(
                $productId,
                $images,
                (int) $detail['image_id']
            );
        }
        return $parts;
    }

    /** @param array<string,mixed> $detail @return list<int> */
    private function imagesOf(array $detail): array
    {
        return array_values(array_filter(array_map(
            'intval',
            array_merge(
                ((int) $detail['image_id']) > 0 ? [(int) $detail['image_id']] : [],
                (array) $detail['gallery_image_ids']
            )
        )));
    }

    /**
     * The intakes that are HALF WRITTEN, with the part each one is missing.
     *
     * An atomic intake cannot make one of these — but two things still can:
     * a row created by `alpha.55`, whose intake reported success with an
     * empty baseline, and a commit that fails after every part landed. So
     * «اتمیک یا دارای مسیر ترمیم صریح و قابل ادامه» is answered with both:
     * nothing new goes half written, and what already is can be named and
     * finished.
     *
     * Detected by COMPARING THE ROW WITH THE SHOP rather than by a new
     * column: the baseline is empty, or the shop has a brand the row has not,
     * or the shop has images the row has none of. That needs no migration and
     * it cannot go stale, because the question is asked of both sides at the
     * moment it is asked.
     *
     * @return list<array{product_id:int, wc_product_id:int, missing:list<string>}>
     */
    public function incomplete(int $limit = 200): array
    {
        $out = [];
        foreach ($this->products->observedFromImportRuns($limit) as $productId) {
            $missing = $this->missingParts($productId);
            if ($missing === []) {
                continue;
            }
            $product = $this->products->find($productId);
            $out[] = [
                'product_id' => $productId,
                'wc_product_id' => (int) ($product?->wcProductId ?? 0),
                'title' => (string) ($product?->details->title ?? ''),
                'missing' => $missing,
            ];
        }
        return $out;
    }

    /**
     * Finish one half-written intake — only the parts that are missing.
     *
     * One unit of work, like the intake itself, and it never touches a part
     * that is already there: a repair that rewrote the brand would undo a
     * manager's correction while claiming to fix a fault.
     */
    public function repair(int $productId): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('intake_repair_not_found', ['product_id' => $productId]);
        }
        if ($this->products->linkOwnership($productId) !== LinkOwnership::Observed) {
            // Accepted into marketplace management: not an intake's row any
            // more, and its values are now a decision rather than a snapshot.
            return OperationResult::failure('intake_repair_not_observed', ['product_id' => $productId]);
        }
        $wcId = (int) ($product->wcProductId ?? 0);
        $detail = $wcId > 0 ? $this->reader->detail($wcId) : [];
        if ($detail === []) {
            return OperationResult::failure('product_unreadable', ['product_id' => $productId]);
        }
        $missing = $this->missingParts($productId);
        if ($missing === []) {
            return OperationResult::failure('intake_repair_nothing', ['product_id' => $productId]);
        }
        if ($this->transaction === null || $this->transaction->inTransaction()) {
            return OperationResult::failure('intake_repair_no_transaction', ['product_id' => $productId]);
        }
        if (!$this->transaction->begin()) {
            return OperationResult::failure('intake_repair_not_atomic', ['product_id' => $productId]);
        }
        $parts = $this->partsOf($productId, $detail);
        foreach ($missing as $part) {
            $write = $parts[$part] ?? null;
            if ($write === null || !$write()) {
                $this->transaction->rollback();
                return OperationResult::failure('intake_repair_not_stored', [
                    'product_id' => $productId,
                    'part' => $part,
                ]);
            }
        }
        if (!$this->transaction->commit()) {
            return OperationResult::failure('intake_repair_not_committed', ['product_id' => $productId]);
        }
        $this->audit->log(AuditEventCatalog::CATALOGUE_INTAKE, $this->actorId(), 'product', (string) $productId, [
            'repaired' => implode(',', $missing),
            'product_id' => $productId,
        ]);
        return OperationResult::success('intake_repaired', [
            'product_id' => $productId,
            'parts' => implode('، ', $missing),
        ]);
    }

    /**
     * WHERE THE ROW AND THE SHOP NOW DISAGREE — field by field, both values.
     *
     * An intake is a snapshot of WooCommerce, and acceptance is a step a
     * manager sleeps on. In between, somebody changes the price. `alpha.55`
     * accepted the product and the next projection wrote the SNAPSHOT'S price
     * back over it: measured on its own bytes, the shop at `480000` before
     * acceptance and `450000` after the first sync, with nothing said to
     * anybody. The risk acknowledgement did not cover it and could not — that
     * list is about fields this marketplace cannot express, not about a value
     * that MOVED.
     *
     * Compared against what the row WOULD WRITE, so the question asked is the
     * one that matters: «if this is accepted, what changes in the shop?»
     *
     * @return array<string,array{row:string, shop:string, label:string}>
     */
    public function driftOf(int $productId): array
    {
        $product = $this->products->find($productId);
        $wcId = (int) ($product?->wcProductId ?? 0);
        if ($product === null || $wcId <= 0) {
            return [];
        }
        $detail = $this->reader->detail($wcId);
        if ($detail === []) {
            // A read that failed is not «they agree» (`alpha.49`). The caller
            // is told by `driftReadable()` and refuses rather than accepting
            // on the strength of a read that did not happen.
            return [];
        }
        $shop = $this->detailsFrom($detail);
        if ($shop === null) {
            return [];
        }
        $row = $product->details;
        $fields = [
            'title' => ['عنوان', $row->title, $shop->title],
            'price' => ['قیمت', (string) $row->priceMinor, (string) $shop->priceMinor],
            'sale_price' => ['قیمت فروش ویژه', (string) ($row->salePriceMinor ?? ''), (string) ($shop->salePriceMinor ?? '')],
            'sku' => ['SKU', $row->sku, $shop->sku],
            'stock' => ['موجودی', (string) $row->stock, (string) $shop->stock],
            'short_description' => ['توضیح کوتاه', $row->shortDescription, $shop->shortDescription],
            'description' => ['توضیحات کامل', (string) $row->description, (string) $shop->description],
            'brand' => ['برند', $row->brand, $shop->brand],
            'category' => ['دسته', $row->categoryKey, $shop->categoryKey],
            'min_purchase' => ['حداقل خرید', (string) $row->minPurchase, (string) $shop->minPurchase],
            'max_purchase' => ['حداکثر خرید', (string) $row->maxPurchase, (string) $shop->maxPurchase],
            'purchase_step' => ['گام خرید', (string) $row->purchaseStep, (string) $shop->purchaseStep],
            'tax_class' => ['کلاس مالیات', $row->taxClass, $shop->taxClass],
            'weight' => ['وزن (گرم)', (string) $row->weightGrams, (string) $shop->weightGrams],
            'type' => ['نوع محصول', $row->type, $shop->type],
        ];
        $out = [];
        foreach ($fields as $key => [$label, $rowValue, $shopValue]) {
            if (trim((string) $rowValue) === trim((string) $shopValue)) {
                continue;
            }
            $shown = mb_substr(trim((string) $shopValue), 0, 60);
            $out[$key] = [
                'label' => $label,
                'row' => mb_substr(trim((string) $rowValue), 0, 60),
                'shop' => $shown,
                // What a form carries back to prove it is acknowledging THIS
                // value. A fingerprint rather than the text itself, because a
                // description's first sixty characters go through
                // `sanitize_text_field()` on the way in and come back with
                // their newlines collapsed — and a comparison that fails for
                // that reason would refuse an honest acknowledgement for ever.
                // Hex survives any sanitiser, and `alpha.55` settled that a
                // permission is stored as the FINGERPRINT OF THE VALUE it was
                // given for.
                'fingerprint' => self::fingerprint($shown),
            ];
        }
        return $out;
    }

    /**
     * The fingerprint of one shown value.
     *
     * Public and static so the screen and the guard cannot compute it two
     * ways — the whole mistake `alpha.55` found in its own new code was a
     * permission that did not describe the value it was given for.
     */
    public static function fingerprint(string $value): string
    {
        return substr(hash('sha256', trim($value)), 0, 32);
    }

    /** Whether the drift question could be asked at all. */
    public function driftReadable(int $productId): bool
    {
        $product = $this->products->find($productId);
        $wcId = (int) ($product?->wcProductId ?? 0);
        if ($wcId <= 0) {
            return false;
        }
        $detail = $this->reader->detail($wcId);
        return $detail !== [] && $this->detailsFrom($detail) !== null;
    }

    /**
     * Take the snapshot again — WooCommerce's values as they are NOW.
     *
     * The one-click way out of a drift: the row and the agreement baseline are
     * both rewritten from the shop, under the row's own version
     * (`updateDetails($id, $details, $expectedVersion)`), so a vendor or
     * manager writing to the same row in the same moment loses nothing
     * silently — the refresh is refused instead. And after the write the shop
     * is READ AGAIN: a value that moved during the refresh would otherwise
     * leave a row that is stale the instant it was made fresh.
     */
    public function refreshFrom(int $productId): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('intake_refresh_not_found', ['product_id' => $productId]);
        }
        if ($this->products->linkOwnership($productId) !== LinkOwnership::Observed) {
            return OperationResult::failure('intake_refresh_not_observed', ['product_id' => $productId]);
        }
        $wcId = (int) ($product->wcProductId ?? 0);
        $detail = $wcId > 0 ? $this->reader->detail($wcId) : [];
        if ($detail === []) {
            return OperationResult::failure('product_unreadable', ['product_id' => $productId]);
        }
        $details = $this->detailsFrom($detail);
        if ($details === null) {
            return OperationResult::failure('price_not_an_integer', ['product_id' => $productId]);
        }
        if ($this->transaction === null || $this->transaction->inTransaction()) {
            return OperationResult::failure('intake_refresh_no_transaction', ['product_id' => $productId]);
        }
        if (!$this->transaction->begin()) {
            return OperationResult::failure('intake_refresh_not_atomic', ['product_id' => $productId]);
        }
        if (!$this->products->updateDetails($productId, $details, $product->rowVersion)) {
            $this->transaction->rollback();
            // The row moved while this was being prepared. Zero rows from a
            // version-guarded `UPDATE` is a lost race, not «it already said
            // that» (`alpha.49`).
            return OperationResult::failure('intake_refresh_row_moved', ['product_id' => $productId]);
        }
        if (!$this->products->saveBaseline($productId, $this->baselineFrom($detail))) {
            $this->transaction->rollback();
            return OperationResult::failure('intake_refresh_not_stored', [
                'product_id' => $productId,
                'part' => 'baseline',
            ]);
        }
        if (!$this->transaction->commit()) {
            return OperationResult::failure('intake_refresh_not_committed', ['product_id' => $productId]);
        }
        $after = $this->driftOf($productId);
        if ($after !== []) {
            return OperationResult::failure('intake_refresh_moved_again', [
                'product_id' => $productId,
                'fields' => implode('، ', array_column($after, 'label')),
            ]);
        }
        $this->audit->log(AuditEventCatalog::CATALOGUE_INTAKE, $this->actorId(), 'product', (string) $productId, [
            'refreshed_from_storefront' => $wcId,
            'product_id' => $productId,
        ]);
        return OperationResult::success('intake_refreshed', ['product_id' => $productId]);
    }

    /** @return list<string> */
    private function missingParts(int $productId): array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return [];
        }
        $wcId = (int) ($product->wcProductId ?? 0);
        if ($wcId <= 0) {
            return [];
        }
        $detail = $this->reader->detail($wcId);
        if ($detail === []) {
            // A read that failed is not «nothing is missing» (`alpha.49`):
            // it is no answer, and the honest shape of no answer here is an
            // empty list with the product left out of the repair list rather
            // than repaired from values nobody read.
            return [];
        }
        $missing = [];
        if ($product->baseline === null) {
            $missing[] = 'baseline';
        }
        if ((string) $detail['brand'] !== '' && trim($product->details->brand) === '') {
            $missing[] = 'brand';
        }
        if ($this->imagesOf($detail) !== [] && $product->imageIds === [] && $product->mainImageId === 0) {
            $missing[] = 'images';
        }
        return $missing;
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
            // THE SINGLE KEY ONLY WHEN THERE IS EXACTLY ONE CATEGORY.
            //
            // Until `alpha.81` this stored `category_ids[0]` — a choice
            // nobody made, already in the row before any manager acted, and
            // the thing the first acceptance then wrote back as a REPLACE.
            // «اولین دسته را خودسرانه انتخاب نکن» and «انتخاب یک دسته به جای
            // دسته‌های فعلی تأیید نمی‌شود», so a product with two categories
            // gets `''` here — «no single key» — and its relationship is
            // recorded in full by `categoryIds` below.
            //
            // Which category's spec template applies to such a product is the
            // owner's open decision, not this line's
            // (`docs/open-decisions-alpha82.md` §1).
            categoryKey: CategorySet::soleKey($this->categoryIdsFrom($detail)),
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
            purchaseStep: $quantity['step'],
            // The relationship itself, whole.
            categoryIds: $this->categoryIdsFrom($detail)
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

        if ($this->transaction === null || $this->transaction->inTransaction()) {
            return OperationResult::failure('intake_rollback_no_transaction', ['run_id' => $runId]);
        }
        $versions = $this->versionsOf($runId);
        $removed = 0;
        $kept = [];
        $resolved = [];
        foreach ($productIds as $productId) {
            if ($this->products->linkOwnership($productId) === LinkOwnership::Marketplace) {
                // Accepted. Not an intake's row any more, and it is RESOLVED:
                // no later retry can do anything about it, so its stamp goes
                // and the manifest may forget it.
                $kept[] = $productId . ':accepted';
                $resolved[] = $productId;
                $this->products->stampImportRun($productId, '');
                continue;
            }
            if ($this->hasOpenProposal($productId)) {
                // Somebody asked for something and nobody has answered. The
                // stamp STAYS, so answering the proposal and rolling back
                // again is a road rather than a dead end.
                $kept[] = $productId . ':open_proposal';
                continue;
            }
            $drift = $this->editedSinceIntake($productId, $versions);
            if ($drift !== '') {
                // «ویرایش جدید ... نباید با بازگشت از بین برود». The row has
                // moved since the intake wrote it, so deleting it would take
                // somebody's edit with it — and that is the one thing a
                // rollback cannot undo.
                $kept[] = $productId . ':' . $drift;
                continue;
            }
            $part = $this->removeOne($productId, $runId);
            if ($part === '') {
                $removed++;
                $resolved[] = $productId;
                continue;
            }
            // NOTHING is cleared here. `alpha.55` cleared the stamp on this
            // path AND deleted the manifest, so the two things a retry needs
            // were both gone: measured on its own bytes, `stamp_after=__empty__
            // manifest_after=__absent__`, and the next attempt answered
            // `intake_run_unknown` about a row that was still there.
            $kept[] = $productId . ':not_removed:' . $part;
        }

        // The manifest survives while anything is unresolved — and keeps only
        // the unresolved products, so a second rollback is about what is left
        // rather than about rows that have already gone.
        $unresolved = array_values(array_diff($productIds, $resolved));
        if ($unresolved === []) {
            unset($runs[$runId]);
        } else {
            $runs[$runId] = [
                'products' => $unresolved,
                'versions' => array_intersect_key($versions, array_flip($unresolved)),
                'at' => (string) ($manifest['at'] ?? $this->clock->now()->format('Y-m-d H:i:s')),
            ];
        }
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

    /**
     * One row and everything of it, as ONE unit of work.
     *
     * The transaction is what makes a named part an undone unit rather than a
     * deleted product with its images still in the table — `alpha.55` read
     * none of those answers at all.
     *
     * @return string `''` when it went; otherwise the part that would not
     */
    private function removeOne(int $productId, string $runId): string
    {
        if ($this->transaction === null) {
            return 'no_transaction';
        }
        if (!$this->transaction->begin()) {
            return 'not_atomic';
        }
        $part = $this->products->deleteObservedFromRun($productId, $runId);
        if ($part !== '') {
            $this->transaction->rollback();
            return $part;
        }
        if (!$this->transaction->commit()) {
            return 'not_committed';
        }
        return '';
    }

    /**
     * Whether this row has been edited since the intake created it.
     *
     * `row_version` is the answer because every guarded write bumps it
     * (`alpha.14`), and the intake records the version it finished with. Two
     * different answers, not one:
     *
     *  - `changed_since_intake` — the version moved, so somebody wrote to it;
     *  - `version_unknown` — no version was recorded, which is every row an
     *    `alpha.55` intake made. Deleting a row we cannot prove untouched is
     *    exactly the loss a rollback exists to prevent, so it is KEPT and
     *    named, and the explicit per-product `discard()` is the road out.
     *
     * @param array<int,string> $versions
     */
    private function editedSinceIntake(int $productId, array $versions): string
    {
        $recorded = (string) ($versions[$productId] ?? '');
        if ($recorded === '') {
            return 'version_unknown';
        }
        $now = (string) ($this->products->find($productId)?->rowVersion ?? '');
        return $now === $recorded ? '' : 'changed_since_intake';
    }

    /**
     * Remove ONE observed intake row the manager names, after reading why the
     * rollback kept it.
     *
     * The escape from `version_unknown` and `changed_since_intake`, and the
     * only thing in this class that removes a row the version guard refused.
     * It still refuses the two cases no acknowledgement can cover: a row
     * accepted into marketplace management, and a row carrying an unanswered
     * proposal.
     */
    public function discard(int $productId, string $runId): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        if ($this->products->linkOwnership($productId) === LinkOwnership::Marketplace) {
            return OperationResult::failure('intake_discard_accepted', ['product_id' => $productId]);
        }
        if ($this->hasOpenProposal($productId)) {
            return OperationResult::failure('intake_discard_open_proposal', ['product_id' => $productId]);
        }
        $part = $this->removeOne($productId, $runId);
        if ($part !== '') {
            return OperationResult::failure('intake_discard_not_removed', [
                'product_id' => $productId,
                'part' => $part,
            ]);
        }
        $runs = $this->runs();
        if (isset($runs[$runId])) {
            $left = array_values(array_diff(array_map('intval', $runs[$runId]['products'] ?? []), [$productId]));
            if ($left === []) {
                unset($runs[$runId]);
            } else {
                $runs[$runId]['products'] = $left;
                $runs[$runId]['versions'] = array_intersect_key(
                    $this->versionsOf($runId),
                    array_flip($left)
                );
            }
            $this->options->set(self::RUNS_OPTION, $runs);
        }
        $this->audit->log(AuditEventCatalog::CATALOGUE_INTAKE_ROLLED_BACK, $this->actorId(), 'product', (string) $productId, [
            'run_id' => $runId,
            'discarded' => $productId,
        ]);
        return OperationResult::success('intake_discarded', ['product_id' => $productId]);
    }

    /**
     * The row versions one run recorded, read tolerantly.
     *
     * `alpha.55` wrote only a list of ids, so a manifest with no `versions`
     * key is the ordinary state of an upgraded site — and it answers
     * `version_unknown` rather than pretending to know.
     *
     * @return array<int,string>
     */
    private function versionsOf(string $runId): array
    {
        $stored = $this->runs()[$runId]['versions'] ?? [];
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $productId => $version) {
            $out[(int) $productId] = (string) $version;
        }
        return $out;
    }

    /** @return array<string,array{products:list<int>, at:string}> */
    public function runs(): array
    {
        $stored = $this->options->get(self::RUNS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Record what this run created — and the row version each product
     * FINISHED the intake with.
     *
     * The version is what lets a rollback tell «nobody has touched this»
     * from «somebody edited it», and it has to be recorded here because
     * afterwards there is nothing to compare against. A manifest holding only
     * ids can answer neither question (`alpha.55` held only ids).
     *
     * @param list<int> $created
     * @param array<int,string> $versions product id => its version after the intake
     */
    public function rememberRun(string $runId, array $created, array $versions = []): void
    {
        $runs = $this->runs();
        $existing = array_map('intval', $runs[$runId]['products'] ?? []);
        $knownVersions = $this->versionsOf($runId);
        foreach ($versions as $productId => $version) {
            if ((string) $version !== '') {
                $knownVersions[(int) $productId] = (string) $version;
            }
        }
        $runs[$runId] = [
            'products' => array_values(array_unique(array_merge($existing, $created))),
            'versions' => $knownVersions,
            'at' => (string) ($runs[$runId]['at'] ?? $this->clock->now()->format('Y-m-d H:i:s')),
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
            'intake_no_transaction' =>
                'ورود یک واحد کار است و این نصب تراکنش در اختیار نگذاشت؛ پس هیچ‌چیز نوشته نشد.',
            'intake_caller_transaction' =>
                'تراکنشی از قبل باز بود. ورود تراکنش خودش را باز می‌کند و روی تراکنش دیگری نمی‌نویسد.',
            'intake_not_atomic' => 'تراکنش باز نشد، پس ورود انجام نشد — و نیمی از آن هم انجام نشد.',
            'intake_not_stored' =>
                'یکی از اجزای ورود ذخیره نشد و کل ورود برگشت. هیچ ردیف نیمه‌کاره‌ای نماند؛ دوباره تلاش کنید.',
            'intake_not_committed' => 'ورود نوشته شد ولی نهایی نشد. فهرست «ورودهای نیمه‌کاره» آن را نام می‌برد.',
            'intake_rollback_no_transaction' =>
                'بازگشت یک واحد کار است و تراکنش در اختیار نبود؛ هیچ ردیفی حذف نشد.',
            'version_unknown' =>
                'نسخهٔ این ردیف هنگام ورود ثبت نشده (ورودهای پیش از این نسخه)، پس بازگشت نمی‌تواند ثابت کند '
                . 'کسی دستش نزده. ردیف نگه داشته شد؛ برای حذفش از دکمهٔ «حذف این ردیف» استفاده کنید.',
            'changed_since_intake' =>
                'این ردیف پس از ورود ویرایش شده. بازگشت ویرایش را با خودش می‌برد، پس ردیف نگه داشته شد؛ '
                . 'برای حذفش از دکمهٔ «حذف این ردیف» استفاده کنید.',
            'accepted' => 'مدیریت این محصول پذیرفته شده؛ دیگر ردیفِ یک ورود نیست و دست نمی‌خورد.',
            'open_proposal' => 'روی این محصول پیشنهاد بی‌پاسخ هست. اول به پیشنهاد جواب بدهید، بعد بازگشت.',
            'intake_repaired' => 'اجزای جامانده نوشته شدند.',
            'intake_repair_nothing' => 'چیزی برای تکمیل نیست؛ این ورود کامل است.',
            'intake_repair_not_observed' =>
                'مدیریت این محصول پذیرفته شده، پس مقدارهایش تصمیم‌اند نه snapshot؛ تکمیل انجام نمی‌شود.',
            'intake_repair_not_found' => 'ردیفی با این شناسه نیست.',
            'intake_repair_no_transaction' => 'تکمیل یک واحد کار است و تراکنش در اختیار نبود.',
            'intake_repair_not_atomic' => 'تراکنش باز نشد، پس تکمیل انجام نشد.',
            'intake_repair_not_stored' => 'یکی از اجزا ذخیره نشد و تکمیل برگشت. ردیف دست‌نخورده ماند.',
            'intake_repair_not_committed' => 'تکمیل نهایی نشد.',
            'intake_discarded' => 'ردیف ورود حذف شد. محصول ووکامرس دست‌نخورده است.',
            'intake_discard_accepted' => 'مدیریت این محصول پذیرفته شده؛ حذف ردیف از این راه انجام نمی‌شود.',
            'intake_discard_open_proposal' =>
                'روی این محصول پیشنهاد بی‌پاسخ هست؛ حذف ردیف پیشنهاد را هم می‌برد.',
            'intake_discard_not_removed' => 'ردیف حذف نشد و چیزی نیمه‌کاره نماند.',
            'intake_refreshed' => 'مقدارهای ردیف از ووکامرس تازه شد؛ حالا پذیرش مدیریت چیزی را عقب نمی‌برد.',
            'intake_refresh_not_found' => 'ردیفی با این شناسه نیست.',
            'intake_refresh_not_observed' =>
                'مدیریت این محصول پذیرفته شده؛ از این پس مقدارهای ردیف تصمیم‌اند و با snapshot تازه نمی‌شوند.',
            'intake_refresh_row_moved' =>
                'ردیف در همین لحظه جای دیگری عوض شد، پس تازه‌سازی انجام نشد. دوباره تلاش کنید.',
            'intake_refresh_not_stored' => 'تازه‌سازی ذخیره نشد و ردیف دست‌نخورده ماند.',
            'intake_refresh_no_transaction' => 'تازه‌سازی یک واحد کار است و تراکنش در اختیار نبود.',
            'intake_refresh_not_atomic' => 'تراکنش باز نشد، پس تازه‌سازی انجام نشد.',
            'intake_refresh_not_committed' => 'تازه‌سازی نهایی نشد.',
            'intake_refresh_moved_again' =>
                'در همان لحظهٔ تازه‌سازی، فروشگاه باز هم عوض شد. یک بار دیگر تازه‌سازی کنید.',
            'storefront_moved' =>
                'فروشگاه پس از ورود این محصول عوض شده. پذیرش مدیریت با مقدارهای ردیف، تغییر فروشگاه را '
                . 'برمی‌گرداند — پس یا «تازه‌سازی از فروشگاه» را بزنید، یا با دیدن هر دو مقدار صریح تأیید کنید.',
            'storefront_moved_again' =>
                'فروشگاه بین چاپ این صفحه و زدن دکمه هم عوض شد. صفحه را تازه کنید و دوباره نگاه کنید.',
            'storefront_unreadable' =>
                'مقدارهای فعلی محصول از ووکامرس خوانده نشد، پس معلوم نیست پذیرش چه چیزی را عوض می‌کند.',
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

    /**
     * An exact integer, or null — never a round.
     *
     * An EMPTY price answers `0`, and that is a decision rather than a
     * shortcut: `price_minor` is `BIGINT NOT NULL DEFAULT 0` and
     * `ProductDetails::hasPrice()` reads nought as «قیمتی ثبت نشده», so
     * nought is this model's own representation of absence — not an invented
     * price. What `alpha.65` got wrong was downstream: the projector wrote
     * that nought out to the shop as if it were a price, and from this round
     * it holds the field instead. «قیمت خالی با صفر متفاوت است» is honoured by
     * the two of them together: the row says «no price» and nothing turns it
     * into a number on somebody's product.
     */
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
