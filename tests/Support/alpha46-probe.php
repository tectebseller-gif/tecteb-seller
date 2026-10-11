<?php
/**
 * The reset path, measured — and the window it used to open.
 *
 * `alpha.46` gave the WooCommerce panel a named «back to automatic» control
 * and it did the wrong thing in the one way nobody looked at: it DELETED the
 * three quantity rows and waited for the next projection. Between the two,
 * `QuantityGuard::storedRule()` reads absence through
 * `ProjectedQuantity::ruleFrom()` as «no minimum, no maximum, no step» — so a
 * manager handing a rule back to the marketplace removed the rule from the
 * shop until somebody saved the product again.
 *
 * THE GAP IS THE SUBJECT, so it is measured in its own invocation. A verb that
 * arranges the reset and then asks about the page in the same PHP process
 * proves nothing about a visitor's request; and a verb that measures after a
 * projection measures the state the defect never reached. So:
 *
 *   gap-arrange          sets the marketplace rule, lets the manager edit it,
 *                        then saves the panel with the reset box ticked
 *   gap-page             A FRESH REQUEST: WooCommerce's own quantity args
 *   gap-cart             A FRESH REQUEST: classic add-to-cart, the till, and
 *                        the Store API's own validator
 *   gap-variable-*       the same three, on a variation governed by its
 *                        parent's rule
 *   write-failure        the restore refused by a real `update_post_metadata`
 *                        short-circuit, so the existing restriction must stay
 *   write-failure-page   A FRESH REQUEST: the ceiling is still there
 *
 * Nothing between `gap-arrange` and the measuring verbs saves the product or
 * projects anything. That is the point.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha46-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
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

const TMC_P47_VENDOR = 8809;
const TMC_P47_CATEGORY = 'tmc-p47-gauze';
const TMC_P47_SKU = 'SKU-ALPHA47';

$verb = (string) ($args[0] ?? 'gap-arrange');
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

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};
$say('probe', 'ran');

(new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
    ->upsertProfile(TMC_P47_VENDOR, 'داروخانهٔ آزمون ۴۷', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P47_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (
    string $brand = '',
    int $min = 1,
    ?int $max = null,
    int $step = 1
): ProductDetails {
    $named = [
        'title' => 'گاز استریل آزمون ۴۶',
        'type' => 'simple',
        'categoryKey' => TMC_P47_CATEGORY,
        'brand' => $brand,
        'shortDescription' => 'یک جمله',
        'priceMinor' => 300000,
        'sku' => TMC_P47_SKU,
        'stock' => 40,
        'minPurchase' => $min,
        'maxPurchase' => $max,
        'description' => 'متن کامل فروشنده',
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = $step;
    }
    return new ProductDetails(...$named);
};

/** A published, projected product whose shop rows this plugin owns. */
$published = static function (int $min = 2, ?int $max = 6, int $step = 2)
use ($products, $findBySku, $detailsOf, $revisions, $catalog): array {
    $id = $findBySku(TMC_P47_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P47_VENDOR, $detailsOf('', $min, $max, $step), ProductStatus::Published);
    } else {
        // WITH THE ROW VERSION — `updateDetails('')` is a refusal by design.
        if (!$products->updateDetails($id, $detailsOf('', $min, $max, $step), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
        $products->updateInventory($id, 40, TMC_P47_SKU, $min, $max, $step);
    }
    // Give back the ownership record and the shop rows, so no verb measures
    // what another verb left behind (`alpha.45`'s own lesson).
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
        $revisions->decide($pending->id, \Tecteb\Marketplace\Modules\Product\Domain\ProductRevision::SUPERSEDED, 0, '');
    }
    $catalog->publish($id);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    return [$id, $wcId];
};

/**
 * Open the sale gate for a measurement, or say why it stayed shut.
 *
 * The gate as it ships refuses everything (DEC-02 and DEC-04 are open) and NOT
 * ONE LINE OF IT IS CHANGED. This uses the plugin's own
 * `tmc_order_trial_mode` — local/development/staging only — and puts it back.
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
    // Asked AFTER both, because the gate memoises per request.
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class)->check();
    if ($gate['ready'] !== true) {
        $restore();
        return [false, (string) ($gate['reason'] ?? 'closed'), $restore];
    }
    return [true, (string) ($gate['reason'] ?? 'ready'), $restore];
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

$recordKeys = static function (int $wcId): string {
    $record = get_post_meta($wcId, '_tmc_quantity_written', true);
    if (!is_array($record)) {
        return '__none__';
    }
    $short = [];
    foreach (array_keys($record) as $key) {
        $short[] = str_replace(['_tmc_', '_purchase'], ['', ''], (string) $key);
    }
    sort($short);
    return implode(',', $short);
};

/**
 * The panel's own rendered form, and a `$_POST` built out of it.
 *
 * Scraped rather than hand-written, so the request under test carries the
 * field names and the nonce THIS BUILD renders — a hand-built POST would keep
 * passing after a rename and prove nothing.
 */
$panelForm = static function (int $wcId) use ($c): array {
    $GLOBALS['post'] = get_post($wcId);
    ob_start();
    do_action('woocommerce_product_data_panels');
    $html = (string) ob_get_clean();
    $fields = [];
    if (preg_match_all('~<input[^>]*name="(tmc_[a-z_]+)"[^>]*>~', $html, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $tag) {
            // AN UNTICKED CHECKBOX IS NOT SENT. A browser omits it entirely,
            // and its rendered `value="1"` is what it WOULD send if ticked —
            // not its state. The first version of this scrape took that value
            // and so every POST silently ticked the «hand it back» box: the
            // rows came out `__absent__` and it looked like the code had
            // deleted them.
            if (preg_match('~type="checkbox"~', $tag[0]) === 1) {
                continue;
            }
            $value = preg_match('~value="([^"]*)"~', $tag[0], $v) === 1 ? $v[1] : '';
            $fields[$tag[1]] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        }
    }
    return [$html, $fields];
};

/**
 * One real invocation of the hook WooCommerce fires after the editor saves —
 * with ONLY this plugin's listener attached.
 *
 * Said plainly: WooCommerce's own `WC_Meta_Box_Product_Data::save()` is on the
 * same hook and rewrites the WHOLE product from `$_POST`. A `$_POST` carrying
 * only the panel's four fields would therefore blank price, stock and the rest
 * — a side effect of the harness, not of the code under test. So the hook is
 * cleared and the panel re-registered, and what runs is exactly the panel's
 * own callback on a request built from the panel's own rendered form.
 */
$panelSave = static function (int $wcId, array $post) use ($c): void {
    remove_all_actions('woocommerce_process_product_meta');
    \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\QuantityPanel::register($c);
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['post'] = get_post($wcId);
    do_action('woocommerce_process_product_meta', $wcId, get_post($wcId));
    $_POST = [];
};

/** The shop rule as the three rows express it, through the plugin's own reader. */
$ruleOfRows = static function (int $wcId): PurchaseQuantity {
    $meta = [];
    foreach (ProjectedQuantity::keys() as $key) {
        $meta[$key] = (string) get_post_meta($wcId, $key, true);
    }
    return ProjectedQuantity::ruleFrom($meta);
};

/** WooCommerce's own quantity numbers — the classic page AND the blocks. */
$inputArgs = static function (mixed $product): string {
    $args = wc_get_quantity_input_args([], $product);
    return $args['min_value'] . '/' . $args['max_value'] . '/' . $args['step'];
};

/**
 * The classic validation point WooCommerce actually fires.
 *
 * `WC_Cart::add_to_cart()` is NOT one (`alpha.45`), so the filter is run the
 * way `class-wc-form-handler.php` runs it.
 */
$addToCart = static function (int $productId, int $quantity, int $variationId = 0): string {
    return apply_filters(
        'woocommerce_add_to_cart_validation',
        true,
        $productId,
        $quantity,
        $variationId,
        [],
        []
    ) === true ? 'allowed' : 'refused';
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

// -------------------------------------------------------------- the verbs

switch ($verb) {
    /**
     * ARRANGE, and nothing else. A valid marketplace rule, a real manager
     * edit through the panel's own form, then the reset box ticked.
     *
     * No measurement of the page or the basket happens here: this process has
     * already called `publish()`, which asks the sale gate and memoises it,
     * and it has already loaded the product into `PurchasePolicy`'s memo. A
     * number read after all that is a number about this process, not about a
     * visitor's request.
     */
    case 'gap-arrange':
        [$id, $wcId] = $published(2, 6, 2);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('marketplace_rule', '2/6/2');
        $say('rows_after_projection', $shopRows($wcId));
        $say('record_after_projection', $recordKeys($wcId));

        // The manager lowers the maximum — their own edit, their own field.
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('rows_after_manager_edit', $shopRows($wcId));
        $say('record_after_manager_edit', $recordKeys($wcId));

        // …and then asks for the rule back. The checkbox is sent the way a
        // browser sends a TICKED one: by being present at all.
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, [
            \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\QuantityPanel::RESET => '1',
        ]));
        $say('rows_after_reset', $shopRows($wcId));
        $say('record_after_reset', $recordKeys($wcId));
        $notice = get_transient('tmc_quantity_notice_' . get_current_user_id());
        $say('outcome', (string) (is_array($notice) ? ($notice['outcome'] ?? '__none__') : '__none__'));
        break;

    /**
     * A FRESH REQUEST. Nothing in this process has saved or projected
     * anything; this is the page a visitor would get between the reset and
     * the next save.
     */
    case 'gap-page':
        $id = $findBySku(TMC_P47_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_gap-arrange_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('rows_now', $shopRows($wcId));
        $rule = $ruleOfRows($wcId);
        $say('rule_the_rows_express', $rule->min . '/'
            . ($rule->largestAllowed() === null ? 'none' : (string) $rule->largestAllowed()) . '/' . $rule->step);
        $say('rows_read_as_unrestricted', $rule->isUnrestricted() ? 'YES' : 'no');
        $product = wc_get_product($wcId);
        $say('input_args', $inputArgs($product));
        // The marketplace's own approved rule, for comparison: this is what
        // «back to automatic» was asked to restore.
        $policy = $c->get(\Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class);
        $row = $policy->decide($wcId)['product'];
        $say('marketplace_rule_now', $row === null ? '__not_ours__'
            : $row->details->minPurchase . '/' . ($row->details->maxPurchase ?? 'none') . '/' . $row->details->purchaseStep);
        $say('enforced_matches_marketplace', $inputArgs($product) === '2/6/2' ? 'yes' : 'NO');
        break;

    /** A FRESH REQUEST, the basket half. Nothing here projects anything. */
    case 'gap-cart':
        $id = $findBySku(TMC_P47_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_gap-arrange_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('rows_now', $shopRows($wcId));
        [$gateOpen, $gateWhy, $restoreGate] = $openGateForMeasurement();
        $say('gate', $gateOpen ? $gateWhy : 'not_open:' . $gateWhy);
        if (!$gateOpen) {
            $say('measurement', 'not_run');
            break;
        }
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        // With `2/6/2` in force: one is below the minimum, three is not a
        // multiple, eight is over the ceiling. All three must be refused.
        foreach ([1, 3, 8] as $quantity) {
            $say('classic_qty_' . $quantity, $addToCart($wcId, $quantity));
        }
        $say('classic_qty_4', $addToCart($wcId, 4));
        $product = wc_get_product($wcId);
        $say('store_api_qty_8', $storeApi($product, 8));
        $say('store_api_qty_4', $storeApi($product, 4));
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        $restoreGate();
        break;

    /** ARRANGE, variable: the parent's rule governs each variation. */
    case 'gap-variable-arrange':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        /** @var \Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface $variations */
        $variations = $c->get(\Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface::class);
        $varSku = 'SKU-ALPHA47-VAR';
        $varId = 0;
        foreach ($products->forVendor(TMC_P47_VENDOR) as $product) {
            if ($product->details->sku === $varSku) {
                $varId = $product->id;
            }
        }
        $varDetails = static fn (): ProductDetails => new ProductDetails(
            title: 'سرنگ متنوع آزمون ۴۷',
            type: 'variable',
            categoryKey: TMC_P47_CATEGORY,
            shortDescription: 'یک جمله',
            priceMinor: 450000,
            sku: $varSku,
            stock: 50,
            minPurchase: 2,
            maxPurchase: 6,
            description: 'متن کامل فروشنده',
            purchaseStep: 2
        );
        if ($varId === 0) {
            $varId = $products->create(TMC_P47_VENDOR, $varDetails(), ProductStatus::Published);
        } else {
            $products->updateDetails($varId, $varDetails(), $products->find($varId)?->rowVersion ?? '');
            $products->updateInventory($varId, 50, $varSku, 2, 6, 2);
        }
        $parentWc = (int) ($products->find($varId)?->wcProductId ?? 0);
        if ($parentWc > 0) {
            // Give back what a previous run spent, variations included — their
            // SKUs would otherwise stay taken (`alpha.43`).
            foreach ((array) get_children(['post_parent' => $parentWc, 'post_type' => 'product_variation']) as $child) {
                wp_delete_post((int) $child->ID, true);
            }
            foreach (ProjectedQuantity::keys() as $key) {
                delete_post_meta($parentWc, $key);
            }
            delete_post_meta($parentWc, ProjectedQuantity::OWNED_META);
            delete_post_meta($parentWc, ProjectedQuantity::CONFLICT_META);
        }
        $variations->saveAttribute($varId, 'size', 'اندازه', ['کوچک', 'بزرگ'], 0);
        $small = $variations->saveVariation($varId, new \Tecteb\Marketplace\Modules\Product\Domain\ProductVariation(
            0, $varId, ['size' => 'کوچک'], 210000, null, 'V47-SMALL', 7
        ));
        $catalog->publish($varId);
        $parentWc = (int) ($products->find($varId)?->wcProductId ?? 0);
        $wcSmall = (int) ($variations->findVariation($varId, $small)?->wcVariationId ?? 0);
        $say('parent_wc', $parentWc);
        $say('variation_wc', $wcSmall);
        $say('rows_after_projection', $shopRows($parentWc));

        // The manager edits, then asks for it back — on the PARENT, which is
        // where the rows live and where the editor's panel renders.
        [, $fields] = $panelForm($parentWc);
        $panelSave($parentWc, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('rows_after_manager_edit', $shopRows($parentWc));
        [, $fields] = $panelForm($parentWc);
        $panelSave($parentWc, array_merge($fields, [
            \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\QuantityPanel::RESET => '1',
        ]));
        $say('rows_after_reset', $shopRows($parentWc));
        $notice = get_transient('tmc_quantity_notice_' . get_current_user_id());
        $say('outcome', (string) (is_array($notice) ? ($notice['outcome'] ?? '__none__') : '__none__'));
        break;

    /** A FRESH REQUEST: the variation's page. */
    case 'gap-variable-page':
        $varId = 0;
        foreach ($products->forVendor(TMC_P47_VENDOR) as $product) {
            if ($product->details->sku === 'SKU-ALPHA47-VAR') {
                $varId = $product->id;
            }
        }
        /** @var \Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface $vr */
        $vr = $c->get(\Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface::class);
        $parentWc = (int) ($products->find($varId)?->wcProductId ?? 0);
        $wcSmall = 0;
        foreach ($varId > 0 ? $vr->variations($varId) : [] as $variation) {
            if ($variation->sku === 'V47-SMALL') {
                $wcSmall = (int) ($variation->wcVariationId ?? 0);
            }
        }
        if ($parentWc <= 0 || $wcSmall <= 0) {
            $say('fixture', 'run_gap-variable-arrange_first');
            break;
        }
        $say('parent_wc', $parentWc);
        $say('variation_wc', $wcSmall);
        $say('parent_rows_now', $shopRows($parentWc));
        $say('variation_has_own_rows', metadata_exists('post', $wcSmall, ProjectedQuantity::META_MIN) ? 'yes' : 'no');
        $say('variation_input_args', $inputArgs(wc_get_product($wcSmall)));
        $say('variation_enforced_matches_marketplace',
            $inputArgs(wc_get_product($wcSmall)) === '2/6/2' ? 'yes' : 'NO');
        break;

    /** A FRESH REQUEST: the variation in a basket. */
    case 'gap-variable-cart':
        $varId = 0;
        foreach ($products->forVendor(TMC_P47_VENDOR) as $product) {
            if ($product->details->sku === 'SKU-ALPHA47-VAR') {
                $varId = $product->id;
            }
        }
        /** @var \Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface $vr */
        $vr = $c->get(\Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface::class);
        $parentWc = (int) ($products->find($varId)?->wcProductId ?? 0);
        $wcSmall = 0;
        foreach ($varId > 0 ? $vr->variations($varId) : [] as $variation) {
            if ($variation->sku === 'V47-SMALL') {
                $wcSmall = (int) ($variation->wcVariationId ?? 0);
            }
        }
        if ($parentWc <= 0 || $wcSmall <= 0) {
            $say('fixture', 'run_gap-variable-arrange_first');
            break;
        }
        $say('parent_wc', $parentWc);
        $say('variation_wc', $wcSmall);
        [$gateOpen, $gateWhy, $restoreGate] = $openGateForMeasurement();
        $say('gate', $gateOpen ? $gateWhy : 'not_open:' . $gateWhy);
        if (!$gateOpen) {
            $say('measurement', 'not_run');
            break;
        }
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        foreach ([1, 3, 8] as $quantity) {
            $say('classic_qty_' . $quantity, $addToCart($parentWc, $quantity, $wcSmall));
        }
        $say('classic_qty_4', $addToCart($parentWc, 4, $wcSmall));
        $say('store_api_qty_8', $storeApi(wc_get_product($wcSmall), 8));
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        $restoreGate();
        break;

    /**
     * THE RESTORE REFUSED, with a real short-circuit.
     *
     * `update_post_metadata` returning non-null is WordPress's own way of
     * saying «somebody else handled this write»: `update_post_meta()` returns
     * that value and the row is NOT touched. So this is an injected failure
     * of the write itself, not a mock of one — and the question it asks is the
     * only one that matters: does the restriction the shop already had stay?
     */
    case 'write-failure':
        [$id, $wcId] = $published(2, 6, 2);
        $say('wc_id', $wcId);
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('rows_after_manager_edit', $shopRows($wcId));

        $refuse = static function (mixed $check, mixed $objectId, mixed $metaKey): mixed {
            return (string) $metaKey === ProjectedQuantity::META_MAX ? false : $check;
        };
        add_filter('update_post_metadata', $refuse, 10, 3);
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, [
            \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\QuantityPanel::RESET => '1',
        ]));
        remove_filter('update_post_metadata', $refuse, 10);

        $say('rows_after_refused_reset', $shopRows($wcId));
        $say('ceiling_kept', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true) === '4' ? 'yes' : 'NO');
        $say('max_row_absent', metadata_exists('post', $wcId, ProjectedQuantity::META_MAX) ? 'no' : 'YES');
        $notice = get_transient('tmc_quantity_notice_' . get_current_user_id());
        $say('outcome', (string) (is_array($notice) ? ($notice['outcome'] ?? '__none__') : '__none__'));
        $say('named_the_key', is_array($notice)
            && str_contains((string) ($notice['context']['failed'] ?? ''), ProjectedQuantity::META_MAX) ? 'yes' : 'no');
        break;

    /** A FRESH REQUEST after the refused restore: the ceiling is still there. */
    case 'write-failure-page':
        $id = $findBySku(TMC_P47_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_write-failure_first');
            break;
        }
        $say('wc_id', $wcId);
        $say('rows_now', $shopRows($wcId));
        $rule = $ruleOfRows($wcId);
        $say('rows_read_as_unrestricted', $rule->isUnrestricted() ? 'YES' : 'no');
        $say('input_args', $inputArgs(wc_get_product($wcId)));
        break;

    /**
     * THE RULE UNREADABLE, injected through WooCommerce's own filter.
     *
     * `WC_Product_Factory::get_product()` returns `false` when the product
     * class it is told to build throws (`class-wc-product-factory.php:58-68`),
     * and `woocommerce_product_class` is where it is told. So this is the real
     * `wc_get_product()` failure the guard is for — the shop product not being
     * resolvable in the middle of the save — injected at a documented seam
     * rather than mocked. Said plainly: the COLLABORATOR is made to fail; the
     * failure is not spontaneous.
     *
     * What must happen: nothing is written, nothing is DELETED, the manager is
     * told, and the restriction the shop already had is still there.
     */
    case 'read-failure':
        [$id, $wcId] = $published(2, 6, 2);
        $say('wc_id', $wcId);
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('rows_after_manager_edit', $shopRows($wcId));
        $say('record_after_manager_edit', $recordKeys($wcId));

        if (!class_exists('Tmc47UnbuildableProduct')) {
            eval('class Tmc47UnbuildableProduct { public function __construct($id = 0) { throw new \Exception("unbuildable"); } }');
        }
        $break = static fn (): string => 'Tmc47UnbuildableProduct';
        add_filter('woocommerce_product_class', $break, 99);
        $say('wc_get_product_now', is_object(wc_get_product($wcId)) ? 'object' : 'false');
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, [
            \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\QuantityPanel::RESET => '1',
        ]));
        remove_filter('woocommerce_product_class', $break, 99);

        $say('rows_after_unreadable_reset', $shopRows($wcId));
        $say('restriction_kept', $shopRows($wcId) === '2/4/2' ? 'yes' : 'NO');
        $say('any_row_absent', str_contains($shopRows($wcId), '__absent__') ? 'YES' : 'no');
        $say('record_after', $recordKeys($wcId));
        $notice = get_transient('tmc_quantity_notice_' . get_current_user_id());
        $say('outcome', (string) (is_array($notice) ? ($notice['outcome'] ?? '__none__') : '__none__'));
        break;

    /** CONTROL: a save that changes nothing still changes nothing. */
    case 'control-no-change':
        [$id, $wcId] = $published(2, 6, 2);
        $say('rows_before', $shopRows($wcId));
        $say('record_before', $recordKeys($wcId));
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['post_title' => 'عنوان تازه']));
        $say('rows_after', $shopRows($wcId));
        $say('record_after', $recordKeys($wcId));
        break;

    /** CONTROL: a real manager edit still takes only the rule it changed. */
    case 'control-manager-edit':
        [$id, $wcId] = $published(2, 6, 2);
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('rows_after', $shopRows($wcId));
        $say('record_after', $recordKeys($wcId));
        /** @var \Tecteb\Marketplace\Modules\Product\Application\ManageProducts $manage */
        $manage = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);
        $manage->updateInventory(TMC_P47_VENDOR, TMC_P47_VENDOR, $id, 40, TMC_P47_SKU, 4, 6, 2);
        $catalog->publish($id);
        $say('rows_after_vendor_min', $shopRows($wcId));
        $say('manager_max_survived', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true) === '4' ? 'yes' : 'NO');
        $say('vendor_min_applied', (string) get_post_meta($wcId, ProjectedQuantity::META_MIN, true) === '4' ? 'yes' : 'NO');
        break;

    case 'reset':
        $id = $findBySku(TMC_P47_SKU);
        if ($id > 0) {
            $products->updateInventory($id, 40, TMC_P47_SKU, 2, 6, 2);
        }
        $say('reset_product', $id);
        break;
}
