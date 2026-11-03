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

// ⛔ NO `declare(strict_types=1)` HERE, and that is deliberate.
//
// `wp eval-file` runs the file through `eval()`, and inside eval'd code a
// `strict_types` declaration is ALWAYS illegal — «must be the very first
// statement in the script». Measured: with it, every verb fataled and the
// upgrade check reported forty-odd empty measurements as FAILures about a
// package that was fine. `tools/upgrade-alpha89-state.php` and
// `tools/upgrade-alpha88-state.php` — the two that actually ran — carry no
// such line either.

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
// ⛔ THE VERB COMES FROM `$args`, NOT `$argv`.
//
// `wp eval-file FILE a b` puts the extra arguments in `$args`; `$argv` is
// WP-CLI's OWN argv, so `$argv[1]` is the string `eval-file`. Measured: with
// `$argv[1]` every call fell through to the default state report, so
// `arrange` created nothing and `unmigrate-38` walked nothing back, and the
// upgrade check reported twenty-eight FAILures about a package that was
// fine. `tools/upgrade-alpha89-state.php:39` reads `$args[0]`, which is the
// contract that has worked for three rounds.
//
// And an unknown verb is LOUD. The silent default is exactly what hid the
// defect above: a tool that prints a plausible report whatever you ask it
// cannot be caught by reading its output.
$args = isset($args) && is_array($args) ? $args : [];
$verb = (string) ($args[0] ?? 'state');
$which = (string) ($args[1] ?? 'dokan');
const TMC_VERBS = [
    'state', 'arrange', 'unmigrate-38', 'break-column', 'fix-column',
    'shop', 'take', 'give-back', 'clear-stamp', 'reset',
];
if (!in_array($verb, TMC_VERBS, true)) {
    fwrite(STDERR, 'unknown verb: ' . $verb . "\n");
    echo "unknown_verb=1\n";
    exit(2);
}

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

/**
 * EVERY row carrying this SKU, oldest first.
 *
 * ⛔ There must only ever be one, and `tmc_row_by_sku()` refuses out loud
 * when there is not. Measured: three rows carried `UPG92-DOKAN` because
 * `arrange` made a new one on each call and nothing removed the old ones, and
 * `get_row()` answered about the FIRST — so §2 read `wc_product_id=77229`
 * about a product §1 had just replaced with 77238, and reported
 * `post_status=__absent__` for a published product that was right there. A
 * lookup that silently picks one of several is how a fixture answers a
 * different question than the one asked (`alpha.33`).
 *
 * @return list<array<string, mixed>>
 */
function tmc_rows_by_sku(string $sku): array
{
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM `' . tmc_products_table() . '` WHERE sku = %s ORDER BY id ASC',
            $sku
        ),
        ARRAY_A
    );
    return is_array($rows) ? $rows : [];
}

function tmc_row_by_sku(string $sku): ?array
{
    $rows = tmc_rows_by_sku($sku);
    if (count($rows) > 1) {
        fwrite(STDERR, sprintf(
            "%d rows carry the SKU %s; run `reset` first\n",
            count($rows),
            $sku
        ));
        echo 'duplicate_rows=' . $sku . ':' . count($rows) . "\n";
        exit(3);
    }
    return $rows === [] ? null : $rows[0];
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

    // ⛔ THROUGH WOOCOMMERCE'S OWN API, so the product and the row AGREE
    // from birth.
    //
    // The first draft used `wp_insert_post()` plus `_sku` and
    // `_regular_price`. Measured: that leaves `manage_stock = false` and
    // `catalog_visibility = ''`, which are TWO BLOCKING risks, so
    // `take($id, [], [])` answered `ownership_risks_unacknowledged` and §2,
    // §7 and §8d all measured that instead of the publish state they were
    // written for. The product correctly refused; the fixture was wrong. This
    // is `alpha.90`'s rule in a new place: a fixture built half through the
    // platform and half with raw writes is inconsistent from the moment it
    // exists.
    //
    // Everything the status question needs is set here and NOTHING else, so a
    // blocking risk appearing later is a real finding rather than fixture
    // noise — and `risks_dokan` below prints what the service actually sees,
    // because a fixture that claims to be consistent should be asked.
    // ⛔ IDEMPOTENT, because a verb that only works on a clean site is a
    // verb that fails exactly when you need it.
    //
    // `set_sku()` THROWS on a duplicate, and a run that died midway leaves the
    // first products behind — measured: 77223 and 77224 survived an `arrange`
    // that fataled on the third. So this sweeps its own three SKUs first, and
    // gives back what it has made if a later save fails. Only these three
    // SKUs, never a pattern.
    foreach ([TMC_SKU_DOKAN, TMC_SKU_OURS, TMC_SKU_GONE] as $sku) {
        $strayId = (int) wc_get_product_id_by_sku($sku);
        if ($strayId > 0) {
            wp_delete_post($strayId, true);
        }
        // ⛔ AND THE MARKETPLACE ROWS, because a second `arrange` added a
        // row and removed none — measured: three rows per SKU, and the probe
        // then answered about the OLDEST one.
        foreach (tmc_rows_by_sku($sku) as $stale) {
            $staleWc = (int) ($stale['wc_product_id'] ?? 0);
            if ($staleWc > 0 && get_post($staleWc) !== null) {
                wp_delete_post($staleWc, true);
            }
            $wpdb->query($wpdb->prepare(
                'DELETE FROM `' . tmc_products_table() . '` WHERE id = %d',
                (int) $stale['id']
            ));
        }
    }

    $made = [];
    foreach ([TMC_SKU_DOKAN, TMC_SKU_OURS, TMC_SKU_GONE] as $sku) {
        $product = new \WC_Product_Simple();
        $product->set_name('کالای ' . $sku);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_sku($sku);
        $product->set_regular_price('450000');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(3);
        $product->set_backorders('no');
        $product->set_sold_individually(false);
        try {
            $postId = $product->save();
        } catch (\Throwable $e) {
            foreach ($made as $alreadyMade) {
                wp_delete_post($alreadyMade, true);
            }
            echo 'error=' . $sku . ': ' . $e->getMessage() . "\n";
            exit(1);
        }
        if (!is_int($postId) || $postId <= 0) {
            foreach ($made as $alreadyMade) {
                wp_delete_post($alreadyMade, true);
            }
            echo 'error=the product for ' . $sku . " was not saved\n";
            exit(1);
        }
        wp_update_post(['ID' => $postId, 'post_author' => TMC_VENDOR]);
        $made[$sku] = $postId;
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
    // The fixture's own consistency, from the service that judges it: the
    // only thing standing between this row and a transfer must be the stamp.
    // ⛔ ASKED WITH `class_exists`, because this verb must answer on BOTH
    // packages. `PreservationRisk` is `alpha.92`'s, so on `alpha.91` the
    // honest answer is `absent` — and the first draft of these two lines
    // FATALED there, breaking the very rule this file's docblock states
    // (`alpha.32`).
    $reader = Bootstrap::container()->get(
        \Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface::class
    );
    $risks = $reader->preservationRisksFrom($reader->detail($made[TMC_SKU_DOKAN]));
    echo 'risks_dokan=' . ($risks === [] ? '__none__' : implode(',', array_keys($risks))) . "\n";
    $split = 'Tecteb\\Marketplace\\Modules\\Migration\\Domain\\PreservationRisk';
    if (!class_exists($split)) {
        echo "blocking_dokan=__absent__\n";
        exit(0);
    }
    $blocking = $split::blockingIn($risks);
    echo 'blocking_dokan=' . ($blocking === [] ? '__none__' : implode(',', $blocking)) . "\n";
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

if ($verb === 'clear-stamp') {
    // ⛔ THE ROW §8 NEEDS, AND THE REASON THE FIRST VERSION OF §8d MEASURED
    // THE WRONG THING. The cost of rolling back is «the status guard is
    // gone», and asking that question needs a row with NO stamp beside a
    // LIVE, PUBLISHED product. After the step there is no such row — that is
    // the point of the backfill — and the only unstamped rows left are the
    // marketplace-owned one and the one whose product was deleted. The second
    // of those is refused for a different reason entirely: a missing product
    // IS the blocking risk `product`, so `take` answers «no» on both builds
    // and the measurement says nothing about the guard.
    //
    // So the fixture makes one, on the dokan row whose product is alive, and
    // the schema version is left alone: this is «a row the backfill could not
    // reach», not «the step did not run».
    $wpdb->query(
        'UPDATE `' . tmc_products_table() . '` SET `' . tmc_stamp_column() . '` = NULL
          WHERE sku = "' . TMC_SKU_DOKAN . '"'
    );
    $row = tmc_row_by_sku(TMC_SKU_DOKAN);
    // Stated as a value rather than left blank: `check()` scores an empty
    // measurement as «nothing measured», which is the right default and the
    // reason an assertion about absence has to name the absence.
    echo 'schema_version=' . tmc_schema() . "\n";
    // ⛔ `tmc_stamp()` never returns an empty string: NULL comes back as the
    // sentinel `__null__`, and a missing row as `__norow__`. Comparing
    // against '' answered «no» about a stamp that WAS cleared, and the run
    // printed both lines side by side saying opposite things.
    echo 'stamp_cleared=' . (tmc_stamp(TMC_SKU_DOKAN) === '__null__' ? 'yes' : 'no') . "\n";
    echo 'stamp_dokan=' . tmc_stamp(TMC_SKU_DOKAN) . "\n";
    echo 'dokan_post_status=' . ($row === null
        ? '__no_row__'
        : (string) get_post_status((int) $row['wc_product_id'])) . "\n";
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
    // ⛔ `visible` IS THE ABSENCE OF THE EXCLUSION TERMS, so an empty read is
    // a real answer and has to be spelled. Printing a blank made §7d read
    // «<nothing measured>» once `check()` stopped scoring empty-against-empty
    // as a pass — the hardening asking, correctly, which emptiness this is.
    $names = is_array($terms) ? $terms : [];
    $exclusions = array_values(array_filter(
        $names,
        static fn (string $n): bool => str_starts_with($n, 'exclude-from-')
    ));
    echo 'visibility=' . ($visibility !== ''
        ? (string) $visibility
        : ($exclusions === [] ? 'visible' : implode(',', $exclusions))) . "\n";
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
    // ⛔ THE SERVICE ASKS FOR `tmc_review_vendor`, AND WP-CLI HAS NOBODY.
    //
    // Measured: without this the verb answered `code=forbidden`, and SIX
    // checks — §2b, §2c, §7a, §7b, §7d and §8d — reported that neither
    // package allows a transfer, when what they had actually measured was an
    // anonymous request. `tools/upgrade-alpha88-state.php:253` carries the
    // same comment and the same two lines; this round's tool had neither.
    //
    // The administrator is named rather than queried for by role, because
    // `get_users(['role' => …, 'number' => 1])` answers about whoever happens
    // to be first (`alpha.36`).
    $admin = get_user_by('login', 'tmcowner');
    if ($admin === false) {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => ['ID']]);
        $admin = $admins === [] ? false : get_user_by('id', (int) $admins[0]->ID);
    }
    if ($admin === false) {
        echo "ok=no\ncode=__no_admin__\nnow=__none__\nwould_write=__none__\n";
        echo 'ownership=' . $products->linkOwnership((int) $row['id'])->value . "\n";
        exit(0);
    }
    wp_set_current_user((int) $admin->ID);
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
    foreach (tmc_rows_by_sku($sku) as $row) {
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
    }
    // ⛔ AND THE PRODUCTS ARE SWEPT BY SKU, not only through the rows.
    //
    // `reset` reached each WooCommerce product through its marketplace row, so
    // a run that died after creating the product but before (or after losing)
    // the row left the SKU taken — and the next `arrange` fataled with
    // WooCommerce's «Invalid or duplicated SKU». Measured: that is exactly
    // what an aborted run left behind. A fixture has to give back everything
    // it spent, and «the row» includes the ones that no longer point at it
    // (`alpha.33`).
    foreach ([TMC_SKU_DOKAN, TMC_SKU_OURS, TMC_SKU_GONE] as $sku) {
        $strayId = function_exists('wc_get_product_id_by_sku')
            ? (int) wc_get_product_id_by_sku($sku)
            : 0;
        if ($strayId > 0) {
            wp_delete_post($strayId, true);
        }
    }

    // ⛔ THE COLUMN IS `user_id`, and the answer is READ.
    //
    // The first draft wrote `vendor_user_id`, which `tmc_vendor_profiles`
    // does not have (`M0002CreateVendorTables`), so MySQL refused the
    // statement — and this verb printed `reset=done` anyway, because nobody
    // read the return value. A fixture that cannot give back what it spent
    // makes the NEXT run answer a different question (`alpha.33`), and a
    // `done` that is not true is how that goes unnoticed (`alpha.31`).
    $profiles = $wpdb->query($wpdb->prepare(
        'DELETE FROM `' . $wpdb->prefix . 'tmc_vendor_profiles` WHERE user_id = %d',
        TMC_VENDOR
    ));
    delete_option('tmc_migration_last_error');
    if ($profiles === false) {
        fwrite(STDERR, "the vendor profile could not be deleted\n");
        echo "reset=profile_not_deleted\n";
        exit(1);
    }
    echo 'reset=done' . "\n";
    echo 'reset_profiles=' . (int) $profiles . "\n";
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
