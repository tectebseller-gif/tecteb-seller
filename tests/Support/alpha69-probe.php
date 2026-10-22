<?php
/**
 * The three exit-verdict gaps of `alpha.69`, measured on the real plugin.
 *
 * Every number here comes from Min Max 8.1.0 actually installed and actually
 * enforcing, on WooCommerce 11.0.1, on the disposable install — the same
 * footing as `alpha53-probe.php`. The question this round asks is not «what
 * does the source say» but «what does `exitVerdict()` ANSWER», so each verb
 * arranges a state and prints the verdict, its counts and its blockers.
 *
 * **It loads on BOTH trees.** Anything this round adds is asked for with
 * `method_exists`/`array_key_exists` and reported `__absent__` on the base
 * (`alpha.32`), because a probe that fatals on the bytes it was written to
 * measure measures nothing.
 *
 * **And it gives back every guarantee it spends** (`alpha.45`). The verdict is
 * about the WHOLE site, so a measurement is only legible once no other Min Max
 * rule is in play: `exit-arrange` stashes every Min Max meta row on the site
 * into one option, deletes them, and `reset` puts them all back, byte for
 * byte, before deleting the products this probe created.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha69-probe.php <verb>
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
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
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
// NOTHING LEAVES THIS BOX. No mail, no sms, no gateway — the instruction's
// own constraint, enforced here rather than promised.
add_filter('pre_wp_mail', static fn () => true, 9999);

const TMC_P69_VENDOR = 990069;
const TMC_P69_CATEGORY = 'tmc-p69-minmax';
/** Convertible: the step divides the minimum, so both sides allow 4,6,8,10. */
const TMC_P69_SKU_OK = 'SKU-A69-OK';
/** Not convertible: step 3 from a minimum of 2 — the two sets share nothing. */
const TMC_P69_SKU_STEP = 'SKU-A69-STEP';
/** Convertible, and the destination already carries a manager's own rule. */
const TMC_P69_SKU_HELD = 'SKU-A69-HELD';
/** Where the site's other Min Max rows are kept while a verdict is measured. */
const TMC_P69_STASH = 'tmc_p69_minmax_stash';
/** Title prefix of the throwaway plain products the grant scan needs. */
const TMC_P69_GRANT_TITLE = 'کالای مجوزدار ۶۹ شمارهٔ ';

$verb = (string) ($args[0] ?? 'report');
$arg1 = $args[1] ?? null;
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

/** Set a Min Max rule the way the plugin's own admin panel does. */
$setMinMax = static function (int $wcId, string $min, string $max, string $step, string $default = '') use ($mmPrefix): void {
    update_post_meta($wcId, $mmPrefix . 'min_quantity', $min);
    update_post_meta($wcId, $mmPrefix . 'max_quantity', $max);
    update_post_meta($wcId, $mmPrefix . 'product_step', $step);
    if ($default !== '') {
        update_post_meta($wcId, $mmPrefix . 'default_quantity', $default);
    }
    wc_delete_product_transients($wcId);
};

$clearMinMax = static function (int $wcId) use ($mmKeys): void {
    foreach ($mmKeys as $key) {
        delete_post_meta($wcId, $key);
    }
    wc_delete_product_transients($wcId);
};

/**
 * Take every OTHER Min Max rule off the site and remember it exactly.
 *
 * The verdict is one answer about the whole catalogue, so a rule belonging to
 * an earlier round's fixture is a true blocker that hides the one this
 * measurement is about. Stashed rather than deleted: `reset` writes every row
 * back with its own value.
 */
$stashOtherRules = static function (array $keepIds) use ($db, $mmKeys, $say): int {
    $placeholders = implode(', ', array_fill(0, count($mmKeys), '%s'));
    $rows = $db->getResults(
        'SELECT post_id, meta_key, meta_value FROM `' . $db->prefix() . 'postmeta`
          WHERE meta_key IN (' . $placeholders . ')',
        $mmKeys
    );
    $stash = get_option(TMC_P69_STASH, null);
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
    update_option(TMC_P69_STASH, $stash, false);
    return $taken;
};

$restoreStashedRules = static function (): int {
    $stash = get_option(TMC_P69_STASH, null);
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
    delete_option(TMC_P69_STASH);
    return $back;
};

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P69_VENDOR) as $product) {
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
        'categoryKey' => TMC_P69_CATEGORY,
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

$wcIdOf = static fn (string $sku): int => 0;

$rowsOf = static function (int $wcId): string {
    return implode('/', array_map(
        static fn (string $k): string => metadata_exists('post', $wcId, $k)
            ? (string) (get_post_meta($wcId, $k, true) === '' ? '__empty__' : get_post_meta($wcId, $k, true))
            : '__absent__',
        ProjectedQuantity::keys()
    ));
};

/** The three products, published and projected, with no rule of their own. */
$arrange = static function () use ($products, $findBySku, $detailsOf, $catalog, $db, $clearMinMax, $stashOtherRules): array {
    (new DbVendorRepository($db, $clock = new SystemClock()))->upsertProfile(TMC_P69_VENDOR, 'تجهیزات آزمون ۶۹', true, false);
    $ids = [];
    foreach ([
        TMC_P69_SKU_OK => 'ماسک خروجی ۶۹',
        TMC_P69_SKU_STEP => 'دستکش خروجی ۶۹',
        TMC_P69_SKU_HELD => 'سرنگ خروجی ۶۹',
    ] as $sku => $title) {
        $id = $findBySku($sku);
        if ($id === 0) {
            $id = $products->create(TMC_P69_VENDOR, $detailsOf($sku, $title), ProductStatus::Draft);
        } else {
            $products->updateDetails($id, $detailsOf($sku, $title), $products->find($id)?->rowVersion ?? 0);
        }
        $row = $products->find($id);
        if ($row !== null && $row->status !== ProductStatus::Published) {
            $products->updateStatus($id, ProductStatus::Published, $row->rowVersion);
        }
        $catalog->publish($id);
        $wcId = $products->find($id)?->wcProductId ?? 0;
        $ids[$sku] = $wcId;
        foreach (ProjectedQuantity::keys() as $key) {
            delete_post_meta($wcId, $key);
        }
        delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
        delete_post_meta($wcId, '_tmc_quantity_enforced');
        $clearMinMax($wcId);
        $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$wcId]);
        $db->execute(
            'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE wc_product_id = %d',
            ['marketplace', $wcId]
        );
        wc_delete_product_transients($wcId);
    }
    $ids['stashed'] = $stashOtherRules(array_values($ids));
    return $ids;
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
    foreach ([
        'convertible', 'incompatible', 'migrated_unenforceable', 'would_be_unenforceable',
        'unknown', 'held_by_manager', 'already_decided', 'rolled_back', 'source_rows', 'scanned',
        // this round's counts; `__absent__` on the base
        'decided_in_force', 'decided_drifted', 'decided_unenforced', 'decided_unreadable',
        'refused_recorded', 'held_recorded', 'grants_scanned', 'global_rules', 'term_rules',
    ] as $key) {
        $counts = (array) ($answer['counts'] ?? []);
        $say(
            $prefix . '_count_' . $key,
            array_key_exists($key, $counts) ? (int) $counts[$key] : '__absent__'
        );
    }
    $codes = array_map(
        static fn (array $b): string => (string) ($b['code'] ?? '?') . ':' . (int) ($b['count'] ?? 0),
        (array) ($answer['blockers'] ?? [])
    );
    $say($prefix . '_blockers', $codes);
    $say($prefix . '_stale_grants', (array) ($answer['stale_grants'] ?? []));
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
 * The two sets the owner's §2 asks to be compared, up to a bound:
 *  - what MIN MAX allows today, by Min Max's own arithmetic;
 *  - what would be allowed after deactivation — the destination rows read
 *    now, and NOTHING at all when no code here would read them.
 */
$sets = static function (int $wcId, $rule, int $bound = 14) use ($port): array {
    $minmax = [];
    $after = [];
    $read = $port->readRows($wcId);
    $owns = $port->marketplaceOwns($wcId);
    $granted = $port->enforcementGranted($wcId);
    $enforced = $owns || $granted;
    $stored = null;
    if ($enforced && ($read['read'] ?? false)) {
        $min = $read['min'];
        $max = $read['max'];
        $step = $read['step'];
        $stored = PurchaseQuantity::of(
            $min === null || $min === '' ? 1 : (int) $min,
            $max === null || $max === '' ? null : (int) $max,
            $step === null || $step === '' ? 1 : (int) $step
        );
    }
    for ($q = 1; $q <= $bound; $q++) {
        if ($rule !== null && $rule->sourceAllows($q)) {
            $minmax[] = $q;
        }
        if ($stored === null ? true : $stored->allows($q)) {
            $after[] = $q;
        }
    }
    return [
        'minmax' => $minmax,
        'after' => $after,
        'same' => $minmax === $after,
        'owns' => $owns,
        'granted' => $granted,
        'read' => (bool) ($read['read'] ?? false),
    ];
};

$say('probe', 'ran');
$say('minmax_active', is_plugin_active('woo-min-max-quantity-step-control-single/wcmmq.php'));
$say('minmax_version', defined('WC_MMQ_VERSION') ? WC_MMQ_VERSION : '__absent__');
$say('plugin_version', defined('TMC_VERSION') ? TMC_VERSION : '__absent__');

switch ($verb) {
    // ================================================================ §1
    case 'exit-arrange':
        $ids = $arrange();
        $say('wc_ok', $ids[TMC_P69_SKU_OK]);
        $say('wc_step', $ids[TMC_P69_SKU_STEP]);
        $say('wc_held', $ids[TMC_P69_SKU_HELD]);
        $say('other_rules_stashed', (int) $ids['stashed']);
        // Only the OK product carries a rule to begin with, and it is taken,
        // so the baseline is the state the instruction calls the control:
        // every current rule migrated, equal and actually enforced.
        $setMinMax($ids[TMC_P69_SKU_OK], '4', '10', '2');
        $batch = $service->migrateBatch(6901, $ids[TMC_P69_SKU_OK] - 1, false, 1);
        $say('ok_migrated', (string) ($ledger->find($ids[TMC_P69_SKU_OK])['status'] ?? '__none__'));
        $say('ok_rows', $rowsOf($ids[TMC_P69_SKU_OK]));
        $say('arranged', 'yes');
        break;

    case 'exit-control':
        // THE CONTROL: one rule, migrated, equal, enforced ⇒ permitted.
        $verdict('control');
        break;

    case 'exit-refused':
        // The site's only incompatible rule, its migration ATTEMPTED and
        // `refused` recorded. The exit must not be permitted after this.
        $wcId = $products->find($findBySku(TMC_P69_SKU_STEP))?->wcProductId ?? 0;
        $say('step_wc_id', $wcId);
        $setMinMax($wcId, '2', '11', '3');
        $before = $service->exitVerdict();
        $say('before_attempt_verdict', (string) ($before['verdict'] ?? '__none__'));
        $say('before_attempt_incompatible', (int) ($before['counts']['incompatible'] ?? -1));
        $batch = $service->migrateBatch(6902, $wcId - 1, false, 1);
        $row = $ledger->find($wcId);
        $say('ledger_status', (string) ($row['status'] ?? '__none__'));
        $say('ledger_problems', (string) (($row['problems'] ?? '') ?: '__none__'));
        $say('ledger_live', ($row['live_key'] ?? null) === null ? '__null__' : (string) $row['live_key']);
        $say('rows_written', $rowsOf($wcId));
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        $verdict('refused');
        break;

    case 'exit-both':
        // BOTH live decisions at once, which is the state the instruction
        // describes: the only incompatible rule attempted and recorded, and a
        // held destination recorded beside it. Nothing is written by either.
        $step = $products->find($findBySku(TMC_P69_SKU_STEP))?->wcProductId ?? 0;
        $held = $products->find($findBySku(TMC_P69_SKU_HELD))?->wcProductId ?? 0;
        $setMinMax($step, '2', '11', '3');
        $service->migrateBatch(6905, $step - 1, false, 1);
        $say('step_status', (string) ($ledger->find($step)['status'] ?? '__none__'));
        $say('held_status', (string) ($ledger->find($held)['status'] ?? '__none__'));
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        $verdict('both');
        break;

    case 'exit-released':
        // CONTROL IN THE OTHER DIRECTION: a RELEASED refusal is history, not
        // a live decision, and must not block.
        $wcId = $products->find($findBySku(TMC_P69_SKU_STEP))?->wcProductId ?? 0;
        $result = $service->release($wcId);
        $say('release_code', $result->code);
        $say('ledger_after_release', (string) ($ledger->find($wcId)['status'] ?? '__none__'));
        $say('history_after_release', (string) ($ledger->history($wcId)[0]['status'] ?? '__none__'));
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        // The rule is still incompatible and still there, so it blocks again
        // — as an UNDECIDED incompatible rule this time, by its own name.
        $verdict('released');
        break;

    case 'exit-held':
        $wcId = $products->find($findBySku(TMC_P69_SKU_HELD))?->wcProductId ?? 0;
        $say('held_wc_id', $wcId);
        // The manager's own rule at the destination, written the way a manager
        // writes it: no ownership record of ours.
        update_post_meta($wcId, ProjectedQuantity::META_MIN, '6');
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '12');
        update_post_meta($wcId, ProjectedQuantity::META_STEP, '1');
        delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
        $setMinMax($wcId, '4', '10', '2');
        $batch = $service->migrateBatch(6903, $wcId - 1, false, 1);
        $row = $ledger->find($wcId);
        $say('ledger_status', (string) ($row['status'] ?? '__none__'));
        $say('manager_rows_kept', $rowsOf($wcId));
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        $verdict('held');
        break;

    // ================================================================ §2
    case 'drift-source':
        // A SUCCESSFUL migration, then the SOURCE rule changes.
        $wcId = $products->find($findBySku(TMC_P69_SKU_OK))?->wcProductId ?? 0;
        $say('ok_wc_id', $wcId);
        $say('ledger_status', (string) ($ledger->find($wcId)['status'] ?? '__none__'));
        $before = $sets($wcId, $ruleFor($wcId));
        $say('before_minmax_allows', $before['minmax']);
        $say('before_after_exit_allows', $before['after']);
        $say('before_same_set', $before['same']);
        $setMinMax($wcId, '6', '12', '2');
        $after = $sets($wcId, $ruleFor($wcId));
        $say('changed_minmax_allows', $after['minmax']);
        $say('changed_after_exit_allows', $after['after']);
        $say('changed_same_set', $after['same']);
        $say('rows_untouched', $rowsOf($wcId));
        $verdict('drift_source');
        break;

    case 'drift-dest':
        // Source back where it was; the MANAGER edits the destination.
        $wcId = $products->find($findBySku(TMC_P69_SKU_OK))?->wcProductId ?? 0;
        $setMinMax($wcId, '4', '10', '2');
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '8');
        $after = $sets($wcId, $ruleFor($wcId));
        $say('manager_rows', $rowsOf($wcId));
        $say('minmax_allows', $after['minmax']);
        $say('after_exit_allows', $after['after']);
        $say('same_set', $after['same']);
        $verdict('drift_dest');
        break;

    case 'drift-ownership':
        // The rule is still equal, and nothing would read it: the product
        // stopped being the marketplace's after the migration.
        $wcId = $products->find($findBySku(TMC_P69_SKU_OK))?->wcProductId ?? 0;
        $db->execute(
            'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE wc_product_id = %d',
            ['observed', $wcId]
        );
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '10');
        $after = $sets($wcId, $ruleFor($wcId));
        $say('ledger_status', (string) ($ledger->find($wcId)['status'] ?? '__none__'));
        $say('owns_now', $after['owns']);
        $say('granted_now', $after['granted']);
        $say('minmax_allows', $after['minmax']);
        $say('after_exit_allows', $after['after']);
        $say('same_set', $after['same']);
        $verdict('drift_ownership');
        break;

    case 'drift-restore':
        $wcId = $products->find($findBySku(TMC_P69_SKU_OK))?->wcProductId ?? 0;
        $db->execute(
            'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE wc_product_id = %d',
            ['marketplace', $wcId]
        );
        $setMinMax($wcId, '4', '10', '2');
        update_post_meta($wcId, ProjectedQuantity::META_MIN, '4');
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '10');
        update_post_meta($wcId, ProjectedQuantity::META_STEP, '2');
        $after = $sets($wcId, $ruleFor($wcId));
        $say('rows', $rowsOf($wcId));
        $say('same_set', $after['same']);
        $verdict('drift_restored');
        break;

    // ================================================================ §3
    case 'grants-arrange':
        // MORE THAN FIFTY grants, so the row after the fiftieth can be
        // measured. Plain WooCommerce products: a rule written onto one is
        // `written_unenforced`, which is the only state a grant comes from.
        $wanted = max(51, (int) ($arg1 ?? 55));
        $made = 0;
        $ids = [];
        for ($n = 1; $n <= $wanted; $n++) {
            $title = TMC_P69_GRANT_TITLE . $n;
            $id = (int) ($db->getVar(
                'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title = %s LIMIT 1',
                ['product', $title]
            ) ?? 0);
            if ($id === 0) {
                $id = (int) wp_insert_post([
                    'post_title' => $title,
                    'post_type' => 'product',
                    'post_status' => 'publish',
                ]);
                $made++;
            }
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$id]);
            foreach (ProjectedQuantity::keys() as $key) {
                delete_post_meta($id, $key);
            }
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
            delete_post_meta($id, '_tmc_quantity_enforced');
            $setMinMax($id, '4', '10', '2');
            $ids[] = $id;
        }
        $say('grant_products_wanted', $wanted);
        $say('grant_products_created', $made);
        $say('grant_first_id', $ids[0] ?? 0);
        $say('grant_last_id', $ids[count($ids) - 1] ?? 0);
        update_option('tmc_p69_grant_ids', $ids, false);
        break;

    case 'grants-migrate':
        $ids = (array) get_option('tmc_p69_grant_ids', []);
        $taken = 0;
        $enforced = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            $batch = $service->migrateBatch(6904, $id - 1, false, 1);
            $status = (string) ($ledger->find($id)['status'] ?? '');
            if ($status === MinMaxHandoverRepositoryInterface::UNENFORCED) {
                $taken++;
                $result = $service->enforce($id);
                if ($result->ok) {
                    $enforced++;
                }
            }
        }
        $say('written_unenforced', $taken);
        $say('granted', $enforced);
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        break;

    case 'grants-stale':
        // The LAST grant drifts: the manager changes the destination, so the
        // grant no longer describes the rows and nothing enforces that rule.
        $ids = array_map('intval', (array) get_option('tmc_p69_grant_ids', []));
        $rows = $ledger->withStatus([MinMaxHandoverRepositoryInterface::ENFORCED], 1000);
        $live = [];
        foreach ($rows as $row) {
            if (($row['live_key'] ?? null) !== null) {
                $live[] = ['id' => (int) $row['id'], 'post_id' => (int) $row['post_id']];
            }
        }
        $say('live_granted_rows', count($live));
        $say('ledger_order_first', $live[0]['post_id'] ?? 0);
        $target = $live[count($live) - 1] ?? null;
        $position = count($live);
        $say('target_position_in_ledger_order', $position);
        $say('target_post_id', $target['post_id'] ?? 0);
        if ($target !== null) {
            update_post_meta($target['post_id'], ProjectedQuantity::META_MAX, '8');
            wc_delete_product_transients($target['post_id']);
        }
        $say('target_rows', $target === null ? '__none__' : $rowsOf($target['post_id']));
        $say('target_grant_live', $target === null ? '__none__' : ($port->enforcementGranted($target['post_id']) ? 'yes' : 'no'));
        $stale = $service->staleGrants();
        $say('stale_grants_default_limit', count($stale));
        $say('target_listed_by_default', $target === null ? '__none__' : (in_array($target['post_id'], $stale, true) ? 'yes' : 'no'));
        $wide = $service->staleGrants(1000);
        $say('stale_grants_wide_limit', count($wide));
        $say('target_listed_with_wide_limit', $target === null ? '__none__' : (in_array($target['post_id'], $wide, true) ? 'yes' : 'no'));
        if (method_exists($service, 'staleGrantScan')) {
            $scan = $service->staleGrantScan();
            $say('scan_stale', count((array) ($scan['stale'] ?? [])));
            $say('scan_scanned', (int) ($scan['scanned'] ?? -1));
            $say('scan_expected', (int) ($scan['expected'] ?? -1));
            $say('scan_finished', (bool) ($scan['finished'] ?? false));
        } else {
            $say('scan_stale', '__absent__');
            $say('scan_scanned', '__absent__');
            $say('scan_expected', '__absent__');
            $say('scan_finished', '__absent__');
        }
        $verdict('stale');
        break;

    case 'grants-reset':
        $ids = array_map('intval', (array) get_option('tmc_p69_grant_ids', []));
        $gone = 0;
        foreach ($ids as $id) {
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$id]);
            $clearMinMax($id);
            foreach (ProjectedQuantity::keys() as $key) {
                delete_post_meta($id, $key);
            }
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
            delete_post_meta($id, '_tmc_quantity_enforced');
            if (get_post($id) !== null) {
                wp_delete_post($id, true);
                $gone++;
            }
        }
        // Any straggler from a crashed run: found by TITLE, not by the option
        // — the `alpha.69` lesson about an order the next reset could not see.
        $left = $db->getResults(
            'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title LIKE %s',
            ['product', TMC_P69_GRANT_TITLE . '%']
        );
        foreach ($left as $row) {
            $id = (int) $row['ID'];
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$id]);
            $clearMinMax($id);
            wp_delete_post($id, true);
            $gone++;
        }
        delete_option('tmc_p69_grant_ids');
        $say('grant_products_removed', $gone);
        break;

    // ============================================================ coverage
    case 'coverage-global':
        // A GLOBAL Min Max rule has no destination here at all: deactivating
        // the plugin drops it from every product that has no rule of its own.
        $option = (string) ($reader->mode()['option'] ?? '');
        $say('option_key', $option === '' ? '__empty__' : $option);
        $previous = get_option($option, null);
        update_option(TMC_P69_STASH . '_global', ['key' => $option, 'value' => $previous], false);
        $existing = is_array($previous) ? $previous : [];
        update_option($option, array_merge($existing, [
            'min_quantity' => '3',
            'max_quantity' => '9',
            'product_step' => '3',
        ]), false);
        $preview = $service->preview();
        $say('global_seen', ($preview['global'] ?? null) === null ? '__none__' : 'yes');
        $say('global_effective', ($preview['global']['effective'] ?? null) === null
            ? '__none__'
            : implode('/', [
                (string) ($preview['global']['effective']['min'] ?? '?'),
                (string) ($preview['global']['effective']['max'] ?? 'null'),
                (string) ($preview['global']['effective']['step'] ?? '?'),
            ]));
        $say('count_global', (int) ($preview['counts']['global'] ?? 0));
        $say('count_term', (int) ($preview['counts']['term'] ?? 0));
        $verdict('global');
        break;

    case 'coverage-restore':
        $saved = get_option(TMC_P69_STASH . '_global', null);
        if (is_array($saved) && ($saved['key'] ?? '') !== '') {
            if ($saved['value'] === null) {
                delete_option((string) $saved['key']);
            } else {
                update_option((string) $saved['key'], $saved['value'], false);
            }
            $say('global_restored', 'yes');
        } else {
            $say('global_restored', '__nothing_saved__');
        }
        delete_option(TMC_P69_STASH . '_global');
        $verdict('restored');
        break;

    case 'coverage-variation-arrange':
        // A REAL variation rule, so «variations are covered» is a number and
        // not a reading of the walk's source. A variable product of the main
        // shop (not the marketplace's), one variation, the rule on the
        // VARIATION — which is where Min Max stores it.
        $title = 'کالای متنوع ۶۹';
        $parent = (int) ($db->getVar(
            'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title = %s LIMIT 1',
            ['product', $title]
        ) ?? 0);
        if ($parent === 0) {
            $parent = (int) wp_insert_post([
                'post_title' => $title,
                'post_type' => 'product',
                'post_status' => 'publish',
            ]);
            wp_set_object_terms($parent, 'variable', 'product_type');
        }
        $variation = (int) ($db->getVar(
            'SELECT ID FROM `' . $db->prefix() . 'posts`
              WHERE post_type = %s AND post_parent = %d LIMIT 1',
            ['product_variation', $parent]
        ) ?? 0);
        if ($variation === 0) {
            $variation = (int) wp_insert_post([
                'post_title' => $title . ' — یک',
                'post_type' => 'product_variation',
                'post_parent' => $parent,
                'post_status' => 'publish',
            ]);
            update_post_meta($variation, '_regular_price', '100000');
            update_post_meta($variation, '_price', '100000');
        }
        update_option('tmc_p69_variation_ids', [$parent, $variation], false);
        $setMinMax($variation, '4', '10', '2');
        $say('variation_parent', $parent);
        $say('variation_id', $variation);
        $say('variation_rule', '4/10/2');
        break;

    case 'coverage-variation-reset':
        $ids = array_map('intval', (array) get_option('tmc_p69_variation_ids', []));
        foreach ($ids as $id) {
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$id]);
            $clearMinMax($id);
            foreach (ProjectedQuantity::keys() as $key) {
                delete_post_meta($id, $key);
            }
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
            delete_post_meta($id, '_tmc_quantity_enforced');
            if (get_post($id) !== null) {
                wp_delete_post($id, true);
            }
        }
        delete_option('tmc_p69_variation_ids');
        $say('variation_products_removed', count($ids));
        break;

    case 'coverage-variation':
        // A VARIATION rule is inside the walk's own counts, and this prints
        // the number rather than asserting it from the source.
        $preview = $service->preview();
        $say('levels_product', (int) ($preview['levels']['product'] ?? 0));
        $say('levels_variation', (int) ($preview['levels']['variation'] ?? 0));
        $say('counts_product', (int) ($preview['counts']['product'] ?? 0));
        $say('counts_variation', (int) ($preview['counts']['variation'] ?? 0));
        $say('scanned', (int) ($preview['scanned'] ?? 0));
        $say('walk_finished', array_key_exists('walk_finished', $preview)
            ? ((bool) $preview['walk_finished'] ? 'yes' : 'no')
            : '__absent__');
        $verdict('variation');
        break;

    // ============================================================== report
    case 'report':
        $preview = $service->preview();
        $say('available', (bool) ($preview['available'] ?? false));
        foreach (['convertible', 'refused', 'held_by_manager', 'unenforced', 'already_decided', 'scanned'] as $key) {
            $say('preview_' . $key, (int) ($preview[$key] ?? 0));
        }
        foreach (['decided_in_force', 'decided_drifted', 'decided_unenforced', 'decided_unreadable'] as $key) {
            $say('preview_' . $key, array_key_exists($key, $preview) ? (int) $preview[$key] : '__absent__');
        }
        foreach ($ledger->tally() as $status => $count) {
            $say('tally_' . $status, (int) $count);
        }
        $verdict('report');
        break;

    case 'reset':
        $ids = [];
        foreach ([TMC_P69_SKU_OK, TMC_P69_SKU_STEP, TMC_P69_SKU_HELD] as $sku) {
            $id = $findBySku($sku);
            $wcId = $id === 0 ? 0 : ($products->find($id)?->wcProductId ?? 0);
            if ($wcId > 0) {
                $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$wcId]);
                $clearMinMax($wcId);
                foreach (ProjectedQuantity::keys() as $key) {
                    delete_post_meta($wcId, $key);
                }
                delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
                delete_post_meta($wcId, '_tmc_quantity_enforced');
                $db->execute(
                    'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE wc_product_id = %d',
                    ['marketplace', $wcId]
                );
                $ids[] = $wcId;
            }
        }
        $say('own_products_cleared', $ids);
        $say('other_rules_restored', $restoreStashedRules());
        $saved = get_option(TMC_P69_STASH . '_global', null);
        if (is_array($saved) && ($saved['key'] ?? '') !== '') {
            if ($saved['value'] === null) {
                delete_option((string) $saved['key']);
            } else {
                update_option((string) $saved['key'], $saved['value'], false);
            }
        }
        delete_option(TMC_P69_STASH . '_global');
        $say('global_restored', 'yes');
        break;

    default:
        $say('unknown_verb', $verb);
}
