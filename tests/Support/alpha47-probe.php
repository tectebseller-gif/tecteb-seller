<?php
/**
 * The purchase limits that never left the marketplace row, and the stock that
 * now waits — measured on real WooCommerce.
 *
 * THE OWNER'S OWN TEST IS THE SCENARIO. On `staging` they set min 1, max 20,
 * step 1 in the vendor form and the WooCommerce tab was empty; they approved
 * an unrelated brand proposal and all three appeared; then they changed only
 * the maximum, 20 → 18, saved it, refreshed WooCommerce and it still said 20.
 * Both halves have one cause: `ManageProducts::updateInventory()` wrote the
 * marketplace row and called `pushStock()`, and NOTHING carried the three
 * quantity rows outward. They arrived only with `project()` — a publish or an
 * approval away on a live product.
 *
 * So the measurement is taken in the shape the owner used:
 *
 *   limits-arrange     a live, projected product whose shop rows say 1/20/1,
 *                      then the vendor saves max 18 — AND NOTHING IS APPROVED
 *   limits-page        A FRESH REQUEST: the three rows, WooCommerce's own
 *                      quantity args, and what the classic cart and the Store
 *                      API answer for 18, 19 and 20
 *   empty-tab-arrange  a product projected by a build that had no quantity
 *                      rows (the rows removed by hand, which is what
 *                      `alpha.40` left on staging), then the vendor saves
 *                      1/20/1
 *   empty-tab-page     A FRESH REQUEST: is the tab still empty?
 *   manager-owned      a manager's own edit in the shop is NOT overwritten,
 *                      and the vendor is told which limit did not move
 *   write-failure      the row refused by a real `update_post_metadata`
 *                      short-circuit: the previous ceiling stays and the save
 *                      does not report success
 *   stock-arrange      the vendor's stock edit on a live product
 *   stock-page         A FRESH REQUEST: the shop still holds the old number
 *   stock-approve      the manager agrees, and the shop moves
 *   stock-conflict     the shop moved between proposing and approving
 *   keeps-proposal     saving the inventory tab keeps a pending brand proposal
 *   control-draft      a draft's stock still applies at once
 *   control-no-change  a save that changes nothing changes nothing
 *
 * Every measuring verb runs in ITS OWN `wp` invocation, because the subject is
 * what a visitor's request sees: a verb that arranges and then measures in the
 * same process measures a process whose memos are warm (`alpha.46`).
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha47-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ManageBrands;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;

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

const TMC_P48_VENDOR = 8810;
const TMC_P48_CATEGORY = 'tmc-p48-swab';
const TMC_P48_SKU = 'SKU-ALPHA48';

$verb = (string) ($args[0] ?? 'limits-arrange');
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
/**
 * THE BRAND SERVICES ARE RESOLVED LAZILY, and that is not tidiness.
 *
 * This probe runs on the `alpha.47` bytes too — that is what it is for — and
 * `alpha.47` has no brand registry at all. Resolving them at the top would
 * throw before the first verb, every verb would print nothing, and the script
 * would report «reproduced» about a probe that never ran: the `alpha.32` trap
 * in a new coat. So the brand verbs ask for them, and say so when the build
 * does not have them.
 *
 * @return array{0:?ManageBrands,1:?BrandDirectoryInterface,2:?BrandProposalRepositoryInterface}
 */
$brandServices = static function () use ($c): array {
    if (!interface_exists(BrandDirectoryInterface::class) || !class_exists(ManageBrands::class)) {
        return [null, null, null];
    }
    try {
        return [
            $c->get(ManageBrands::class),
            $c->get(BrandDirectoryInterface::class),
            $c->get(BrandProposalRepositoryInterface::class),
        ];
    } catch (\Throwable) {
        return [null, null, null];
    }
};

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};
$say('probe', 'ran');

(new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
    ->upsertProfile(TMC_P48_VENDOR, 'تجهیزات آزمون ۴۸', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P48_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (
    string $brand = '',
    int $stock = 20,
    int $min = 1,
    ?int $max = 20,
    int $step = 1
): ProductDetails {
    $named = [
        'title' => 'سوآب الکلی آزمون ۴۸',
        'type' => 'simple',
        'categoryKey' => TMC_P48_CATEGORY,
        'brand' => $brand,
        'shortDescription' => 'یک جمله',
        'priceMinor' => 450000,
        'sku' => TMC_P48_SKU,
        'stock' => $stock,
        'minPurchase' => $min,
        'maxPurchase' => $max,
        'description' => 'متن کامل فروشنده',
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = $step;
    }
    return new ProductDetails(...$named);
};

/**
 * A published, projected product whose three shop rows this plugin owns.
 *
 * Reset the way the previous rounds reset: the ownership record and the rows
 * are taken away and `publish()` writes them again, so no verb measures what
 * another verb left behind.
 */
$published = static function (int $stock = 20, int $min = 1, ?int $max = 20, int $step = 1)
use ($products, $findBySku, $detailsOf, $revisions, $catalog): array {
    $id = $findBySku(TMC_P48_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P48_VENDOR, $detailsOf('', $stock, $min, $max, $step), ProductStatus::Published);
    } else {
        // WITH THE ROW VERSION — `updateDetails('')` is a refusal by design.
        if (!$products->updateDetails($id, $detailsOf('', $stock, $min, $max, $step), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
        $products->updateInventory($id, $stock, TMC_P48_SKU, $min, $max, $step);
    }
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    if ($wcId > 0) {
        foreach ([
            '_tmc_quantity_written', '_tmc_quantity_conflicts', '_tmc_quantity_contradiction',
            '_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step',
        ] as $key) {
            delete_post_meta($wcId, $key);
        }
    }
    $pending = $revisions->pendingFor($id);
    if ($pending !== null) {
        $revisions->decide($pending->id, ProductRevision::SUPERSEDED, 0, '');
    }
    // A GALLERY, because `ProductReadiness` requires one and an approval
    // writes the proposal and then asks. Without it the first measurement of
    // `stock-approve` answered `missing_image` — a correct refusal about a
    // fixture, dressed as a result about the code.
    if ($products->find($id)?->imageIds === []) {
        $attachmentId = (int) (get_posts([
            'post_type' => 'attachment',
            'numberposts' => 1,
            'fields' => 'ids',
        ])[0] ?? 0);
        if ($attachmentId === 0) {
            $file = wp_upload_dir()['path'] . '/tmc-alpha48.png';
            file_put_contents($file, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
            ));
            $attachmentId = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'tmc-alpha48',
                'post_status' => 'inherit',
            ], $file);
        }
        if ($attachmentId > 0) {
            $products->saveImages($id, [$attachmentId], $attachmentId);
        }
    }
    $catalog->publish($id);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    // The shop's own stock is set here and only here, so a verb that asks
    // «did the shop move?» is asking about its own arrangement.
    $shopProduct = wc_get_product($wcId);
    if ($shopProduct instanceof \WC_Product) {
        $shopProduct->set_manage_stock(true);
        $shopProduct->set_stock_quantity($stock);
        $shopProduct->save();
    }
    return [$id, $wcId];
};

/** The three shop rows, with ABSENT told apart from empty (`alpha.28`). */
$shopRows = static function (int $wcId): string {
    $out = [];
    foreach (['_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step'] as $key) {
        $out[] = metadata_exists('post', $wcId, $key)
            ? ((string) get_post_meta($wcId, $key, true) === '' ? 'empty' : (string) get_post_meta($wcId, $key, true))
            : '__absent__';
    }
    return implode('/', $out);
};

/** WooCommerce's own quantity numbers — the classic page AND the blocks. */
$inputArgs = static function (mixed $product): string {
    $args = wc_get_quantity_input_args([], $product);
    return $args['min_value'] . '/' . $args['max_value'] . '/' . $args['step'];
};

/** The classic validation point WooCommerce actually fires (`alpha.45`). */
$addToCart = static function (int $productId, int $quantity): string {
    return apply_filters('woocommerce_add_to_cart_validation', true, $productId, $quantity, 0, [], []) === true
        ? 'allowed'
        : 'refused';
};

/** The Store API's own per-item validator, which the block cart runs. */
$storeApi = static function (mixed $product, int $quantity): string {
    try {
        do_action('woocommerce_store_api_validate_cart_item', $product, ['quantity' => $quantity]);
    } catch (\Throwable) {
        return 'refused';
    }
    return 'allowed';
};

/**
 * Open the sale gate for a measurement, or say why it stayed shut.
 *
 * The gate as it ships refuses everything (DEC-02 and DEC-04 are open) and NOT
 * ONE LINE OF IT IS CHANGED. This uses the plugin's own `tmc_order_trial_mode`
 * — local/development/staging only — and puts it back.
 *
 * @return array{0:bool,1:string,2:callable():void}
 */
$openGateForMeasurement = static function () use ($c): array {
    /** @var \Tecteb\Marketplace\Modules\Order\Application\TrialUnlock $trial */
    $trial = $c->get(\Tecteb\Marketplace\Modules\Order\Application\TrialUnlock::class);
    $restore = static function () use ($trial): void {
        $trial->request(false);
    };
    $trial->request(true);
    if (!$trial->isPermitted()) {
        $restore();
        return [false, 'environment_' . $trial->environmentName(), $restore];
    }
    $c->get(\Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface::class)
        ->setRate(
            \Tecteb\Marketplace\Modules\Finance\Domain\RateScope::General,
            'general',
            \Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate::ofBasisPoints(1000)
        );
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class)->check();
    if ($gate['ready'] !== true) {
        $restore();
        return [false, (string) ($gate['reason'] ?? 'closed'), $restore];
    }
    return [true, (string) ($gate['reason'] ?? 'ready'), $restore];
};

/**
 * The vendor's own inventory save, through whichever signature this build has.
 *
 * `alpha.45` appended the step with a default, so both builds take eight
 * arguments — but asking rather than assuming is the `alpha.40` rule, and a
 * probe that cannot load on the bytes it exists to measure measures nothing.
 */
$vendorSave = static function (int $id, int $stock, int $min, ?int $max, int $step) use ($manage): array {
    $method = new \ReflectionMethod(ManageProducts::class, 'updateInventory');
    $arguments = [TMC_P48_VENDOR, TMC_P48_VENDOR, $id, $stock, TMC_P48_SKU, $min, $max];
    if ($method->getNumberOfParameters() >= 8) {
        $arguments[] = $step;
    }
    $result = $manage->updateInventory(...$arguments);
    return [$result->ok ? 'ok' : 'failed', $result->code, $result->context];
};

/** Whether this build's approval takes the manager's «apply it anyway». */
$approve = static function (int $revisionId, bool $acceptStockConflict = false) use ($review): array {
    $method = new \ReflectionMethod(ReviewProducts::class, 'approveRevision');
    $arguments = [$revisionId, []];
    if ($method->getNumberOfParameters() >= 3) {
        $arguments[] = $acceptStockConflict;
    } elseif ($acceptStockConflict) {
        return ['failed', 'build_has_no_override', []];
    }
    $result = $review->approveRevision(...$arguments);
    return [$result->ok ? 'ok' : 'failed', $result->code, $result->context];
};

$shopStock = static function (int $wcId): string {
    $product = wc_get_product($wcId);
    return $product instanceof \WC_Product ? (string) (int) $product->get_stock_quantity() : '__unreadable__';
};

// -------------------------------------------------------------- the verbs

switch ($verb) {
    /**
     * ARRANGE, and nothing else: the owner's 20 → 18.
     *
     * Nothing is approved here. §6 asks for exactly that — «موفقیت همان سناریو
     * روی نسخهٔ جدید، بدون تأیید هیچ پیشنهاد دیگری» — because on `alpha.47` the
     * values appeared only when an unrelated brand proposal was approved, and
     * an approval in the middle of this measurement would hide the defect.
     */
    case 'limits-arrange':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('rows_at_start', $shopRows($wcId));
        [$ok, $code, $context] = $vendorSave($id, 20, 1, 18, 1);
        $say('save', $ok);
        $say('save_code', $code);
        $say('save_limits', (string) ($context['limits'] ?? '__none__'));
        $row = $products->find($id);
        $say('row_max_after_save', (string) ($row?->details->maxPurchase ?? '__null__'));
        $say('pending_revision', $revisions->pendingFor($id) === null ? 'none' : 'yes');
        break;

    /** A FRESH REQUEST: what a shopper's page and basket now see. */
    case 'limits-page':
        $id = $findBySku(TMC_P48_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_limits-arrange_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('rows_now', $shopRows($wcId));
        // THE GATE IS OPENED FIRST, and the order is the measurement.
        // `wc_get_quantity_input_args()` runs this plugin's own filter, which
        // from `alpha.47` asks the marketplace row through `PurchasePolicy` —
        // and that memoises the sale gate for the whole request. Asking for
        // the args before opening the gate therefore froze it shut and every
        // basket answer came back `gate=decisions_open`: a control that
        // declines to measure (`alpha.46`).
        [$open, $why, $restore] = $openGateForMeasurement();
        $say('gate', $open ? 'open' : $why);
        $say('input_args', $inputArgs(wc_get_product($wcId)));
        if ($open) {
            foreach ([18, 19, 20] as $quantity) {
                $say('classic_qty_' . $quantity, $addToCart($wcId, $quantity));
            }
            $say('store_api_qty_19', $storeApi(wc_get_product($wcId), 19));
            $restore();
        }
        break;

    /**
     * THE OWNER'S FIRST SYMPTOM: a tab that was empty.
     *
     * Product 12112 on staging was projected by a build that had no quantity
     * rows at all (`alpha.40`), so the shop held NONE of the three. The rows
     * are removed here to reproduce that, and then the vendor saves 1/20/1 —
     * which is what the owner did.
     */
    case 'empty-tab-arrange':
        [$id, $wcId] = $published(20, 1, 20, 1);
        foreach (ProjectedQuantity::keys() as $key) {
            delete_post_meta($wcId, $key);
        }
        delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('rows_before_save', $shopRows($wcId));
        [$ok, $code] = $vendorSave($id, 20, 1, 20, 1);
        $say('save', $ok);
        $say('save_code', $code);
        break;

    /** A FRESH REQUEST: is the WooCommerce tab still empty? */
    case 'empty-tab-page':
        $id = $findBySku(TMC_P48_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_empty-tab-arrange_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('rows_now', $shopRows($wcId));
        $say('tab_empty', $shopRows($wcId) === '__absent__/__absent__/__absent__' ? 'YES' : 'no');
        $say('input_args', $inputArgs(wc_get_product($wcId)));
        break;

    /**
     * A MANAGER'S OWN EDIT IS NOT OVERWRITTEN, and the vendor is told.
     *
     * The `alpha.44` rule arriving through the new door: ownership is bound to
     * the value we wrote, so a row the manager has changed is held, named and
     * left alone — and «ذخیره شد» is not said about it.
     */
    case 'manager-owned':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('wc_id', $wcId);
        $say('rows_at_start', $shopRows($wcId));
        // The manager's hand, straight on the shop row.
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '7');
        $say('rows_after_manager_edit', $shopRows($wcId));

        [$ok, $code, $context] = $vendorSave($id, 20, 1, 18, 1);
        $say('save', $ok);
        $say('save_code', $code);
        $say('held', (string) ($context['limits_held'] ?? '__none__'));
        $say('manager_value_kept', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true) === '7' ? 'yes' : 'NO');
        $say('rows_after_save', $shopRows($wcId));
        $say('conflicts', implode(',', (array) (get_post_meta($wcId, ProjectedQuantity::CONFLICT_META, true) ?: [])));
        break;

    /**
     * THE WRITE REFUSED, injected through WordPress's own seam.
     *
     * A non-null return from `update_post_metadata` short-circuits the write
     * (`meta.php`), which is the real refusal rather than a mock of one. What
     * must happen: the previous ceiling stays — never absence, which would be
     * a licence nobody granted (`alpha.47`) — and the save does NOT report
     * plain success.
     */
    case 'write-failure':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('wc_id', $wcId);
        $say('rows_at_start', $shopRows($wcId));

        $refuse = static function (mixed $check, mixed $objectId, mixed $metaKey): mixed {
            return (string) $metaKey === ProjectedQuantity::META_MAX ? false : $check;
        };
        add_filter('update_post_metadata', $refuse, 10, 3);
        [$ok, $code, $context] = $vendorSave($id, 20, 1, 18, 1);
        remove_filter('update_post_metadata', $refuse, 10);

        $say('save', $ok);
        $say('save_code', $code);
        $say('failed', (string) ($context['limits_failed'] ?? '__none__'));
        $say('rows_after_refused_write', $shopRows($wcId));
        $say('ceiling_kept', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true) === '20' ? 'yes' : 'NO');
        $say('max_row_absent', metadata_exists('post', $wcId, ProjectedQuantity::META_MAX) ? 'no' : 'YES');
        break;

    /** A FRESH REQUEST after the refused write: the ceiling is still there. */
    case 'write-failure-page':
        $id = $findBySku(TMC_P48_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_write-failure_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('rows_now', $shopRows($wcId));
        $say('input_args', $inputArgs(wc_get_product($wcId)));
        break;

    /** THE VENDOR'S STOCK EDIT ON A LIVE PRODUCT — §3-الف. */
    case 'stock-arrange':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('shop_stock_at_start', $shopStock($wcId));
        [$ok, $code, $context] = $vendorSave($id, 4, 1, 20, 1);
        $say('save', $ok);
        $say('save_code', $code);
        $say('row_stock_after_save', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $pending = $revisions->pendingFor($id);
        $say('pending_revision', $pending === null ? 'none' : 'yes');
        $say('proposed_stock', $pending === null
            ? '__none__'
            : (string) ($pending->payload['stock_proposal']['value'] ?? '__absent__'));
        $say('baseline_stock', $pending === null
            ? '__none__'
            : (string) ($pending->payload['stock_proposal']['baseline'] ?? '__absent__'));
        break;

    /** A FRESH REQUEST: the shop still holds the number a shopper met. */
    case 'stock-page':
        $id = $findBySku(TMC_P48_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_stock-arrange_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('shop_stock_now', $shopStock($wcId));
        $say('row_stock_now', (string) ($products->find($id)?->details->stock ?? '__none__'));
        break;

    /** THE MANAGER AGREES, and the shop moves. */
    case 'stock-approve':
        $id = $findBySku(TMC_P48_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $pending = $revisions->pendingFor($id);
        if ($wcId <= 0 || $pending === null) {
            $say('fixture', 'run_stock-arrange_first');
            break;
        }
        [$ok, $code] = $approve($pending->id);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('row_stock_after_approval', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $say('shop_stock_after_approval', $shopStock($wcId));
        break;

    /**
     * THE GROUND MOVED between proposing and approving.
     *
     * The shop's own number is changed on the shop's side — a sale, or a
     * manager typing into WooCommerce — which is the only way it can move
     * without replacing the proposal.
     */
    case 'stock-conflict':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('wc_id', $wcId);
        [$ok, $code] = $vendorSave($id, 12, 1, 20, 1);
        $say('save_code', $code);
        $pending = $revisions->pendingFor($id);
        if ($pending === null) {
            $say('pending_revision', 'none');
            break;
        }
        $shopProduct = wc_get_product($wcId);
        if ($shopProduct instanceof \WC_Product) {
            $shopProduct->set_stock_quantity(3);
            $shopProduct->save();
        }
        $say('shop_stock_before_approval', $shopStock($wcId));
        [$ok, $code, $context] = $approve($pending->id);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('named_numbers', implode('/', [
            (string) ($context['baseline_stock'] ?? '?'),
            (string) ($context['current_stock'] ?? '?'),
            (string) ($context['proposed_stock'] ?? '?'),
        ]));
        $say('shop_stock_after_refusal', $shopStock($wcId));
        $say('revision_still_pending', $revisions->pendingFor($id) === null ? 'NO' : 'yes');

        [$ok2, $code2] = $approve($pending->id, true);
        $say('override', $ok2);
        $say('override_code', $code2);
        $say('shop_stock_after_override', $shopStock($wcId));
        break;

    /**
     * SAVING ONE TAB KEEPS THE OTHER TAB'S PROPOSAL.
     *
     * «ذخیرهٔ یک زبانه نباید دادهٔ زبانهٔ دیگر یا پیشنهاد موجود را پاک کند.» A
     * brand proposal is pending; the vendor saves the inventory tab; the brand
     * must still be in the proposal afterwards.
     */
    case 'keeps-proposal':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('wc_id', $wcId);
        $product = $products->find($id);
        // Built through the repository rather than through a form, because the
        // subject is what the SECOND save does to a proposal that exists.
        $payload = [
            'details' => [
                'title' => $product?->details->title,
                'brand' => 'برند پیشنهادی ۴۸',
                'priceMinor' => $product?->details->priceMinor,
                'stock' => $product?->details->stock,
            ],
            'specs' => $product?->specs ?? [],
            'images' => $product?->imageIds ?? [],
            'main_image_id' => $product?->mainImageId ?? 0,
        ];
        $brandRevision = $revisions->create($id, TMC_P48_VENDOR, $payload);
        $say('brand_revision', $brandRevision);
        $say('brand_in_proposal_before', (string) ($revisions->pendingFor($id)?->payload['details']['brand'] ?? '__none__'));

        [$ok, $code] = $vendorSave($id, 6, 1, 20, 1);
        $say('save_code', $code);
        $pending = $revisions->pendingFor($id);
        $say('brand_in_proposal_after', (string) ($pending?->payload['details']['brand'] ?? '__none__'));
        $say('stock_in_proposal_after', (string) ($pending?->payload['stock_proposal']['value'] ?? '__absent__'));
        $say('new_revision_id', (string) ($pending?->id ?? 0));
        $say('badge_token_moved', ($pending?->id ?? 0) !== $brandRevision ? 'yes' : 'NO');
        break;

    /** CONTROL: a draft's stock still applies at once, with no proposal. */
    case 'control-draft':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $products->updateStatus($id, ProductStatus::Draft, '');
        $say('status', (string) ($products->find($id)?->status->value ?? '?'));
        [$ok, $code] = $vendorSave($id, 7, 1, 20, 1);
        $say('save', $ok);
        $say('save_code', $code);
        $say('row_stock', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $say('shop_stock', $shopStock($wcId));
        $say('pending_revision', $revisions->pendingFor($id) === null ? 'none' : 'yes');
        $products->updateStatus($id, ProductStatus::Published, '');
        break;

    /** CONTROL: a save that changes nothing changes nothing. */
    case 'control-no-change':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('wc_id', $wcId);
        $say('rows_at_start', $shopRows($wcId));
        $before = (array) (get_post_meta($wcId, ProjectedQuantity::OWNED_META, true) ?: []);
        [$ok, $code] = $vendorSave($id, 20, 1, 20, 1);
        $say('save', $ok);
        $say('save_code', $code);
        $say('rows_after_save', $shopRows($wcId));
        $after = (array) (get_post_meta($wcId, ProjectedQuantity::OWNED_META, true) ?: []);
        $say('record_unchanged', $before === $after ? 'yes' : 'NO');
        $say('pending_revision', $revisions->pendingFor($id) === null ? 'none' : 'yes');
        break;

    /**
     * THE BRAND LIST IS WOOCOMMERCE'S OWN — measured, not read off a
     * changelog.
     *
     * The whole of §4 rests on `product_brand` existing in WooCommerce 11.0.1,
     * because the alternative was a table of ours and the owner asked for no
     * parallel source. So the first thing this says is whether the taxonomy is
     * there.
     */
    case 'brand-list':
        [$brandService, $brandDirectory, $brandRequests] = $brandServices();
        if ($brandService === null || $brandDirectory === null || $brandRequests === null) {
            $say('brand_registry', 'absent_in_this_build');
            break;
        }
        $say('taxonomy_exists', taxonomy_exists('product_brand') ? 'yes' : 'NO');
        $say('directory_available', $brandDirectory->isAvailable() ? 'yes' : 'NO');
        // A clean list for the verbs below: the terms this probe made, and
        // nothing else. Other brands a manager may have added are left alone.
        foreach (['برند آزمون ۴۸', 'سینا طب ۴۸', 'آمبو بگ ۴۸'] as $name) {
            $term = get_term_by('name', $name, 'product_brand');
            if ($term instanceof \WP_Term) {
                wp_delete_term($term->term_id, 'product_brand');
            }
        }
        $seed = wp_insert_term('برند آزمون ۴۸', 'product_brand');
        $say('seeded_term', is_array($seed) ? (string) ($seed['term_id'] ?? 0) : 'failed');
        $say('found_by_key', $brandDirectory->findByKey(BrandName::key('برند آزمون ۴۸'))?->name ?? '__none__');
        // The normalised key, on the real list: a space and an Arabic yeh are
        // not a different brand.
        $say('found_by_variant', $brandDirectory->findByKey(BrandName::key('برندآزمون ۴۸'))?->name ?? '__none__');
        $say('count', (string) $brandDirectory->count());
        break;

    /** Free text cannot become an official brand. */
    case 'brand-free-text':
        [$brandService, $brandDirectory, $brandRequests] = $brandServices();
        if ($brandService === null || $brandDirectory === null || $brandRequests === null) {
            $say('brand_registry', 'absent_in_this_build');
            break;
        }
        [$id, $wcId] = $published(20, 1, 20, 1);
        $before = (string) ($products->find($id)?->details->brand ?? '');
        $say('brand_before', $before === '' ? '__empty__' : $before);
        $details = $detailsOf('برندی که کسی تأیید نکرده ۴۸', 20, 1, 20, 1);
        $result = $manage->save(TMC_P48_VENDOR, TMC_P48_VENDOR, $id, $details, [], [], 0, (string) ($products->find($id)?->rowVersion ?? ''));
        $say('save', $result->ok ? 'ok' : 'failed');
        $say('save_code', $result->code);
        $after = (string) ($products->find($id)?->details->brand ?? '');
        $say('brand_after', $after === '' ? '__empty__' : $after);
        break;

    /** The vendor asks, and the product's brand does not move. */
    case 'brand-request':
        [$brandService, $brandDirectory, $brandRequests] = $brandServices();
        if ($brandService === null || $brandDirectory === null || $brandRequests === null) {
            $say('brand_registry', 'absent_in_this_build');
            break;
        }
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('product_id', $id);
        $open = $brandRequests->openFor($id);
        if ($open !== null) {
            $brandRequests->decide($open->id, \Tecteb\Marketplace\Modules\Product\Domain\BrandProposal::REJECTED, $adminId, 'reset', 0, $open->status);
        }
        $asked = $brandService->propose(TMC_P48_VENDOR, TMC_P48_VENDOR, $id, 'سینا طب ۴۸');
        $say('ask', $asked->ok ? 'ok' : 'failed');
        $say('ask_code', $asked->code);
        $brandNow = (string) ($products->find($id)?->details->brand ?? '');
        $say('brand_after_ask', $brandNow === '' ? '__empty__' : $brandNow);
        $say('term_exists_yet', get_term_by('name', 'سینا طب ۴۸', 'product_brand') instanceof \WP_Term ? 'YES' : 'no');
        $say('in_needs_review', in_array($id, array_map(
            static fn (object $p): int => $p->id,
            $products->forManager(null, '', 100, 0, 0, null, true)
        ), true) ? 'yes' : 'NO');
        break;

    /**
     * The manager approves: the term appears in WooCommerce's own list AND
     * the brand reaches the attribute the owner measured working.
     */
    case 'brand-approve':
        [$brandService, $brandDirectory, $brandRequests] = $brandServices();
        if ($brandService === null || $brandDirectory === null || $brandRequests === null) {
            $say('brand_registry', 'absent_in_this_build');
            break;
        }
        $id = $findBySku(TMC_P48_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $open = $brandRequests->openFor($id);
        if ($id === 0 || $open === null) {
            $say('fixture', 'run_brand-request_first');
            break;
        }
        $approved = $brandService->approve($open->id);
        $say('approve', $approved->ok ? 'ok' : 'failed');
        $say('approve_code', $approved->code);
        $term = get_term_by('name', 'سینا طب ۴۸', 'product_brand');
        $say('term_in_list', $term instanceof \WP_Term ? (string) $term->term_id : '__none__');
        $rowBrand = (string) ($products->find($id)?->details->brand ?? '');
        $say('row_brand', $rowBrand === '' ? '__empty__' : $rowBrand);
        $say('row_brand_term', (string) ($products->brandTermIdOf($id) ?? '__null__'));
        // THE DISPLAY PATH THE OWNER MEASURED WORKING: WooCommerce → Product
        // data → Attributes → «برند». Read from the shop product itself.
        $shopProduct = wc_get_product($wcId);
        $attributeValue = '__unreadable__';
        if ($shopProduct instanceof \WC_Product) {
            $attributeValue = '__absent__';
            foreach ($shopProduct->get_attributes() as $attribute) {
                if ($attribute->get_name() === 'برند') {
                    $attributeValue = implode('|', $attribute->get_options());
                }
            }
        }
        $say('shop_attribute_brand', $attributeValue);
        $say('request_closed', $brandRequests->openFor($id) === null ? 'yes' : 'NO');
        $say('in_needs_review', in_array($id, array_map(
            static fn (object $p): int => $p->id,
            $products->forManager(null, '', 100, 0, 0, null, true)
        ), true) ? 'YES' : 'no');
        break;

    /**
     * A name that differs only in a space is the SAME brand — on the real
     * taxonomy, where the duplicate would otherwise be a second term.
     */
    case 'brand-duplicate':
        [$brandService, $brandDirectory, $brandRequests] = $brandServices();
        if ($brandService === null || $brandDirectory === null || $brandRequests === null) {
            $say('brand_registry', 'absent_in_this_build');
            break;
        }
        [$id, $wcId] = $published(20, 1, 20, 1);
        $open = $brandRequests->openFor($id);
        if ($open !== null) {
            $brandRequests->decide($open->id, \Tecteb\Marketplace\Modules\Product\Domain\BrandProposal::REJECTED, $adminId, 'reset', 0, $open->status);
        }
        $term = get_term_by('name', 'آمبو بگ ۴۸', 'product_brand');
        if (!$term instanceof \WP_Term) {
            $made = wp_insert_term('آمبو بگ ۴۸', 'product_brand');
            $say('seeded', is_array($made) ? (string) ($made['term_id'] ?? 0) : 'failed');
        } else {
            $say('seeded', (string) $term->term_id);
        }
        $before = count(get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'fields' => 'ids']));
        // Asked for without the space — the same brand.
        $asked = $brandService->propose(TMC_P48_VENDOR, TMC_P48_VENDOR, $id, 'آمبوبگ ۴۸');
        $say('ask_code', $asked->code);
        $say('named_real_brand', (string) ($asked->context['brand'] ?? '__none__'));
        $after = count(get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'fields' => 'ids']));
        $say('terms_before', (string) $before);
        $say('terms_after', (string) $after);
        $say('no_second_term', $before === $after ? 'yes' : 'NO');
        break;

    /**
     * REGISTER TWICE, as the race resolves it.
     *
     * Not two processes here — this is the directory's own guard, asked twice
     * in one request with the memo cleared between, which is what a loser of a
     * real race sees: `wp_insert_term()` refuses it and `findByKey()` hands
     * back the winner's term. The two-process form is run by
     * `alpha48-reproduction.sh`, which is the only place it can be.
     */
    case 'brand-register-twice':
        [$brandService, $brandDirectory, $brandRequests] = $brandServices();
        if ($brandService === null || $brandDirectory === null || $brandRequests === null) {
            $say('brand_registry', 'absent_in_this_build');
            break;
        }
        $term = get_term_by('name', 'سینا طب ۴۸', 'product_brand');
        if ($term instanceof \WP_Term) {
            wp_delete_term($term->term_id, 'product_brand');
        }
        $first = $brandDirectory->register('سینا طب ۴۸');
        $say('first', $first === null ? '__null__' : (string) $first->termId);
        // Spelled differently, and the same brand.
        $second = $brandDirectory->register('سیناطب ۴۸');
        $say('second', $second === null ? '__null__' : (string) $second->termId);
        $say('same_term', $first !== null && $second !== null && $first->termId === $second->termId ? 'yes' : 'NO');
        $say('term_count_for_name', (string) count(get_terms([
            'taxonomy' => 'product_brand',
            'hide_empty' => false,
            'search' => 'سینا',
            'fields' => 'ids',
        ])));
        break;

    case 'reset':
        [$id, $wcId] = $published(20, 1, 20, 1);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('rows', $shopRows($wcId));
        break;

    default:
        $say('verb', 'unknown');
        break;
}
