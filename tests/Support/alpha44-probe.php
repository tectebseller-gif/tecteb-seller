<?php
/**
 * The owner's staging report on `alpha.44` bytes, measured.
 *
 * Four things were reported from `staging.tecteb.com` after installing
 * `alpha.44`, and this probe asks each of them of the build that is on disk —
 * so the same verbs answer on `alpha.44` and on the fix, and the control verbs
 * answer identically on both.
 *
 *  (a) A vendor typed a BRAND while editing a PUBLISHED product, «ذخیره و
 *      ادامه» advanced, and going back showed the brand field EMPTY. Three
 *      readings had to be told apart and only measurement can:
 *        - the input was never saved;
 *        - it was saved in the PROPOSED REVISION and the form shows the
 *          PUBLISHED row;
 *        - the next step's save overwrote the previous step's change.
 *  (b) On the last step the «ارسال برای بررسی» button was not shown — and
 *      nothing said why or what happens instead.
 *  (c) Price persisted in the vendor form and stock reached WooCommerce
 *      (observed 22), but the PRICE reaching WooCommerce was never
 *      independently confirmed. It is confirmed here, from the shop.
 *  (d) The min/max/step quantity fields of WooCommerce's Min Max Control tab
 *      were empty. What the marketplace itself stores and what it writes to
 *      the shop are two questions, and this probe asks both.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha44-probe.php <verb>
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

const TMC_P45_VENDOR = 8805;
const TMC_P45_CATEGORY = 'tmc-p45-syringe';
const TMC_P45_SKU = 'SKU-ALPHA45';

$verb = (string) ($args[0] ?? 'published-brand');
$c = Bootstrap::container();
wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
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

// The shop the probe's vendor belongs to. Approved, because every verb here is
// about a vendor who may already edit products — and `StaffAccess::storeFor()`
// answers `null` for anything else, which would make each verb measure a
// «you belong to no shop» page instead of the form.
(new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
    ->upsertProfile(TMC_P45_VENDOR, 'داروخانهٔ آزمون ۴۵', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P45_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $brand = '', int $price = 450000, int $min = 1, ?int $max = null, int $step = 1): ProductDetails {
    return new ProductDetails(
        title: 'سرنگ آزمون ۴۵',
        type: 'simple',
        categoryKey: TMC_P45_CATEGORY,
        brand: $brand,
        shortDescription: 'یک جمله',
        priceMinor: $price,
        sku: TMC_P45_SKU,
        stock: 22,
        minPurchase: $min,
        maxPurchase: $max,
        description: 'متن کامل فروشنده',
        purchaseStep: $step
    );
};

/** A PUBLISHED product whose brand is empty — the owner's starting point. */
$published = static function (int $min = 1, ?int $max = null, int $step = 1)
use ($products, $findBySku, $detailsOf, $revisions): int {
    $id = $findBySku(TMC_P45_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P45_VENDOR, $detailsOf('', 450000, $min, $max, $step), ProductStatus::Published);
    } else {
        // WITH THE ROW VERSION. `updateDetails('')` is a refusal by design —
        // «a token this layer cannot compare is a REFUSAL, never a waiver» —
        // so a fixture that omits it resets nothing and silently leaves the
        // previous verb's values in place. Measured: without this, the stock
        // control answered `revision_requested` because the brand another
        // verb had written was still there, which is a true sentence about
        // the fixture reported as a finding about the build.
        if (!$products->updateDetails($id, $detailsOf('', 450000, $min, $max, $step), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
        $products->updateInventory($id, 22, TMC_P45_SKU, $min, $max, $step);
        // Give back whatever a previous verb spent: a pending proposal makes
        // every later verb measure a different scenario.
        $pending = $revisions->pendingFor($id);
        if ($pending !== null) {
            $revisions->decide($pending->id, \Tecteb\Marketplace\Modules\Product\Domain\ProductRevision::SUPERSEDED, 0, '');
        }
    }
    // AND GIVE BACK THE OWNERSHIP RECORD.
    //
    // A verb that writes the shop's quantity rows by hand — the parity verb
    // does, and so does a manager — leaves them looking manager-edited, and
    // the next projection then correctly refuses to touch them. Measured: the
    // cart verb asked for 2/6/2, the projection held all three, and every
    // quantity was accepted because the rule on the shop was still 1/-/1. A
    // true sentence about the fixture, reported as a finding about the guard.
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    if ($wcId > 0) {
        delete_post_meta($wcId, '_tmc_quantity_written');
        delete_post_meta($wcId, '_tmc_quantity_conflicts');
        delete_post_meta($wcId, '_tmc_quantity_contradiction');
        foreach (['_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step'] as $key) {
            delete_post_meta($wcId, $key);
        }
    }
    return $id;
};

$urls = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls(
    'https://example.test/vendor/',
    'https://example.test/vendor/application/',
    'https://example.test/vendor/store/',
    'https://example.test/vendor/staff/',
    'https://example.test/vendor/invite/',
    'https://example.test/vendor/products/'
);

/** One real POST through the area router, on whichever build is on disk. */
$post = static function (array $fields) use ($c, $urls) {
    $_POST = $fields;
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $outcome = (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))->handle(
        'save_product',
        \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
        TMC_P45_VENDOR,
        $urls
    );
    if ($outcome === null) {
        throw new \RuntimeException('the area router did not handle save_product');
    }
    return $outcome;
};

/** One real GET render of the vendor product area, on whichever build is on disk. */
$render = static function (int $productId, string $step) use ($c, $urls): string {
    $_POST = [];
    $_GET = ['product' => (string) $productId, 'step' => $step];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $view = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView(
        \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
        TMC_P45_VENDOR,
        $urls,
        '',
        null
    );
    return (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))->render($view);
};

/**
 * Open the sale gate for a measurement, or say why it stayed shut.
 *
 * The gate as it ships refuses everything (DEC-02 and DEC-04 are open) and
 * NOT ONE LINE OF IT IS CHANGED. This uses the plugin's own
 * `tmc_order_trial_mode` — the switch `alpha.23` added for exactly this — which
 * waives only «the decisions are open» and which `isPermitted()` honours only
 * on a local, development or staging box. Returns a closure that puts it back.
 *
 * @return array{0:bool,1:string,2:callable():void}
 */
$openGateForMeasurement = static function () use ($c, $say): array {
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

/** The value of one named input in rendered markup — '' when absent. */
$inputValue = static function (string $html, string $name): string {
    if (preg_match('~<input[^>]*name="' . preg_quote($name, '~') . '"[^>]*>~', $html, $m) !== 1) {
        return '__absent__';
    }
    if (preg_match('~value="([^"]*)"~', $m[0], $v) !== 1) {
        return '';
    }
    return html_entity_decode($v[1], ENT_QUOTES, 'UTF-8');
};

/** Every value posted under one name, in document order — the carry-over trap. */
$allInputValues = static function (string $html, string $name) use ($inputValue): array {
    preg_match_all('~<input[^>]*name="' . preg_quote($name, '~') . '"[^>]*>~', $html, $m);
    $out = [];
    foreach ($m[0] as $tag) {
        $out[] = preg_match('~value="([^"]*)"~', $tag, $v) === 1
            ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8')
            : '';
    }
    return $out;
};

$formFields = static function (int $productId, string $revision, array $override = []) use ($products): array {
    $stored = $productId > 0 ? $products->find($productId) : null;
    return array_merge([
        'tmc_vendor_action' => 'save_product',
        'product_id' => (string) $productId,
        'step' => '1',
        'revision' => $revision,
        'title' => $stored?->details->title ?? 'سرنگ آزمون ۴۵',
        'type' => 'simple',
        'category' => TMC_P45_CATEGORY,
        'brand' => '',
        'short_description' => 'یک جمله',
        'price' => (string) ($stored?->details->priceMinor ?? 450000),
        'sale_price' => '',
        'sale_from' => '',
        'sale_to' => '',
        'sku' => TMC_P45_SKU,
        'stock' => '22',
        'min_purchase' => (string) ($stored?->details->minPurchase ?? 1),
        'max_purchase' => $stored?->details->maxPurchase === null ? '' : (string) $stored->details->maxPurchase,
        'weight_grams' => '0',
        'dimensions' => '',
        'tax_class' => '',
        'main_image_id' => '0',
        'description' => 'متن کامل فروشنده',
        'description_base' => 'value',
    ], $override);
};

$revisionBrand = static function (int $productId) use ($revisions): string {
    $pending = $revisions->pendingFor($productId);
    if ($pending === null) {
        return '__no_pending__';
    }
    $payload = $pending->payload;
    $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
    return array_key_exists('brand', $details) ? (string) $details['brand'] : '__no_brand_key__';
};

// -------------------------------------------------------------------- verbs

switch ($verb) {
    /**
     * (a) THE DEFECT. Type a brand on a published product, save, come back.
     *
     * Four measurements separate the three readings the owner's report could
     * have had:
     *   code                 — what the save said it did
     *   revision_brand       — what the PROPOSAL holds (never saved? saved?)
     *   row_brand            — what the PUBLISHED row holds (must not move)
     *   form_brand_after     — what the FORM SHOWS when the vendor comes back
     */
    case 'published-brand':
        $id = $published();
        $say('status_before', $products->find($id)?->status->value ?? '?');
        $say('row_brand_before', $products->find($id)?->details->brand === '' ? 'empty' : 'set');

        $outcome = $post($formFields($id, $products->find($id)?->rowVersion ?? '', [
            'brand' => 'برندی که فروشنده نوشته',
        ]));
        $say('code', $outcome->code);
        $say('revision_brand', $revisionBrand($id));
        $say('row_brand_after', $products->find($id)?->details->brand === '' ? 'empty' : 'set');

        $html = $render($id, '1');
        $say('form_brand_after', $inputValue($html, 'brand'));
        $say('form_mentions_pending', str_contains($html, 'پیشنهاد') ? 'yes' : 'no');
        break;

    /**
     * The third reading, ruled out on its own: does step 2's save overwrite
     * step 1's change? The carry-over hidden inputs are what would do it, and
     * `carryOver()` compares an INT key against a STRING step, so the current
     * step's fields are emitted as hidden inputs TOO.
     */
    case 'carry-over':
        $id = $published();
        $post($formFields($id, $products->find($id)?->rowVersion ?? '', [
            'brand' => 'برندی که فروشنده نوشته',
        ]));
        $html = $render($id, '1');
        $say('brand_inputs_on_step1', implode('|', $allInputValues($html, 'brand')));
        $html2 = $render($id, '2');
        $say('brand_inputs_on_step2', implode('|', $allInputValues($html2, 'brand')));
        break;

    /**
     * (b) The last step: is there a button, and — if not — is there an answer?
     *
     * Read from the block's DATA ATTRIBUTES, never from its Persian text. The
     * first version of this verb looked for the words and reported «no
     * sentence» about a page that had one, because the probe spelled
     * «نسخهٔ پیشنهادی» and the view spells «نسخه پیشنهادی». `alpha.23` wrote
     * that rule down; this is it arriving in a new place.
     *
     * Both states are asked, because they are different answers: a published
     * product with a proposal waiting, and one with nothing pending.
     */
    case 'last-step':
        $id = $published();
        $html = $render($id, '4');
        $say('nothing_pending_has_button', str_contains($html, 'submit_product') ? 'yes' : 'no');
        $say('nothing_pending_answer', preg_match('~data-status="([^"]*)" data-pending="([^"]*)"~', $html, $m) === 1
            ? $m[1] . '/' . $m[2]
            : '__absent__');
        $say('nothing_pending_notice', str_contains($html, 'tv-next-action') ? 'present' : 'absent');

        // Now with a proposal waiting.
        $post($formFields($id, $products->find($id)?->rowVersion ?? '', ['brand' => 'برندی که فروشنده نوشته']));
        $html2 = $render($id, '4');
        $say('pending_has_button', str_contains($html2, 'submit_product') ? 'yes' : 'no');
        $say('pending_answer', preg_match('~data-status="([^"]*)" data-pending="([^"]*)"~', $html2, $m2) === 1
            ? $m2[1] . '/' . $m2[2]
            : '__absent__');
        $say('pending_notice', str_contains($html2, 'tv-next-action') ? 'present' : 'absent');
        break;

    /**
     * The other three statuses a vendor can land on step 4 with. Each must
     * have its own answer — one condition answering for all of them is the
     * `alpha.33` defect, and silence was that one step worse.
     */
    case 'last-step-statuses':
        $id = $published();
        foreach ([
            ProductStatus::Submitted,
            ProductStatus::Suspended,
            ProductStatus::Archived,
        ] as $status) {
            $products->updateStatus($id, $status);
            $html = $render($id, '4');
            $say('answer_' . $status->value, preg_match('~data-status="([^"]*)" data-pending="([^"]*)"~', $html, $m) === 1
                ? $m[1] . '/' . $m[2]
                : '__absent__');
            $say('button_' . $status->value, str_contains($html, 'submit_product') ? 'yes' : 'no');
        }
        $products->updateStatus($id, ProductStatus::Published);
        break;

    /**
     * A visit and a no-change save must not pile up proposals.
     *
     * Measured by the revision ID: two saves that each create one leave two
     * different ids, and a count saturates where an id does not (`alpha.29`).
     */
    case 'no-change-save':
        $id = $published();
        $post($formFields($id, $products->find($id)?->rowVersion ?? '', [
            'brand' => 'برندی که فروشنده نوشته',
        ]));
        $first = $revisions->pendingFor($id)?->id ?? 0;
        $say('first_revision', $first);
        // Exactly what the form would re-post after the vendor comes back and
        // presses save again without typing anything.
        $html = $render($id, '1');
        $second = $post($formFields($id, $products->find($id)?->rowVersion ?? '', [
            'brand' => $inputValue($html, 'brand'),
        ]));
        $say('second_code', $second->code);
        $say('second_revision', $revisions->pendingFor($id)?->id ?? 0);
        break;

    /** (c) The price the SHOP holds, read from WooCommerce itself. */
    case 'price-to-shop':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $say('wc_version', defined('WC_VERSION') ? WC_VERSION : '?');
        $id = $published();
        $result = $catalog->publish($id);
        $say('publish_code', $result->code);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $say('wc_product_id', $wcId);
        $fresh = $wcId > 0 ? wc_get_product($wcId) : null;
        $say('row_price', $products->find($id)?->details->priceMinor ?? 0);
        $say('shop_regular_price', $fresh instanceof WC_Product ? $fresh->get_regular_price() : '__none__');
        $say('shop_price', $fresh instanceof WC_Product ? $fresh->get_price() : '__none__');
        $say('shop_stock', $fresh instanceof WC_Product ? (string) $fresh->get_stock_quantity() : '__none__');
        break;

    /** (d) Purchase quantities: what the marketplace stores, and what the shop holds. */
    case 'quantities':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $id = $published(2, 10, 3);
        $catalog->publish($id);
        $stored = $products->find($id);
        $say('row_min', $stored?->details->minPurchase ?? 0);
        $say('row_max', $stored?->details->maxPurchase === null ? 'null' : (string) $stored->details->maxPurchase);
        $say('row_step', $stored?->details->purchaseStep ?? 0);
        $wcId = (int) ($stored?->wcProductId ?? 0);
        // Every meta key any build of this plugin, or Min Max Control, could
        // have written. Absent is printed as `__absent__` rather than empty,
        // because «no row» and «a row holding nothing» are different answers.
        foreach ([
            '_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step',
            'min_allowed_quantity', 'max_allowed_quantity', 'group_of_quantity',
            '_wc_min_qty_product', '_wc_max_qty_product',
        ] as $key) {
            $say('meta_' . $key, metadata_exists('post', $wcId, $key)
                ? (string) get_post_meta($wcId, $key, true)
                : '__absent__');
        }
        $say('min_max_control_active', defined('WC_MIN_MAX_QUANTITIES') || class_exists('WC_Min_Max_Quantities') ? 'yes' : 'no');
        $record = get_post_meta($wcId, '_tmc_quantity_written', true);
        $say('record_keys', is_array($record) ? implode(',', array_keys($record)) : '__none__');
        $conflicts = get_post_meta($wcId, '_tmc_quantity_conflicts', true);
        $say('conflicts', is_array($conflicts) ? implode(',', $conflicts) : '');
        break;

    /**
     * A MANAGER edits the maximum in WooCommerce, then the marketplace
     * projects again. The manager's number must survive, and the key must be
     * reported — `alpha.44`'s rule, applied to this field.
     */
    case 'quantity-manager-edit':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $id = $published(2, 10, 3);
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $say('shop_max_after_projection', (string) get_post_meta($wcId, '_tmc_max_purchase', true));

        // The manager's own hand, through the product object the panel uses.
        $wcProduct = wc_get_product($wcId);
        $wcProduct->update_meta_data('_tmc_max_purchase', '4');
        $wcProduct->save();
        $say('shop_max_after_manager', (string) get_post_meta($wcId, '_tmc_max_purchase', true));

        // And project again, with the marketplace still saying 10.
        $catalog->publish($id);
        $say('shop_max_after_reprojection', (string) get_post_meta($wcId, '_tmc_max_purchase', true));
        $conflicts = get_post_meta($wcId, '_tmc_quantity_conflicts', true);
        $say('conflicts', is_array($conflicts) ? implode(',', $conflicts) : '');
        // The two the manager did NOT touch are still ours.
        $say('shop_min', (string) get_post_meta($wcId, '_tmc_min_purchase', true));
        $say('shop_step', (string) get_post_meta($wcId, '_tmc_purchase_step', true));
        break;

    /**
     * The flash path, which §3 reaches through «form re-read»: a REFUSED save
     * puts the vendor's own values back on the form, and the long description
     * has to survive that round trip.
     *
     * `ProductArea::detailsToArray()` writes `description`; `detailsFromArray()`
     * omits the seventeenth argument, so it comes back as `null` — «this
     * product never had one». Nothing in the owner's report named this, and it
     * is the same three-state rule `alpha.41` wrote down, surviving one layer
     * further out.
     *
     * The refusal used is a stale row version, because that is a refusal that
     * genuinely CARRIES the vendor's values forward rather than failing early.
     */
    case 'failed-save-description':
        $id = $published();
        $say('row_description', $products->find($id)?->details->description ?? '__null__');
        $outcome = $post($formFields($id, '1', [
            'brand' => 'برندی تازه',
            'description' => 'متن بلند فروشنده که نباید گم شود',
            'description_base' => 'value',
        ]));
        $say('code', $outcome->code);
        $html = $render($id, '1');
        // The long description is a textarea, not an input.
        $shown = preg_match('~<textarea[^>]*name="description"[^>]*>(.*?)</textarea>~s', $html, $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
            : '__absent__';
        $say('form_description_after_refusal', $shown === '' ? '__empty__' : $shown);
        $say('form_description_base', $inputValue($html, 'description_base'));
        break;

    /**
     * PARITY: does `PurchaseQuantity` give the same answer WooCommerce gives?
     *
     * «اوراکلِ یک آزمون ممکن است غلط باشد» — `alpha.43`'s rule. The whole
     * reason this class adopted WooCommerce's multiple-of arithmetic is so
     * that the classic page and the block cart enforce ONE rule, and that
     * claim is worth nothing unless it is measured against the real
     * `QuantityLimits`. Each triple is written to the shop product and both
     * sides are asked.
     *
     * Prints one line per triple: `min/max/step → ours vs theirs`.
     */
    case 'quantity-parity':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $limitsClass = 'Automattic\\WooCommerce\\StoreApi\\Utilities\\QuantityLimits';
        if (!class_exists($limitsClass)) {
            $say('quantity_limits_class', 'absent');
            break;
        }
        $say('wc_version', defined('WC_VERSION') ? WC_VERSION : '?');
        $id = $published();
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $limits = new $limitsClass();
        $mismatches = 0;
        foreach ([
            [1, null, 1], [2, 10, 1], [1, 5, 1],
            [2, null, 3], [3, 10, 3], [10, 11, 4],
            [1, null, 2], [5, 20, 5], [7, 7, 7], [4, 9, 2],
        ] as [$min, $max, $step]) {
            $wcProduct = wc_get_product($wcId);
            $wcProduct->update_meta_data('_tmc_min_purchase', (string) $min);
            $wcProduct->update_meta_data('_tmc_max_purchase', $max === null ? '' : (string) $max);
            $wcProduct->update_meta_data('_tmc_purchase_step', (string) $step);
            $wcProduct->save();
            // Stock high enough that WooCommerce's own ceiling never decides.
            $fresh = wc_get_product($wcId);
            $fresh->set_manage_stock(true);
            $fresh->set_stock_quantity(9999);
            $fresh->save();

            $ours = \Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity::of($min, $max, $step);
            $theirs = $limits->get_add_to_cart_limits(wc_get_product($wcId));
            $oursText = $ours->min . '/' . ($ours->largestAllowed() ?? '-') . '/' . $ours->step;
            $theirsText = (int) $theirs['minimum'] . '/' . (int) $theirs['maximum'] . '/' . (int) $theirs['multiple_of'];
            // WooCommerce has no «no maximum»: it caps at the stock figure,
            // so an unlimited rule is compared on the minimum and the step
            // only, and that is said rather than hidden.
            $same = $ours->max === null
                ? ((int) $theirs['minimum'] === $ours->min && (int) $theirs['multiple_of'] === $ours->step)
                : $oursText === $theirsText;
            if (!$same) {
                $mismatches++;
            }
            $say(
                'pair_' . $min . '_' . ($max ?? 'null') . '_' . $step,
                $oursText . ' vs ' . $theirsText . ' ' . ($same ? 'same' : 'DIFFER')
            );
        }
        $say('mismatches', $mismatches);
        // Give the product back to the fixture's own rule.
        $wcProduct = wc_get_product($wcId);
        $wcProduct->update_meta_data('_tmc_min_purchase', '1');
        $wcProduct->update_meta_data('_tmc_max_purchase', '');
        $wcProduct->update_meta_data('_tmc_purchase_step', '1');
        $wcProduct->save();
        break;

    /**
     * THE CART, on real WooCommerce: every quantity path §4 names.
     *
     * A filter that decorates an input box proves nothing about a hand-built
     * request, so each line below is an actual `add_to_cart()` /
     * `set_quantity()` against a real `WC_Cart` with no browser in the
     * picture — which is precisely the «با تغییر درخواست یا با خاموش بودن
     * جاوااسکریپت قابل دور زدن نباشد» case.
     *
     * The repeated-product line is the one that matters most: WooCommerce
     * will hold the same item twice, so a maximum enforced per line is a
     * maximum of twice itself.
     */
    case 'cart-enforcement':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $say('wc_version', defined('WC_VERSION') ? WC_VERSION : '?');

        // THE SALE GATE IS NOT TOUCHED, AND THIS IS NOT A WAY ROUND IT.
        //
        // Measured first: with the gate as it ships, every `add_to_cart()`
        // here is refused with «این کالا فعلاً برای فروش در دسترس نیست» —
        // `PurchaseGuard` doing its job, because DEC-02 and DEC-04 are open.
        // That refusal is correct and no line of it is changed.
        //
        // To measure a QUANTITY rule a basket has to be reachable, so this
        // uses the plugin's own `tmc_order_trial_mode` — the switch `alpha.23`
        // added for exactly this, which waives ONLY «the decisions are open»
        // and which `TrialUnlock::isPermitted()` honours ONLY on a local,
        // development or staging environment. On production it can sit at `1`
        // for ever and sell nothing. The switch is turned back off at the end
        // of the verb, and its state is printed either way.
        [$gateOpen, $gateWhy, $restoreGate] = $openGateForMeasurement();
        $say('gate_for_measurement', $gateOpen ? $gateWhy : 'not_open:' . $gateWhy);
        if (!$gateOpen) {
            $say('cart_measurement', 'not_run');
            break;
        }
        // min 2, max 6, step 2 → allowed 2, 4, 6.
        $id = $published(2, 6, 2);
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $fresh = wc_get_product($wcId);
        $fresh->set_manage_stock(true);
        $fresh->set_stock_quantity(500);
        $fresh->set_sold_individually(false);
        $fresh->save();
        $say('wc_product_id', $wcId);
        $say('rule', '2/6/2');

        // A cart and a session, as a front-end request would have.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        WC()->cart->empty_cart();

        $notices = static function (): string {
            if (!function_exists('wc_get_notices')) {
                return '';
            }
            $all = wc_get_notices('error');
            wc_clear_notices();
            $out = [];
            foreach ($all as $notice) {
                $out[] = is_array($notice) ? (string) ($notice['notice'] ?? '') : (string) $notice;
            }
            return implode(' | ', $out);
        };
        $notices();

        // WHICH CALL SITES ACTUALLY VALIDATE — asked of WooCommerce 11.0.1's
        // source, not assumed.
        //
        // `WC_Cart::add_to_cart()` does NOT fire
        // `woocommerce_add_to_cart_validation`. Four places do, and they are
        // every way a buyer reaches a basket:
        //
        //   includes/class-wc-form-handler.php:981,1013,1063  the classic form
        //                                                     (and so the
        //                                                     no-JavaScript path)
        //   includes/class-wc-ajax.php:520                    the ajax button
        //   src/StoreApi/Utilities/CartController.php:335     the blocks
        //   includes/class-wc-cart-session.php:615            a restored session
        //
        // So the filter is asked here the way those four ask it. The first
        // version of this verb called `add_to_cart()` directly and reported
        // every quantity accepted — a true sentence about an API that is not
        // a validation point, in the shape of a finding about the guard.
        $validate = static function (int $quantity) use ($wcId, $notices): string {
            $passed = apply_filters('woocommerce_add_to_cart_validation', true, $wcId, $quantity, 0, [], []);
            $said = $notices();
            return ($passed === true ? 'allowed' : 'refused') . ($said === '' ? '' : ' (said)');
        };

        $say('qty_1', $validate(1));        // below the minimum
        $say('qty_2', $validate(2));        // allowed
        $say('qty_3', $validate(3));        // off the step
        $say('qty_4', $validate(4));        // allowed
        $say('qty_6', $validate(6));        // the ceiling
        $say('qty_8', $validate(8));        // over the ceiling

        // THE REPEATED PRODUCT. Four are in the basket; asking for four more
        // would be eight, over a maximum of six. A check that looked at the
        // line alone would wave it through.
        //
        // The basket is filled through the cart API deliberately — that is
        // the one way to get a known basket without a browser, and it is NOT
        // a validation point, which is exactly why it can be used to stage
        // the state the validator is then asked about.
        WC()->cart->add_to_cart($wcId, 4);
        $say('basket_before_repeat', WC()->cart->get_cart_contents_count());
        $notices();
        $say('repeat_add_4', $validate(4));
        $say('repeat_add_2', $validate(2));  // exactly six in total — allowed

        // The classic cart page's own quantity change, through its own filter.
        $keys = array_keys(WC()->cart->get_cart());
        $key = (string) ($keys[0] ?? '');
        $values = WC()->cart->get_cart()[$key] ?? [];
        $notices();
        $changeTo = static function (int $quantity) use ($key, $values, $notices): string {
            $passed = apply_filters('woocommerce_update_cart_validation', true, $key, $values, $quantity);
            $said = $notices();
            return ($passed === true ? 'allowed' : 'refused') . ($said === '' ? '' : ' (said)');
        };
        $say('change_to_5', $changeTo(5));   // off the step
        $say('change_to_6', $changeTo(6));   // allowed
        $say('change_to_9', $changeTo(9));   // over the ceiling

        // THE TILL. A quantity forced straight into the cart object — past
        // every filter above, which is what a hand-built request or a stale
        // session amounts to — must still be caught before an order.
        $contents = WC()->cart->cart_contents;
        $contents[$key]['quantity'] = 99;
        WC()->cart->cart_contents = $contents;
        $notices();
        do_action('woocommerce_check_cart_items');
        $say('till_on_99', $notices() === '' ? '__silent__' : 'named');

        // …and says nothing about a basket that obeys the rule.
        $contents[$key]['quantity'] = 6;
        WC()->cart->cart_contents = $contents;
        $notices();
        do_action('woocommerce_check_cart_items');
        $say('till_on_6', $notices() === '' ? '__silent__' : 'named');

        // THE CART TOTAL for a legitimately repeated product: six units at
        // 450,000 and nothing doubled or lost.
        WC()->cart->calculate_totals();
        $say('cart_count', WC()->cart->get_cart_contents_count());
        $say('cart_subtotal', (string) WC()->cart->get_subtotal());
        $say('expected_subtotal', (string) (6 * 450000));

        // The Store API's own cart-item validator, which the blocks call.
        $storeApiRefused = 'allowed';
        try {
            do_action('woocommerce_store_api_validate_cart_item', wc_get_product($wcId), ['quantity' => 99]);
        } catch (\Throwable $e) {
            $storeApiRefused = 'refused';
        }
        $say('store_api_on_99', $storeApiRefused);

        WC()->cart->empty_cart();
        // Put the switch back. A verb that leaves a sale gate open is a verb
        // that changes what the next verb measures — and this one in
        // particular would leave the site in a state nobody asked for.
        $restoreGate();
        $say('trial_after', (bool) get_option('tmc_order_trial_mode', false) ? 'still_on' : 'off');
        // The gate's own answer is memoised per request, so this line is
        // about THIS process and not about the site: the option is off, which
        // is what `trial_after` reports and what the next request will read.
        $say('gate_memo_in_this_process', (string) ($c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class)->check()['reason'] ?? ''));
        break;

    /**
     * Sold individually WINS, and the contradiction is reported.
     *
     * WooCommerce forces one unit; a minimum of two over the top of that
     * would make the product unbuyable for ever. So the rule stands down —
     * and says so, rather than resolving it in silence.
     */
    case 'sold-individually':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $id = $published(2, 6, 2);
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $fresh = wc_get_product($wcId);
        $fresh->set_manage_stock(true);
        $fresh->set_stock_quantity(500);
        $fresh->set_sold_individually(true);
        $fresh->save();
        delete_post_meta($wcId, '_tmc_quantity_contradiction');

        // The gate opened for the measurement, so the only thing that can
        // refuse below is the QUANTITY rule. Without this the verb reported
        // «one unit refused» about `PurchaseGuard` doing its job.
        [$gateOpen, $gateWhy, $restoreGate] = $openGateForMeasurement();
        $say('gate', $gateOpen ? $gateWhy : 'not_open:' . $gateWhy);
        if (!$gateOpen) {
            $say('measurement', 'not_run');
            break;
        }

        // The rule IS on the shop — this is not a product without one.
        $say('shop_rule', get_post_meta($wcId, '_tmc_min_purchase', true) . '/'
            . get_post_meta($wcId, '_tmc_max_purchase', true) . '/'
            . get_post_meta($wcId, '_tmc_purchase_step', true));

        // ASKED BEFORE READ. The contradiction is recorded by the guard when
        // the guard runs, and `WC_Cart::add_to_cart()` is not a point where it
        // does — so the first version of this verb read the meta before
        // anything had written it and reported «not recorded» about a guard
        // that records correctly.
        $args = wc_get_quantity_input_args([], wc_get_product($wcId));
        $say('input_min', (string) $args['min_value']);
        $say('input_max', (string) $args['max_value']);
        $say('input_step', (string) $args['step']);
        $say('one_unit_allowed', apply_filters('woocommerce_add_to_cart_validation', true, $wcId, 1, 0, [], []) === true ? 'yes' : 'no');
        $say('two_units_allowed', apply_filters('woocommerce_add_to_cart_validation', true, $wcId, 2, 0, [], []) === true ? 'yes' : 'no');
        $say('contradiction_recorded', (string) get_post_meta($wcId, '_tmc_quantity_contradiction', true));

        $fresh = wc_get_product($wcId);
        $fresh->set_sold_individually(false);
        $fresh->save();
        $restoreGate();
        break;

    /**
     * A ceiling WooCommerce already lowered is never raised.
     *
     * Stock of 3 against a vendor maximum of 6: the shop's number has to win,
     * or the rule would sell a fourth unit that is not there.
     */
    case 'stock-ceiling':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $id = $published(2, 6, 2);
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $fresh = wc_get_product($wcId);
        $fresh->set_manage_stock(true);
        $fresh->set_stock_quantity(3);
        $fresh->set_backorders('no');
        $fresh->set_sold_individually(false);
        $fresh->save();
        $args = wc_get_quantity_input_args([], wc_get_product($wcId));
        $say('stock', '3');
        $say('vendor_max', '6');
        $say('input_max', (string) $args['max_value']);
        $say('input_min', (string) $args['min_value']);
        $say('input_step', (string) $args['step']);
        $fresh = wc_get_product($wcId);
        $fresh->set_stock_quantity(500);
        $fresh->save();
        break;

    /**
     * A VARIABLE product: the parent's rule applies to each variation, and
     * nothing else about the variation moves.
     *
     * The stated semantics are «the rule is per sellable item, and for a
     * variable product that is the variation»; there is no per-variation
     * override in this round. So what has to be measured is that a variation
     * is governed by the parent's rule — and that projecting the rule did not
     * touch the variation's id, price, stock or SKU, which is §4's last
     * requirement and §8's regression.
     */
    case 'variable-quantities':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        /** @var \Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface $variations */
        $variations = $c->get(\Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface::class);
        $sku = 'SKU-ALPHA45-VAR';
        $varId = 0;
        foreach ($products->forVendor(TMC_P45_VENDOR) as $product) {
            if ($product->details->sku === $sku) {
                $varId = $product->id;
            }
        }
        $makeDetails = static fn (): ProductDetails => new ProductDetails(
            title: 'سرنگ متنوع آزمون ۴۵',
            type: 'variable',
            categoryKey: TMC_P45_CATEGORY,
            shortDescription: 'یک جمله',
            priceMinor: 450000,
            sku: $sku,
            stock: 50,
            minPurchase: 2,
            maxPurchase: 6,
            description: 'متن کامل فروشنده',
            purchaseStep: 2
        );
        if ($varId === 0) {
            $varId = $products->create(TMC_P45_VENDOR, $makeDetails(), ProductStatus::Published);
        } else {
            $products->updateDetails($varId, $makeDetails(), $products->find($varId)?->rowVersion ?? '');
            $products->updateInventory($varId, 50, $sku, 2, 6, 2);
        }
        $parentWc = (int) ($products->find($varId)?->wcProductId ?? 0);
        if ($parentWc > 0) {
            // Give back what a previous run spent, including the storefront
            // variations whose SKUs would otherwise stay taken (`alpha.43`).
            foreach ((array) get_children(['post_parent' => $parentWc, 'post_type' => 'product_variation']) as $child) {
                wp_delete_post((int) $child->ID, true);
            }
            foreach (['_tmc_quantity_written', '_tmc_quantity_conflicts', '_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step'] as $k) {
                delete_post_meta($parentWc, $k);
            }
        }
        $variations->saveAttribute($varId, 'size', 'اندازه', ['کوچک', 'بزرگ'], 0);
        $small = $variations->saveVariation($varId, new \Tecteb\Marketplace\Modules\Product\Domain\ProductVariation(
            0, $varId, ['size' => 'کوچک'], 210000, null, 'V45-SMALL', 7
        ));
        $catalog->publish($varId);
        $parentWc = (int) ($products->find($varId)?->wcProductId ?? 0);
        $wcSmall = (int) ($variations->findVariation($varId, $small)?->wcVariationId ?? 0);
        $say('parent_wc', $parentWc);
        $say('variation_wc', $wcSmall);
        $say('parent_rule', get_post_meta($parentWc, '_tmc_min_purchase', true) . '/'
            . get_post_meta($parentWc, '_tmc_max_purchase', true) . '/'
            . get_post_meta($parentWc, '_tmc_purchase_step', true));
        // The variation holds no rows of its own — the rule is the product's.
        $say('variation_has_own_rows', metadata_exists('post', $wcSmall, '_tmc_min_purchase') ? 'yes' : 'no');

        // The rule the VARIATION is governed by, through WooCommerce's own
        // quantity args — which is what both the classic page and the blocks
        // read.
        $variation = wc_get_product($wcSmall);
        $args = wc_get_quantity_input_args([], $variation);
        $say('variation_input', $args['min_value'] . '/' . $args['max_value'] . '/' . $args['step']);

        // And the variation's own data is untouched by any of it.
        $say('variation_price', (string) $variation->get_regular_price());
        $say('variation_stock', (string) $variation->get_stock_quantity());
        $say('variation_sku', (string) $variation->get_sku());

        // The cart half is its own verb: the gate memoises per request, and
        // `publish()` above has already asked it — so a `check()` here is the
        // answer from before the switch was requested, for ever in this
        // process. Measured: `gate=not_open:decisions_open` on a site where
        // the switch was permitted.
        $say('cart_check', 'see_verb_variable_cart');
        break;

    /**
     * The variation in a basket — a separate invocation, deliberately.
     *
     * Nothing here projects anything, so the sale gate is asked for the first
     * time after the trial switch is requested rather than before it.
     */
    case 'variable-cart':
        if (!$catalog->isAvailable()) {
            $say('woocommerce', 'absent');
            break;
        }
        $parentWc = 0;
        $wcSmall = 0;
        foreach ($products->forVendor(TMC_P45_VENDOR) as $product) {
            if ($product->details->sku === 'SKU-ALPHA45-VAR') {
                $parentWc = (int) ($product->wcProductId ?? 0);
                /** @var \Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface $vr */
                $vr = $c->get(\Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface::class);
                foreach ($vr->variations($product->id) as $variation) {
                    if ($variation->sku === 'V45-SMALL') {
                        $wcSmall = (int) ($variation->wcVariationId ?? 0);
                    }
                }
            }
        }
        if ($parentWc <= 0 || $wcSmall <= 0) {
            $say('fixture', 'run_variable-quantities_first');
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
        $check = static fn (int $q): string => apply_filters(
            'woocommerce_add_to_cart_validation',
            true,
            $parentWc,
            $q,
            $wcSmall,
            [],
            []
        ) === true ? 'allowed' : 'refused';
        $say('variation_qty_1', $check(1));
        $say('variation_qty_2', $check(2));
        $say('variation_qty_3', $check(3));
        $say('variation_qty_6', $check(6));
        $say('variation_qty_8', $check(8));
        $restoreGate();
        break;

    /** The step-1 reading of a DRAFT — unchanged by this round, so a control. */
    case 'control-draft-brand':
        $id = $published();
        $products->updateStatus($id, ProductStatus::Draft);
        $post($formFields($id, $products->find($id)?->rowVersion ?? '', [
            'brand' => 'برند پیش‌نویس',
        ]));
        $say('row_brand', $products->find($id)?->details->brand ?? '');
        $html = $render($id, '1');
        $say('form_brand', $inputValue($html, 'brand'));
        $products->updateStatus($id, ProductStatus::Published);
        break;

    /** Stock is an IMMEDIATE field: it still writes straight through. A control. */
    case 'control-stock-immediate':
        $id = $published();
        $outcome = $post($formFields($id, $products->find($id)?->rowVersion ?? '', ['stock' => '7']));
        $say('code', $outcome->code);
        $say('row_stock', $products->find($id)?->details->stock ?? 0);
        break;

    case 'reset':
        $id = $findBySku(TMC_P45_SKU);
        if ($id > 0) {
            $pending = $revisions->pendingFor($id);
            if ($pending !== null) {
                $revisions->decide($pending->id, \Tecteb\Marketplace\Modules\Product\Domain\ProductRevision::SUPERSEDED, 0, '');
            }
            $products->updateDetails($id, $detailsOf());
            $products->updateInventory($id, 22, TMC_P45_SKU, 1, null);
            $products->updateStatus($id, ProductStatus::Published);
        }
        $say('reset_product', $id);
        break;

    default:
        $say('unknown_verb', $verb);
}
