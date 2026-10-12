<?php
/**
 * Three findings on the `alpha.48` bytes, measured on real WooCommerce.
 *
 * The owner read `alpha.48` before installing it and named three places where
 * a failure is reported as a success or a guard does not guard:
 *
 *   1. **A proposal that is closed before its replacement exists.**
 *      `ManageProducts::proposeStock()` and `saveAsRevision()` both супersede
 *      the pending proposal and THEN create the new one — two statements. A
 *      refused `create()` leaves the product with no pending proposal at all,
 *      so the title or brand the vendor was waiting on is gone, the product
 *      drops out of the review queue, and the only thing the vendor is told is
 *      «ذخیره نشد».
 *   2. **A structure that could not be read, and a column of the wrong TYPE.**
 *      `ProductTableShape::unsoundColumns()` answers with an empty list both
 *      when the table is sound and when `information_schema` could not be
 *      read, and `isSound()` says «yes» to both. And the shape it checks is
 *      nullability and default — not the type — so a column of the right name
 *      and the wrong type passes, and on this server (no `STRICT_TRANS_TABLES`,
 *      measured) a write into it stores a different number than it was given.
 *   3. **A stock approval recorded without a valid result.**
 *      `SyncCatalog::pushStock()` returns true when the read-back differs from
 *      what was asked (it mirrors the difference) and when the read-back fails
 *      altogether, so `approveRevision()` marks the proposal APPROVED about a
 *      shop that holds another number. And the manager's «apply it anyway»
 *      skips the baseline comparison entirely, so a change that arrived AFTER
 *      the review page was rendered is applied over without ever being shown.
 *
 * Every measuring verb runs in ITS OWN `wp` invocation — a verb that arranges
 * and measures in one process measures a process whose memos are warm
 * (`alpha.46`) — and each failure is injected through a mechanism WordPress or
 * WooCommerce itself offers (`the query filter`, `woocommerce_stock_amount`,
 * `woocommerce_product_get_stock_quantity`), never a mock.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha48-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;

// --- refuses to run anywhere but a disposable install ---------------------
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_P49_VENDOR = 8910;
const TMC_P49_CATEGORY = 'tmc-p49-swab';
const TMC_P49_SKU = 'SKU-ALPHA49';
const TMC_P49_DRAFT_SKU = 'SKU-ALPHA49-D';

$verb = (string) ($args[0] ?? 'reset');
$c = Bootstrap::container();
$adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adminId);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$products = new DbProductRepository($db, $clock);
/** @var ProductRevisionRepositoryInterface $revisions */
$revisions = $c->get(ProductRevisionRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);
/** @var ManageProducts $manage */
$manage = $c->get(ManageProducts::class);
/** @var ReviewProducts $review */
$review = $c->get(ReviewProducts::class);

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};
$say('probe', 'ran');

(new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
    ->upsertProfile(TMC_P49_VENDOR, 'تجهیزات آزمون ۴۹', true, false);

$productsTable = T::table($db, T::PRODUCTS);
$revisionsTable = T::table($db, T::REVISIONS);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P49_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (
    string $title = 'ماسک آزمون ۴۹',
    int $stock = 9,
    string $sku = TMC_P49_SKU
): ProductDetails {
    $named = [
        'title' => $title,
        'type' => 'simple',
        'categoryKey' => TMC_P49_CATEGORY,
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 450000,
        'sku' => $sku,
        'stock' => $stock,
        'minPurchase' => 1,
        'maxPurchase' => 20,
        'description' => 'متن کامل فروشنده',
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

/**
 * A published, projected product whose shop stock is exactly `$stock`.
 *
 * Reset the way every round resets: the pending proposal is closed and the
 * shop's own number is set here and only here, so a verb asking «did the shop
 * move?» is asking about its own arrangement. The gallery is part of the
 * fixture because `ProductReadiness` requires one and an approval writes the
 * proposal and THEN asks (`alpha.48`'s fixture lesson).
 *
 * @return array{0:int,1:int}
 */
$published = static function (int $stock = 9)
use ($products, $findBySku, $detailsOf, $revisions, $catalog): array {
    $id = $findBySku(TMC_P49_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P49_VENDOR, $detailsOf('ماسک آزمون ۴۹', $stock), ProductStatus::Published);
    } else {
        if (!$products->updateDetails($id, $detailsOf('ماسک آزمون ۴۹', $stock), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
        $products->updateInventory($id, $stock, TMC_P49_SKU, 1, 20, 1);
    }
    while (($pending = $revisions->pendingFor($id)) !== null) {
        $revisions->decide($pending->id, ProductRevision::SUPERSEDED, 0, '');
    }
    if ($products->find($id)?->imageIds === []) {
        $attachmentId = (int) (get_posts([
            'post_type' => 'attachment',
            'numberposts' => 1,
            'fields' => 'ids',
        ])[0] ?? 0);
        if ($attachmentId === 0) {
            $file = wp_upload_dir()['path'] . '/tmc-alpha49.png';
            file_put_contents($file, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
            ));
            $attachmentId = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'tmc-alpha49',
                'post_status' => 'inherit',
            ], $file);
        }
        if ($attachmentId > 0) {
            $products->saveImages($id, [$attachmentId], $attachmentId);
        }
    }
    $catalog->publish($id);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    $shopProduct = wc_get_product($wcId);
    if ($shopProduct instanceof \WC_Product) {
        $shopProduct->set_manage_stock(true);
        $shopProduct->set_stock_quantity($stock);
        $shopProduct->save();
    }
    return [$id, $wcId];
};

/**
 * A DRAFT of its own, with its own SKU.
 *
 * Its own, because a draft's stock still applies at once — which is what the
 * two structure findings need to measure — and because a second row carrying
 * the published product's SKU would be refused by the unique index and the
 * verb would report a defect about a fixture.
 */
$draft = static function () use ($products, $findBySku, $detailsOf): int {
    $id = $findBySku(TMC_P49_DRAFT_SKU);
    if ($id === 0) {
        $id = $products->create(
            TMC_P49_VENDOR,
            $detailsOf('پیش‌نویس آزمون ۴۹', 9, TMC_P49_DRAFT_SKU),
            ProductStatus::Draft
        );
    }
    return $id;
};

/** The shop's own number, with «could not read» told apart from a value. */
$shopStock = static function (int $wcId): string {
    $product = wc_get_product($wcId);
    if (!$product instanceof \WC_Product) {
        return '__unreadable__';
    }
    $stock = $product->get_stock_quantity();
    return $stock === null ? '__null__' : (string) (int) $stock;
};

/** Move the shop's stock the way a sale or a manager's correction does. */
$moveShopStock = static function (int $wcId, int $to): void {
    $product = wc_get_product($wcId);
    if ($product instanceof \WC_Product) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($to);
        $product->save();
        // The object cache holds the product; the next request would not see
        // it otherwise, and neither would the same request's second read.
        wc_delete_product_transients($wcId);
    }
};

/** The vendor's own inventory save, through whichever signature this build has. */
$vendorSave = static function (int $id, int $stock, string $sku = TMC_P49_SKU) use ($manage): array {
    $method = new \ReflectionMethod(ManageProducts::class, 'updateInventory');
    $arguments = [TMC_P49_VENDOR, TMC_P49_VENDOR, $id, $stock, $sku, 1, 20];
    if ($method->getNumberOfParameters() >= 8) {
        $arguments[] = 1;
    }
    $result = $manage->updateInventory(...$arguments);
    return [$result->ok ? 'ok' : 'failed', $result->code, $result->context];
};

/**
 * The manager's approval, through whichever signature this build has.
 *
 * `alpha.48` takes three parameters and has nowhere to carry «the number I was
 * shown»: that absence IS the fourth finding, so the probe asks rather than
 * assumes and prints which build it is talking to.
 */
$approve = static function (int $revisionId, bool $accept = false, ?int $seenStock = null)
use ($review): array {
    $method = new \ReflectionMethod(ReviewProducts::class, 'approveRevision');
    $carriesSeen = $method->getNumberOfParameters() >= 4;
    $arguments = [$revisionId, []];
    if ($method->getNumberOfParameters() >= 3) {
        $arguments[] = $accept;
    }
    if ($carriesSeen) {
        $arguments[] = $seenStock;
    }
    $result = $review->approveRevision(...$arguments);
    return [$result->ok ? 'ok' : 'failed', $result->code, $result->context, $carriesSeen ? 'yes' : 'no'];
};

/** What the structure check answers, and whether it can say «unknown». */
$shapeAnswer = static function () use ($products): array {
    // FORGET FIRST. The fixture's own writes have already asked this object,
    // so without it the answer printed here is the memo from before the
    // failure was injected — a measurement of the arrangement rather than of
    // the question (`alpha.46`).
    $shape = (new \ReflectionClass(DbProductRepository::class))->getMethod('shape');
    $shape->setAccessible(true);
    $object = $shape->invoke($products);
    if (method_exists($object, 'forget')) {
        $object->forget();
    }
    $unsound = $object->unsoundColumns();
    $problem = method_exists($object, 'lastProblem') ? (string) $object->lastProblem() : '__no_method__';
    $sound = method_exists($object, 'isSound') ? ($object->isSound() ? 'yes' : 'no') : '__no_method__';
    return [$unsound === [] ? '' : implode(',', $unsound), $sound, $problem];
};

/** A pending proposal standing in for the vendor's earlier, unanswered edit. */
$earlierProposal = static function (int $id, string $title) use ($revisions, $products): int {
    $product = $products->find($id);
    $payload = [
        'details' => [
            'title' => $title,
            'type' => 'simple',
            'categoryKey' => TMC_P49_CATEGORY,
            'brand' => '',
            'shortDescription' => 'یک جمله',
            'priceMinor' => 450000,
            'sku' => TMC_P49_SKU,
            'stock' => (int) ($product?->details->stock ?? 9),
            'minPurchase' => 1,
            'maxPurchase' => 20,
            'description' => 'متن کامل فروشنده',
        ],
        'specs' => [],
        'images' => $product?->imageIds ?? [],
        'main_image_id' => (int) ($product?->mainImageId ?? 0),
    ];
    return $revisions->create($id, TMC_P49_VENDOR, $payload);
};

$inNeedsReview = static function (int $id) use ($products): string {
    $rows = $products->forManager(null, '', 200, 0, 0, null, true);
    foreach ($rows as $row) {
        if ((int) $row->id === $id) {
            return 'yes';
        }
    }
    return 'no';
};

// -------------------------------------------------------------- the verbs

switch ($verb) {
    /**
     * FINDING 1: the pending proposal is closed and its replacement refused.
     *
     * The refusal is real and is injected the way WordPress itself allows one
     * — `wpdb`'s own `query` filter, pointing that one INSERT at a table that
     * is not there. Nothing else in the request is touched, so the audit row,
     * the product row and every read still work.
     */
    case 'proposal-lost':
        [$id, $wcId] = $published(9);
        $earlier = $earlierProposal($id, 'ماسک آزمون ۴۹ — عنوان تازهٔ فروشنده');
        $say('product_id', $id);
        $say('earlier_revision', $earlier);
        $say('pending_before', (string) ($revisions->pendingFor($id)?->id ?? 0));
        $say('in_needs_review_before', $inNeedsReview($id));
        $target = $revisionsTable;
        $refuseInsert = static function (string $sql) use ($target): string {
            if (stripos($sql, 'INSERT INTO `' . $target . '`') === 0) {
                return str_ireplace('`' . $target . '`', '`' . $target . '_not_here`', $sql);
            }
            return $sql;
        };
        add_filter('query', $refuseInsert);
        [$ok, $code, $context] = $vendorSave($id, 4);
        $say('save', $ok);
        $say('save_code', $code);
        remove_filter('query', $refuseInsert);
        $after = $revisions->pendingFor($id);
        $say('pending_after', (string) ($after?->id ?? 0));
        $say('proposal_kept', $after !== null && $after->id === $earlier ? 'yes' : 'NO');
        $row = $GLOBALS['wpdb']->get_row(
            $GLOBALS['wpdb']->prepare('SELECT status FROM `' . $revisionsTable . '` WHERE id = %d', $earlier),
            ARRAY_A
        );
        $say('earlier_status', (string) ($row['status'] ?? '__gone__'));
        $say('earlier_title_still_asked', $after !== null
            && str_contains((string) ($after->payload['details']['title'] ?? ''), 'عنوان تازهٔ فروشنده') ? 'yes' : 'no');
        $say('in_needs_review_after', $inNeedsReview($id));
        break;

    /**
     * THE SAME FINDING, from the other side: no transaction, so nothing is
     * attempted.
     *
     * `START TRANSACTION` is pointed at a statement the server refuses, which
     * is what a build or a connection that cannot hold two statements together
     * looks like from inside the repository. The rule is `alpha.39`'s: a unit
     * of work that cannot be opened is a REFUSAL, not a reason to run the
     * first half and hope.
     *
     * On the `alpha.48` bytes there is no such question — it superseded and
     * inserted without ever asking — so this verb's answer on that tree is the
     * defect itself.
     */
    case 'proposal-not-atomic':
        [$id, $wcId] = $published(9);
        $earlier = $earlierProposal($id, 'ماسک آزمون ۴۹ — عنوان تازهٔ فروشنده');
        $say('product_id', $id);
        $say('pending_before', (string) ($revisions->pendingFor($id)?->id ?? 0));
        $refuseTransaction = static function (string $sql): string {
            if (stripos(trim($sql), 'START TRANSACTION') === 0) {
                return 'START TRANSACTION WITH SOMETHING THAT IS NOT SQL';
            }
            return $sql;
        };
        add_filter('query', $refuseTransaction);
        [$ok, $code, $context] = $vendorSave($id, 4);
        remove_filter('query', $refuseTransaction);
        $say('save', $ok);
        $say('save_code', $code);
        $say('reason', (string) ($context['reason'] ?? '__none__'));
        $say('context_proposal_kept', (string) ($context['proposal_kept'] ?? '__none__'));
        $after = $revisions->pendingFor($id);
        $say('pending_after', (string) ($after?->id ?? 0));
        $say('proposal_kept', $after !== null && $after->id === $earlier ? 'yes' : 'NO');
        $say('in_needs_review_after', $inNeedsReview($id));
        break;

    /**
     * FINDING 2a: the structure could not be read, and the write went ahead.
     *
     * The one read `ProductTableShape` makes is pointed at a table that is not
     * there, so `getResults()` comes back empty — which is exactly what a
     * failed read looks like from inside the class, and the class cannot tell
     * it from «the table has none of these columns».
     */
    case 'structure-unreadable':
        $draftId = $draft();
        $say('draft_id', $draftId);
        $breakRead = static function (string $sql): string {
            if (stripos($sql, 'information_schema.COLUMNS') !== false
                && stripos($sql, 'tmc_products') !== false) {
                return str_ireplace('information_schema.COLUMNS', 'information_schema.COLUMNS_NOT_HERE', $sql);
            }
            return $sql;
        };
        add_filter('query', $breakRead);
        [$unsound, $sound, $problem] = $shapeAnswer();
        $say('unsound_columns', $unsound === '' ? '__none__' : $unsound);
        $say('reads_as_sound', $sound);
        $say('problem', $problem === '' ? '__none__' : $problem);
        [$ok, $code] = $vendorSave($draftId, 77, TMC_P49_DRAFT_SKU);
        $say('save', $ok);
        $say('save_code', $code);
        remove_filter('query', $breakRead);
        $say('row_stock', (string) ($products->find($draftId)?->details->stock ?? '__none__'));
        $say('wrote', ($products->find($draftId)?->details->stock ?? 0) === 77 ? '1' : '0');
        break;

    /**
     * FINDING 2b: a column of the right name and the WRONG TYPE.
     *
     * `stock` is declared `int(11) NOT NULL DEFAULT 0`. Narrowed to a
     * `tinyint`, it keeps its name, its nullability and its default — every
     * question `alpha.48` asks — and silently stores 127 for 500, because this
     * server's `sql_mode` has no `STRICT_TRANS_TABLES` (measured:
     * `ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`).
     *
     * The column is put back in this same verb, reported, because a probe that
     * leaves the table narrowed hands the next verb a broken site.
     */
    case 'wrong-type':
        $draftId = $draft();
        $say('draft_id', $draftId);
        $GLOBALS['wpdb']->query('ALTER TABLE `' . $productsTable . '` MODIFY `stock` TINYINT NOT NULL DEFAULT 0');
        $say('column_now', (string) $GLOBALS['wpdb']->get_var(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . $productsTable . "' AND COLUMN_NAME = 'stock'"
        ));
        [$unsound, $sound, $problem] = $shapeAnswer();
        $say('unsound_columns', $unsound === '' ? '__none__' : $unsound);
        $say('reads_as_sound', $sound);
        $say('problem', $problem === '' ? '__none__' : $problem);
        [$ok, $code] = $vendorSave($draftId, 500, TMC_P49_DRAFT_SKU);
        $say('save', $ok);
        $say('save_code', $code);
        $say('asked_stock', '500');
        $say('row_stock', (string) ($products->find($draftId)?->details->stock ?? '__none__'));
        $GLOBALS['wpdb']->query('ALTER TABLE `' . $productsTable . '` MODIFY `stock` INT NOT NULL DEFAULT 0');
        $say('column_restored', (string) $GLOBALS['wpdb']->get_var(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . $productsTable . "' AND COLUMN_NAME = 'stock'"
        ));
        break;

    /**
     * FINDING 3a: «apply it anyway» over a number the manager never saw.
     *
     * The shape is the owner's: the vendor proposes 4 when there are 9; the
     * manager opens the page and is shown «9 then, 2 now»; a sale takes it to
     * 7 BEFORE they submit; they tick the box about the 2 and send it.
     */
    case 'override-unseen':
        [$id, $wcId] = $published(9);
        [$ok, $code, $context] = $vendorSave($id, 4);
        $say('propose', $ok);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        $say('revision_id', $revisionId);
        $moveShopStock($wcId, 2);
        $say('shop_when_page_rendered', $shopStock($wcId));
        $moveShopStock($wcId, 7);
        $say('shop_when_submitted', $shopStock($wcId));
        [$ok, $code, $context, $carries] = $approve($revisionId, true, 2);
        $say('build_carries_seen_stock', $carries);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('shop_after', $shopStock($wcId));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        $say('applied_over_unseen', $ok === 'ok' ? 'YES' : 'no');
        break;

    /**
     * FINDING 3b: the shop clamped the number, and the proposal was closed.
     *
     * `woocommerce_stock_amount` is WooCommerce's own filter and the one every
     * stock-management plugin uses; `set_stock_quantity()` runs the value
     * through it. So the shop genuinely holds 3 while the manager approved 4.
     */
    case 'readback-differs':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 4);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        $say('revision_id', $revisionId);
        $clamp = static fn ($amount) => min(3, (int) $amount);
        add_filter('woocommerce_stock_amount', $clamp);
        [$ok, $code, $context] = $approve($revisionId);
        $say('approve', $ok);
        $say('approve_code', $code);
        remove_filter('woocommerce_stock_amount', $clamp);
        $say('proposed_stock', '4');
        $say('shop_after', $shopStock($wcId));
        $say('row_after', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        $say('closed_on_a_different_number', $ok === 'ok' && $shopStock($wcId) !== '4' ? 'YES' : 'no');
        break;

    /**
     * FINDING 3c: the read-back failed, and the proposal was closed anyway.
     *
     * `woocommerce_product_get_stock_quantity` is WooCommerce's own prop
     * filter, so this is the shop answering «I cannot tell you» — which is
     * exactly what `readStock()` returns null for.
     */
    case 'readback-failed':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 4);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        $say('revision_id', $revisionId);
        $blind = static fn () => null;
        add_filter('woocommerce_product_get_stock_quantity', $blind);
        [$ok, $code, $context] = $approve($revisionId);
        $say('approve', $ok);
        $say('approve_code', $code);
        remove_filter('woocommerce_product_get_stock_quantity', $blind);
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        $say('closed_unverified', $ok === 'ok' ? 'YES' : 'no');
        break;

    // ------------------------------------------------------------ controls
    //
    // Each of these must answer IDENTICALLY on both trees. A control that
    // declines to measure is a failure, not a pass (`alpha.46`).

    /** A replacement that is not refused still replaces: one pending, the new one. */
    case 'control-proposal-replaced':
        [$id, $wcId] = $published(9);
        $earlier = $earlierProposal($id, 'ماسک آزمون ۴۹ — عنوان تازهٔ فروشنده');
        [$ok, $code] = $vendorSave($id, 4);
        $say('save', $ok);
        $say('save_code', $code);
        $after = $revisions->pendingFor($id);
        $say('pending_count', (string) count(array_filter(
            $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare(
                'SELECT id FROM `' . $revisionsTable . '` WHERE product_id = %d AND status = %s',
                $id,
                ProductRevision::PENDING
            ))
        )));
        $say('pending_is_new', $after !== null && $after->id !== $earlier ? 'yes' : 'NO');
        $say('earlier_superseded', (string) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            'SELECT status FROM `' . $revisionsTable . '` WHERE id = %d',
            $earlier
        )));
        $say('carries_title_forward', $after !== null
            && str_contains((string) ($after->payload['details']['title'] ?? ''), 'عنوان') ? 'yes' : 'no');
        break;

    /** An untouched table is sound, and the write lands. */
    case 'control-sound':
        $draftId = $draft();
        $say('draft_id', $draftId);
        [$unsound, $sound, $problem] = $shapeAnswer();
        $say('unsound_columns', $unsound === '' ? '__none__' : $unsound);
        $say('reads_as_sound', $sound);
        $say('problem', $problem === '' ? '__none__' : $problem);
        [$ok, $code] = $vendorSave($draftId, 31, TMC_P49_DRAFT_SKU);
        $say('save', $ok);
        $say('save_code', $code);
        $say('row_stock', (string) ($products->find($draftId)?->details->stock ?? '__none__'));
        break;

    /** A clean approval still moves the shop and closes the proposal. */
    case 'control-approve':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 4);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        [$ok, $code, $context] = $approve($revisionId);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('shop_after', $shopStock($wcId));
        $say('row_after', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        break;

    /** The manager's «apply it anyway» over the number they DID see still works. */
    case 'control-override-seen':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 4);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        $moveShopStock($wcId, 2);
        $say('shop_when_page_rendered', $shopStock($wcId));
        [$ok, $code, $context, $carries] = $approve($revisionId, true, 2);
        $say('build_carries_seen_stock', $carries);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('shop_after', $shopStock($wcId));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        break;

    /** A refused approval without the box is still refused, and named. */
    case 'control-refuses-moved':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 4);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        $moveShopStock($wcId, 2);
        [$ok, $code, $context] = $approve($revisionId);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('baseline_stock', (string) ($context['baseline_stock'] ?? '__none__'));
        $say('current_stock', (string) ($context['current_stock'] ?? '__none__'));
        $say('shop_after', $shopStock($wcId));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        break;

    case 'reset':
        [$id, $wcId] = $published(9);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('shop_stock', $shopStock($wcId));
        $say('pending', (string) ($revisions->pendingFor($id)?->id ?? 0));
        $say('stock_column', (string) $GLOBALS['wpdb']->get_var(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . $productsTable . "' AND COLUMN_NAME = 'stock'"
        ));
        break;

    default:
        $say('verb', 'unknown');
        break;
}
