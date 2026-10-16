<?php
/**
 * `alpha.56` on real WooCommerce: the three intake defects of `alpha.55`.
 *
 * **Its own file, named for its round** — the rule `alpha.54` paid for, and
 * `EvidenceToolsAreNotOverwrittenTest` is its guard. It loads on BOTH trees
 * (`alpha.32`'s rule): everything this round adds is reached through
 * `method_exists()` / `class_exists()`, so a verb about a capability
 * `alpha.55` does not have answers `absent=1` instead of dying at load — and
 * «absent» is itself a measurement.
 *
 * **The failures are injected into the DATABASE, by statement name**
 * (`FailingDatabase`, which the database suite already uses). Every other
 * statement runs against the real MariaDB on the same connection, so what is
 * measured afterwards is the rows that are really in the table — not a mock's
 * opinion. These are injected failures of a collaborator, not spontaneous
 * ones, and they are reported as such.
 *
 * Its fixture is its OWN (`WC-A56-*`), because a fixture shared with the
 * previous round's script is a fixture one of them spends (`alpha.33`).
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha56-probe.php <verb> [args]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;

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

require_once __DIR__ . '/FailingDatabase.php';

/** The marketplace vendor this round's fixtures are assigned to. */
const TMC_P56_VENDOR = 9156;
const TMC_P56_SKU = 'WC-A56-SHOP';
const TMC_P56_RUN = 'intake-a56';

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

(new DbVendorRepository($db, $clock))->upsertProfile(TMC_P56_VENDOR, 'تجهیزات آزمون ۵۶', true, false);

// ------------------------------------------------------------------ helpers

/**
 * An intake service whose DATABASE can be made to refuse named statements.
 *
 * Everything but the repository comes from the container, so what runs is the
 * real wiring; only the gateway underneath the repository is wrapped.
 */
$intakeWith = static function (?FailingDatabase $failing) use ($c, $clock): ImportExistingProducts {
    // The CONTAINER's gateway, wrapped — not a second `WpDatabase` over the
    // same `$wpdb`. Measured: with two objects the depth counter is per
    // object, so `saveImages()`'s own `begin()` (one unit of work since
    // `alpha.31`) issued a REAL `START TRANSACTION`, which MySQL answers by
    // committing the one the intake had already opened. The row then survived
    // a rolled-back intake and the probe reported a defect in a build that
    // was right. Nesting lives in the object, so the decorator must wrap the
    // object the transaction comes from.
    $gateway = $failing ?? $c->get(DatabaseInterface::class);
    return new ImportExistingProducts(
        $c->get(CatalogueReaderInterface::class),
        $c->get(CatalogueInventory::class),
        new DbProductRepository($gateway, $clock),
        $c->get(VendorRepositoryInterface::class),
        $c->get(\Tecteb\Marketplace\Contracts\OptionStoreInterface::class),
        $c->get(\Tecteb\Marketplace\Core\Audit\AuditLogger::class),
        $clock,
        $c->get(CapabilityCheckerInterface::class),
        $c->get(ProductRevisionRepositoryInterface::class),
        // The transaction comes from the container and the statements from the
        // wrapper: two objects, ONE `$wpdb` and so one MySQL connection, which
        // is what makes a rolled-back unit really roll the injected failure's
        // siblings back. Passed through `method_exists` on the constructor so
        // this file still loads on `alpha.55`, whose constructor has no tenth
        // argument (`alpha.32`'s rule).
        ...((new ReflectionClass(ImportExistingProducts::class))->getConstructor()->getNumberOfParameters() >= 10
            ? [$c->get(\Tecteb\Marketplace\Contracts\TransactionInterface::class)]
            : [])
    );
};

/** The marketplace row for one WooCommerce product, read with raw SQL. */
$rawRow = static function (int $wcId) use ($db): array {
    $rows = $db->getResults(
        'SELECT id, status, link_ownership, import_run_id, approved_baseline, brand, price_minor, stock
           FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d ORDER BY id ASC',
        [$wcId]
    );
    return $rows[0] ?? [];
};

$imageCount = static fn (int $productId): int => (int) ($db->getVar(
    'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_product_images` WHERE product_id = %d',
    [$productId]
) ?? -1);

$runManifest = static function () use ($db): array {
    $raw = get_option('tmc_catalogue_intake_runs', []);
    return is_array($raw) ? $raw : [];
};

switch ($verb) {
    // ============================================ the fixture of this round

    /**
     * A plain WooCommerce product with a brand, a gallery and a price — the
     * shop's own, authored by the administrator, which is the state most of a
     * live catalogue is in. Idempotent by SKU.
     */
    case 'arrange': {
        if (!function_exists('wc_get_product_id_by_sku')) {
            $say('refused', 'no_woocommerce');
            break;
        }
        $wcId = (int) wc_get_product_id_by_sku(TMC_P56_SKU);
        if ($wcId <= 0) {
            $product = new WC_Product_Simple();
            $product->set_name('کالای ورود ۵۶');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_short_description('خلاصهٔ ورود ۵۶');
            $product->set_description('متن کامل ورود ۵۶');
            $product->set_sku(TMC_P56_SKU);
            $product->set_regular_price('450000');
            $product->set_manage_stock(true);
            $product->set_stock_quantity(22);
            $product->set_weight('1.5');
            $wcId = (int) $product->save();
        }
        // Two attachments so the gallery write has something to write, and a
        // brand term so `setBrandTerm()` is reached.
        $gallery = [];
        foreach (['a56-one', 'a56-two'] as $slug) {
            $existing = get_posts([
                'post_type' => 'attachment',
                'name' => $slug,
                'numberposts' => 1,
                'fields' => 'ids',
                'post_status' => 'inherit',
            ]);
            $gallery[] = (int) ($existing[0] ?? wp_insert_post([
                'post_title' => $slug,
                'post_name' => $slug,
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image/png',
            ]));
        }
        update_post_meta($wcId, '_thumbnail_id', $gallery[0]);
        update_post_meta($wcId, '_product_image_gallery', implode(',', array_slice($gallery, 1)));
        if (taxonomy_exists('product_brand')) {
            wp_set_object_terms($wcId, ['سینا طب ۵۶'], 'product_brand', false);
        }
        $db->execute(
            'UPDATE `' . $db->prefix() . 'posts` SET post_author = %d WHERE ID = %d',
            [$adminId, $wcId]
        );
        clean_post_cache($wcId);
        $say('wc_id', $wcId);
        $say('status', (string) get_post_status($wcId));
        $say('price', (string) get_post_meta($wcId, '_regular_price', true));
        $say('stock', (string) get_post_meta($wcId, '_stock', true));
        $say('gallery', implode(',', $gallery));
        $say('marketplace_rows', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [$wcId]
        ) ?? -1));
        break;
    }

    // ===================== §2-الف an intake whose parts did not all land

    /**
     * THE FINDING: `intake()` writes the row, then the baseline, the brand and
     * the gallery — and reports `imported` whichever of the three failed.
     *
     * The baseline is the one that matters most: it is the agreement baseline
     * the approval path compares against, so a row with an empty one makes
     * every field look like somebody else's change for ever.
     */
    case 'intake-baseline-fails': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $failing = new FailingDatabase($c->get(DatabaseInterface::class));
        $failing->failWhen(['UPDATE', 'tmc_products', 'approved_baseline']);
        $batch = $intakeWith($failing)->importBatch(TMC_P56_RUN, [$wcId], TMC_P56_VENDOR);
        $say('batch_code', (string) ($batch['code'] ?? '?'));
        $say('imported', (string) (($batch['counts']['imported'] ?? 0)));
        $say('failed', (string) (($batch['counts']['failed'] ?? 0)));
        $say('verdict', (string) ($batch['results'][0]['verdict'] ?? '?'));
        $say('reason', (string) ($batch['results'][0]['reason'] ?? ''));
        $say('refused_statements', (string) count($failing->refused));
        $row = $rawRow($wcId);
        $say('row_exists', $row !== []);
        $say('row_id', (string) ($row['id'] ?? 0));
        $say('baseline', ((string) ($row['approved_baseline'] ?? '')) === '' ? '__empty__' : 'present');
        $say('import_run', (string) ($row['import_run_id'] ?? ''));
        break;
    }

    /** The same for the gallery: a row with no images where the shop has two. */
    case 'intake-images-fail': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $failing = new FailingDatabase($c->get(DatabaseInterface::class));
        $failing->failWhen(['tmc_product_images']);
        $batch = $intakeWith($failing)->importBatch(TMC_P56_RUN, [$wcId], TMC_P56_VENDOR);
        $say('verdict', (string) ($batch['results'][0]['verdict'] ?? '?'));
        $say('reason', (string) ($batch['results'][0]['reason'] ?? ''));
        $say('imported', (string) (($batch['counts']['imported'] ?? 0)));
        $row = $rawRow($wcId);
        $say('row_exists', $row !== []);
        $say('images_in_row', (string) $imageCount((int) ($row['id'] ?? 0)));
        break;
    }

    /**
     * And the second run, with nothing failing: on `alpha.55` the product is
     * `already_linked_observed`, so the half-written row is abandoned for
     * ever — the retry cannot reach it and no page says it is half written.
     */
    case 'intake-retry': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $batch = $intakeWith(null)->importBatch(TMC_P56_RUN . '-retry', [$wcId], TMC_P56_VENDOR);
        $say('verdict', (string) ($batch['results'][0]['verdict'] ?? '?'));
        $say('reason', (string) ($batch['results'][0]['reason'] ?? ''));
        $say('imported', (string) (($batch['counts']['imported'] ?? 0)));
        $row = $rawRow($wcId);
        $say('rows_for_product', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [$wcId]
        ) ?? -1));
        $say('baseline_after_retry', ((string) ($row['approved_baseline'] ?? '')) === '' ? '__empty__' : 'present');
        $say('images_after_retry', (string) $imageCount((int) ($row['id'] ?? 0)));
        // WHICH run the row ends up stamped with — the retry's on a tree that
        // retried, the first attempt's on a tree that skipped. The rollback
        // verbs are given this rather than a constant, because a script that
        // named the run itself would roll back a run one of the two trees
        // never created and report a defect about the wrong thing.
        $say('row_run', ((string) ($row['import_run_id'] ?? '')) === ''
            ? '__empty__'
            : (string) $row['import_run_id']);
        break;
    }

    /** The named repair path — `alpha.56` only. */
    case 'intake-repair': {
        $intake = $intakeWith(null);
        if (!method_exists($intake, 'repair')) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $row = $rawRow($wcId);
        $result = $intake->repair((int) ($row['id'] ?? 0));
        $say('repair_code', $result->code);
        $say('repair_ok', $result->ok);
        $after = $rawRow($wcId);
        $say('baseline_after_repair', ((string) ($after['approved_baseline'] ?? '')) === '' ? '__empty__' : 'present');
        $say('images_after_repair', (string) $imageCount((int) ($after['id'] ?? 0)));
        break;
    }

    /** What a page can say about half-written intakes — `alpha.56` only. */
    case 'intake-incomplete': {
        $intake = $intakeWith(null);
        if (!method_exists($intake, 'incomplete')) {
            $say('absent', 1);
            break;
        }
        $rows = $intake->incomplete();
        $say('incomplete_count', (string) count($rows));
        $say('incomplete_ids', implode(',', array_map(static fn (array $r): string => (string) $r['product_id'], $rows)));
        $say('incomplete_parts', implode(',', array_map(static fn (array $r): string => implode('+', $r['missing']), $rows)));
        break;
    }

    // ======================== §2-ب a rollback whose delete did not happen

    /**
     * THE FINDING: when `deleteObservedFromRun()` refuses, `alpha.55` clears
     * the import stamp AND deletes the run manifest — so the two things a
     * retry needs are both gone, and the next attempt answers
     * `intake_run_unknown` about a row that is still there.
     */
    case 'rollback-delete-fails': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $runId = (string) ($args[2] ?? TMC_P56_RUN);
        $failing = new FailingDatabase($c->get(DatabaseInterface::class));
        $failing->failWhen(['DELETE', 'tmc_products']);
        $before = $rawRow($wcId);
        $say('row_before', (string) ($before['id'] ?? 0));
        $say('stamp_before', (string) ($before['import_run_id'] ?? ''));
        $say('manifest_before', array_key_exists($runId, $runManifest()) ? 'present' : '__absent__');
        $result = $intakeWith($failing)->rollback($runId);
        $say('rollback_code', $result->code);
        $say('rollback_ok', $result->ok);
        $say('kept', (string) ($result->context['kept'] ?? ''));
        $after = $rawRow($wcId);
        $say('row_after', (string) ($after['id'] ?? 0));
        $say('stamp_after', ((string) ($after['import_run_id'] ?? '')) === '' ? '__empty__' : (string) $after['import_run_id']);
        $say('manifest_after', array_key_exists($runId, $runManifest()) ? 'present' : '__absent__');
        break;
    }

    /** And the retry: can the same run be rolled back once the fault is gone? */
    case 'rollback-retry': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $runId = (string) ($args[2] ?? TMC_P56_RUN);
        $result = $intakeWith(null)->rollback($runId);
        $say('rollback_code', $result->code);
        $say('rollback_ok', $result->ok);
        $say('removed', (string) ($result->context['removed'] ?? ''));
        $say('rows_for_product', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [$wcId]
        ) ?? -1));
        break;
    }

    /**
     * A rollback must not take the dependent rows and leave the product — nor
     * leave the dependent rows behind after it took the product.
     */
    case 'rollback-leftovers': {
        $wcId = (int) ($args[1] ?? 0);
        $row = $rawRow($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $say('product_row', $productId > 0 ? 'present' : '__absent__');
        $say('image_rows', (string) $imageCount($productId));
        $say('spec_rows', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_product_specs` WHERE product_id = %d',
            [$productId]
        ) ?? -1));
        break;
    }

    // ===================== §2-ج acceptance that carries a stale snapshot

    /**
     * THE FINDING: the intake snapshots WooCommerce; acceptance does not ask
     * whether that snapshot is still true. A price the manager changes between
     * the intake and the acceptance is overwritten by the intake's number the
     * first time the product is projected — and the manager is not told.
     *
     * The risk acknowledgement does not cover this: it is about fields this
     * marketplace cannot express, not about a value that MOVED.
     */
    case 'accept-stale': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        // 1. a clean intake
        $intakeWith(null)->importBatch(TMC_P56_RUN . '-accept', [$wcId], TMC_P56_VENDOR);
        $row = $rawRow($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $say('row_price_at_intake', (string) ($row['price_minor'] ?? '?'));
        $say('row_stock_at_intake', (string) ($row['stock'] ?? '?'));

        // 2. somebody changes the shop AFTER the intake — the ordinary case,
        //    because an intake is a preview step a manager sleeps on.
        $product = wc_get_product($wcId);
        $product->set_regular_price('480000');
        $product->set_price('480000');
        $product->set_stock_quantity(30);
        $product->save();
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('shop_price_before_accept', (string) get_post_meta($wcId, '_regular_price', true));
        $say('shop_stock_before_accept', (string) get_post_meta($wcId, '_stock', true));

        // 3. acceptance, with every named risk acknowledged
        $transfer = $c->get(TransferOwnership::class);
        $risks = array_keys($transfer->risksFor($productId));
        $say('risks', implode(',', $risks) === '' ? '__none__' : implode(',', $risks));
        $taken = $transfer->take($productId, $risks);
        $say('take_code', $taken->code);
        $say('take_ok', $taken->ok);
        $say('ownership_after', $products->linkOwnership($productId)->value);

        // 4. the next sync — which is what a save, an approval or the
        //    manager's own «sync» button all reach.
        $synced = $c->get(SyncCatalog::class)->publish($productId);
        $say('sync_code', $synced->code);
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('shop_price_after_sync', (string) get_post_meta($wcId, '_regular_price', true));
        $say('shop_stock_after_sync', (string) get_post_meta($wcId, '_stock', true));
        $say('manager_price_kept', ((string) get_post_meta($wcId, '_regular_price', true)) === '480000');
        // Said out loud, because the first version of this verb left the row
        // behind in one tree and not in the other and the difference looked
        // like a finding: how many marketplace rows point at this product
        // when the verb ends, and what the sync did to the catalogue.
        $say('rows_at_end', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
            [$wcId]
        ) ?? -1));
        $say('rows_for_vendor_at_end', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_products` WHERE vendor_user_id = %d',
            [TMC_P56_VENDOR]
        ) ?? -1));
        break;
    }

    /**
     * A FOURTH FINDING, found while measuring the third: projecting an
     * observed row DUPLICATES the product.
     *
     * `publish()` asks the projector «do you own this post?». An intake
     * writes no ownership stamp on purpose, so the answer is no, so the link
     * was dropped and a brand-new WooCommerce product was built and the row
     * pointed at it. Nobody asked for a product; the one being observed was
     * left behind with nothing pointing at it.
     *
     * It is reachable from any manager action that syncs a row, which is why
     * it is worth a verb of its own rather than a footnote.
     */
    case 'project-observed': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $productsBefore = (int) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_status <> %s',
            ['product', 'trash']
        ) ?? -1);
        $intakeWith(null)->importBatch(TMC_P56_RUN . '-proj', [$wcId], TMC_P56_VENDOR);
        $row = $rawRow($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $say('row', (string) $productId);
        $say('ownership', $products->linkOwnership($productId)->value);
        $synced = $c->get(SyncCatalog::class)->publish($productId);
        $say('sync_code', $synced->code);
        $after = $products->find($productId);
        $say('row_points_at', (string) ($after?->wcProductId ?? 0));
        $say('still_points_at_the_observed_product', ((int) ($after?->wcProductId ?? 0)) === $wcId);
        $say('wc_products_before', (string) $productsBefore);
        $say('wc_products_after', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_status <> %s',
            ['product', 'trash']
        ) ?? -1));
        break;
    }

    /**
     * The control: an acceptance with NOTHING changed in between must still
     * work, on both trees. A guard that stood in the way of the ordinary case
     * would be worse than the defect.
     */
    case 'accept-fresh': {
        if (!class_exists(ImportExistingProducts::class)) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $intakeWith(null)->importBatch(TMC_P56_RUN . '-fresh', [$wcId], TMC_P56_VENDOR);
        $row = $rawRow($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $transfer = $c->get(TransferOwnership::class);
        $taken = $transfer->take($productId, array_keys($transfer->risksFor($productId)));
        $say('take_code', $taken->code);
        $say('take_ok', $taken->ok);
        $say('ownership_after', $products->linkOwnership($productId)->value);
        $synced = $c->get(SyncCatalog::class)->publish($productId);
        $say('sync_code', $synced->code);
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('shop_price_after_sync', (string) get_post_meta($wcId, '_regular_price', true));
        break;
    }

    /** The refreshed-then-accepted path — `alpha.56` only. */
    case 'accept-refresh': {
        $transfer = $c->get(TransferOwnership::class);
        if (!method_exists($transfer, 'refreshFromStorefront')) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) ($args[1] ?? 0);
        $row = $rawRow($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $refreshed = $transfer->refreshFromStorefront($productId);
        $say('refresh_code', $refreshed->code);
        $say('refresh_ok', $refreshed->ok);
        $after = $rawRow($wcId);
        $say('row_price_after_refresh', (string) ($after['price_minor'] ?? '?'));
        $say('row_stock_after_refresh', (string) ($after['stock'] ?? '?'));
        $taken = $transfer->take($productId, array_keys($transfer->risksFor($productId)));
        $say('take_code', $taken->code);
        $synced = $c->get(SyncCatalog::class)->publish($productId);
        $say('sync_code', $synced->code);
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('shop_price_after_sync', (string) get_post_meta($wcId, '_regular_price', true));
        $say('manager_price_kept', ((string) get_post_meta($wcId, '_regular_price', true)) === '480000');
        break;
    }

    // ============ §3 why 189 products and 262 simple are the same table

    /**
     * THE MECHANISM, PLANTED AND MEASURED.
     *
     * The owner read 189 products across four statuses beside `simple=262
     * variable=32` on staging. The cause is in the query rather than on the
     * site: `alpha.55` counted `product_type` term relationships with NO
     * join to `posts`, while the status count was scoped to
     * `post_type = 'product'`.
     *
     * A clean demo site cannot show it — there is nothing stray to count, and
     * both numbers agree, which is exactly why every suite passed. So the
     * three stray shapes are PLANTED, measured, and removed again: a type
     * term on a variation, one on another post type, and one pointing at no
     * post at all. On `alpha.55` the unscoped count rises by three and the
     * status total does not move; on this tree the two stay equal and the
     * strays are reported by name.
     */
    case 'counts-scope': {
        $reader = $c->get(CatalogueReaderInterface::class);
        $pfx = $db->prefix();
        $typeTax = (int) ($db->getVar(
            'SELECT tt.term_taxonomy_id FROM `' . $pfx . 'term_taxonomy` tt
             INNER JOIN `' . $pfx . 'terms` t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = %s AND t.name = %s LIMIT 1',
            ['product_type', 'simple']
        ) ?? 0);
        if ($typeTax <= 0) {
            $say('refused', 'no_simple_type_term');
            break;
        }
        $before = $reader->counts();
        $say('before_total', (string) ($before['total'] ?? 0));
        $say('before_by_type', implode(',', array_map(
            static fn (string $k, int $v): string => $k . ':' . $v,
            array_keys($before['by_type'] ?? []),
            array_values($before['by_type'] ?? [])
        )));

        // 1. a variation with a type term — some importers write one
        $variation = (int) ($db->getVar(
            'SELECT ID FROM `' . $pfx . 'posts` WHERE post_type = %s LIMIT 1',
            ['product_variation']
        ) ?? 0);
        // 2. another post type entirely
        $page = (int) ($db->getVar(
            'SELECT ID FROM `' . $pfx . 'posts` WHERE post_type = %s LIMIT 1',
            ['page']
        ) ?? 0);
        // 3. an id with no post behind it at all, which is what a deleted
        //    product can leave behind
        $ghost = 1 + (int) ($db->getVar('SELECT MAX(ID) FROM `' . $pfx . 'posts`') ?? 0);
        $planted = [];
        foreach ([$variation, $page, $ghost] as $objectId) {
            if ($objectId <= 0) {
                continue;
            }
            if ($db->execute(
                'INSERT IGNORE INTO `' . $pfx . 'term_relationships` (object_id, term_taxonomy_id, term_order)
                 VALUES (%d, %d, 0)',
                [$objectId, $typeTax]
            ) !== null) {
                $planted[] = $objectId;
            }
        }
        $say('planted', implode(',', $planted));

        $after = $reader->counts();
        $say('after_total', (string) ($after['total'] ?? 0));
        $say('after_by_type', implode(',', array_map(
            static fn (string $k, int $v): string => $k . ':' . $v,
            array_keys($after['by_type'] ?? []),
            array_values($after['by_type'] ?? [])
        )));
        $say('type_total_moved', array_sum($after['by_type'] ?? []) !== array_sum($before['by_type'] ?? []));
        $say('status_total_moved', (int) ($after['total'] ?? 0) !== (int) ($before['total'] ?? 0));
        $say('types_add_up_to_statuses', array_sum($after['by_type'] ?? []) === (int) ($after['total'] ?? -1));
        $say('strays_reported', array_key_exists('strays', $after)
            ? implode(',', array_map(
                static fn (string $k, int $v): string => $k . ':' . $v,
                array_keys($after['strays']),
                array_values($after['strays'])
            ))
            : '__absent__');
        $say('without_type', (string) ($after['without_type'] ?? '__absent__'));
        $say('with_two_types', (string) ($after['with_two_types'] ?? '__absent__'));

        foreach ($planted as $objectId) {
            $db->execute(
                'DELETE FROM `' . $pfx . 'term_relationships`
                  WHERE object_id = %d AND term_taxonomy_id = %d',
                [$objectId, $typeTax]
            );
        }
        $say('planted_removed', 'yes');
        break;
    }

    // ------------------------------------------------------------------ reset

    /**
     * Hand the fixture back (`alpha.33`): the marketplace rows go, the
     * WooCommerce product and its attachments STAY — they were never ours to
     * remove — and the shop's price goes back to what `arrange` writes.
     */
    // ========================== §3 ONE LIST: both halves, each product once

    /**
     * The whole catalogue, one page of it, with all three kinds of row in
     * view: a WooCommerce product nobody has imported, one a marketplace row
     * points at, and a marketplace row with no WooCommerce product at all.
     *
     * What this measures that no double can: that the UNION really returns
     * each related product once on a real `wp_posts` with real duplicate
     * `_sku` rows — the LEFT JOIN this list deliberately does not make.
     */
    case 'one-list': {
        if (!method_exists(CatalogueInventory::class, 'unified')) {
            $say('absent', 1);
            break;
        }
        $wcId = (int) wc_get_product_id_by_sku(TMC_P56_SKU);
        // A second `_sku` row on the same product: `wp_postmeta` has no unique
        // index on `(post_id, meta_key)` — measured on WordPress 7.1 in
        // `alpha.55` — so a list that joined it would show this product twice.
        $db->execute(
            'INSERT INTO `' . $db->prefix() . 'postmeta` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)',
            [$wcId, '_sku', TMC_P56_SKU . '-DUP']
        );
        wp_cache_delete($wcId, 'post_meta');
        $inventory = $c->get(CatalogueInventory::class);
        $page = $inventory->unified(['search' => 'ورود ۵۶'], 100, 0);
        $ids = array_map(static fn (array $r): int => (int) $r['wc_product_id'], $page['rows']);
        $say('available', (bool) $page['available']);
        $say('rows', count($page['rows']));
        $say('this_product_appears', count(array_filter($ids, static fn (int $id): bool => $id === $wcId)));
        $row = null;
        foreach ($page['rows'] as $candidate) {
            if ((int) $candidate['wc_product_id'] === $wcId) {
                $row = $candidate;
            }
        }
        $say('source', (string) ($row['source'] ?? '__absent__'));
        $say('management', (string) ($row['management'] ?? '__absent__'));
        $say('wc_status', (string) ($row['wc_status'] ?? '__absent__'));
        $say('bucket', (string) ($row['bucket'] ?? '__absent__'));
        $say('sku', (string) ($row['sku'] ?? '__absent__'));
        $say('submission', (string) ($row['submission'] ?? '__absent__'));
        $db->execute(
            'DELETE FROM `' . $db->prefix() . 'postmeta` WHERE post_id = %d AND meta_key = %s AND meta_value = %s',
            [$wcId, '_sku', TMC_P56_SKU . '-DUP']
        );
        wp_cache_delete($wcId, 'post_meta');
        break;
    }

    /**
     * A marketplace row with NO WooCommerce product — the half of the
     * catalogue the old intake screen could not show at all, and the half the
     * review list could not show either once it was filtered by WooCommerce
     * status.
     */
    case 'list-draft-only': {
        if (!method_exists(CatalogueInventory::class, 'unified')) {
            $say('absent', 1);
            break;
        }
        // Named arguments, because `ProductDetails` has sixteen promoted
        // properties and a positional call here would be a silent reshuffle
        // the day one of them moves.
        $created = $products->create(
            TMC_P56_VENDOR,
            new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                title: 'پیش‌نویس بی محصول ۵۶',
                priceMinor: 1000,
                sku: 'A56-DRAFT',
                stock: 3
            ),
            \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Draft
        );
        $page = $c->get(CatalogueInventory::class)->unified(['search' => 'بی محصول ۵۶'], 50, 0);
        $row = $page['rows'][0] ?? [];
        $say('rows', count($page['rows']));
        $say('wc_product_id', (int) ($row['wc_product_id'] ?? -1));
        $say('product_id', (int) ($row['product_id'] ?? -1));
        $say('source', (string) ($row['source'] ?? '__absent__'));
        $say('wc_status', (string) ($row['wc_status'] ?? '__absent__'));
        $say('bucket', (string) ($row['bucket'] ?? '__absent__'));
        $say('vendor', (int) ($row['vendor_user_id'] ?? -1));
        $say('created_id', (int) $created);
        break;
    }

    /**
     * The three facets are three questions.
     *
     * The pair that matters: a WooCommerce DRAFT that is managed by this
     * marketplace, and a PUBLISHED product that is only observed. Either
     * filter alone finds both of its own kind; mixing them the way a single
     * column would is what the instruction forbids.
     */
    case 'facets': {
        if (!method_exists(CatalogueInventory::class, 'unified')) {
            $say('absent', 1);
            break;
        }
        $inventory = $c->get(CatalogueInventory::class);
        $all = $inventory->unified([], 1, 0);
        $say('total', (int) $all['total']);
        foreach (['source', 'management', 'wc_status'] as $facet) {
            $tally = (array) ($all['facets'][$facet] ?? []);
            $say($facet . '_sum', array_sum($tally));
            $say($facet . '_values', implode(',', array_keys($tally)));
        }
        // Every row is in exactly one bucket of each facet, so each tally sums
        // to the same total. A sum that disagrees means a row was counted
        // twice — which is what a LEFT JOIN on a duplicated column does.
        $say('sums_agree', array_sum((array) $all['facets']['source'])
            === array_sum((array) $all['facets']['management']));
        $published = $inventory->unified(['wc_status' => 'publish'], 1, 0);
        $observed = $inventory->unified(['management' => 'observed'], 1, 0);
        $both = $inventory->unified(['wc_status' => 'publish', 'management' => 'observed'], 1, 0);
        $say('published_total', (int) $published['total']);
        $say('observed_total', (int) $observed['total']);
        $say('published_and_observed', (int) $both['total']);
        break;
    }

    /**
     * Paging is the query's job.
     *
     * Two pages of five, and the ids must not overlap: «همهٔ محصولات برای
     * صفحه‌بندی در PHP خوانده نشوند» is only true if the `LIMIT`/`OFFSET` is
     * real, and an overlap is what an unstable sort looks like from outside.
     */
    case 'paging': {
        if (!method_exists(CatalogueInventory::class, 'unified')) {
            $say('absent', 1);
            break;
        }
        $inventory = $c->get(CatalogueInventory::class);
        $first = $inventory->unified([], 5, 0);
        $second = $inventory->unified([], 5, 5);
        $key = static fn (array $rows): array => array_map(
            static fn (array $r): string => $r['wc_product_id'] . ':' . $r['product_id'],
            $rows
        );
        $say('total', (int) $first['total']);
        $say('first_rows', count($first['rows']));
        $say('second_rows', count($second['rows']));
        $say('overlap', count(array_intersect($key($first['rows']), $key($second['rows']))));
        // And the bound is real: asking for a thousand gets the ceiling, not a
        // thousand. `per_page=100000` is the same request as «everything».
        $say('ceiling', count($inventory->unified([], 100000, 0)['rows']));
        break;
    }

    /**
     * What one page of the list COSTS, in statements and in milliseconds.
     *
     * §9 asks for this and asks for it honestly: «بدون اندازه‌گیری «سبک‌تر»
     * نگو». Read from `Com_select` and `Handler_read_*` in this session, the
     * way `alpha.37` measured the badge, rather than from the shape of the
     * query.
     */
    case 'list-cost': {
        if (!method_exists(CatalogueInventory::class, 'unified')) {
            $say('absent', 1);
            break;
        }
        $before = [];
        foreach ($db->getResults("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Handler_read_rnd_next','Handler_read_key','Handler_read_next')") as $row) {
            $before[(string) $row['Variable_name']] = (int) $row['Value'];
        }
        $started = microtime(true);
        $page = $c->get(CatalogueInventory::class)->unified([], 20, 0);
        $elapsed = (microtime(true) - $started) * 1000;
        $after = [];
        foreach ($db->getResults("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Handler_read_rnd_next','Handler_read_key','Handler_read_next')") as $row) {
            $after[(string) $row['Variable_name']] = (int) $row['Value'];
        }
        $say('rows', count($page['rows']));
        $say('total', (int) $page['total']);
        // Minus one: the `SHOW SESSION STATUS` that read the «after» numbers
        // is itself a select, and counting it would be this measurement
        // measuring itself.
        $say('selects', max(0, ($after['Com_select'] ?? 0) - ($before['Com_select'] ?? 0) - 1));
        $say('rows_touched', ($after['Handler_read_rnd_next'] ?? 0) - ($before['Handler_read_rnd_next'] ?? 0)
            + ($after['Handler_read_key'] ?? 0) - ($before['Handler_read_key'] ?? 0)
            + ($after['Handler_read_next'] ?? 0) - ($before['Handler_read_next'] ?? 0));
        $say('elapsed_ms', round($elapsed, 2));
        break;
    }

    /**
     * A shop is chosen by NAME, not by a user id somebody has to know.
     *
     * Three places a shop can be named, and the one answer each: the settings
     * row, the application and the profile.
     */
    case 'shop-search': {
        $stores = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class);
        if (!method_exists($stores, 'searchByShopName')) {
            $say('absent', 1);
            break;
        }
        $matches = $stores->searchByShopName('آزمون ۵۶');
        $say('matches', count($matches));
        $say('first_vendor', (int) ($matches[0]['vendor_user_id'] ?? -1));
        $say('first_name', (string) ($matches[0]['store_name'] ?? '__absent__'));
        $say('first_source', (string) ($matches[0]['source'] ?? '__absent__'));
        $say('nothing_matches', count($stores->searchByShopName('فروشگاهی که نیست ۵۶')));
        // A `%` somebody types is a percent sign in a shop name, not a
        // wildcard that matches every shop on the site.
        $say('percent_is_literal', count($stores->searchByShopName('%')));
        break;
    }

    // ============== §4 the marketplace button on the account dashboard

    /**
     * What `/my-account/`'s dashboard hook actually prints, for each kind of
     * person — on real WooCommerce, through the real hook.
     *
     * The owner reported seeing only Dokan's red button. This separates the
     * two possible causes: whether the hook runs at all (it does here, and
     * `lastSeen()` is how the owner's own site answers it for their theme),
     * and whether this plugin draws anything for the person looking (a
     * manager got nothing until this round, and that was correct — what was
     * missing was an ADMINISTRATIVE link, not a vendor one).
     */
    case 'account-button': {
        if (!class_exists(\Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorAccountLink::class)) {
            $say('absent', 1);
            break;
        }
        $link = \Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorAccountLink::class;
        if (!method_exists($link, 'managerLabel')) {
            $say('manager_route', '__absent__');
            break;
        }
        delete_option($link::SEEN_OPTION);
        $render = static function (int $userId) use ($link, $c): string {
            wp_set_current_user($userId);
            // A FRESH instance per person: `$printed` is per-request and a
            // shared one would make the second answer «nothing», which is
            // the `alpha.37` mistake in a measurement.
            $instance = new $link($c);
            ob_start();
            $instance->renderPanel();
            return (string) ob_get_clean();
        };
        // The manager: an administrator with no vendor profile and no roster
        // place, which is the account in the owner's screenshots.
        $managerHtml = $render($adminId);
        $say('manager_printed', $managerHtml !== '');
        $say('manager_is_admin_route', str_contains($managerHtml, 'page=tmc-product-review'));
        // Compared against the route's OWN address, not against a query
        // string: this site has pretty permalinks, so `?tmc_vendor=dashboard`
        // never appears and the first version of this check reported `no`
        // about a build that was right.
        $panelUrl = \Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorRoutes::dashboardUrl();
        $say('panel_url', $panelUrl);
        $say('manager_not_vendor_panel', !str_contains($managerHtml, $panelUrl));
        $say('manager_label', $link::managerLabel());
        // …and the manager is STILL not a vendor afterwards: no profile was
        // created, no roster touched. Drawing a link must change nothing.
        $say('manager_profile_after', $c->get(VendorRepositoryInterface::class)
            ->findProfileByUser($adminId) === null ? '__none__' : 'created');

        // The vendor this round's fixtures belong to.
        $vendorUser = (int) (get_users(['number' => 1, 'fields' => 'ID', 'exclude' => [$adminId]])[0] ?? 0);
        if ($vendorUser > 0) {
            (new DbVendorRepository($db, $clock))->upsertProfile($vendorUser, 'فروشگاه دکمه ۵۶', true, false);
            $vendorHtml = $render($vendorUser);
            $say('vendor_printed', $vendorHtml !== '');
            $say('vendor_is_panel', str_contains($vendorHtml, $panelUrl));
            $say('vendor_not_admin_route', !str_contains($vendorHtml, 'page=tmc-product-review'));
        } else {
            $say('vendor_printed', '__no_second_user__');
        }

        wp_set_current_user($adminId);
        $seen = $link::lastSeen();
        $say('seen_dashboard', (string) $seen['dashboard']);
        $say('seen_printed', (string) $seen['printed']);
        $say('seen_label', (string) $seen['label']);
        // And the shortcode is the answer for a theme that calls neither
        // hook. Measured here as «the same block», not as a claim about the
        // owner's theme — which this environment cannot see.
        $instance = new $link($c);
        $say('shortcode_same_block', $instance->shortcode() !== '');
        break;
    }

    // ===================== §5 the Min Max exit on a VARIABLE product

    /**
     * A variable, NON-MARKETPLACE product with a Min Max rule — migrated and
     * granted.
     *
     * `alpha.55` measured the grant on a simple product. §5 asks for the
     * parent, the variation, a repeated add and a cart change as well,
     * because those are four different code paths in WooCommerce and a rule
     * that held on one of them says nothing about the other three.
     *
     * «Non-marketplace» is made the way a real one is: an OBSERVED row, which
     * answers no to `marketplaceOwns()` while the link still exists. Nulling
     * `wc_product_id` is a state nothing produces and it made the previous
     * round's fixture unable to find its own product (`alpha.55`).
     */
    case 'minmax-variable-arrange': {
        if (!function_exists('wc_get_product_id_by_sku')) {
            $say('refused', 'no_woocommerce');
            break;
        }
        $mm = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax::class);
        if (!method_exists($mm, 'enforce')) {
            $say('absent', 1);
            break;
        }
        $sku = 'WC-A56-VAR3';
        $wcId = (int) wc_get_product_id_by_sku($sku);
        if ($wcId <= 0) {
            $parent = new WC_Product_Variable();
            $parent->set_name('کالای متنوع ۵۶');
            $parent->set_status('publish');
            $parent->set_catalog_visibility('visible');
            $parent->set_sku($sku);
            $attribute = new WC_Product_Attribute();
            // A LATIN attribute name, on purpose and for a reason that is not
            // about this round: `sanitize_title('رنگ')` is percent-encoded
            // (`alpha.43` measured it), so `WC_Cart::add_to_cart()` cannot
            // match the chosen option and refuses with «رنگ is a required
            // field» — a true sentence about WooCommerce's attribute keys
            // which the first version of this verb read as the purchase
            // rule's refusal. The quantity rule does not care what the
            // attribute is called, so the fixture stops asking.
            $attribute->set_name('Color');
            $attribute->set_options(['Blue', 'Red']);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $parent->set_attributes([$attribute]);
            $wcId = (int) $parent->save();
            foreach (['Blue' => '210000', 'Red' => '220000'] as $option => $price) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($wcId);
                // The SANITIZED name as the key — `attribute_color`, which is
                // what `WC_Cart::add_to_cart()` looks the valid value up by
                // (`class-wc-cart.php:1243`). Keyed `'Color'` the meta row is
                // never written and the cart answers «Color is a required
                // field», which the first version of this verb read as the
                // purchase rule's refusal.
                $variation->set_attributes(['color' => $option]);
                $variation->set_regular_price($price);
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity(40);
                $variation->save();
            }
        }
        $db->execute('UPDATE `' . $db->prefix() . 'posts` SET post_author = %d WHERE ID = %d', [$adminId, $wcId]);
        // The Min Max rule on the PARENT, under the prefix the installed
        // plugin actually uses — read from its own constant, never guessed.
        $pfx = defined('WC_MMQ_PREFIX') ? WC_MMQ_PREFIX : '';
        foreach (['min_quantity' => '4', 'max_quantity' => '10', 'product_step' => '2'] as $field => $value) {
            update_post_meta($wcId, $pfx . $field, $value);
        }
        // An observed marketplace row pointing at it, so «not ours» is the
        // real state and not an invented one. Built through the intake, which
        // is the thing that makes observed rows.
        $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d', [$wcId]);
        $existing = $products->findByWcProduct($wcId);
        if ($existing === null) {
            $batch = $c->get(ImportExistingProducts::class)
                ->importBatch(TMC_P56_RUN . '-var', [$wcId], TMC_P56_VENDOR);
            $say('intake_verdict', (string) ($batch['results'][0]['verdict'] ?? '?'));
        } else {
            $say('intake_verdict', 'already');
        }
        wc_delete_product_transients($wcId);
        wp_cache_delete((string) $wcId, 'post_meta');

        $migrated = $mm->migrateBatch(1, $wcId - 1, false, 1);
        $say('wc_id', $wcId);
        $say('variations', (string) count((array) wc_get_product($wcId)?->get_children()));
        $say('migrate_outcome', (string) (($migrated['results'][0]['outcome'] ?? ($migrated['code'] ?? '?'))));
        $port = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface::class);
        $say('marketplace_owns', $port->marketplaceOwns($wcId));
        $say('granted_before', $port->enforcementGranted($wcId));
        $enforce = $mm->enforce($wcId);
        $say('enforce_code', $enforce->code);
        $say('granted_after', $port->enforcementGranted($wcId));
        // And nothing else moved: the grant is about one rule.
        $say('wc_status', (string) get_post_status($wcId));
        $say('wc_author', (string) ($db->getVar(
            'SELECT post_author FROM `' . $db->prefix() . 'posts` WHERE ID = %d',
            [$wcId]
        ) ?? 0));
        break;
    }

    /**
     * What the cart does about that product — parent, variation, a repeated
     * add and a change of quantity, in the classic cart and through the Store
     * API.
     *
     * The gate is opened with the plugin's OWN local key and closed again:
     * without it every quantity is refused, which is a true sentence about
     * the sales gate and says nothing about the purchase rule (`alpha.53`).
     */
    case 'minmax-variable-cart': {
        $wcId = (int) ($args[1] ?? 0);
        $product = $wcId > 0 && function_exists('wc_get_product') ? wc_get_product($wcId) : null;
        if (!is_object($product)) {
            $say('refused', 'no_product');
            break;
        }
        $children = array_map('intval', (array) $product->get_children());
        $variationId = (int) ($children[0] ?? 0);
        $gateWas = get_option('tmc_order_trial_mode');
        update_option('tmc_order_trial_mode', '1');
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        // Min Max's own validator calls `wc_add_notice()` from inside its
        // namespace and fatals without it under WP-CLI — its bug, not this
        // plugin's, and a probe that dies on it measures nothing.
        if (!function_exists('wc_add_notice') && defined('WC_ABSPATH')) {
            require_once WC_ABSPATH . 'includes/wc-notice-functions.php';
        }
        $clear = static function (): void {
            if (function_exists('wc_clear_notices')) {
                wc_clear_notices();
            }
            if (function_exists('WC') && WC()->cart !== null) {
                WC()->cart->empty_cart();
            }
        };
        $clear();
        $args9 = function_exists('wc_get_quantity_input_args') ? wc_get_quantity_input_args([], $product) : [];
        $say('parent_input_args', implode('/', [
            (string) ($args9['min_value'] ?? '?'),
            (string) ($args9['max_value'] ?? '?'),
            (string) ($args9['step'] ?? '?'),
        ]));
        $say('variation_id', $variationId);
        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            $vargs = is_object($variation) && function_exists('wc_get_quantity_input_args')
                ? wc_get_quantity_input_args([], $variation)
                : [];
            $say('variation_input_args', implode('/', [
                (string) ($vargs['min_value'] ?? '?'),
                (string) ($vargs['max_value'] ?? '?'),
                (string) ($vargs['step'] ?? '?'),
            ]));
        }
        // THE PARENT, through the filter every classic add-to-cart path runs
        // (`alpha.45`: `WC_Cart::add_to_cart()` is not the validation point).
        foreach ([1, 3, 4, 8, 11] as $quantity) {
            $clear();
            $passed = apply_filters('woocommerce_add_to_cart_validation', true, $wcId, $quantity, $variationId);
            $say('qty_' . $quantity, $passed === true ? 'allowed' : 'refused');
        }
        // A REPEATED ADD, starting from a quantity the rule ALLOWS.
        //
        // The first version started from 2, which both validators refuse on a
        // `4/10/2` rule — so the cart stayed empty, there was nothing to
        // repeat, and the cart-change filter was handed an empty item and
        // answered «allowed» about nothing. A measurement whose precondition
        // failed reads exactly like a passing one (`alpha.46`).
        //
        // So: add 4 (allowed), then 2 more (total 6, allowed), then 1 more
        // (total 7, refused). The rule counts the cart's TOTAL for the item,
        // not one row, which is why the second add of 2 is a different
        // question from an add of 2 into an empty cart.
        $clear();
        if (function_exists('WC') && WC()->cart !== null) {
            // The variation's ATTRIBUTES too: `add_to_cart()` refuses a
            // variable product without them («Please choose product
            // options»), and the first version read that refusal as the
            // purchase rule's — a true sentence about the wrong guard.
            $chosen = $variationId > 0 && is_object(wc_get_product($variationId))
                ? (array) wc_get_product($variationId)->get_variation_attributes()
                : [];
            $added = WC()->cart->add_to_cart($wcId, 4, $variationId, $chosen);
            $say('first_add_4', $added === false ? 'refused' : 'in_cart');
            // WHY it was refused, when it was: `add_to_cart()` runs a dozen
            // checks of WooCommerce's own and reports them as notices. A
            // probe that printed only «refused» would attribute every one of
            // them to the purchase rule.
            if ($added === false && function_exists('wc_get_notices')) {
                $notices = (array) (wc_get_notices('error') ?: []);
                $first = $notices[0] ?? [];
                $say('first_add_refusal', mb_substr(wp_strip_all_tags((string) (is_array($first) ? ($first['notice'] ?? '') : $first)), 0, 80));
            }
            $say('cart_has', (string) WC()->cart->get_cart_contents_count());
            $say('repeat_add_2', apply_filters('woocommerce_add_to_cart_validation', true, $wcId, 2, $variationId) === true ? 'allowed' : 'refused');
            $say('repeat_add_1', apply_filters('woocommerce_add_to_cart_validation', true, $wcId, 1, $variationId) === true ? 'allowed' : 'refused');
            // A CHANGE of quantity in the cart, which is a different filter
            // again — and only answerable when there IS an item.
            $items = WC()->cart->get_cart();
            $key = (string) array_key_first($items);
            $say('cart_key', $key === '' ? '__empty_cart__' : 'present');
            if ($key !== '') {
                $say('change_to_5', apply_filters('woocommerce_update_cart_validation', true, $key, $items[$key], 5) === true ? 'allowed' : 'refused');
                $say('change_to_6', apply_filters('woocommerce_update_cart_validation', true, $key, $items[$key], 6) === true ? 'allowed' : 'refused');
            }
        }
        // And the Store API's own per-item validator, which the block cart
        // runs — the half a hand-built request cannot route around.
        foreach ([3, 4] as $quantity) {
            try {
                do_action('woocommerce_store_api_validate_cart_item', $product, ['quantity' => $quantity]);
                $say('store_api_qty_' . $quantity, 'allowed');
            } catch (\Throwable) {
                $say('store_api_qty_' . $quantity, 'refused');
            }
        }
        $clear();
        if ($gateWas === false) {
            delete_option('tmc_order_trial_mode');
        } else {
            update_option('tmc_order_trial_mode', $gateWas);
        }
        $say('gate_restored', 'yes');
        break;
    }

    /**
     * A grant that no longer describes its rule — and the page that says so.
     *
     * The grant is the fingerprint of the three values it was given for, so
     * editing the rule afterwards leaves it alive and dead: the product
     * allows any quantity from that moment. Correct, and also a purchase
     * limit that has gone away, which is why it has a count of its own.
     */
    case 'minmax-stale-grant': {
        $wcId = (int) ($args[1] ?? 0);
        $mm = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax::class);
        if (!method_exists($mm, 'staleGrants')) {
            $say('absent', 1);
            break;
        }
        $port = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface::class);
        $say('granted_before', $port->enforcementGranted($wcId));
        // `__none__` and not an empty string: the reader of these files turns
        // an empty value into `__missing__`, so «nothing is stale» and «the
        // key was never printed» would read the same — the conflation this
        // repository refuses everywhere else (`alpha.8`).
        $stale = static fn (array $ids): string => $ids === [] ? '__none__' : implode(',', $ids);
        $say('stale_before', $stale($mm->staleGrants()));
        // A manager edits the ceiling by hand, through WooCommerce's own meta.
        update_post_meta($wcId, '_tmc_max_purchase', '8');
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('granted_after', $port->enforcementGranted($wcId));
        $say('stale_after', $stale($mm->staleGrants()));
        $say('this_product_listed', in_array($wcId, $mm->staleGrants(), true));
        // Re-issued, and it is live again — the way back that the warning
        // names.
        $again = $mm->enforce($wcId);
        // And re-issuing on the CHANGED rule is refused, which is
        // `alpha.55`'s rule and the reason the warning does not advise it:
        // the grant is about the rule that was migrated, and a rule nobody
        // migrated is not a rule this plugin may grant.
        $say('reissue_code', $again->code);
        $say('granted_again', $port->enforcementGranted($wcId));
        $say('stale_at_end', $stale($mm->staleGrants()));
        // The way out that DOES work: put the rule back where the migration
        // left it, and the grant is live again with no new decision.
        update_post_meta($wcId, '_tmc_max_purchase', '10');
        wp_cache_delete((string) $wcId, 'post_meta');
        $say('granted_after_putting_it_back', $port->enforcementGranted($wcId));
        $say('stale_after_putting_it_back', $stale($mm->staleGrants()));
        break;
    }

    /** The rollback of a granted product takes the grant with it. */
    case 'minmax-rollback-granted': {
        $wcId = (int) ($args[1] ?? 0);
        $mm = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax::class);
        $port = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface::class);
        $say('granted_before', $port->enforcementGranted($wcId));
        $result = $mm->rollback($wcId);
        $say('rollback_code', $result->code);
        $say('granted_after', $port->enforcementGranted($wcId));
        $rows = [];
        foreach (\Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity::keys() as $key) {
            $rows[] = metadata_exists('post', $wcId, $key)
                ? (string) get_post_meta($wcId, $key, true)
                : '__absent__';
        }
        $say('rows_after_rollback', implode('/', $rows));
        break;
    }

    // ============================ §10 WHAT ACTIVATION ACTUALLY SPENDS

    /**
     * Each step of activation, timed, on a site that already has the schema.
     *
     * §10 asks why `alpha.55` installed slowly and says not to name a cause
     * without evidence. So this names no cause: it measures the four things
     * `Activator::activate()` does and reports each one's milliseconds and
     * statement count, so a slow step on the owner's site can be compared
     * against a fast one here.
     *
     * It does NOT deactivate the plugin. Deactivation takes marketplace
     * products off sale (`alpha.8`) and that is a change to a shop, not a
     * measurement of one — and the steps below are the same calls whether
     * they run from the activation hook or from here.
     */
    case 'install-timing': {
        $selects = static function () use ($db): int {
            foreach ($db->getResults("SHOW SESSION STATUS WHERE Variable_name = 'Questions'") as $row) {
                return (int) $row['Value'];
            }
            return 0;
        };
        $time = static function (string $label, callable $work) use ($say, $selects): void {
            $before = $selects();
            $started = microtime(true);
            try {
                $work();
                $outcome = 'ok';
            } catch (\Throwable $e) {
                $outcome = 'threw:' . substr($e->getMessage(), 0, 40);
            }
            // Minus two: the two `SHOW SESSION STATUS` reads are themselves
            // statements, and counting them would be the measurement
            // measuring itself.
            $say($label . '_ms', round((microtime(true) - $started) * 1000, 2));
            $say($label . '_statements', max(0, $selects() - $before - 2));
            $say($label . '_outcome', $outcome);
        };

        $say('schema_target', (string) \Tecteb\Marketplace\Core\Migration\SchemaVersion::TARGET);
        $say('schema_now', (string) ($db->getVar(
            'SELECT option_value FROM `' . $db->prefix() . 'options` WHERE option_name = %s',
            ['tmc_schema_version']
        ) ?? '__absent__'));
        $say('products_on_this_site', (string) ($db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'posts` WHERE post_type = %s',
            ['product']
        ) ?? -1));
        $say('active_plugins', (string) count((array) get_option('active_plugins', [])));

        $time('capabilities', static function () use ($c): void {
            \Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\CapabilityInstaller::install();
        });
        $time('settings', static function () use ($c): void {
            $c->get(\Tecteb\Marketplace\Core\Config\SettingsService::class)->ensureStored();
        });
        // The migration chain on a site that is ALREADY at the target: this is
        // the «nothing to do» cost, which is what a re-activation pays.
        $time('migrations_at_target', static function () use ($c): void {
            $c->get(\Tecteb\Marketplace\Core\Migration\MigrationRunner::class)->run();
        });
        $time('legacy_documents', static function (): void {
            Bootstrap::relocateLegacyPrivateDocuments();
        });
        // The one step whose cost grows with the SITE rather than with this
        // plugin: every post type and taxonomy on the install contributes
        // rules, and the result is one option row.
        $time('flush_rewrite_rules', static function (): void {
            \Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorRoutes::addRewriteRules();
            flush_rewrite_rules(false);
        });
        $rules = get_option('rewrite_rules', []);
        $say('rewrite_rules_count', (string) (is_array($rules) ? count($rules) : -1));
        $say('rewrite_rules_bytes', (string) strlen((string) maybe_serialize($rules)));
        // And what the plugin does NOT do, said as a measurement rather than
        // as a promise: `grep` proves there is no outbound call in `src/`,
        // and this proves none happened during the steps above.
        $say('http_calls_during_activation', (string) count((array) ($GLOBALS['tmc_probe_http'] ?? [])));
        break;
    }

    case 'reset': {
        $wcId = (int) wc_get_product_id_by_sku(TMC_P56_SKU);
        $ids = array_map('intval', array_column((array) $db->getResults(
            'SELECT id FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d OR vendor_user_id = %d',
            [$wcId, TMC_P56_VENDOR]
        ), 'id'));
        foreach ($ids as $id) {
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_product_images` WHERE product_id = %d', [$id]);
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_product_specs` WHERE product_id = %d', [$id]);
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_product_revisions` WHERE product_id = %d', [$id]);
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_product_decisions` WHERE product_id = %d', [$id]);
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_products` WHERE id = %d', [$id]);
        }
        delete_option('tmc_catalogue_intake_runs');
        if ($wcId > 0) {
            // The ownership stamps this plugin may have written on the post,
            // so the next run starts where `arrange` leaves it.
            // `_tmc_product_id` matters most: it is the post's link to a
            // marketplace row, and leaving a dead one behind makes the next
            // run's `claimStorefrontPost()` refuse with `storefront_refused`
            // — a true sentence about a stale fixture, printed as if it were
            // a finding about the build.
            foreach ([
                '_tmc_product_id',
                '_tmc_vendor_id',
                '_tmc_claimed_from_status',
                '_tmc_projected_owned',
                '_tmc_projected_conflicts',
                '_tmc_quantity_written',
                '_tmc_quantity_conflicts',
            ] as $key) {
                delete_post_meta($wcId, $key);
            }
            // Through WooCommerce's OWN API, not `update_post_meta()`: the
            // price also lives in `wc_product_meta_lookup` and in the
            // product instance cache (`alpha.50`), so a meta-only restore
            // leaves `wc_get_product()` answering the old number — and then
            // the next run's intake reads 480000, «changes» it to 480000,
            // and the measurement has nothing to lose. That is how the first
            // version of this verb printed `manager_price_kept=yes` about a
            // tree that loses the price.
            $product = wc_get_product($wcId);
            if (is_object($product)) {
                $product->set_regular_price('450000');
                $product->set_price('450000');
                $product->set_manage_stock(true);
                $product->set_stock_quantity(22);
                $product->save();
            }
            wp_cache_delete((string) $wcId, 'post_meta');
            clean_post_cache($wcId);
        }
        // Any WooCommerce product a run CREATED by projecting an observed row
        // (the fourth finding) — swept by this fixture's own title so the
        // next run starts from one product rather than a growing pile.
        $extra = 0;
        foreach ((array) $db->getResults(
            'SELECT ID FROM `' . $db->prefix() . 'posts`
              WHERE post_type = %s AND post_title = %s AND ID <> %d',
            ['product', 'کالای ورود ۵۶', $wcId]
        ) as $post) {
            wp_delete_post((int) $post['ID'], true);
            $extra++;
        }
        $say('extra_products_swept', (string) $extra);
        $say('rows_removed', (string) count($ids));
        $say('wc_product_kept', $wcId > 0 && get_post_status($wcId) !== false);
        $say('reset', 'done');
        break;
    }

    /**
     * GIVE BACK the Min Max rows this round's variable fixture spent.
     *
     * `alpha.33`'s rule, and it cost three checks before it was written: the
     * variable fixture leaves a parent and two variations carrying the rule
     * `4/10/2`, so `alpha53-minmax-check.sh` — which asks the SITE how many
     * products carry a rule — counted **seven** where it expects four and
     * reported a failure about a build that was entirely correct. The number
     * was true; it was about the leftovers.
     *
     * The WooCommerce products stay (they are cheap and `arrange` reuses
     * them); what goes is the Min Max rule, the enforcement grant and the
     * handover row, which is exactly what another script can see.
     */
    case 'minmax-hand-back': {
        // Swept by TITLE, not by SKU — and that is the second half of the
        // same lesson. `arrange` looks its product up by SKU, so an earlier
        // run whose SKU was cleared leaves a parent behind that the next run
        // cannot find: the site ended with THREE parents called «کالای متنوع
        // ۵۶», each carrying `4/10/2`, and `alpha53-minmax-check.sh` counted
        // seven products with a rule where it expects four. The number was
        // true; it was about the pile.
        $swept = 0;
        $removed = 0;
        $pfx = defined('WC_MMQ_PREFIX') ? WC_MMQ_PREFIX : '';
        foreach ((array) $db->getResults(
            'SELECT ID FROM `' . $db->prefix() . 'posts` WHERE post_type = %s AND post_title = %s',
            ['product', 'کالای متنوع ۵۶']
        ) as $post) {
            $parentId = (int) $post['ID'];
            $ids = [$parentId];
            foreach ((array) (wc_get_product($parentId)?->get_children() ?? []) as $child) {
                $ids[] = (int) $child;
            }
            foreach ($ids as $id) {
                foreach (['min_quantity', 'max_quantity', 'product_step'] as $f) {
                    if (metadata_exists('post', $id, $pfx . $f)) {
                        delete_post_meta($id, $pfx . $f);
                        $removed++;
                    }
                }
                wc_delete_product_transients($id);
                wp_cache_delete((string) $id, 'post_meta');
            }
            $db->execute(
                'DELETE FROM `' . $db->prefix() . 'tmc_minmax_handover` WHERE post_id = %d',
                [$parentId]
            );
            // The row that points at it goes with it, or the next run reads a
            // marketplace row whose product is gone — an orphan link, which
            // is a real state this plugin reports and NOT one a fixture
            // should be manufacturing.
            foreach ((array) $db->getResults(
                'SELECT id FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
                [$parentId]
            ) as $row) {
                $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_products` WHERE id = %d', [(int) $row['id']]);
            }
            // And the product itself: `arrange` builds it again on demand,
            // and a fixture that leaves a growing pile measures the pile
            // (`alpha.55`, where five products appeared where four were
            // expected).
            wp_delete_post($parentId, true);
            $swept++;
        }
        $say('parents_swept', (string) $swept);
        $say('rule_rows_removed', (string) $removed);
        $say('rules_left_on_site', (string) ($db->getVar(
            'SELECT COUNT(DISTINCT m.post_id) FROM `' . $db->prefix() . 'postmeta` m
               WHERE m.meta_key = %s AND m.meta_value <> %s',
            [$pfx . 'min_quantity', '']
        ) ?? -1));
        $say('handed_back', 1);
        break;
    }

    default: {
        $say('verb', 'unknown');
        break;
    }
}
