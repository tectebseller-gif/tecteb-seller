<?php
/**
 * `alpha.55` on real WooCommerce: the two `alpha.54` gaps, the Min Max
 * enforcement grant, and the intake of products the shop already has.
 *
 * **Its own file, named for its round** — the rule `alpha.54` paid for, and
 * `EvidenceToolsAreNotOverwrittenTest` is its guard.
 *
 * It loads on BOTH trees (`alpha.32`'s rule). Everything this round adds is
 * reached through `method_exists()` / `class_exists()`, so a verb about a
 * capability `alpha.54` does not have answers `absent=1` instead of dying at
 * load — and «absent» is itself a measurement: it is what «this could not be
 * done before» looks like.
 *
 * **The second writer is a SEPARATE PROCESS** (`alpha.40`): an injected write
 * on our own connection joins our transaction and models nothing. For the
 * window inside `decide()` it is spawned from a decorator on the ledger's
 * `claim()`, which runs after the baseline read and before the destination
 * write — the one seam that makes «somebody edited it in the middle»
 * deterministic rather than a coin toss.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha55-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
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
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

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

/** The marketplace vendor these fixtures belong to. */
const TMC_P55_VENDOR = 9150;
const TMC_P55_CATEGORY = 'tmc-p55-intake';
/** Two products: one the marketplace owns, one it does not. */
const TMC_P55_SKU_OURS = 'SKU-A55-OURS';
const TMC_P55_SKU_THEIRS = 'SKU-A55-THEIRS';

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
$catalog = $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = implode('|', array_map('strval', $value));
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};
$say('probe', 'ran');

(new DbVendorRepository($db, $clock))->upsertProfile(TMC_P55_VENDOR, 'تجهیزات آزمون ۵۵', true, false);

// -------------------------------------------------------------- second writer

/**
 * Run the separate-process writer and return its printed keys.
 *
 * `TMC_LOCK_TIMEOUT` keeps the wait bounded: when this tree holds the rows the
 * writer is SUPPOSED to be refused, and a twenty-second default would make
 * every run of this verb take twenty seconds to say so.
 */
$secondWriter = static function (array $argv, int $timeout = 4): array {
    $repo = (string) (getenv('TMC_REPO') ?: dirname(__DIR__, 2));
    $wpPath = (string) (getenv('TMC_WP_PATH') ?: ABSPATH);
    $wpcli = (string) (getenv('TMC_WPCLI') ?: '/usr/local/bin/wp');
    $command = 'cd ' . escapeshellarg($repo)
        . ' && TMC_LOCK_TIMEOUT=' . (int) $timeout . ' php ' . escapeshellarg($wpcli)
        . ' --allow-root --path=' . escapeshellarg($wpPath)
        . ' eval-file tests/Support/concurrent-quantity-edit.php '
        . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    $output = (string) shell_exec($command);
    $keys = [];
    foreach (explode("\n", $output) as $line) {
        if (preg_match('~^([a-z_]+)=(.*)$~', trim($line), $m) === 1) {
            $keys[$m[1]] = $m[2];
        }
    }
    return $keys;
};

// ------------------------------------------------------------------ helpers

$build = static function (
    ?TransactionInterface $tx,
    ?MinMaxHandoverRepositoryInterface $ledger = null
) use ($c): MigrateMinMax {
    return new MigrateMinMax(
        $c->get(MinMaxReaderInterface::class),
        $ledger ?? $c->get(MinMaxHandoverRepositoryInterface::class),
        $c->get(ShopQuantityPortInterface::class),
        $tx
    );
};

$prefix = static fn (): string => defined('WC_MMQ_PREFIX') ? WC_MMQ_PREFIX : '';
$handoverTable = static fn (): string => $db->prefix() . 'tmc_minmax_handover';

/** The three rows as the APPLICATION reads them — through the meta cache. */
$appRows = static function (int $wcId): string {
    $out = [];
    foreach (ProjectedQuantity::keys() as $key) {
        if (!metadata_exists('post', $wcId, $key)) {
            $out[] = '__absent__';
            continue;
        }
        $value = (string) get_post_meta($wcId, $key, true);
        $out[] = $value === '' ? '__empty__' : $value;
    }
    return implode('/', $out);
};

/** The same three rows STRAIGHT FROM THE DATABASE, past every cache. */
$dbRows = static function (int $wcId) use ($db): string {
    $out = [];
    foreach (ProjectedQuantity::keys() as $key) {
        $value = $db->getVar(
            'SELECT meta_value FROM `' . $db->prefix() . 'postmeta`
              WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1',
            [$wcId, $key]
        );
        $out[] = $value === null ? '__absent__' : ((string) $value === '' ? '__empty__' : (string) $value);
    }
    return implode('/', $out);
};

$ledgerRow = static function (int $wcId) use ($c): string {
    $row = $c->get(MinMaxHandoverRepositoryInterface::class)->find($wcId);
    if ($row === null) {
        return '__none__';
    }
    return (string) ($row['status'] ?? '?')
        . ' before=' . ($row['dest_max_before'] === null ? '__absent__' : (string) $row['dest_max_before'])
        . ' wrote=' . ($row['written_max'] === null ? '__null__' : (string) $row['written_max']);
};

$detailsOf = static function (string $sku): ProductDetails {
    $named = [
        'title' => 'کالای ۵۵ ' . $sku,
        'type' => 'simple',
        'categoryKey' => TMC_P55_CATEGORY,
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 450000,
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
 * The marketplace row with this SKU — read with SQL, NOT through
 * `forVendor()`.
 *
 * `forVendor()` returns only rows the marketplace OWNS, and this fixture
 * deliberately makes one of its products `observed` to produce the
 * `written_unenforced` state. The first version used `forVendor()`, could not
 * find the row it had just un-owned, and created a FRESH WooCommerce product
 * on every run — five of them, each carrying a Min Max rule, which then made
 * the previous round's script report failures about a build that was working
 * («four products carry a rule → got 9»). `alpha.33` again: a fixture that
 * cannot find what it made does not hand it back.
 */
$findBySku = static function (string $sku) use ($db): int {
    return (int) ($db->getVar(
        'SELECT id FROM `' . $db->prefix() . 'tmc_products`
          WHERE vendor_user_id = %d AND sku = %s ORDER BY id ASC LIMIT 1',
        [TMC_P55_VENDOR, $sku]
    ) ?? 0);
};

/**
 * One published, projected marketplace product with a convertible Min Max
 * rule and a clean destination.
 *
 * `$ours` false ALSO removes the marketplace link, which is what makes the
 * product «not ours» for `QuantityGuard` while the three rows are still on
 * it — the `written_unenforced` state the grant exists for. The row itself is
 * kept so the fixture can put the link back (`alpha.33`: a fixture gives back
 * what it spends).
 */
$arrange = static function (string $sku, string $rule = '4/10/2', bool $ours = true)
use ($products, $findBySku, $detailsOf, $catalog, $db, $prefix, $handoverTable): array {
    [$min, $max, $step] = explode('/', $rule);
    $id = $findBySku($sku);
    if ($id === 0) {
        $id = $products->create(TMC_P55_VENDOR, $detailsOf($sku), ProductStatus::Draft);
    } else {
        $products->updateDetails($id, $detailsOf($sku), $products->find($id)?->rowVersion ?? '');
    }
    $row = $products->find($id);
    if ($row !== null && $row->status !== ProductStatus::Published) {
        $products->updateStatus($id, ProductStatus::Published, $row->rowVersion);
    }
    $catalog->publish($id);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    foreach (ProjectedQuantity::keys() as $key) {
        delete_post_meta($wcId, $key);
    }
    delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
    delete_post_meta($wcId, '_tmc_quantity_enforced');
    foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
        foreach (['', '_wcmmq_', '_wcmmq_s_'] as $p) {
            delete_post_meta($wcId, $p . $field);
        }
    }
    update_post_meta($wcId, $prefix() . 'min_quantity', $min);
    update_post_meta($wcId, $prefix() . 'max_quantity', $max);
    update_post_meta($wcId, $prefix() . 'product_step', $step);
    $db->execute('DELETE FROM `' . $handoverTable() . '` WHERE post_id = %d', [$wcId]);
    // OWNED or merely OBSERVED, and the WooCommerce id stays either way.
    //
    // `observed` is how a real product becomes «not ours»: `marketplaceOwns()`
    // and `PurchasePolicy` both ask for `link_ownership = 'marketplace'`, so an
    // observed row answers no to both while the three rule rows sit on the
    // product. Nulling `wc_product_id` instead — which the first version did —
    // also works for the guard and is a state nothing produces, and it made
    // the fixture unable to find its own product.
    $db->execute(
        'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE id = %d',
        [$ours ? 'marketplace' : 'observed', $id]
    );
    wc_delete_product_transients($wcId);
    wp_cache_delete((string) $wcId, 'post_meta');
    return ['product_id' => $id, 'wc_id' => $wcId];
};

$migrateOne = static function (MigrateMinMax $service, int $wcId, bool $allowOverwrite = false): array {
    return $service->migrateBatch(1, $wcId - 1, $allowOverwrite, 1);
};

$outcomeOf = static function (array $batch): string {
    foreach (($batch['counts'] ?? []) as $outcome => $count) {
        if ((int) $count > 0) {
            return (string) $outcome;
        }
    }
    return (string) (($batch['failed'][0]['outcome'] ?? '') ?: '__none__');
};

/**
 * A ledger decorator that runs the second writer INSIDE the window.
 *
 * `claim()` is called after `decide()` has read the baseline and before it
 * writes the destination, on both trees — so hooking it is how «a manager
 * edited the rule in the middle» becomes a repeatable measurement instead of
 * a timing race. It declares every method of the interface and forwards
 * through `method_exists()`, so it loads on `alpha.54` as well.
 */
final class TmcP55WindowLedger implements MinMaxHandoverRepositoryInterface
{
    /** @var array<string,string> */
    public array $writerKeys = [];

    public function __construct(
        private readonly MinMaxHandoverRepositoryInterface $inner,
        private readonly \Closure $duringClaim
    ) {
    }

    public function claim(array $row): array
    {
        $this->writerKeys = ($this->duringClaim)();
        return $this->inner->claim($row);
    }

    public function finish(int $claimId, string $status, string $problems, array $written): bool
    {
        return $this->inner->finish($claimId, $status, $problems, $written);
    }

    public function find(int $postId): ?array
    {
        return $this->inner->find($postId);
    }

    public function history(int $postId, int $limit = 20): array
    {
        return method_exists($this->inner, 'history') ? $this->inner->history($postId, $limit) : [];
    }

    public function tally(): array
    {
        return $this->inner->tally();
    }

    public function withStatus(array $statuses, int $limit = 200, int $afterId = 0): array
    {
        return $this->inner->withStatus($statuses, $limit, $afterId);
    }

    public function markRolledBack(int $postId): bool
    {
        return $this->inner->markRolledBack($postId);
    }

    public function release(int $postId): bool
    {
        return method_exists($this->inner, 'release') ? $this->inner->release($postId) : false;
    }

    public function markEnforced(int $postId): bool
    {
        return method_exists($this->inner, 'markEnforced') ? $this->inner->markEnforced($postId) : false;
    }

    public function markUnenforced(int $postId): bool
    {
        return method_exists($this->inner, 'markUnenforced') ? $this->inner->markUnenforced($postId) : false;
    }
}

$realTx = $c->has(TransactionInterface::class) ? $c->get(TransactionInterface::class) : null;
/** @var ShopQuantityPortInterface $port */
$port = $c->get(ShopQuantityPortInterface::class);

switch ($verb) {
    // ======================================= §6-ب the read under the lock

    /**
     * THE MEASUREMENT: a WARM meta cache, a row changed behind it, and a
     * rollback that compares one of the two.
     *
     * `get_metadata_raw()` answers from `wp_cache_get($id, 'post_meta')`, so
     * after the cache is warm a value another process committed is invisible
     * to `get_post_meta()` — which is what `alpha.54`'s rollback compared,
     * lock or no lock.
     */
    case 'rollback-warm-cache': {
        $ids = $arrange(TMC_P55_SKU_OURS);
        $wcId = $ids['wc_id'];
        $batch = $migrateOne($build($realTx), $wcId);
        $say('migrated', $outcomeOf($batch));
        $say('rows_after_migration', $dbRows($wcId));

        // WARM the cache, the way any admin screen or `wc_get_product()` call
        // would in the same request.
        $say('cache_warmed_max', (string) get_post_meta($wcId, ProjectedQuantity::META_MAX, true));
        // A manager edits the rule, in their own process and on their own
        // connection. Nothing invalidates THIS process's cache.
        $keys = $secondWriter(['write', (string) $wcId, '8'], 10);
        $say('second_writer', $keys['write_ok'] ?? '__missing__');
        $say('row_now', $dbRows($wcId));
        $say('app_reads', $appRows($wcId));
        $say('cache_is_stale', $appRows($wcId) !== $dbRows($wcId));

        $result = $build($realTx)->rollback($wcId);
        $say('rollback_code', $result->code);
        $say('rollback_field', (string) ($result->context['field'] ?? '__none__'));
        $say('rollback_now', (string) ($result->context['now'] ?? '__none__'));
        // THE QUESTION: is the manager's brand-new 8 still there?
        $say('db_rows_after_rollback', $dbRows($wcId));
        $say('manager_value_kept', $db->getVar(
            'SELECT meta_value FROM `' . $db->prefix() . 'postmeta`
              WHERE post_id = %d AND meta_key = %s LIMIT 1',
            [$wcId, ProjectedQuantity::META_MAX]
        ) === '8');
        $say('ledger_after', $ledgerRow($wcId));
        break;
    }

    // ===================================== §6-الف the window in decide()

    /**
     * A manager's edit that lands BETWEEN the baseline read and the
     * destination write.
     *
     * On `alpha.54` nothing is locked, so the second writer's commit goes
     * through and the migration then writes over it — with a `dest_*_before`
     * recorded from the value it read a moment earlier, which is no longer
     * what was there. On this tree the rows are locked before the read, so
     * the second writer waits and is refused.
     */
    case 'decide-window': {
        $ids = $arrange(TMC_P55_SKU_OURS);
        $wcId = $ids['wc_id'];
        // A destination value for the second writer to replace, so «whose
        // value survived» is a question with two different answers.
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '6');
        update_post_meta($wcId, ProjectedQuantity::OWNED_META, [
            ProjectedQuantity::META_MAX => ProjectedQuantity::fingerprint('6'),
        ]);
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('destination_before', $dbRows($wcId));

        $writer = null;
        $ledger = new TmcP55WindowLedger(
            $c->get(MinMaxHandoverRepositoryInterface::class),
            static function () use ($secondWriter, $wcId, &$writer): array {
                $writer = $secondWriter(['write', (string) $wcId, '9'], 3);
                return $writer;
            }
        );
        $batch = $migrateOne($build($realTx, $ledger), $wcId, true);
        $say('outcome', $outcomeOf($batch));
        $say('second_writer_landed', ($ledger->writerKeys['write_ok'] ?? 'no') === 'yes');
        $say('second_writer_waited_ms', $ledger->writerKeys['waited_ms'] ?? '__missing__');
        $say('db_rows_after', $dbRows($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        break;
    }

    // ================================ §5 the grant that makes a rule apply

    /** Arrange a NON-marketplace product carrying a migrated rule. */
    case 'unenforced-arrange': {
        $ids = $arrange(TMC_P55_SKU_THEIRS, '4/10/2', false);
        $wcId = $ids['wc_id'];
        $batch = $migrateOne($build($realTx), $wcId);
        $say('wc_id', $wcId);
        $say('outcome', $outcomeOf($batch));
        $say('rows_after', $dbRows($wcId));
        $say('marketplace_owns', $port->marketplaceOwns($wcId));
        $say('granted', method_exists($port, 'enforcementGranted') ? $port->enforcementGranted($wcId) : 'absent');
        break;
    }

    /** Grant the enforcement — and nothing else. */
    case 'enforce': {
        // The id comes from the caller, because the fixture deliberately
        // removes the product's marketplace LINK — that is what makes it «not
        // ours» — so there is nothing to look it up by. `unenforced-arrange`
        // printed it.
        $wcId = (int) ($args[1] ?? 0);
        $service = $build($realTx);
        if (!method_exists($service, 'enforce') || $wcId <= 0) {
            $say('absent', 1);
            break;
        }
        $result = $service->enforce($wcId);
        $say('enforce_code', $result->code);
        $say('granted', $port->enforcementGranted($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        $say('rows_after', $dbRows($wcId));
        // The grant is about ONE rule: ownership, vendor and status are
        // exactly as they were.
        $say('marketplace_owns', $port->marketplaceOwns($wcId));
        $say('wc_status', (string) get_post_status($wcId));
        $say('wc_author', (string) ($db->getVar(
            'SELECT post_author FROM `' . $db->prefix() . 'posts` WHERE ID = %d',
            [$wcId]
        ) ?? 0));
        break;
    }

    /** Withdraw the grant, leaving the rule where it is. */
    case 'unenforce': {
        $wcId = (int) ($args[1] ?? 0);
        $service = $build($realTx);
        if (!method_exists($service, 'stopEnforcing')) {
            $say('absent', 1);
            break;
        }
        $result = $service->stopEnforcing($wcId);
        $say('unenforce_code', $result->code);
        $say('granted', $port->enforcementGranted($wcId));
        $say('rows_after', $dbRows($wcId));
        break;
    }

    /**
     * THE GRANT IS ABOUT ONE RULE. Change the rule by hand and the grant
     * stops being live.
     *
     * The grant is stored as a fingerprint of the three values it was given
     * for, so this measures the thing a flag could not express: the row is
     * still on the product, and the cart no longer enforces it. Not enforcing
     * is where such a product was before any grant existed; enforcing numbers
     * nobody approved would be new.
     *
     * Run `cart` after this to read what the basket now allows.
     */
    case 'drift': {
        $wcId = (int) ($args[1] ?? 0);
        $say('grant_row_before', (string) get_post_meta($wcId, ProjectedQuantity::ENFORCE_META, true));
        $say('granted_before', $port->enforcementGranted($wcId));
        // A hand in Min Max, a hand in the WooCommerce panel — written
        // straight onto the row, which is what either of them does.
        update_post_meta($wcId, ProjectedQuantity::META_MAX, '8');
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('rows_after', $dbRows($wcId));
        $say('grant_row_after', (string) get_post_meta($wcId, ProjectedQuantity::ENFORCE_META, true));
        $say('grant_row_unchanged', (string) get_post_meta($wcId, ProjectedQuantity::ENFORCE_META, true) !== '');
        $say('granted_after', $port->enforcementGranted($wcId));
        $say('ledger_after', $ledgerRow($wcId));
        break;
    }

    /**
     * What the cart allows for one product — classic validation and the
     * Store API's own limits, read from WooCommerce itself.
     *
     * The sale gate is opened with the plugin's OWN local key and put back,
     * because without it «no quantity is allowed» is a true sentence about
     * the gate and says nothing about the quantity rule (`alpha.46`).
     */
    case 'cart': {
        $wcId = (int) ($args[1] ?? 0);
        $product = $wcId > 0 && function_exists('wc_get_product') ? wc_get_product($wcId) : null;
        if (!is_object($product)) {
            $say('refused', 'no_product');
            break;
        }
        $gateWas = get_option('tmc_order_trial_mode');
        update_option('tmc_order_trial_mode', '1');
        // The cart and the notice functions, which WP-CLI does not load: the
        // front end would have both, and WITHOUT the notices Min Max's own
        // validator fatals on the first refusal — `wc_add_notice()` called
        // from inside its namespace, measured on 8.1.0. That is its bug and
        // not this plugin's, but a probe that dies on it measures nothing.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (!function_exists('wc_add_notice') && defined('WC_ABSPATH')) {
            require_once WC_ABSPATH . 'includes/wc-notice-functions.php';
        }
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        if (function_exists('WC') && WC()->cart !== null) {
            WC()->cart->empty_cart();
        }
        $args9 = function_exists('wc_get_quantity_input_args')
            ? wc_get_quantity_input_args([], $product)
            : [];
        $say('input_args', implode('/', [
            (string) ($args9['min_value'] ?? '?'),
            (string) ($args9['max_value'] ?? '?'),
            (string) ($args9['step'] ?? '?'),
        ]));
        foreach ([1, 3, 4, 8, 11] as $quantity) {
            if (function_exists('WC') && WC()->cart !== null) {
                WC()->cart->empty_cart();
            }
            if (function_exists('wc_clear_notices')) {
                wc_clear_notices();
            }
            $passed = apply_filters('woocommerce_add_to_cart_validation', true, $wcId, $quantity, 0);
            $say('qty_' . $quantity, $passed === true ? 'allowed' : 'refused');
        }
        // And the Store API's own per-item validator, which the block cart
        // runs: one filter reaches both (`alpha.45`), and this is the half a
        // hand-built request cannot route around.
        foreach ([3, 4] as $quantity) {
            try {
                do_action('woocommerce_store_api_validate_cart_item', $product, ['quantity' => $quantity]);
                $say('store_api_qty_' . $quantity, 'allowed');
            } catch (\Throwable) {
                $say('store_api_qty_' . $quantity, 'refused');
            }
        }
        if (function_exists('WC') && WC()->cart !== null) {
            WC()->cart->empty_cart();
        }
        if ($gateWas === false) {
            delete_option('tmc_order_trial_mode');
        } else {
            update_option('tmc_order_trial_mode', $gateWas);
        }
        $say('gate_restored', 'yes');
        break;
    }

    // ================================ §1–§4 the intake of existing products

    /**
     * A plain WooCommerce product the marketplace has never seen — the main
     * shop's own, made with WooCommerce's own API and authored by the
     * administrator, which is the state most of a live shop's catalogue is in.
     *
     * Idempotent by SKU, so running the script twice does not fill the shop
     * with fixtures.
     */
    case 'intake-arrange': {
        if (!function_exists('wc_get_product_id_by_sku')) {
            $say('refused', 'no_woocommerce');
            break;
        }
        $sku = 'WC-A55-SHOP';
        $wcId = (int) wc_get_product_id_by_sku($sku);
        if ($wcId <= 0) {
            $product = new WC_Product_Simple();
            $product->set_name('کالای فروشگاه اصلی ۵۵');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_short_description('خلاصهٔ فروشگاه اصلی');
            $product->set_description('متن کامل فروشگاه اصلی');
            $product->set_sku($sku);
            $product->set_regular_price('450000');
            $product->set_manage_stock(true);
            $product->set_stock_quantity(22);
            $product->set_weight('1.5');
            $wcId = (int) $product->save();
        }
        // The administrator as author, which is what makes this the shop's
        // own product rather than anybody's.
        $db->execute(
            'UPDATE `' . $db->prefix() . 'posts` SET post_author = %d WHERE ID = %d',
            [$adminId, $wcId]
        );
        clean_post_cache($wcId);
        $say('wc_id', $wcId);
        $say('status', (string) get_post_status($wcId));
        $say('author', (string) ($db->getVar(
            'SELECT post_author FROM `' . $db->prefix() . 'posts` WHERE ID = %d',
            [$wcId]
        ) ?? 0));
        $say('marketplace_rows', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [$wcId]
        ) ?? -1));
        break;
    }

    case 'inventory': {
        if (!class_exists(\Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory::class)) {
            $say('absent', 1);
            break;
        }
        $inventory = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory::class);
        $page = $inventory->page(0, 100);
        $say('available', $page['available'] ?? false);
        $say('total', (string) (($page['counts']['total'] ?? 0)));
        $say('variations', (string) (($page['counts']['variations'] ?? 0)));
        $say('unsupported_types', implode(',', array_keys($page['counts']['unsupported'] ?? [])));
        foreach (($page['buckets_on_this_page'] ?? []) as $bucket => $count) {
            $say('bucket_' . $bucket, (string) $count);
        }
        $say('duplicate_links', (string) count($page['duplicate_links'] ?? []));
        // And it wrote nothing: the products table is the same size.
        $say('rows_in_products', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products`'
        ) ?? -1));
        break;
    }

    /**
     * A product WooCommerce has and the marketplace does not — taken in, with
     * its id, its URL and its past order lines untouched.
     */
    case 'intake': {
        if (!class_exists(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        if ($wcId <= 0) {
            $say('refused', 'no_product');
            break;
        }
        $urlBefore = (string) get_permalink($wcId);
        $statusBefore = (string) get_post_status($wcId);
        $authorBefore = (string) ($db->getVar(
            'SELECT post_author FROM `' . $db->prefix() . 'posts` WHERE ID = %d',
            [$wcId]
        ) ?? 0);
        $orderLinesBefore = (int) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'woocommerce_order_itemmeta`
              WHERE meta_key = %s AND meta_value = %s',
            ['_product_id', (string) $wcId]
        ) ?? 0);

        $intake = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class);
        $runId = 'intake-probe';
        $batch = $intake->importBatch($runId, [$wcId], TMC_P55_VENDOR);
        $say('code', (string) ($batch['code'] ?? '?'));
        $say('imported', (string) (($batch['counts']['imported'] ?? 0)));
        $say('reason', (string) ($batch['results'][0]['reason'] ?? ''));
        $row = $products->findObservedByWcProduct($wcId);
        $say('observed_row', $row === null ? '__none__' : (string) $row->id);
        $say('same_wc_id', $row !== null && (int) $row->wcProductId === $wcId);
        $say('url_unchanged', $urlBefore === (string) get_permalink($wcId));
        $say('status_unchanged', $statusBefore === (string) get_post_status($wcId));
        $say('author_unchanged', $authorBefore === (string) ($db->getVar(
            'SELECT post_author FROM `' . $db->prefix() . 'posts` WHERE ID = %d',
            [$wcId]
        ) ?? 0));
        $say('order_lines_unchanged', $orderLinesBefore === (int) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'woocommerce_order_itemmeta`
              WHERE meta_key = %s AND meta_value = %s',
            ['_product_id', (string) $wcId]
        ) ?? -1));
        $say('row_title', $row === null ? '__none__' : $row->details->title);
        $say('row_price', $row === null ? '__none__' : (string) $row->details->priceMinor);
        $say('row_has_baseline', $row?->baseline !== null);
        $say('owns_storefront', $row !== null && $row->ownsItsStorefrontProduct());
        break;
    }

    /** The same product again: no second row, no second link. */
    case 'intake-twice': {
        if (!class_exists(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $intake = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class);
        $batch = $intake->importBatch('intake-probe-2', [$wcId], TMC_P55_VENDOR);
        $say('imported', (string) (($batch['counts']['imported'] ?? 0)));
        $say('reason', (string) ($batch['results'][0]['reason'] ?? ''));
        $say('rows_for_this_product', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [$wcId]
        ) ?? -1));
        break;
    }

    /** The rollback takes its own rows and nothing of WooCommerce's. */
    case 'intake-rollback': {
        if (!class_exists(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $intake = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class);
        $result = $intake->rollback('intake-probe');
        $say('rollback_code', $result->code);
        $say('removed', (string) ($result->context['removed'] ?? 0));
        $say('observed_row', $products->findObservedByWcProduct($wcId) === null ? '__none__' : 'still_there');
        // The product, its media and its orders are exactly where they were.
        $say('wc_product_still_there', get_post_status($wcId) !== false);
        $say('wc_status', (string) get_post_status($wcId));
        break;
    }

    /**
     * Give back everything the fixtures spend — INCLUDING the Min Max SOURCE
     * rows, which is the half the first version forgot.
     *
     * `alpha.33`'s rule, and this round paid it again: leaving two products
     * with a Min Max rule on them made the PREVIOUS round's script see five
     * convertible products where its own fixture had made three, and it
     * reported a failure about a build that was working — «it carried on and
     * migrated the one after it, expected=2 actual=3».
     *
     * The marketplace row and the WooCommerce product are left alone: they
     * are what the next run reuses, and deleting a product is not something
     * this repository's tools do.
     */
    case 'reset': {
        foreach ([TMC_P55_SKU_OURS, TMC_P55_SKU_THEIRS] as $sku) {
            $id = $findBySku($sku);
            if ($id === 0) {
                continue;
            }
            $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
            if ($wcId <= 0) {
                continue;
            }
            foreach (ProjectedQuantity::keys() as $key) {
                delete_post_meta($wcId, $key);
            }
            delete_post_meta($wcId, ProjectedQuantity::OWNED_META);
            delete_post_meta($wcId, '_tmc_quantity_enforced');
            // The SOURCE rows, in every prefix this build might use — the
            // ones that make the product visible to `MinMaxReader`.
            foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
                foreach (['', '_wcmmq_', '_wcmmq_s_'] as $p) {
                    delete_post_meta($wcId, $p . $field);
                }
            }
            $db->execute('DELETE FROM `' . $handoverTable() . '` WHERE post_id = %d', [$wcId]);
            // And the ownership the fixture borrowed.
            $db->execute(
                'UPDATE `' . $db->prefix() . 'tmc_products` SET link_ownership = %s WHERE id = %d',
                ['marketplace', $id]
            );
            wp_cache_delete((string) $wcId, 'post_meta');
            wc_delete_product_transients($wcId);
        }
        $say('reset', 'done');
        break;
    }

    default:
        $say('verb', 'unknown');
}
