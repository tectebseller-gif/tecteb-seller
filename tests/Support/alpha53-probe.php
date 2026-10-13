<?php
/**
 * Leaving «Min Max Control», measured against the real plugin.
 *
 * Every number this prints comes from Min Max 8.1.0 actually installed and
 * actually enforcing — header `8.1.0`, `WC_MMQ_VERSION` `8.1.0.0`, main file
 * sha256 `1e5aa797b8da1b8ac12f6c9adf8c967e4a9c3fc36e893ae4c29d690a84f125fa` —
 * on WooCommerce 11.0.1, on the disposable install. Nothing here is a reading
 * of the plugin's source turned into an expectation: the source said what the
 * rule IS, and this says what the cart DOES.
 *
 * **The two states the instruction asks to be compared**, in this order and
 * never mixed:
 *
 *  - `both-*` verbs run with Min Max ACTIVE and this marketplace active. That
 *    is today's site.
 *  - `only-*` verbs run with Min Max DEACTIVATED. That is the site after the
 *    exit, and the question is whether the same quantities are refused.
 *
 * Switching between them is the OWNER's action in the checklist and this
 * probe's `deactivate` / `reactivate` verbs on the disposable site only. The
 * probe refuses to run anywhere else.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha53-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxHandoverRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

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

const TMC_P53_VENDOR = 9130;
const TMC_P53_CATEGORY = 'tmc-p53-minmax';
/** Convertible: the step divides the minimum, so both plugins allow 4,6,8,10. */
const TMC_P53_SKU_OK = 'SKU-A53-OK';
/** Not convertible: step 3 from a minimum of 2 — the sets share nothing. */
const TMC_P53_SKU_STEP = 'SKU-A53-STEP';
/** Convertible, and the destination already carries a manager's own rule. */
const TMC_P53_SKU_HELD = 'SKU-A53-HELD';

$verb = (string) ($args[0] ?? 'report');
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
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = implode('|', array_map('strval', $value));
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};
// WooCommerce's FRONTEND includes, loaded on purpose.
//
// Both plugins' cart rules report refusals through `wc_add_notice()`, and
// WP-CLI loads WooCommerce without its frontend half — so a probe that just
// applied the filter fataled inside MIN MAX's own code
// (`min-max-controller.php:1125`), which measures nothing. Measured, not
// guessed: the first run printed one input-args line and then died.
if (!function_exists('wc_add_notice') && defined('WC_ABSPATH')) {
    require_once WC_ABSPATH . 'includes/wc-notice-functions.php';
}
if (function_exists('wc_load_cart') && (WC()->cart === null)) {
    wc_load_cart();
}

$say('probe', 'ran');
$say('minmax_active', is_plugin_active('woo-min-max-quantity-step-control-single/wcmmq.php'));
$say('minmax_version', defined('WC_MMQ_VERSION') ? WC_MMQ_VERSION : '__absent__');

(new DbVendorRepository($db, $clock))->upsertProfile(TMC_P53_VENDOR, 'تجهیزات آزمون ۵۳', true, false);

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P53_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $sku, string $title): ProductDetails {
    $named = [
        'title' => $title,
        'type' => 'simple',
        'categoryKey' => TMC_P53_CATEGORY,
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 500000,
        'sku' => $sku,
        'stock' => 90,
        'minPurchase' => 1,
        'maxPurchase' => null,
        'description' => null,
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

/**
 * The three products, published and projected, with NO marketplace quantity
 * rule of their own — so the only rule in force is Min Max's.
 *
 * Everything this fixture spends it gives back (`alpha.45`): the Min Max meta
 * rows, our three meta rows, the ownership record and the handover ledger are
 * all cleared first, because a row left behind by an earlier verb would make
 * the next verb's answer a true sentence about the previous measurement.
 */
$arrange = static function () use ($products, $findBySku, $detailsOf, $catalog, $db): array {
    $ids = [];
    foreach ([
        TMC_P53_SKU_OK => 'ماسک گام‌دار ۵۳',
        TMC_P53_SKU_STEP => 'دستکش گام‌دار ۵۳',
        TMC_P53_SKU_HELD => 'سرنگ گام‌دار ۵۳',
    ] as $sku => $title) {
        $id = $findBySku($sku);
        if ($id === 0) {
            $id = $products->create(TMC_P53_VENDOR, $detailsOf($sku, $title), ProductStatus::Draft);
        } else {
            $products->updateDetails($id, $detailsOf($sku, $title), $products->find($id)?->rowVersion ?? 0);
        }
        $row = $products->find($id);
        if ($row !== null && $row->status !== ProductStatus::Published) {
            $products->updateStatus($id, ProductStatus::Published, $row->rowVersion);
        }
        $catalog->publish($id);
        $wcId = $products->find($id)?->wcProductId ?? 0;
        $ids[$sku] = ['product_id' => $id, 'wc_id' => $wcId];
        // Clear both sides so the next verb measures only what it sets.
        foreach (ProjectedQuantity::keys() as $key) {
            delete_post_meta($wcId, $key);
        }
        delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
        foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
            foreach (['', '_wcmmq_', '_wcmmq_s_'] as $prefix) {
                delete_post_meta($wcId, $prefix . $field);
            }
        }
        $db->execute(
            'DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d',
            [$wcId]
        );
        wc_delete_product_transients($wcId);
    }
    // A NON-MARKETPLACE product, so the coverage question can be measured
    // rather than argued: a plain WooCommerce product with a Min Max rule.
    $native = (int) ($db->getVar(
        'SELECT ID FROM `' . $db->prefix() . 'posts`
          WHERE post_type = %s AND post_title = %s LIMIT 1',
        ['product', 'کالای غیرِبازارگاه ۵۳']
    ) ?? 0);
    if ($native === 0) {
        $native = wp_insert_post([
            'post_title' => 'کالای غیرِبازارگاه ۵۳',
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);
        $plain = wc_get_product($native);
        if ($plain instanceof \WC_Product) {
            $plain->set_regular_price('100000');
            $plain->set_manage_stock(false);
            $plain->save();
        }
    }
    foreach (ProjectedQuantity::keys() as $key) {
        delete_post_meta($native, $key);
    }
    delete_post_meta($native, ProjectedQuantity::OWNED_META);
    $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$native]);
    $ids['native'] = ['product_id' => 0, 'wc_id' => (int) $native];
    return $ids;
};

/** Set a Min Max rule the way the plugin's own admin panel does. */
$setMinMax = static function (int $wcId, string $min, string $max, string $step, string $default = ''): void {
    $prefix = defined('WC_MMQ_PREFIX') ? WC_MMQ_PREFIX : '';
    update_post_meta($wcId, $prefix . 'min_quantity', $min);
    update_post_meta($wcId, $prefix . 'max_quantity', $max);
    update_post_meta($wcId, $prefix . 'product_step', $step);
    if ($default !== '') {
        update_post_meta($wcId, $prefix . 'default_quantity', $default);
    }
    wc_delete_product_transients($wcId);
};

/**
 * Which quantities the CART accepts — the only question that matters.
 *
 * `WC_Cart::add_to_cart()` is NOT the validation point (`alpha.45`): the
 * filter is run by the form handler, the AJAX handler and the Store API's
 * CartController. So the filter is applied the way those do, which is what
 * both plugins hang their rules on.
 */
$allowedQuantities = static function (int $wcId, array $wanted): array {
    // `wc_clear_notices()` needs WooCommerce's frontend includes, which
    // WP-CLI does not load. Guarded rather than assumed: a probe that fatals
    // on a helper it did not need measures nothing (`alpha.13`).
    $clear = static function (): void {
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
    };
    $allowed = [];
    foreach ($wanted as $qty) {
        $clear();
        $passed = apply_filters('woocommerce_add_to_cart_validation', true, $wcId, $qty, 0, false);
        if ($passed === true) {
            $allowed[] = $qty;
        }
    }
    $clear();
    return $allowed;
};

/** What the quantity INPUT offers, which is the other half of the rule. */
$inputArgs = static function (int $wcId): string {
    $product = wc_get_product($wcId);
    if (!$product instanceof \WC_Product) {
        return '__unreadable__';
    }
    $args = apply_filters('woocommerce_quantity_input_args', [
        'min_value' => 1,
        'max_value' => -1,
        'step' => 1,
    ], $product);
    return ($args['min_value'] ?? '?') . '/' . ($args['max_value'] ?? '?') . '/' . ($args['step'] ?? '?');
};

/**
 * Open the sale gate for a measurement, or say why it stayed shut.
 *
 * The gate as it ships refuses EVERYTHING (DEC-02 and DEC-04 are open) and not
 * one line of it is changed here. This uses the plugin's own
 * `tmc_order_trial_mode` — local/development/staging only — and puts it back.
 *
 * Measured, which is why it is here: the first run of `both-enforce` reported
 * that Min Max allowed NOTHING on all three marketplace products while the
 * non-marketplace one answered `4|6|8|10`. That was the sale gate refusing
 * every add-to-cart, not a quantity rule — a true sentence about a closed
 * gate in the clothing of a rule measurement.
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

/** @var MinMaxReaderInterface $reader */
$reader = $c->get(MinMaxReaderInterface::class);
/** @var MigrateMinMax $service */
$service = $c->get(MigrateMinMax::class);
/** @var MinMaxHandoverRepositoryInterface $ledger */
$ledger = $c->get(MinMaxHandoverRepositoryInterface::class);
/** @var ShopQuantityPortInterface $port */
$port = $c->get(ShopQuantityPortInterface::class);

switch ($verb) {
    // ------------------------------------------------- both plugins active
    case 'both-arrange':
        $ids = $arrange();
        $setMinMax($ids[TMC_P53_SKU_OK]['wc_id'], '4', '10', '2');
        $setMinMax($ids[TMC_P53_SKU_STEP]['wc_id'], '2', '11', '3');
        $setMinMax($ids[TMC_P53_SKU_HELD]['wc_id'], '4', '10', '2');
        $setMinMax($ids['native']['wc_id'], '4', '10', '2');
        // The manager's own rule on the HELD product's destination, written
        // the way a manager writes it: no ownership record of ours.
        update_post_meta($ids[TMC_P53_SKU_HELD]['wc_id'], ProjectedQuantity::META_MIN, '6');
        update_post_meta($ids[TMC_P53_SKU_HELD]['wc_id'], ProjectedQuantity::META_MAX, '12');
        update_post_meta($ids[TMC_P53_SKU_HELD]['wc_id'], ProjectedQuantity::META_STEP, '1');
        foreach ($ids as $sku => $pair) {
            $say('wc_' . $sku, $pair['wc_id']);
        }
        $say('arranged', 'yes');
        break;

    case 'both-enforce':
        [$open, $why, $restore] = $openGateForMeasurement();
        $say('gate', $open ? 'open' : $why);
        foreach ([TMC_P53_SKU_OK, TMC_P53_SKU_STEP, TMC_P53_SKU_HELD] as $sku) {
            $id = $findBySku($sku);
            $wcId = $products->find($id)?->wcProductId ?? 0;
            $say('minmax_' . $sku . '_input', $inputArgs($wcId));
            $say('minmax_' . $sku . '_allowed', $allowedQuantities($wcId, [1, 2, 3, 4, 5, 6, 8, 10, 11, 12]));
        }
        $native = (int) ($db->getVar(
            'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title = %s LIMIT 1',
            ['product', 'کالای غیرِبازارگاه ۵۳']
        ) ?? 0);
        $say('minmax_native_input', $inputArgs($native));
        $say('minmax_native_allowed', $allowedQuantities($native, [1, 2, 3, 4, 5, 6, 8, 10, 11, 12]));
        $restore();
        break;

    case 'preview':
        $preview = $service->preview();
        $say('available', (bool) $preview['available']);
        $say('mode', $preview['mode']['mode'] ?? '__none__');
        $say('prefix', ($preview['mode']['prefix'] ?? '') === '' ? '__empty__' : $preview['mode']['prefix']);
        $say('option_key', $preview['mode']['option'] ?? '__none__');
        $say('premium', (bool) ($preview['mode']['premium'] ?? false));
        foreach (['product', 'variation', 'term', 'global', 'default_quantity'] as $level) {
            $say('count_' . $level, (int) ($preview['counts'][$level] ?? 0));
        }
        foreach (['convertible', 'refused', 'held_by_manager', 'unenforced', 'already_decided', 'scanned'] as $key) {
            $say('preview_' . $key, (int) ($preview[$key] ?? 0));
        }
        foreach (($preview['problems'] ?? []) as $code => $count) {
            $say('problem_' . $code, (int) $count);
        }
        $say('wrote_nothing', $ledger->tally() === [] ? 'yes' : 'no_rows_exist_already');
        break;

    case 'migrate':
        $batch = $service->migrateBatch(1, 0, false);
        $say('batch_ok', (bool) $batch['ok']);
        $say('batch_done', (bool) $batch['done']);
        $say('batch_cursor', (int) $batch['cursor']);
        foreach ($batch['counts'] as $key => $count) {
            $say('migrated_' . $key, (int) $count);
        }
        foreach ([TMC_P53_SKU_OK, TMC_P53_SKU_STEP, TMC_P53_SKU_HELD] as $sku) {
            $wcId = $products->find($findBySku($sku))?->wcProductId ?? 0;
            $row = $ledger->find($wcId);
            $say('ledger_' . $sku, $row === null ? '__none__' : (string) $row['status']);
            $say('ledger_' . $sku . '_problems', $row === null ? '__none__' : (string) ($row['problems'] ?: '__none__'));
            $say('rows_' . $sku, implode('/', [
                (string) (get_post_meta($wcId, ProjectedQuantity::META_MIN, true) ?: '__empty__'),
                (string) (get_post_meta($wcId, ProjectedQuantity::META_MAX, true) ?: '__empty__'),
                (string) (get_post_meta($wcId, ProjectedQuantity::META_STEP, true) ?: '__empty__'),
            ]));
        }
        break;

    case 'migrate-again':
        // REPEATABLE: a second run over the same page writes nothing new.
        $batch = $service->migrateBatch(2, 0, false);
        foreach ($batch['counts'] as $key => $count) {
            $say('again_' . $key, (int) $count);
        }
        break;

    case 'tally':
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        break;

    // -------------------------------------------- marketplace only (exit)
    case 'only-enforce':
        [$open, $why, $restore] = $openGateForMeasurement();
        $say('gate', $open ? 'open' : $why);
        foreach ([TMC_P53_SKU_OK, TMC_P53_SKU_STEP, TMC_P53_SKU_HELD] as $sku) {
            $wcId = $products->find($findBySku($sku))?->wcProductId ?? 0;
            $say('exit_' . $sku . '_input', $inputArgs($wcId));
            $say('exit_' . $sku . '_allowed', $allowedQuantities($wcId, [1, 2, 3, 4, 5, 6, 8, 10, 11, 12]));
        }
        $native = (int) ($db->getVar(
            'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title = %s LIMIT 1',
            ['product', 'کالای غیرِبازارگاه ۵۳']
        ) ?? 0);
        $say('exit_native_input', $inputArgs($native));
        $say('exit_native_allowed', $allowedQuantities($native, [1, 2, 3, 4, 5, 6, 8, 10, 11, 12]));
        $restore();
        break;

    case 'rollback':
        $wcId = $products->find($findBySku(TMC_P53_SKU_OK))?->wcProductId ?? 0;
        $result = $service->rollback($wcId);
        $say('rollback_code', $result->code);
        $say('rows_after_rollback', implode('/', array_map(
            static fn (string $k): string => metadata_exists('post', $wcId, $k)
                ? (string) (get_post_meta($wcId, $k, true) ?: '__empty__')
                : '__absent__',
            ProjectedQuantity::keys()
        )));
        $say('ledger_after_rollback', (string) ($ledger->find($wcId)['status'] ?? '__none__'));
        break;

    case 'rollback-after-change':
        // A manager edited the rule AFTER the migration, so the rollback must
        // refuse and name the field.
        //
        // THE PRECONDITION IS ESTABLISHED HERE, and the first version of this
        // verb did not establish it: it used the HELD product, whose ledger
        // row says `held_by_manager` because nothing was ever written to it —
        // so the answer was `minmax_rollback_not_written`, a true sentence
        // about a product that had no rollback to refuse. `alpha.29`'s rule:
        // a falsification whose precondition does not hold refuses nothing.
        $ids = $arrange();
        $setMinMax($ids[TMC_P53_SKU_OK]['wc_id'], '4', '10', '2');
        $wcId = $ids[TMC_P53_SKU_OK]['wc_id'];
        $batch = $service->migrateBatch(9, $wcId - 1, false, 1);
        $say('arranged_status', (string) ($ledger->find($wcId)['status'] ?? '__none__'));
        $say('arranged_rows', implode('/', array_map(
            static fn (string $k): string => (string) (get_post_meta($wcId, $k, true) ?: '__empty__'),
            ProjectedQuantity::keys()
        )));
        // The manager's later decision.
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '8');
        $result = $service->rollback($wcId);
        $say('changed_rollback_code', $result->code);
        $say('changed_rollback_field', (string) ($result->context['field'] ?? '__none__'));
        $say('changed_rollback_wrote', (string) ($result->context['wrote'] ?? '__none__'));
        $say('changed_rollback_now', (string) ($result->context['now'] ?? '__none__'));
        $say('manager_value_kept', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true));
        break;

    case 'port':
        // The one distinction the whole rollback rests on: absent vs empty.
        $wcId = $products->find($findBySku(TMC_P53_SKU_OK))?->wcProductId ?? 0;
        delete_post_meta($wcId, ProjectedQuantity::META_MIN);
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '');
        $read = $port->read($wcId);
        $say('absent_reads', $read['min'] === null ? '__null__' : 'string:' . $read['min']);
        $say('empty_reads', $read['max'] === null ? '__null__' : 'string:' . ($read['max'] === '' ? '__empty__' : $read['max']));
        $say('owns_marketplace_product', $port->marketplaceOwns($wcId));
        $native = (int) ($db->getVar(
            'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title = %s LIMIT 1',
            ['product', 'کالای غیرِبازارگاه ۵۳']
        ) ?? 0);
        $say('owns_native_product', $port->marketplaceOwns($native));
        break;

    case 'reset':
        $arrange();
        $say('reset', 'yes');
        break;

    default:
        $say('unknown_verb', $verb);
}
