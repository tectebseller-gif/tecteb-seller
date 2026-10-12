<?php
/**
 * The window between the check and the write, measured on real WooCommerce
 * with a real second writer.
 *
 * **What `alpha.49` got wrong.** It made the approval verify its stock write by
 * reading it back, and `docs/phase-47-…` §4.1 said that covered this window.
 * It does not. `writeStock()` writes the approved number unconditionally, so a
 * read-back that finds exactly that number proves the write landed and says
 * nothing about what it replaced. Two units sold between the check and the
 * write are overwritten and every check in the path agrees with itself.
 *
 * **The shape of the measurement.** `approveRevision()` reads the stock, then
 * writes the marketplace row, the specs and the images, then projects — and
 * `project()` ends in `$wcProduct->save()`, which fires WooCommerce's own
 * `woocommerce_update_product`. That hook is INSIDE the window, so it is where
 * the second writer is let in: a one-shot action that runs
 * `tests/Support/concurrent-stock-sale.php` in a SEPARATE `wp` process, which
 * reduces the stock through WooCommerce's own path (an order moving to
 * `processing`, or `wc_update_product_stock(…, 'decrease')`).
 *
 * Deterministic, and that is the point: the second process runs to completion
 * synchronously before the approval's write. No sleeps, no timing, no retry
 * loops — the window is entered every time.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha49-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
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

const TMC_P50_VENDOR = 9010;
const TMC_P50_CATEGORY = 'tmc-p50-swab';
const TMC_P50_SKU = 'SKU-ALPHA50';

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
    ->upsertProfile(TMC_P50_VENDOR, 'تجهیزات آزمون ۵۰', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P50_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (int $stock = 9): ProductDetails {
    $named = [
        'title' => 'باند کشی آزمون ۵۰',
        'type' => 'simple',
        'categoryKey' => TMC_P50_CATEGORY,
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 450000,
        'sku' => TMC_P50_SKU,
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

/** A published, projected product whose shop stock is exactly `$stock`. */
$published = static function (int $stock = 9)
use ($products, $findBySku, $detailsOf, $revisions, $catalog): array {
    $id = $findBySku(TMC_P50_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P50_VENDOR, $detailsOf($stock), ProductStatus::Published);
    } else {
        if (!$products->updateDetails($id, $detailsOf($stock), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
        $products->updateInventory($id, $stock, TMC_P50_SKU, 1, 20, 1);
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
            $file = wp_upload_dir()['path'] . '/tmc-alpha50.png';
            file_put_contents($file, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
            ));
            $attachmentId = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'tmc-alpha50',
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
        $shopProduct->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        $shopProduct->save();
    }
    wc_delete_product_transients($wcId);
    return [$id, $wcId];
};

/** The `_stock` ROW, in the spelling it is actually stored in. */
$storedRow = static function (int $wcId): string {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
        $wcId
    ));
    return $raw === null ? '__absent__' : (string) $raw;
};

/**
 * The stock a shopper is held to: the ROW, read numerically.
 *
 * NOT `wc_get_product()->get_stock_quantity()`, and that is a measurement
 * rather than a preference. WooCommerce 11.0.1 enables
 * `product_instance_caching`, so inside one request the factory can hand back
 * an object it built earlier — measured here: another process took the row
 * from 9 to 7, this process read the row as 7 and the object as 9 in the same
 * breath. A probe that asked the object would have reported a shop that had
 * not moved, about a shop that had.
 */
$shopStock = static function (int $wcId) use (&$storedRow): string {
    $raw = $storedRow($wcId);
    return $raw === '__absent__' ? '__absent__' : (string) (int) round((float) $raw);
};

/**
 * Run the SECOND WRITER, in its own process, and hand back what it printed.
 *
 * `WP_CLI` is not reachable from inside `eval-file` as a library, so this is a
 * plain shell call to the same binary with the same path — a different process
 * by construction, which is the whole requirement.
 */
$secondWriter = static function (string $mode, int $wcId, int $qty): array {
    $binary = getenv('TMC_WPCLI') ?: '/usr/local/bin/wp';
    $php = getenv('TMC_PHPBIN') ?: 'php';
    $root = getenv('TMC_WP_PATH') ?: ABSPATH;
    $script = getenv('TMC_REPO') ?: dirname(__DIR__, 2);
    $command = sprintf(
        '%s %s --allow-root --path=%s eval-file %s %s %d %d 2>&1',
        escapeshellarg($php),
        escapeshellarg($binary),
        escapeshellarg(rtrim($root, '/')),
        escapeshellarg($script . '/tests/Support/concurrent-stock-sale.php'),
        escapeshellarg($mode),
        $wcId,
        $qty
    );
    $output = (string) shell_exec($command);
    $values = [];
    foreach (explode("\n", $output) as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', trim($line), 2);
            $values[$key] = $value;
        }
    }
    return $values;
};

/**
 * Let the second writer in exactly ONCE, inside the window.
 *
 * `woocommerce_update_product` is WooCommerce's own hook at the end of
 * `WC_Product_Data_Store_CPT::update()`, and `approveRevision()` reaches it
 * through `publish()` → `project()` → `save()` — after the stock has been
 * checked and before it is written. One shot, because the approval saves the
 * product more than once on its way through and a second firing would be
 * measuring a different window.
 */
$arrangeSecondWriter = static function (string $mode, int $wcId, int $qty) use ($secondWriter): callable {
    $state = ['ran' => false, 'result' => []];
    $action = static function () use (&$state, $secondWriter, $mode, $wcId, $qty): void {
        if ($state['ran']) {
            return;
        }
        $state['ran'] = true;
        $state['result'] = $secondWriter($mode, $wcId, $qty);
    };
    add_action('woocommerce_update_product', $action, 10, 0);
    return static function () use (&$state, $action): array {
        remove_action('woocommerce_update_product', $action, 10);
        return $state;
    };
};

$vendorSave = static function (int $id, int $stock) use ($manage): array {
    $method = new \ReflectionMethod(ManageProducts::class, 'updateInventory');
    $arguments = [TMC_P50_VENDOR, TMC_P50_VENDOR, $id, $stock, TMC_P50_SKU, 1, 20];
    if ($method->getNumberOfParameters() >= 8) {
        $arguments[] = 1;
    }
    $result = $manage->updateInventory(...$arguments);
    return [$result->ok ? 'ok' : 'failed', $result->code, $result->context];
};

$approve = static function (int $revisionId, bool $accept = false, ?int $seenStock = null)
use ($review): array {
    $method = new \ReflectionMethod(ReviewProducts::class, 'approveRevision');
    $arguments = [$revisionId, []];
    if ($method->getNumberOfParameters() >= 3) {
        $arguments[] = $accept;
    }
    if ($method->getNumberOfParameters() >= 4) {
        $arguments[] = $seenStock;
    }
    $result = $review->approveRevision(...$arguments);
    return [$result->ok ? 'ok' : 'failed', $result->code, $result->context];
};

// -------------------------------------------------------------- the verbs

switch ($verb) {
    /**
     * THE FINDING: an order reduces the stock inside the window.
     *
     * The second writer is the real path — an order moving to `processing`, so
     * `wc_maybe_reduce_stock_levels()` does the reducing — in its own process.
     */
    case 'sale-between-order':
    case 'sale-between-decrease':
        $mode = $verb === 'sale-between-order' ? 'order' : 'decrease';
        [$id, $wcId] = $published(9);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('shop_at_start', $shopStock($wcId));
        [$ok, $code] = $vendorSave($id, 5);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        $say('revision_id', $revisionId);
        $collect = $arrangeSecondWriter($mode, $wcId, 2);
        [$ok, $code, $context] = $approve($revisionId);
        $state = $collect();
        $say('second_writer_ran', $state['ran'] ? 'yes' : 'NO');
        $say('second_writer_mode', $mode);
        $say('second_writer_before', (string) ($state['result']['before'] ?? '__none__'));
        $say('second_writer_after', (string) ($state['result']['after'] ?? '__none__'));
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('expected_stock', (string) ($context['expected_stock'] ?? '__none__'));
        $say('shop_after', $shopStock($wcId));
        $say('row_after', $storedRow($wcId));
        $say('mirror_after', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        // The sale took two units off a shelf of nine. Whatever the approval
        // decides, seven is the number that must survive — and five is the
        // number that proves it did not.
        $say('second_writer_kept', $shopStock($wcId) === '7' ? 'yes' : 'NO');
        $say('overwritten_invalidly', $shopStock($wcId) === '5' ? 'YES' : 'no');
        break;

    /** The ordinary approval, with nobody else writing. */
    case 'control-approve':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 5);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        [$ok, $code, $context] = $approve($revisionId);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('shop_after', $shopStock($wcId));
        $say('mirror_after', (string) ($products->find($id)?->details->stock ?? '__none__'));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        break;

    /** The manager's «apply it anyway» over the number they were shown. */
    case 'control-override':
        [$id, $wcId] = $published(9);
        [$ok, $code] = $vendorSave($id, 5);
        $say('propose_code', $code);
        $revisionId = (int) ($revisions->pendingFor($id)?->id ?? 0);
        // The shop moves BEFORE the page is rendered, so the manager sees the
        // conflict and the number 6.
        $shopProduct = wc_get_product($wcId);
        if ($shopProduct instanceof \WC_Product) {
            $shopProduct->set_stock_quantity(6);
            $shopProduct->save();
        }
        wc_delete_product_transients($wcId);
        $say('shop_when_page_rendered', $shopStock($wcId));
        [$ok, $code, $context] = $approve($revisionId, true, 6);
        $say('approve', $ok);
        $say('approve_code', $code);
        $say('shop_after', $shopStock($wcId));
        $say('revision_status', (string) ($revisions->find($revisionId)?->status ?? '__gone__'));
        break;

    /**
     * And WooCommerce's own reduction still reduces — the regression that
     * matters, because a guard that got in the shop's way would be worse than
     * the defect.
     */
    case 'control-order-reduces':
        [$id, $wcId] = $published(9);
        $say('shop_at_start', $shopStock($wcId));
        $result = $secondWriter('order', $wcId, 2);
        $say('writer_ran', (string) ($result['writer'] ?? '__none__'));
        $say('order_stock_reduced', (string) ($result['order_stock_reduced'] ?? '__none__'));
        $say('writer_before', (string) ($result['before'] ?? '__none__'));
        $say('writer_after', (string) ($result['after'] ?? '__none__'));
        $say('row_after', $storedRow($wcId));
        $say('shop_after', $shopStock($wcId));
        $say('mirror_after', (string) ($products->find($id)?->details->stock ?? '__none__'));
        break;

    /** A draft's stock still applies at once: the unguarded path is untouched. */
    case 'control-draft':
        $draftId = $findBySku(TMC_P50_SKU . '-D');
        if ($draftId === 0) {
            $draft = $detailsOf(9);
            $named = [];
            foreach ((new \ReflectionClass(ProductDetails::class))->getProperties() as $property) {
                $named[$property->getName()] = $property->getValue($draft);
            }
            $named['sku'] = TMC_P50_SKU . '-D';
            $draftId = $products->create(TMC_P50_VENDOR, new ProductDetails(...$named), ProductStatus::Draft);
        }
        $say('draft_id', $draftId);
        $method = new \ReflectionMethod(ManageProducts::class, 'updateInventory');
        $arguments = [TMC_P50_VENDOR, TMC_P50_VENDOR, $draftId, 31, TMC_P50_SKU . '-D', 1, 20];
        if ($method->getNumberOfParameters() >= 8) {
            $arguments[] = 1;
        }
        $saved = $manage->updateInventory(...$arguments);
        $say('save_code', $saved->code);
        $say('row_stock', (string) ($products->find($draftId)?->details->stock ?? '__none__'));
        break;

    /**
     * THE TWO SPELLINGS of one number, and the guard answering the same to
     * both.
     *
     * `WC_Product::save()` stores the stock through `update_post_meta()` as
     * «7»; WooCommerce's own direct path stores
     * `number_format(7, WC_ROUNDING_PRECISION)` — «7.000000». A guard that
     * compared strings would pass or fail on which code last touched the
     * product, so this verb writes the row both ways and asks the guard for a
     * verdict each time.
     */
    case 'dialects':
        [$id, $wcId] = $published(9);
        /** @var CatalogProjectorInterface $projector */
        $projector = $c->get(CatalogProjectorInterface::class);
        if (!method_exists($projector, 'writeStockIfUnchanged')) {
            $say('guarded_write', 'absent_in_this_build');
            break;
        }
        $product = $products->find($id);
        // Dialect one: the object API.
        $shopProduct = wc_get_product($wcId);
        $shopProduct->set_stock_quantity(7);
        $shopProduct->save();
        wp_cache_delete($wcId, 'post_meta');
        $say('dialect_object', $storedRow($wcId));
        $say('guard_on_object_dialect', (string) ($projector->writeStockIfUnchanged($product, 4, 7)['outcome'] ?? '?'));
        $say('row_after_object_dialect', $storedRow($wcId));
        // Dialect two: WooCommerce's own direct statement.
        wc_update_product_stock(wc_get_product($wcId), 7, 'set');
        wp_cache_delete($wcId, 'post_meta');
        $say('dialect_direct', $storedRow($wcId));
        $say('guard_on_direct_dialect', (string) ($projector->writeStockIfUnchanged($product, 4, 7)['outcome'] ?? '?'));
        $say('row_after_direct_dialect', $storedRow($wcId));
        // And a stale expectation is refused in either spelling.
        $say('guard_on_stale_expectation', (string) ($projector->writeStockIfUnchanged($product, 4, 9)['outcome'] ?? '?'));
        $say('row_after_stale', $storedRow($wcId));
        break;

    case 'reset':
        [$id, $wcId] = $published(9);
        $say('product_id', $id);
        $say('wc_id', $wcId);
        $say('shop_stock', $shopStock($wcId));
        $say('row', $storedRow($wcId));
        $say('pending', (string) ($revisions->pendingFor($id)?->id ?? 0));
        break;

    default:
        $say('verb', 'unknown');
        break;
}
