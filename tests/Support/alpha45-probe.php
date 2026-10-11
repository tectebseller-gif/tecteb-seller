<?php
/**
 * The five findings against `alpha.45`, measured.
 *
 * Every verb here is about code this round wrote, and three of the findings
 * are the kind that only a second pair of eyes catches:
 *
 *  §1 `PurchaseQuantity::of()` RAISED the vendor's ceiling. `min=10 max=11
 *     step=4` came out `12/12`, so a vendor who said «at most eleven» got a
 *     rule allowing twelve — and `alpha.45` had a unit test asserting that as
 *     correct, which is the test encoding the defect. The same method turned
 *     an invalid maximum into NO MAXIMUM, which is the largest possible
 *     increase in purchase permission.
 *  §2 `QuantityPanel` deleted the ownership record on EVERY save carrying its
 *     nonce — and its nonce is in the product editor on every render, so
 *     saving only the title handed the three rules to the manager for ever
 *     and the vendor's legitimate changes stopped applying. An invalid
 *     request rewrote all three rows instead of being refused.
 *
 * The panel verbs fire `woocommerce_process_product_meta` with a `$_POST`
 * **taken out of the panel's own rendered markup** — the field names and the
 * nonce are scraped from the HTML, so the request under test is the one the
 * real form sends, at the point WooCommerce invokes it. That is not a browser,
 * and it is not a direct call to the service either; it is said plainly here
 * rather than described as more than it is.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha45-probe.php <verb>
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

const TMC_P46_VENDOR = 8807;
const TMC_P46_CATEGORY = 'tmc-p46-gauze';
const TMC_P46_SKU = 'SKU-ALPHA46';

$verb = (string) ($args[0] ?? 'rule-ceiling');
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
    ->upsertProfile(TMC_P46_VENDOR, 'داروخانهٔ آزمون ۴۶', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P46_VENDOR) as $product) {
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
        'categoryKey' => TMC_P46_CATEGORY,
        'brand' => $brand,
        'shortDescription' => 'یک جمله',
        'priceMinor' => 300000,
        'sku' => TMC_P46_SKU,
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
    $id = $findBySku(TMC_P46_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P46_VENDOR, $detailsOf('', $min, $max, $step), ProductStatus::Published);
    } else {
        // WITH THE ROW VERSION — `updateDetails('')` is a refusal by design.
        if (!$products->updateDetails($id, $detailsOf('', $min, $max, $step), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
        $products->updateInventory($id, 40, TMC_P46_SKU, $min, $max, $step);
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

// -------------------------------------------------------------------- verbs

switch ($verb) {
    /**
     * §1 THE CEILING. A vendor says «between ten and eleven, in fours».
     * `alpha.45` answers 12/12 — eleven became twelve.
     */
    case 'rule-ceiling':
        foreach ([[10, 11, 4], [3, 10, 3], [5, 7, 5], [2, 6, 2], [1, 100, 7]] as [$min, $max, $step]) {
            $rule = PurchaseQuantity::of($min, $max, $step);
            $ceiling = $rule->largestAllowed();
            $satisfiable = method_exists($rule, 'isSatisfiable') ? ($rule->isSatisfiable() ? 'yes' : 'no') : 'n/a';
            $say(
                'stated_' . $min . '_' . $max . '_' . $step,
                'effective=' . $rule->min . '/' . ($ceiling === null ? 'none' : (string) $ceiling) . '/' . $rule->step
                    . ' raised_ceiling=' . ($ceiling !== null && $ceiling > $max ? 'YES' : 'no')
                    . ' satisfiable=' . $satisfiable
            );
        }
        break;

    /** §1 An invalid maximum must not become «no maximum». */
    case 'rule-invalid-max':
        foreach ([[5, 2, 1], [3, 0, 1], [10, 1, 1]] as [$min, $max, $step]) {
            $rule = PurchaseQuantity::of($min, $max, $step);
            $say(
                'stated_' . $min . '_' . $max . '_' . $step,
                'max=' . ($rule->max === null ? 'UNLIMITED' : (string) $rule->max)
                    . ' allows_9999=' . ($rule->allows(9999) ? 'YES' : 'no')
                    . ' satisfiable=' . (method_exists($rule, 'isSatisfiable') ? ($rule->isSatisfiable() ? 'yes' : 'no') : 'n/a')
            );
        }
        break;

    /**
     * §1 The SAME answer from all four doors: the vendor form, the admin
     * panel, the CSV importer and the service the approval path uses.
     */
    case 'rule-one-answer':
        [$id, $wcId] = $published();
        $manage = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);

        // the service (vendor form and approval path both go through it)
        $service = $manage->updateInventory(TMC_P46_VENDOR, TMC_P46_VENDOR, $id, 40, TMC_P46_SKU, 10, 11, 4);
        $say('service_10_11_4', $service->code);
        $ok = $manage->updateInventory(TMC_P46_VENDOR, TMC_P46_VENDOR, $id, 40, TMC_P46_SKU, 3, 10, 3);
        $say('service_3_10_3', $ok->code);

        // the CSV importer
        $csv = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductCsv::class);
        $header = "sku,title,price,stock,min_purchase,max_purchase,purchase_step\n";
        $bad = $csv->import(TMC_P46_VENDOR, TMC_P46_VENDOR, $header . TMC_P46_SKU . ",گاز,300000,40,10,11,4\n", false);
        $say('csv_10_11_4', (string) ($bad['rows'][0]['code'] ?? $bad['code'] ?? '?'));
        $good = $csv->import(TMC_P46_VENDOR, TMC_P46_VENDOR, $header . TMC_P46_SKU . ",گاز,300000,40,3,10,3\n", false);
        $say('csv_3_10_3', (string) ($good['rows'][0]['code'] ?? $good['code'] ?? '?'));

        // the admin panel
        [, $fields] = $panelForm($wcId);
        $before = $shopRows($wcId);
        $panelSave($wcId, array_merge($fields, [
            'tmc_min_purchase' => '10',
            'tmc_max_purchase' => '11',
            'tmc_purchase_step' => '4',
        ]));
        $say('panel_10_11_4_rows_before', $before);
        $say('panel_10_11_4_rows_after', $shopRows($wcId));
        break;

    /**
     * §2 AN INVALID REQUEST MUST CHANGE NOTHING — not the three values, not
     * the ownership record.
     */
    case 'panel-invalid':
        [$id, $wcId] = $published(2, 6, 2);
        $say('rows_before', $shopRows($wcId));
        $say('record_before', $recordKeys($wcId));
        [, $fields] = $panelForm($wcId);
        $say('form_fields', implode(',', array_keys($fields)));
        // «هیچ داده‌ای» — not just the three rules. The rest of the product
        // is read before and after, because a refusal that nonetheless wrote
        // the title or the price would still be a refusal that changed data.
        $fresh = wc_get_product($wcId);
        $otherBefore = $fresh->get_name() . '|' . $fresh->get_regular_price()
            . '|' . (string) $fresh->get_stock_quantity() . '|' . $fresh->get_sku();
        $panelSave($wcId, array_merge($fields, [
            'tmc_min_purchase' => '5',
            'tmc_max_purchase' => '2',
        ]));
        $say('rows_after', $shopRows($wcId));
        $say('record_after', $recordKeys($wcId));
        $after = wc_get_product($wcId);
        $otherAfter = $after->get_name() . '|' . $after->get_regular_price()
            . '|' . (string) $after->get_stock_quantity() . '|' . $after->get_sku();
        $say('other_product_data_unchanged', $otherBefore === $otherAfter ? 'yes' : 'NO');

        // …and the impossible window, from the same door.
        $panelSave($wcId, array_merge($fields, [
            'tmc_min_purchase' => '10',
            'tmc_max_purchase' => '11',
            'tmc_purchase_step' => '4',
        ]));
        $say('rows_after_unreachable', $shopRows($wcId));
        $say('record_after_unreachable', $recordKeys($wcId));
        break;

    /**
     * §1 DATA THAT IS ALREADY INCONSISTENT. Written straight onto the shop
     * rows, which is how it could only ever have got there — `validate()` now
     * refuses it at all four doors.
     *
     * The declared behaviour: nothing may be bought until it is fixed, the
     * product page's own numbers are NOT widened, and the manager's screens
     * say so. Refusing is the only reading of contradictory data that cannot
     * increase what may be bought.
     */
    case 'unsatisfiable-stored':
        [$id, $wcId] = $published(2, 6, 2);
        $fresh = wc_get_product($wcId);
        $fresh->set_manage_stock(true);
        $fresh->set_stock_quantity(500);
        $fresh->set_sold_individually(false);
        $fresh->save();
        // By hand, as a pre-alpha.46 row or a database edit would be.
        update_post_meta($wcId, '_tmc_min_purchase', '10');
        update_post_meta($wcId, '_tmc_max_purchase', '11');
        update_post_meta($wcId, '_tmc_purchase_step', '4');
        delete_post_meta($wcId, '_tmc_quantity_contradiction');
        $say('stored_rows', $shopRows($wcId));

        $args = wc_get_quantity_input_args([], wc_get_product($wcId));
        $say('input_args', $args['min_value'] . '/' . $args['max_value'] . '/' . $args['step']);
        $say('args_not_widened', (int) $args['min_value'] === 1 && (int) $args['step'] === 1 ? 'yes' : 'no');

        $say('contradiction_recorded', (string) get_post_meta($wcId, '_tmc_quantity_contradiction', true));
        [$html] = $panelForm($wcId);
        $say('panel_warns', str_contains($html, 'ناسازگار') ? 'yes' : 'no');
        $say('panel_shows_stored_max', preg_match('~name="tmc_max_purchase"[^>]*value="11"~', $html) === 1
            || preg_match('~value="11"[^>]*name="tmc_max_purchase"~', $html) === 1 ? 'yes' : 'no');
        break;

    /**
     * §2 THE TITLE-ONLY SAVE. The panel's nonce is in the editor on every
     * render, so a manager who only renames a product sent it too.
     */
    case 'panel-title-only':
        [$id, $wcId] = $published(2, 6, 2);
        $say('rows_before', $shopRows($wcId));
        $say('record_before', $recordKeys($wcId));
        [, $fields] = $panelForm($wcId);
        // Exactly what the editor posts when only the title changed: the
        // panel's fields unchanged, carrying their rendered values.
        $panelSave($wcId, array_merge($fields, ['post_title' => 'عنوان تازه']));
        $say('rows_after', $shopRows($wcId));
        $say('record_after', $recordKeys($wcId));

        // …and then the vendor makes a change they are allowed to make.
        $manage = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);
        $result = $manage->updateInventory(TMC_P46_VENDOR, TMC_P46_VENDOR, $id, 40, TMC_P46_SKU, 2, 8, 2);
        $say('vendor_change_code', $result->code);
        $catalog->publish($id);
        $say('rows_after_vendor_change', $shopRows($wcId));
        $say('vendor_change_applied', $shopRows($wcId) === '2/8/2' ? 'yes' : 'NO');
        $conflicts = get_post_meta($wcId, '_tmc_quantity_conflicts', true);
        $say('conflicts', is_array($conflicts) ? implode(',', $conflicts) : '');
        break;

    /** §2 A real manager change must touch only the rule it changed. */
    case 'panel-one-rule':
        [$id, $wcId] = $published(2, 6, 2);
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('rows_after', $shopRows($wcId));
        $say('record_after', $recordKeys($wcId));
        $say('record_keeps_min_and_step', str_contains($recordKeys($wcId), 'min') && str_contains($recordKeys($wcId), 'step') ? 'yes' : 'NO');

        // the vendor then changes the MINIMUM, which the manager never took
        $manage = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);
        $manage->updateInventory(TMC_P46_VENDOR, TMC_P46_VENDOR, $id, 40, TMC_P46_SKU, 4, 6, 2);
        $catalog->publish($id);
        $say('rows_after_vendor_min', $shopRows($wcId));
        $say('manager_max_survived', (string) get_post_meta($wcId, '_tmc_max_purchase', true) === '4' ? 'yes' : 'NO');
        $say('vendor_min_applied', (string) get_post_meta($wcId, '_tmc_min_purchase', true) === '4' ? 'yes' : 'NO');
        break;

    /** §2 «Return to automatic» — does the control the panel promises work? */
    case 'panel-return-auto':
        [$id, $wcId] = $published(2, 6, 2);
        [, $fields] = $panelForm($wcId);
        $panelSave($wcId, array_merge($fields, ['tmc_max_purchase' => '4']));
        $say('after_manager_edit', $shopRows($wcId));
        $say('record_after_manager_edit', $recordKeys($wcId));

        [$html, $fields] = $panelForm($wcId);
        $say('panel_offers_a_reset_control', preg_match('~name="tmc_quantity_reset"~', $html) === 1 ? 'yes' : 'no');
        // `alpha.45` promised «leave the fields empty», so that is what is
        // measured on it; the fix adds a named control and this verb asks for
        // both, so one verb answers on both trees.
        $post = array_merge($fields, [
            'tmc_min_purchase' => '',
            'tmc_max_purchase' => '',
            'tmc_purchase_step' => '',
            'tmc_quantity_reset' => '1',
        ]);
        $panelSave($wcId, $post);
        $say('rows_after_reset', $shopRows($wcId));
        $say('record_after_reset', $recordKeys($wcId));
        $catalog->publish($id);
        $say('rows_after_reprojection', $shopRows($wcId));
        $say('back_to_automatic', $shopRows($wcId) === '2/6/2' && $recordKeys($wcId) !== '__none__' ? 'yes' : 'NO');
        break;

    /** §3 The proposed brand survives a real edit, a round trip and a refresh. */
    case 'brand-persists':
        [$id, $wcId] = $published();
        $urls = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls(
            'https://example.test/vendor/',
            'https://example.test/vendor/application/',
            'https://example.test/vendor/store/',
            'https://example.test/vendor/staff/',
            'https://example.test/vendor/invite/',
            'https://example.test/vendor/products/'
        );
        $post = static function (array $fields) use ($c, $urls) {
            $_POST = $fields;
            $_FILES = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $outcome = (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))->handle(
                'save_product',
                \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
                TMC_P46_VENDOR,
                $urls
            );
            $_POST = [];
            return $outcome;
        };
        $render = static function (string $step) use ($c, $urls, $id): string {
            $_POST = [];
            $_GET = ['product' => (string) $id, 'step' => $step];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $view = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView(
                \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
                TMC_P46_VENDOR,
                $urls,
                '',
                null
            );
            return (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))->render($view);
        };
        $brandOf = static function (string $html): string {
            if (preg_match('~<input[^>]*name="brand"[^>]*>~', $html, $m) !== 1) {
                return '__absent__';
            }
            return preg_match('~value="([^"]*)"~', $m[0], $v) === 1
                ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8')
                : '';
        };
        $stored = $products->find($id);
        $outcome = $post([
            'tmc_vendor_action' => 'save_product',
            'product_id' => (string) $id,
            'step' => '1',
            'revision' => $stored?->rowVersion ?? '',
            'title' => 'گاز استریل آزمون ۴۶',
            'type' => 'simple',
            'category' => TMC_P46_CATEGORY,
            'brand' => 'برندی که فروشنده نوشته',
            'short_description' => 'یک جمله',
            'price' => '300000',
            'sale_price' => '', 'sale_from' => '', 'sale_to' => '',
            'sku' => TMC_P46_SKU,
            'stock' => '40',
            'min_purchase' => '2', 'max_purchase' => '6', 'purchase_step' => '2',
            'weight_grams' => '0', 'dimensions' => '', 'tax_class' => '',
            'main_image_id' => '0',
            'description' => 'متن کامل فروشنده',
            'description_base' => 'value',
        ]);
        $say('save_code', $outcome->code);
        $say('brand_on_step1', $brandOf($render('1')));          // come back
        $say('brand_on_step1_again', $brandOf($render('1')));    // refresh
        $say('brand_after_step2_visit', $brandOf($render('2')) === '' ? 'carried_empty' : 'carried');
        $say('row_brand_untouched', $products->find($id)?->details->brand === '' ? 'yes' : 'NO');
        break;

    /**
     * The basket half of the verb above — a SEPARATE invocation, because the
     * sale gate memoises per request and `publish()` has already asked it by
     * the time `unsatisfiable-stored` finishes. Run that verb first; this one
     * projects nothing.
     */
    case 'unsatisfiable-cart':
        $id = $findBySku(TMC_P46_SKU);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId <= 0) {
            $say('fixture', 'run_unsatisfiable-stored_first');
            break;
        }
        $say('stored_rows', $shopRows($wcId));
        if ($shopRows($wcId) !== '10/11/4') {
            $say('fixture', 'rows_are_not_the_inconsistent_ones');
            break;
        }
        [$gateOpen, $gateWhy, $restoreGate] = $openGateForMeasurement();
        $say('gate', $gateOpen ? $gateWhy : 'not_open:' . $gateWhy);
        if (!$gateOpen) {
            $say('measurement', 'not_run');
            break;
        }
        // A session, so `wc_add_notice()` has somewhere to put the sentence.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        $check = static fn (int $q): string => apply_filters(
            'woocommerce_add_to_cart_validation',
            true,
            $wcId,
            $q,
            0,
            [],
            []
        ) === true ? 'allowed' : 'refused';
        foreach ([1, 8, 10, 11, 12] as $q) {
            $say('qty_' . $q, $check($q));
        }
        // …and the sentence a shopper gets says what is wrong rather than
        // naming a quantity that would not work either.
        if (function_exists('wc_get_notices')) {
            $notices = wc_get_notices('error');
            wc_clear_notices();
            $last = '';
            foreach ($notices as $notice) {
                $last = is_array($notice) ? (string) ($notice['notice'] ?? '') : (string) $notice;
            }
            $say('shopper_told', $last === '' ? '__silent__' : (str_contains($last, 'ناسازگار') ? 'named' : 'other'));
        }
        $restoreGate();
        break;

    case 'reset':
        $id = $findBySku(TMC_P46_SKU);
        if ($id > 0) {
            $pending = $revisions->pendingFor($id);
            if ($pending !== null) {
                $revisions->decide($pending->id, \Tecteb\Marketplace\Modules\Product\Domain\ProductRevision::SUPERSEDED, 0, '');
            }
            $products->updateDetails($id, $detailsOf(), $products->find($id)?->rowVersion ?? '');
            $products->updateInventory($id, 40, TMC_P46_SKU, 1, null, 1);
        }
        $say('reset_product', $id);
        break;

    default:
        $say('unknown_verb', $verb);
}
