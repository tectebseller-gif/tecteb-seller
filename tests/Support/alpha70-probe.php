<?php
/**
 * The bounded comparison in `MigrateMinMax::classifyDecided()`, measured.
 *
 * `alpha.70` answered «did the rule survive the migration» by asking both
 * plugins about the quantities 1…60 and declaring `decided_in_force` when all
 * sixty answers matched. Sixty samples are not a set. This probe arranges the
 * two states the owner named — a ceiling that differs only above 60, and two
 * minimums that differ only above 60 — and prints, side by side:
 *
 *  - whether the two sides agree on the first sixty quantities, and
 *  - whether they agree at all, measured far enough out to see.
 *
 * It loads on BOTH trees (`alpha.32`): anything this round adds is asked for
 * with `method_exists`/`array_key_exists` and reported `__absent__` on the
 * base, because a probe that fatals on the bytes it was written to measure
 * measures nothing.
 *
 * And it gives back every guarantee it spends (`alpha.45`). The verdict is one
 * answer about the WHOLE catalogue, so `bound-arrange` stashes every other Min
 * Max meta row on the site into one option and `reset` writes them all back,
 * byte for byte, before deleting the product this probe created.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha70-probe.php <verb>
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
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
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
// NOTHING LEAVES THIS BOX. No mail, no sms, no gateway.
add_filter('pre_wp_mail', static fn () => true, 9999);

const TMC_P70_VENDOR = 990070;
const TMC_P70_CATEGORY = 'tmc-p70-minmax';
/** One product, re-arranged from scratch by every verb, so no case can leak into another. */
const TMC_P70_SKU = 'SKU-A70-SET';
/** Where the site's other Min Max rows are kept while a verdict is measured. */
const TMC_P70_STASH = 'tmc_p70_minmax_stash';

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
/** @var MinMaxReaderInterface $reader */
$reader = $c->get(MinMaxReaderInterface::class);
/** @var MigrateMinMax $service */
$service = $c->get(MigrateMinMax::class);
/** @var MinMaxHandoverRepositoryInterface $ledger */
$ledger = $c->get(MinMaxHandoverRepositoryInterface::class);
/** @var ShopQuantityPortInterface $port */
$port = $c->get(ShopQuantityPortInterface::class);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = $value === [] ? '__empty__' : implode('|', array_map('strval', $value));
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};

$mmPrefix = defined('WC_MMQ_PREFIX') ? WC_MMQ_PREFIX : '';
$mmKeys = [
    $mmPrefix . 'min_quantity',
    $mmPrefix . 'max_quantity',
    $mmPrefix . 'product_step',
    $mmPrefix . 'default_quantity',
];

$setMinMax = static function (int $wcId, string $min, string $max, string $step) use ($mmPrefix): void {
    update_post_meta($wcId, $mmPrefix . 'min_quantity', $min);
    update_post_meta($wcId, $mmPrefix . 'max_quantity', $max);
    update_post_meta($wcId, $mmPrefix . 'product_step', $step);
    wc_delete_product_transients($wcId);
};

$clearMinMax = static function (int $wcId) use ($mmKeys): void {
    foreach ($mmKeys as $key) {
        delete_post_meta($wcId, $key);
    }
    wc_delete_product_transients($wcId);
};

/** Take every OTHER Min Max rule off the site and remember it exactly. */
$stashOtherRules = static function (array $keepIds) use ($db, $mmKeys): int {
    $placeholders = implode(', ', array_fill(0, count($mmKeys), '%s'));
    $rows = $db->getResults(
        'SELECT post_id, meta_key, meta_value FROM `' . $db->prefix() . 'postmeta`
          WHERE meta_key IN (' . $placeholders . ')',
        $mmKeys
    );
    $stash = get_option(TMC_P70_STASH, null);
    $stash = is_array($stash) ? $stash : [];
    $taken = 0;
    foreach ($rows as $row) {
        $postId = (int) $row['post_id'];
        if (in_array($postId, $keepIds, true)) {
            continue;
        }
        $stash[] = [
            'post_id' => $postId,
            'meta_key' => (string) $row['meta_key'],
            'meta_value' => (string) $row['meta_value'],
        ];
        delete_post_meta($postId, (string) $row['meta_key']);
        $taken++;
    }
    update_option(TMC_P70_STASH, $stash, false);
    return $taken;
};

$restoreStashedRules = static function (): int {
    $stash = get_option(TMC_P70_STASH, null);
    if (!is_array($stash)) {
        return 0;
    }
    $back = 0;
    foreach ($stash as $row) {
        if (!is_array($row) || !isset($row['post_id'], $row['meta_key'])) {
            continue;
        }
        update_post_meta((int) $row['post_id'], (string) $row['meta_key'], (string) ($row['meta_value'] ?? ''));
        $back++;
    }
    delete_option(TMC_P70_STASH);
    return $back;
};

$findBySku = static function () use ($products): int {
    foreach ($products->forVendor(TMC_P70_VENDOR) as $product) {
        if ($product->details->sku === TMC_P70_SKU) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (): ProductDetails {
    $named = [
        'title' => 'ماسک مجموعه ۷۰',
        'type' => 'simple',
        'categoryKey' => TMC_P70_CATEGORY,
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 500000,
        'sku' => TMC_P70_SKU,
        'stock' => 900,
        'minPurchase' => 1,
        'maxPurchase' => null,
        'description' => null,
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

$rowsOf = static function (int $wcId): string {
    return implode('/', array_map(
        static fn (string $k): string => metadata_exists('post', $wcId, $k)
            ? (string) (get_post_meta($wcId, $k, true) === '' ? '__empty__' : get_post_meta($wcId, $k, true))
            : '__absent__',
        ProjectedQuantity::keys()
    ));
};

/** The one product, published, projected, owned, with no rule and no ledger row. */
$arrange = static function () use ($products, $findBySku, $detailsOf, $catalog, $db, $clearMinMax, $stashOtherRules, $clock): int {
    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_P70_VENDOR, 'تجهیزات آزمون ۷۰', true, false);
    $id = $findBySku();
    if ($id === 0) {
        $id = $products->create(TMC_P70_VENDOR, $detailsOf(), ProductStatus::Draft);
    } else {
        $products->updateDetails($id, $detailsOf(), $products->find($id)?->rowVersion ?? 0);
    }
    $row = $products->find($id);
    if ($row !== null && $row->status !== ProductStatus::Published) {
        $products->updateStatus($id, ProductStatus::Published, $row->rowVersion);
    }
    $catalog->publish($id);
    $wcId = $products->find($id)?->wcProductId ?? 0;
    $db->execute(
        'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE wc_product_id = %d',
        ['marketplace', $wcId]
    );
    $stashOtherRules([$wcId]);
    return $wcId;
};

/** Back to «no rule, no destination rows, no ledger row» before each case. */
$blank = static function (int $wcId) use ($db, $clearMinMax): void {
    foreach (ProjectedQuantity::keys() as $key) {
        delete_post_meta($wcId, $key);
    }
    delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
    delete_post_meta($wcId, '_tmc_quantity_enforced');
    $clearMinMax($wcId);
    $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$wcId]);
    wc_delete_product_transients($wcId);
};

/** The verdict, printed under one prefix. */
$verdict = static function (string $prefix) use ($service, $say): array {
    if (!method_exists($service, 'exitVerdict')) {
        $say($prefix . '_verdict', '__absent__');
        return [];
    }
    $answer = $service->exitVerdict();
    $say($prefix . '_verdict', (string) ($answer['verdict'] ?? '__none__'));
    $say($prefix . '_permitted', (bool) ($answer['permitted'] ?? false));
    $counts = (array) ($answer['counts'] ?? []);
    foreach ([
        'already_decided', 'decided_in_force', 'decided_drifted', 'decided_unenforced',
        'decided_unreadable', 'decided_unprovable', 'unknown', 'source_rows', 'scanned',
    ] as $key) {
        $say(
            $prefix . '_count_' . $key,
            array_key_exists($key, $counts) ? (int) $counts[$key] : '__absent__'
        );
    }
    $say($prefix . '_blockers', array_map(
        static fn (array $b): string => (string) ($b['code'] ?? '?') . ':' . (int) ($b['count'] ?? 0),
        (array) ($answer['blockers'] ?? [])
    ));
    return $answer;
};

/** The rule Min Max carries for one product right now, or null. */
$ruleFor = static function (int $wcId) use ($reader) {
    $page = $reader->productRulesAfter(max(0, $wcId - 1), 1);
    foreach ($page['rows'] as $row) {
        if ((int) $row['product_id'] === $wcId) {
            return $row['rule'];
        }
    }
    return null;
};

/**
 * The two sets, sampled to one bound and then to a far bigger one.
 *
 *  - `minmax`: what Min Max allows today, by Min Max's own arithmetic;
 *  - `after`: what the destination rows would allow once Min Max is gone.
 *
 * `same_to_60` is exactly the question `alpha.70` asked. `same_to_<bound>` is
 * the question it should have asked. The point of the measurement is that the
 * two answers differ.
 */
$sets = static function (string $prefix, int $wcId, $rule, int $bound) use ($port, $say): void {
    $read = $port->readRows($wcId);
    $min = $read['min'];
    $max = $read['max'];
    $step = $read['step'];
    $stored = PurchaseQuantity::of(
        $min === null || $min === '' ? 1 : (int) $min,
        $max === null || $max === '' ? null : (int) $max,
        $step === null || $step === '' ? 1 : (int) $step
    );
    $disagreeAt = [];
    $sameTo60 = true;
    for ($q = 1; $q <= $bound; $q++) {
        $source = $rule !== null && $rule->sourceAllows($q);
        if ($source === $stored->allows($q)) {
            continue;
        }
        if ($q <= 60) {
            $sameTo60 = false;
        }
        if (count($disagreeAt) < 6) {
            $disagreeAt[] = $q;
        }
    }
    $say($prefix . '_bound', $bound);
    $say($prefix . '_same_to_60', $sameTo60);
    $say($prefix . '_same_to_bound', $disagreeAt === []);
    $say($prefix . '_first_disagreements', $disagreeAt);
    $say($prefix . '_minmax_rule', $rule === null
        ? '__none__'
        : $rule->min . '/' . ($rule->max ?? '__null__') . '/' . $rule->step);
    $say($prefix . '_stored_rule', $stored->min . '/' . ($stored->max ?? '__null__') . '/' . $stored->step);
};

/**
 * One case: source rule, migrate it, then put the destination rule in place,
 * and measure. The destination write is a manager's own edit — the shape
 * `alpha.69` §2 already measures — and here the edit differs from the source
 * ONLY above the sampled bound.
 *
 * @param array{0:string,1:string,2:string} $source
 * @param array<string,string> $destination meta key => value, written after the migration
 */
$case = static function (
    string $prefix,
    array $source,
    array $destination,
    int $bound,
    int $runId
) use ($findBySku, $products, $blank, $setMinMax, $service, $ledger, $ruleFor, $sets, $rowsOf, $say, $verdict): void {
    $wcId = $products->find($findBySku())?->wcProductId ?? 0;
    $say($prefix . '_wc_id', $wcId);
    $blank($wcId);
    $setMinMax($wcId, $source[0], $source[1], $source[2]);
    $batch = $service->migrateBatch($runId, $wcId - 1, false, 1);
    $say($prefix . '_migrated', (string) ($ledger->find($wcId)['status'] ?? '__none__'));
    $say($prefix . '_rows_after_migration', $rowsOf($wcId));
    foreach ($destination as $key => $value) {
        update_post_meta($wcId, $key, $value);
    }
    wc_delete_product_transients($wcId);
    $say($prefix . '_rows_now', $rowsOf($wcId));
    $sets($prefix, $wcId, $ruleFor($wcId), $bound);
    $verdict($prefix);
};

$say('probe', 'ran');
$say('minmax_active', is_plugin_active('woo-min-max-quantity-step-control-single/wcmmq.php'));
$say('minmax_version', defined('WC_MMQ_VERSION') ? WC_MMQ_VERSION : '__absent__');
$say('plugin_version', defined('TMC_VERSION') ? TMC_VERSION : '__absent__');
// The bound itself, read off the class: `60` on the base, and gone on the
// fixed tree because an exact decision has no bound to raise.
$constants = (new ReflectionClass(MigrateMinMax::class))->getConstants();
$say('compare_bound', array_key_exists('COMPARE_TO', $constants) ? (string) $constants['COMPARE_TO'] : '__absent__');

switch ($verb) {
    case 'bound-arrange':
        $wcId = $arrange();
        $say('wc_id', $wcId);
        $say('other_rules_stashed', (int) count((array) get_option(TMC_P70_STASH, [])));
        $say('arranged', 'yes');
        break;

    // ================================================================ §1
    // The owner's first case: «قاعده مبدأ min=1,max=100,step=1 و مقصد
    // min=1,max=200,step=1». Every quantity from 1 to 60 is allowed by both,
    // and 101…200 is allowed only by the destination — deactivating Min Max
    // would WIDEN the limit on this product.
    case 'bound-ceiling':
        $case(
            'ceiling',
            ['1', '100', '1'],
            [ProjectedQuantity::META_MAX => '200'],
            220,
            7001
        );
        break;

    // The owner's second case: «دو قاعده با حداقل‌های متفاوت بالاتر از ۶۰».
    // Both sides refuse every quantity from 1 to 60, so sixty samples agree
    // perfectly; 70…79 is allowed only by Min Max, so deactivating it would
    // NARROW the limit.
    case 'bound-minimum':
        $case(
            'minimum',
            ['70', '', '1'],
            [ProjectedQuantity::META_MIN => '80'],
            90,
            7002
        );
        break;

    // A ceiling that appears out of nothing beyond the bound: Min Max puts no
    // ceiling on this product and the destination rows put one at 500. The
    // `last === null` branch of the comparison, which the other two cases do
    // not reach.
    case 'bound-ceiling-appears':
        $case(
            'appears',
            ['2', '', '1'],
            [ProjectedQuantity::META_MAX => '500'],
            520,
            7003
        );
        break;

    // A quantity no column here can hold. `min_purchase`, `max_purchase` and
    // `purchase_step` are signed `INT`; Min Max keeps its three values in
    // `wp_postmeta`, which is text. `alpha.70` sampled 1…60, found agreement
    // and said `permitted`; the honest answer is that equality cannot be
    // decided at all.
    case 'bound-unprovable':
        $case(
            'unprovable',
            ['1', '100', '1'],
            [ProjectedQuantity::META_MAX => '5000000000'],
            220,
            7007
        );
        break;

    // ================================================================ controls
    // CONTROL 1 — genuinely the same rule. Must be permitted on BOTH trees.
    case 'bound-control':
        $case('control', ['4', '10', '2'], [], 220, 7004);
        break;

    // CONTROL 2 — the same SET, different numbers: Min Max says «at most 11»
    // and the stored ceiling is 10, because 11 is not a multiple of the step
    // and `PurchaseQuantity::of()` floors it. Both sides allow exactly
    // 4, 6, 8, 10. Equality is about the set, never the three numbers
    // (`alpha.53`), so this must be permitted on BOTH trees.
    case 'bound-control-same-set':
        $case('same_set', ['4', '11', '2'], [], 220, 7005);
        break;

    // CONTROL 3 — a difference INSIDE the sampled bound. Both trees must
    // refuse, which is what makes the two cases above measurements of the
    // BOUND rather than of the comparison.
    case 'bound-control-inside':
        $case(
            'inside',
            ['1', '10', '1'],
            [ProjectedQuantity::META_MAX => '5'],
            220,
            7006
        );
        break;

    case 'report':
        $id = $findBySku();
        $wcId = $products->find($id)?->wcProductId ?? 0;
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('rows', $wcId > 0 ? $rowsOf($wcId) : '__absent__');
        $say('ledger_status', (string) ($wcId > 0 ? ($ledger->find($wcId)['status'] ?? '__none__') : '__none__'));
        $say('stashed_rows', (int) count((array) get_option(TMC_P70_STASH, [])));
        $verdict('report');
        break;

    case 'reset':
        $say('rules_put_back', $restoreStashedRules());
        $id = $findBySku();
        $wcId = $products->find($id)?->wcProductId ?? 0;
        if ($wcId > 0) {
            $blank($wcId);
            wp_delete_post($wcId, true);
        }
        if ($id > 0) {
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_products` WHERE id = %d', [$id]);
        }
        $say('product_removed', $id);
        $say('reset', 'yes');
        break;

    default:
        $say('usage', 'bound-arrange|bound-ceiling|bound-minimum|bound-ceiling-appears|bound-unprovable|bound-control|bound-control-same-set|bound-control-inside|report|reset');
}
