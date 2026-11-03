<?php
/**
 * Fixture and probe for `tools/upgrade-alpha91-check.sh` — structure 37 → 38.
 *
 * ⛔ **It must load and answer on BOTH packages.** `alpha.91` has no
 * `ProjectedStatus`, no migration 38 and no status guard, so every new thing
 * is asked for with `class_exists`/`method_exists` and answered `absent`
 * rather than fataling (`alpha.32`). A probe that cannot load on the baseline
 * reports nothing about the baseline.
 *
 * Disposable only: the first thing it does is refuse to run anywhere but the
 * throwaway database.
 *
 * Verbs:
 *   arrange          one vendor, three posts, three rows: the Dokan state, a
 *                    marketplace-owned control, and one whose post is gone
 *   state            structure, stamps, and whether this build knows step 38
 *   unmigrate-38     put the site GENUINELY on 37: stamps cleared, version 37
 *   break-column     rename the stamp column, so the step's UPDATE fails
 *   fix-column       put it back
 *   take <which>     run a REAL ownership transfer and print the refusal code
 *   shop <which>     the WooCommerce product's own status and visibility
 *   give-back <which>  undo the transfer
 *   reset            give everything back
 */

declare(strict_types=1);

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

// BOTH HALVES, in the canonical wording `DisposableOnlyToolsTest` reads.
//
// ⛔ The first draft of this file wrote `DB_NAME !== 'wordpress'`, which is
// not the disposable site's database at all (`tmc_wp_demo` is), so the tool
// would have refused to run on the very site it was written for — and the
// host half was spelled as an equality against one URL, which the detector
// does not recognise. The unit guard caught both.
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

global $wpdb;

const TMC_VENDOR = 94001;
/** Published in the shop, imported as the Dokan path imported: Draft, no stamp. */
const TMC_SKU_DOKAN = 'UPG92-DOKAN';
/** A row the marketplace owns: the control the backfill must not touch. */
const TMC_SKU_OURS = 'UPG92-OURS';
/** An imported row whose WooCommerce product is deleted. */
const TMC_SKU_GONE = 'UPG92-GONE';

$products = Bootstrap::container()->get(ProductRepositoryInterface::class);
$verb = $argv[1] ?? 'state';
$which = $argv[2] ?? 'dokan';

function tmc_products_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'tmc_products';
}

/** The stamp column's name, read from the class that owns it. */
function tmc_stamp_column(): string
{
    $class = 'Tecteb\\Marketplace\\Modules\\Product\\Infrastructure\\Migrations\\M0032ProductMirroredStatus';
    return class_exists($class) ? $class::MIRRORED : 'mirrored_status';
}

function tmc_row_by_sku(string $sku): ?array
{
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM `' . tmc_products_table() . '` WHERE sku = %s', $sku),
        ARRAY_A
    );
    return is_array($row) ? $row : null;
}

function tmc_stamp(string $sku): string
{
    $row = tmc_row_by_sku($sku);
    if ($row === null) {
        return '__norow__';
    }
    $value = $row[tmc_stamp_column()] ?? null;
    return $value === null ? '__null__' : (string) $value;
}

function tmc_wc_id(string $sku): int
{
    $row = tmc_row_by_sku($sku);
    return (int) ($row['wc_product_id'] ?? 0);
}

function tmc_schema(): int
{
    return (int) get_option('tmc_schema_version', 0);
}

function tmc_sku_of(string $which): string
{
    return match ($which) {
        'ours' => TMC_SKU_OURS,
        'gone' => TMC_SKU_GONE,
        default => TMC_SKU_DOKAN,
    };
}

if ($verb === 'arrange') {
    // The vendor, through the repository rather than the table: a profile
    // written by hand is «approved and unable to sell» (`alpha.17`).
    $vendors = Bootstrap::container()->get(
        \Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class
    );
    $vendors->upsertProfile(TMC_VENDOR, 'فروشگاه ارتقای ۹۲', true, false);

    $made = [];
    foreach ([TMC_SKU_DOKAN => 'publish', TMC_SKU_OURS => 'publish', TMC_SKU_GONE => 'publish'] as $sku => $status) {
        $postId = wp_insert_post([
            'post_type' => 'product',
            'post_title' => 'کالای ' . $sku,
            'post_status' => $status,
            'post_author' => TMC_VENDOR,
        ], true);
        if (is_wp_error($postId)) {
            echo 'error=' . $postId->get_error_message() . "\n";
            exit(1);
        }
        update_post_meta((int) $postId, '_sku', $sku);
        update_post_meta((int) $postId, '_regular_price', '450000');
        $made[$sku] = (int) $postId;
    }

    // THE DOKAN STATE: Draft beside a published product, and NO stamp — which
    // is exactly `create()` without its seventh argument, the defect being
    // fixed. Written through the repository, so the row is whole.
    $details = static fn (string $sku): ProductDetails => new ProductDetails(
        title: 'کالای ' . $sku,
        priceMinor: 450000,
        sku: $sku,
        stock: 3
    );
    $dokanRow = $products->create(
        TMC_VENDOR,
        $details(TMC_SKU_DOKAN),
        ProductStatus::Draft,
        LinkOwnership::Observed,
        $made[TMC_SKU_DOKAN]
    );
    $oursRow = $products->create(
        TMC_VENDOR,
        $details(TMC_SKU_OURS),
        ProductStatus::Published,
        LinkOwnership::Marketplace,
        $made[TMC_SKU_OURS]
    );
    $goneRow = $products->create(
        TMC_VENDOR,
        $details(TMC_SKU_GONE),
        ProductStatus::Draft,
        LinkOwnership::Observed,
        $made[TMC_SKU_GONE]
    );
    // …and then its product really goes, so the skip case is a real absence
    // rather than a number nobody wrote a row for.
    wp_delete_post($made[TMC_SKU_GONE], true);

    echo 'dokan_row=' . $dokanRow . "\n";
    echo 'ours_row=' . $oursRow . "\n";
    echo 'gone_row=' . $goneRow . "\n";
    echo 'dokan_post=' . $made[TMC_SKU_DOKAN] . "\n";
    echo 'dokan_post_status=' . (string) get_post_status($made[TMC_SKU_DOKAN]) . "\n";
    echo 'stamp_dokan=' . tmc_stamp(TMC_SKU_DOKAN) . "\n";
    echo 'stamp_ours=' . tmc_stamp(TMC_SKU_OURS) . "\n";
    echo 'stamp_gone=' . tmc_stamp(TMC_SKU_GONE) . "\n";
    exit(0);
}

if ($verb === 'unmigrate-38') {
    // Genuinely on 37: the stamps this step would write are cleared and the
    // recorded version walks back. Installing the older package does NOT
    // lower the structure (`alpha.89`), so without this the step reports
    // «already done» and nothing is measured.
    $wpdb->query(
        'UPDATE `' . tmc_products_table() . '` SET `' . tmc_stamp_column() . '` = NULL
          WHERE sku IN ("' . TMC_SKU_DOKAN . '", "' . TMC_SKU_GONE . '")'
    );
    update_option('tmc_schema_version', 37);
    delete_option('tmc_migration_last_error');
    echo 'schema_version=' . tmc_schema() . "\n";
    echo 'stamp_dokan=' . tmc_stamp(TMC_SKU_DOKAN) . "\n";
    echo 'stamp_gone=' . tmc_stamp(TMC_SKU_GONE) . "\n";
    exit(0);
}

if ($verb === 'break-column') {
    // ⛔ The injected failure, and it is the shape of a real one: the column
    // the step's `UPDATE` names is not there under that name, so the
    // statement is refused and `up()` throws. `CREATE TABLE IF NOT EXISTS`
    // cannot help a backfill — only the statement failing can
    // (`alpha.51`, from the other side).
    $wpdb->query(
        'ALTER TABLE `' . tmc_products_table() . '`
         CHANGE `' . tmc_stamp_column() . '` `mirrored_status_moved` VARCHAR(20) NULL DEFAULT NULL'
    );
    $has = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            tmc_products_table(),
            tmc_stamp_column()
        )
    );
    echo 'stamp_column_present=' . ($has === 1 ? 'yes' : 'no') . "\n";
    exit(0);
}

if ($verb === 'fix-column') {
    $wpdb->query(
        'ALTER TABLE `' . tmc_products_table() . '`
         CHANGE `mirrored_status_moved` `' . tmc_stamp_column() . '` VARCHAR(20) NULL DEFAULT NULL'
    );
    $has = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            tmc_products_table(),
            tmc_stamp_column()
        )
    );
    echo 'stamp_column_present=' . ($has === 1 ? 'yes' : 'no') . "\n";
    exit(0);
}

if ($verb === 'shop') {
    $wcId = tmc_wc_id(tmc_sku_of($which));
    echo 'wc_product_id=' . $wcId . "\n";
    if ($wcId <= 0 || get_post($wcId) === null) {
        echo "post_status=__absent__\n";
        echo "visibility=__absent__\n";
        echo "price=__absent__\n";
        echo "sku=__absent__\n";
        exit(0);
    }
    echo 'post_status=' . (string) get_post_status($wcId) . "\n";
    $visibility = get_post_meta($wcId, '_visibility', true);
    $terms = wp_get_object_terms($wcId, 'product_visibility', ['fields' => 'names']);
    echo 'visibility=' . ($visibility === '' ? implode(',', is_array($terms) ? $terms : []) : (string) $visibility) . "\n";
    echo 'price=' . (string) get_post_meta($wcId, '_regular_price', true) . "\n";
    echo 'sku=' . (string) get_post_meta($wcId, '_sku', true) . "\n";
    echo 'stock=' . (string) get_post_meta($wcId, '_stock', true) . "\n";
    exit(0);
}

if ($verb === 'take' || $verb === 'give-back') {
    $row = tmc_row_by_sku(tmc_sku_of($which));
    if ($row === null) {
        echo "code=__norow__\n";
        exit(0);
    }
    $service = Bootstrap::container()->get(TransferOwnership::class);
    // ⛔ EMPTY arrays, exactly as the batch passes them: the measurement is
    // about the guard, and a tick would be measuring the override.
    $result = $verb === 'take'
        ? $service->take((int) $row['id'], [], [])
        : $service->giveBack((int) $row['id']);
    echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
    echo 'code=' . $result->code . "\n";
    echo 'now=' . (string) ($result->context['now'] ?? '__none__') . "\n";
    echo 'would_write=' . (string) ($result->context['would_write'] ?? '__none__') . "\n";
    echo 'ownership=' . $products->linkOwnership((int) $row['id'])->value . "\n";
    exit(0);
}

if ($verb === 'reset') {
    foreach ([TMC_SKU_DOKAN, TMC_SKU_OURS, TMC_SKU_GONE] as $sku) {
        $row = tmc_row_by_sku($sku);
        if ($row === null) {
            continue;
        }
        // Give the ownership back first, so the post keeps nothing of ours.
        if ($products->linkOwnership((int) $row['id']) === LinkOwnership::Marketplace) {
            Bootstrap::container()->get(TransferOwnership::class)->giveBack((int) $row['id']);
        }
        $wcId = (int) ($row['wc_product_id'] ?? 0);
        if ($wcId > 0 && get_post($wcId) !== null) {
            wp_delete_post($wcId, true);
        }
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . tmc_products_table() . '` WHERE id = %d',
            (int) $row['id']
        ));
    }
    $wpdb->query($wpdb->prepare(
        'DELETE FROM `' . $wpdb->prefix . 'tmc_vendor_profiles` WHERE vendor_user_id = %d',
        TMC_VENDOR
    ));
    delete_option('tmc_migration_last_error');
    echo "reset=done\n";
    exit(0);
}

// -------------------------------------------------------------------- state
echo 'schema_version=' . tmc_schema() . "\n";
echo 'stamp_dokan=' . tmc_stamp(TMC_SKU_DOKAN) . "\n";
echo 'stamp_ours=' . tmc_stamp(TMC_SKU_OURS) . "\n";
echo 'stamp_gone=' . tmc_stamp(TMC_SKU_GONE) . "\n";
echo 'step38_known=' . (class_exists(
    'Tecteb\\Marketplace\\Modules\\Product\\Infrastructure\\Migrations\\M0038ObservedMirroredStatusBackfill'
) ? 'yes' : 'no') . "\n";
echo 'status_rule_known=' . (class_exists(
    'Tecteb\\Marketplace\\Modules\\Product\\Domain\\ProjectedStatus'
) ? 'yes' : 'no') . "\n";
echo 'snapshot_batch_known=' . (class_exists(
    'Tecteb\\Marketplace\\Modules\\Migration\\Application\\ApplyRefreshSnapshot'
) ? 'yes' : 'no') . "\n";
