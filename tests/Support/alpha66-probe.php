<?php

/**
 * MEASUREMENTS FOR THE `alpha.67` ROUND — loadable on BOTH trees.
 *
 * This file is run with `wp eval-file` against the DISPOSABLE install, on
 * `alpha.66`'s bytes first and on this checkout's second, so every sentence
 * below is a before/after pair rather than a reading of the new code.
 *
 * Everything this round added is asked for with `method_exists()` /
 * `defined()` / `class_exists()` before it is used, because a probe that
 * cannot LOAD on the baseline reports nothing about it — `alpha.32`'s trap,
 * and `alpha.55` paid for it a second time when an interface grew a method.
 *
 * Verbs:
 *   §1  status-arrange · status-judge · status-intake · status-accept <sfx>
 *       status-save <sfx> · status-project <sfx> · status-facets
 *       status-explicit <sfx> publish|draft · status-identity <sfx>
 *   §2  money-scale · money-example · money-line <base> <bp>
 *       money-fixed · money-order · money-ledger · money-balance
 *       trial-on · trial-off
 *       money-pay <whole> · money-void · money-return
 *   report · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;

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

/** This round's own namespace, so nothing collides with `alpha65-probe.php`. */
const TMC_P66_SLUG = 'tmc-p66';

$args = $args ?? [];
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

global $wpdb;
$db = new WpDatabase($wpdb);
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . ($value === '' || $value === null ? '__none__' : (string) $value) . "\n";
};
$productsTable = $db->prefix() . M0005CreateProductTables::PRODUCTS;

/** An EXISTING approved, sellable shop — borrowed, so `reset` owes nothing. */
$vendorId = static function () use ($db, $say): int {
    $id = (int) ($db->getVar(
        'SELECT v.user_id FROM `' . $db->prefix() . 'tmc_vendor_profiles` v
          INNER JOIN `' . $db->prefix() . 'users` u ON u.ID = v.user_id
          INNER JOIN `' . $db->prefix() . 'tmc_vendor_applications` a
                  ON a.user_id = v.user_id AND a.status = %s
          WHERE v.can_sell = 1 ORDER BY v.id DESC LIMIT 1',
        ['approved']
    ) ?? 0);
    if ($id === 0) {
        $say('no_sellable_vendor', 'yes');
    }
    return $id;
};

/** The statuses this round walks, and each carries a price so §1 is not confounded by §1-ب of `alpha.66`. */
$suffixes = ['draft', 'pending', 'publish', 'private', 'future'];

$makeProduct = static function (string $suffix, int $author) use ($say): int {
    $existing = get_page_by_path(TMC_P66_SLUG . '-' . $suffix, OBJECT, 'product');
    if ($existing instanceof \WP_Post) {
        return (int) $existing->ID;
    }
    $post = [
        'post_type' => 'product',
        'post_title' => 'محصول آزمون ۶۷ — ' . $suffix,
        'post_name' => TMC_P66_SLUG . '-' . $suffix,
        'post_status' => $suffix,
        'post_author' => $author,
        'post_content' => 'توضیح آزمون وضعیت',
    ];
    if ($suffix === 'future') {
        // A `future` row with a past date is normalised to `publish` by
        // WordPress, so without a date the fixture measures the wrong status.
        $when = gmdate('Y-m-d H:i:s', time() + 31536000);
        $post['post_date_gmt'] = $when;
        $post['post_date'] = $when;
    }
    $id = wp_insert_post($post, true);
    if (is_wp_error($id)) {
        $say('product_not_created_' . $suffix, $id->get_error_code());
        return 0;
    }
    $id = (int) $id;
    wp_set_object_terms($id, 'simple', 'product_type');
    update_post_meta($id, '_sku', 'TMCP66-' . strtoupper($suffix));
    update_post_meta($id, '_manage_stock', 'yes');
    update_post_meta($id, '_stock', '7');
    update_post_meta($id, '_stock_status', 'instock');
    update_post_meta($id, '_visibility', 'visible');
    update_post_meta($id, '_regular_price', '450000');
    update_post_meta($id, '_price', '450000');
    return $id;
};

$fixtureIds = static function () use ($suffixes): array {
    $out = [];
    foreach ($suffixes as $suffix) {
        $post = get_page_by_path(TMC_P66_SLUG . '-' . $suffix, OBJECT, 'product');
        $out[$suffix] = $post instanceof \WP_Post ? (int) $post->ID : 0;
    }
    return $out;
};

/** The marketplace row behind a shop product, or null. */
$rowFor = static function (int $wcId) use ($db, $productsTable): ?array {
    return $db->getRow(
        'SELECT * FROM `' . $productsTable . '` WHERE wc_product_id = %d ORDER BY id ASC LIMIT 1',
        [$wcId]
    );
};

/**
 * WHAT THE FILTERS SAY about one product — the `wc_status` facet value, read
 * from the same reader the admin page renders. §1 asks for the WooCommerce
 * status AND the status the filters show, so both are printed side by side.
 */
$facetFor = static function (int $wcId) use ($c): string {
    $reader = $c->get(CatalogueReaderInterface::class);
    // `unifiedPage()` takes (filters, LIMIT, OFFSET, sort) — not a page
    // number. Reading it as one made every lookup below answer
    // `__not_found__` about a product that was listed: a true sentence about
    // `limit = 1, offset = <page>`, and nothing about the catalogue.
    for ($offset = 0; $offset < 4000; $offset += 100) {
        $data = $reader->unifiedPage([], 100, $offset, 'id');
        $rows = (array) ($data['rows'] ?? []);
        if ($rows === []) {
            return '__not_listed__';
        }
        foreach ($rows as $row) {
            if ((int) ($row['wc_product_id'] ?? 0) === $wcId) {
                return (string) ($row['wc_status'] ?? '__no_key__')
                    . '|mgmt:' . (string) ($row['management'] ?? '__no_key__');
            }
        }
    }
    return '__not_found__';
};

$shopMeta = static function (int $wcId, string $key) use ($db): string {
    $rows = $db->getResults(
        'SELECT meta_value FROM `' . $db->prefix() . 'postmeta` WHERE post_id = %d AND meta_key = %s',
        [$wcId, $key]
    );
    if ($rows === []) {
        return '__absent__';
    }
    $value = (string) $rows[0]['meta_value'];
    return $value === '' ? '__empty__' : $value;
};

/** Identity: the four things §1-ج says an intake must not move. */
$identity = static function (int $wcId) use ($say, $shopMeta): void {
    $post = get_post($wcId);
    $say('id', $wcId);
    $say('url', $post instanceof \WP_Post ? (string) get_permalink($wcId) : '__gone__');
    $say('author', $post instanceof \WP_Post ? (int) $post->post_author : 0);
    $say('shop_status', $post instanceof \WP_Post ? (string) $post->post_status : '__gone__');
    $say('regular_price', $shopMeta($wcId, '_regular_price'));
    $say('price', $shopMeta($wcId, '_price'));
};

// =========================================================================
if ($verb === 'status-arrange') {
    $vendor = $vendorId();
    $say('vendor_user_id', $vendor);
    foreach ($suffixes as $suffix) {
        $id = $makeProduct($suffix, $vendor);
        $say('product_' . $suffix, $id);
        $say('shop_status_' . $suffix, $id === 0 ? '__none__' : (string) get_post_status($id));
    }
    return;
}

// =========================================================================
if ($verb === 'status-judge') {
    $intake = $c->get(ImportExistingProducts::class);
    foreach ($fixtureIds() as $suffix => $id) {
        if ($id === 0) {
            $say('judge_' . $suffix, '__absent__');
            continue;
        }
        $verdict = $intake->judge($id, 0);
        $say('judge_' . $suffix, (string) $verdict['verdict'] . ':' . (string) ($verdict['reason'] ?? ''));
    }
    // The map itself, straight from the domain — one line per status, so the
    // refusals are a measurement and not a reading of a docblock.
    foreach (['publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'bananas'] as $s) {
        $mapped = ProductStatus::forIntakeOf($s);
        $say('map_' . $s, $mapped === null ? '__refused__' : $mapped->value);
    }
    return;
}

// =========================================================================
if ($verb === 'status-intake') {
    $intake = $c->get(ImportExistingProducts::class);
    $ids = array_values(array_filter($fixtureIds()));
    $runId = $intake->newRunId();
    $say('run_id', $runId);
    $result = $intake->importBatch($runId, $ids, 0, 10);
    foreach ((array) ($result['results'] ?? []) as $row) {
        $wcId = (int) ($row['wc_product_id'] ?? 0);
        $say('intake_' . $wcId, (string) ($row['verdict'] ?? '') . ':' . (string) ($row['reason'] ?? ''));
    }
    foreach ($fixtureIds() as $suffix => $id) {
        if ($id === 0) {
            continue;
        }
        $row = $rowFor($id);
        $say('shop_status_' . $suffix, (string) get_post_status($id));
        $say('row_status_' . $suffix, $row === null ? '__none__' : (string) $row['status']);
        $say('facet_' . $suffix, $facetFor($id));
    }
    return;
}

// =========================================================================
if ($verb === 'status-accept') {
    $suffix = (string) ($args[1] ?? 'pending');
    $wcId = (int) ($fixtureIds()[$suffix] ?? 0);
    $say('suffix', $suffix);
    if ($wcId === 0) {
        $say('fixture', '__absent__');
        return;
    }
    $row = $rowFor($wcId);
    if ($row === null) {
        $say('row', '__none__');
        return;
    }
    $productId = (int) $row['id'];
    $say('product_id', $productId);
    $say('shop_status_before', (string) get_post_status($wcId));
    $say('row_status_before', (string) $row['status']);

    $transfer = $c->get(TransferOwnership::class);
    $risks = $transfer->risksFor($productId);
    $seen = [];
    foreach ($transfer->storefrontDrift($productId) as $field => $pair) {
        $seen[$field] = (string) $pair['fingerprint'];
    }
    $say('risks', implode(',', array_keys($risks)));
    $take = $transfer->take($productId, array_keys($risks), $seen);
    $say('take_code', $take->code);
    clean_post_cache($wcId);
    $after = $rowFor($wcId);
    $say('shop_status_after', (string) get_post_status($wcId));
    $say('row_status_after', $after === null ? '__none__' : (string) $after['status']);
    $say('facet_after', $facetFor($wcId));
    return;
}

// =========================================================================
if ($verb === 'status-save') {
    // AN ORDINARY SAVE — no status action anywhere in it. The vendor changes
    // the short description and nothing else, which is the exact case §1
    // names: «ذخیره عادی».
    $suffix = (string) ($args[1] ?? 'pending');
    $wcId = (int) ($fixtureIds()[$suffix] ?? 0);
    $say('suffix', $suffix);
    $row = $wcId === 0 ? null : $rowFor($wcId);
    if ($row === null) {
        $say('row', '__none__');
        return;
    }
    $productId = (int) $row['id'];
    $vendor = (int) $row['vendor_user_id'];
    $products = $c->get(ProductRepositoryInterface::class);
    $product = $products->find($productId);
    if ($product === null) {
        $say('product', '__none__');
        return;
    }
    $say('shop_status_before', (string) get_post_status($wcId));
    $say('row_status_before', (string) $row['status']);
    $details = $product->details;
    $changed = new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
        title: $details->title,
        type: $details->type,
        categoryKey: $details->categoryKey,
        brand: $details->brand,
        shortDescription: 'توضیح کوتاه عوض‌شده ' . $suffix,
        priceMinor: $details->priceMinor,
        salePriceMinor: $details->salePriceMinor,
        saleFrom: $details->saleFrom,
        saleTo: $details->saleTo,
        sku: $details->sku,
        stock: $details->stock,
        minPurchase: $details->minPurchase,
        maxPurchase: $details->maxPurchase,
        weightGrams: $details->weightGrams,
        dimensions: $details->dimensions,
        taxClass: $details->taxClass,
        description: $details->description,
    );
    $manage = $c->get(ManageProducts::class);
    // The VENDOR saves their own product. An administrator is not staff of
    // this shop, so `StaffAccess` answers `forbidden` — a correct sentence
    // about the wrong actor, and it would have measured nothing.
    // The row's CURRENT counter, which is what the form carries. Without it
    // the save answers `revision_missing` (`alpha.34`'s optimistic lock) and
    // nothing downstream of the save would run.
    $revision = (string) $products->rowVersion($productId);
    $say('revision', $revision);
    $saved = $manage->save($vendor, $vendor, $productId, $changed, [], [], 0, $revision);
    $say('save_ok', (bool) $saved->ok);
    $say('save_code', (string) $saved->code);
    clean_post_cache($wcId);
    $after = $rowFor($wcId);
    $say('shop_status_after', (string) get_post_status($wcId));
    $say('row_status_after', $after === null ? '__none__' : (string) $after['status']);
    $say('facet_after', $facetFor($wcId));
    return;
}

// =========================================================================
if ($verb === 'status-project') {
    // THE FIRST PROJECTION. `project()` writes the status unconditionally on
    // `alpha.66`, so this is the verb that shows whether `pending` survives
    // the path the owner walked.
    $suffix = (string) ($args[1] ?? 'pending');
    $wcId = (int) ($fixtureIds()[$suffix] ?? 0);
    $say('suffix', $suffix);
    $row = $wcId === 0 ? null : $rowFor($wcId);
    if ($row === null) {
        $say('row', '__none__');
        return;
    }
    $productId = (int) $row['id'];
    $say('product_id', $productId);
    $say('shop_status_before', (string) get_post_status($wcId));
    $say('row_status_before', (string) $row['status']);
    $say('visibility_before', $shopMeta($wcId, '_visibility'));
    $say('catalog_visibility_before', implode(',', wp_get_object_terms($wcId, 'product_visibility', ['fields' => 'names']) ?: []));

    $sync = $c->get(SyncCatalog::class);
    $projected = $sync->publish($productId);
    $say('project_ok', (bool) ($projected->ok ?? false));
    $say('project_code', (string) ($projected->code ?? ''));
    clean_post_cache($wcId);
    $say('shop_status_after', (string) get_post_status($wcId));
    $say('facet_after', $facetFor($wcId));
    $say('catalog_visibility_after', implode(',', wp_get_object_terms($wcId, 'product_visibility', ['fields' => 'names']) ?: []));
    // What this round adds, asked for rather than assumed.
    $held = '__no_such_key__';
    if (defined('\\Tecteb\\Marketplace\\Modules\\Product\\Infrastructure\\WooCommerce\\WooCommerceProjector::STATUS_HELD_META')) {
        $key = \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector::STATUS_HELD_META;
        $held = $shopMeta($wcId, $key);
    }
    $say('status_held_meta', $held);
    $say('project_status_held', array_key_exists('status_held', $projected->context) ? ($projected->context['status_held'] ? 'yes' : 'no') : '__no_key__');
    $identity($wcId);
    return;
}

// =========================================================================
if ($verb === 'status-explicit') {
    // THE CONTROL §1 asks for: the draft and publish controls must still
    // work. An explicit status action is a decision, and a decision writes.
    $suffix = (string) ($args[1] ?? 'pending');
    $want = (string) ($args[2] ?? 'publish');
    $wcId = (int) ($fixtureIds()[$suffix] ?? 0);
    $say('suffix', $suffix);
    $say('wanted', $want);
    $row = $wcId === 0 ? null : $rowFor($wcId);
    if ($row === null) {
        $say('row', '__none__');
        return;
    }
    $productId = (int) $row['id'];
    $say('shop_status_before', (string) get_post_status($wcId));
    $target = $want === 'publish' ? ProductStatus::Published : ProductStatus::Draft;
    // THROUGH `updateStatus()`, which is the chokepoint every real status
    // decision goes through — a raw `UPDATE` here measured the wrong thing
    // entirely: it moved the row and left the hold, so the projection that
    // followed held a status a decision had just been made about, and the
    // control reported a defect about code that was working.
    $products = $c->get(ProductRepositoryInterface::class);
    $say('row_status_set', $products->updateStatus($productId, $target) ? $target->value : '__failed__');
    $say('mirrored_after_decision', (string) ($db->getVar(
        'SELECT COALESCE(mirrored_status, %s) FROM `' . $productsTable . '` WHERE id = %d',
        ['__null__', $productId]
    ) ?? '__unreadable__'));
    $r = $c->get(SyncCatalog::class)->publish($productId);
    $say('explicit_code', (string) ($r->code ?? ''));
    clean_post_cache($wcId);
    $say('shop_status_after', (string) get_post_status($wcId));
    $say('facet_after', $facetFor($wcId));
    return;
}

// =========================================================================
if ($verb === 'status-facets') {
    $reader = $c->get(CatalogueReaderInterface::class);
    $page = $reader->unifiedPage([], 1, 0, 'updated');
    $say('unified_total', (int) ($page['total'] ?? -1));
    foreach ((array) ($page['facets']['wc_status'] ?? []) as $status => $n) {
        $say('facet_wc_status_' . (string) $status, (int) $n);
    }
    foreach ((array) ($page['facets']['management'] ?? []) as $m => $n) {
        $say('facet_management_' . (string) $m, (int) $n);
    }
    return;
}

// =========================================================================
if ($verb === 'status-identity') {
    $suffix = (string) ($args[1] ?? 'pending');
    $wcId = (int) ($fixtureIds()[$suffix] ?? 0);
    $say('suffix', $suffix);
    if ($wcId === 0) {
        $say('fixture', '__absent__');
        return;
    }
    $identity($wcId);
    $row = $rowFor($wcId);
    $say('row_status', $row === null ? '__none__' : (string) $row['status']);
    $say('row_ownership', $row === null ? '__none__' : (string) $row['link_ownership']);
    $say('facet', $facetFor($wcId));
    return;
}

// =========================================================================
if ($verb === 'report') {
    foreach ($fixtureIds() as $suffix => $id) {
        $say('product_' . $suffix, $id);
        if ($id === 0) {
            continue;
        }
        $row = $rowFor($id);
        $say('shop_status_' . $suffix, (string) get_post_status($id));
        $say('row_status_' . $suffix, $row === null ? '__none__' : (string) $row['status']);
    }
    return;
}

// =========================================================================
if ($verb === 'reset') {
    // GIVES BACK EVERYTHING THIS FILE SPENT. The vendor is borrowed, so it is
    // not touched; the products are this fixture's own and go, rows first.
    foreach ($fixtureIds() as $suffix => $id) {
        if ($id === 0) {
            continue;
        }
        $db->execute('DELETE FROM `' . $productsTable . '` WHERE wc_product_id = %d', [$id]);
        wp_delete_post($id, true);
        $say('deleted_' . $suffix, $id);
    }
    // No run table is swept: there is none. `import_run_id` is a COLUMN on
    // the product row (`M0016VendorImportRun`), so deleting the rows above
    // already took the stamps with them — and a `DELETE` against a table
    // that does not exist answers a database error, not «nothing to do».
    $say('reset', 'done');
    return;
}

// =========================================================================
// §2 — THE EXACT COMMISSION
// =========================================================================

/** The §2 fixture's own product, so the §1 statuses are not disturbed. */
const TMC_P66_MONEY = 'tmc-p66-money';

$moneyProduct = static function (int $author, string $price) use ($say): int {
    $existing = get_page_by_path(TMC_P66_MONEY, OBJECT, 'product');
    if ($existing instanceof \WP_Post) {
        return (int) $existing->ID;
    }
    $id = wp_insert_post([
        'post_type' => 'product',
        'post_title' => 'محصول آزمون دقت کمیسیون',
        'post_name' => TMC_P66_MONEY,
        'post_status' => 'publish',
        'post_author' => $author,
    ], true);
    if (is_wp_error($id)) {
        $say('money_product_not_created', $id->get_error_code());
        return 0;
    }
    $id = (int) $id;
    wp_set_object_terms($id, 'simple', 'product_type');
    update_post_meta($id, '_sku', 'TMCP66-MONEY');
    update_post_meta($id, '_manage_stock', 'yes');
    update_post_meta($id, '_stock', '50');
    update_post_meta($id, '_stock_status', 'instock');
    update_post_meta($id, '_regular_price', $price);
    update_post_meta($id, '_price', $price);
    return $id;
};

// =========================================================================
if ($verb === 'money-scale') {
    // PURE. Prints the derivation, so «four places» is read off the rate's
    // own denominator rather than taken on trust.
    if (!class_exists(\Tecteb\Marketplace\Modules\Finance\Domain\CommissionPrecision::class)) {
        $say('commission_precision_class', '__absent__');
        return;
    }
    $p = \Tecteb\Marketplace\Modules\Finance\Domain\CommissionPrecision::class;
    $say('rate_denominator', \Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate::MAX_BP);
    $say('extra_scale', $p::EXTRA_SCALE);
    $say('derivation_matches_denominator', (bool) $p::matchesRateDenominator());
    foreach ([0, 1, 2, 3, 6] as $e) {
        $answer = $p::exponentFor($e);
        $say('exponent_for_' . $e, $answer === null ? '__refused__' : $answer);
    }
    $say('rounding_policy', \Tecteb\Marketplace\Modules\Finance\Domain\RoundingPolicy::describe());
    return;
}

// =========================================================================
if ($verb === 'money-example') {
    // THE OWNER'S WORKED EXAMPLE, through the calculator the panel uses.
    if (!class_exists(\Tecteb\Marketplace\Modules\Finance\Domain\CommissionPrecision::class)) {
        $say('exact_commission', '__absent__');
        return;
    }
    $calc = new \Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator();
    foreach ([
        '101@12.5%' => [101, '۱۲٫۵'],
        '1000@12.5%' => [1000, '12.5'],
        '303@12.5%' => [303, '12.5'],
        '250@12.5%' => [250, '12.5'],
    ] as $label => [$base, $rate]) {
        $rule = \Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission::fromInput('percent', $rate);
        if (($rule['rule'] ?? null) === null) {
            $say('rule_' . $label, 'refused:' . (string) ($rule['reason'] ?? ''));
            continue;
        }
        $outcome = $calc->calculate(
            \Tecteb\Marketplace\Modules\Finance\Domain\Money::of($base, 'IRT', 0),
            1,
            $rule['rule'],
            'product',
            0
        );
        if (!$outcome->isCalculated()) {
            $say('outcome_' . $label, 'refused:' . (string) $outcome->reason);
            continue;
        }
        $say('exponent_' . $label, (int) $outcome->commission->exponent);
        $say('commission_' . $label, (string) $outcome->commission->toDecimal());
        $say('share_' . $label, (string) $outcome->vendorShare->toDecimal());
        $say('sums_exactly_' . $label, (bool) (
            $outcome->commission->minor + $outcome->vendorShare->minor === $outcome->base->minor
        ));
    }
    return;
}

// =========================================================================
// =========================================================================
if ($verb === 'trial-on' || $verb === 'trial-off') {
    // THE OPERATIONAL KEY, PUT IN A KNOWN STATE — through the plugin's own
    // service, never an option write. `request()` only asks; `isPermitted()`
    // still decides, so this verb cannot open anything on a site that
    // resolves to production, and the caller has to declare the environment
    // with `--exec='define("WP_ENVIRONMENT_TYPE","local");'`.
    //
    // ⛔ It exists because of `alpha.66`'s own rule, which this script broke
    // on its first run: a key a previous manual run left ON answers the NEXT
    // measurement about somebody else's site, and a key left OFF — which is
    // what happened here — makes the gate `decisions_open` on BOTH trees,
    // so thirteen checks reported a defect about a build that worked.
    $trial = $c->get(\Tecteb\Marketplace\Modules\Order\Application\TrialUnlock::class);
    $trial->request($verb === 'trial-on');
    $say('requested', (bool) $trial->isRequested());
    $say('permitted', (bool) $trial->isPermitted());
    $say('active', (bool) $trial->isActive());
    $say('environment', (string) $trial->environmentName());
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) $answer['ready']);
    $say('gate_reason', (string) $answer['reason']);
    return;
}

if ($verb === 'money-order') {
    // ONE REAL WOOCOMMERCE ORDER, NO GATEWAY, with a commission that cannot
    // be whole: three units of ۱۰۱ تومان at ۱۲٫۵٪ owe ۳۷٫۸۷۵.
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) $answer['ready']);
    $say('gate_reason', (string) $answer['reason']);
    if (($answer['ready'] ?? false) !== true) {
        $say('order_created', 'no');
        return;
    }
    $vendor = $vendorId();
    $wcId = $moneyProduct($vendor, '101');
    if ($wcId === 0 || !function_exists('wc_create_order')) {
        $say('fixture', '__absent__');
        return;
    }
    update_post_meta($wcId, '_tmc_commission_type', 'percent');
    update_post_meta($wcId, '_tmc_commission_value', '12.50');
    // The marketplace row, so the capture recognises the line as ours.
    $intake = $c->get(ImportExistingProducts::class);
    $runId = $intake->newRunId();
    $intake->importBatch($runId, [$wcId], 0, 5);
    $row = $rowFor($wcId);
    if ($row !== null) {
        $transfer = $c->get(TransferOwnership::class);
        $seen = [];
        foreach ($transfer->storefrontDrift((int) $row['id']) as $field => $pair) {
            $seen[$field] = (string) $pair['fingerprint'];
        }
        $say('take_code', $transfer->take(
            (int) $row['id'],
            array_keys($transfer->risksFor((int) $row['id'])),
            $seen
        )->code);
        $products = $c->get(ProductRepositoryInterface::class);
        $products->updateStatus((int) $row['id'], ProductStatus::Published);
    }
    $say('marketplace_row', $row === null ? '__none__' : (int) $row['id']);

    $product = wc_get_product($wcId);
    $order = wc_create_order(['customer_id' => $adminId]);
    if (is_wp_error($order)) {
        $say('order_not_created', $order->get_error_code());
        return;
    }
    $order->add_product($product, 3);
    $order->calculate_totals();
    $order->save();
    $orderId = (int) $order->get_id();
    $say('order_id', $orderId);
    $say('order_total', (string) $order->get_total());
    $say('order_currency', (string) $order->get_currency());
    $say('wc_price_decimals', function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : '__unknown__');
    $order->payment_complete();
    do_action('woocommerce_checkout_order_processed', $orderId, [], $order);

    $items = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class);
    foreach ($items->forOrder($orderId) as $line) {
        $say('line_base_minor', $line->baseMinor);
        $say('line_commission_minor', (string) ($line->commissionMinor ?? '__none__'));
        $say('line_share_minor', (string) ($line->vendorShareMinor ?? '__none__'));
        $say('line_rate_bp', (string) ($line->rateBasisPoints ?? '__none__'));
        $say('line_rate_source', $line->rateSource);
        // WHAT THIS ROUND ADDS, asked for rather than assumed.
        $say('line_currency', (string) ($line->currency ?? '__none__'));
        $say('line_exponent', $line->exponent === null ? '__none__' : $line->exponent);
        if ($line->exponent !== null && class_exists(\Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText::class)) {
            $t = \Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText::class;
            $say('line_commission_text', (string) $t::format((int) $line->commissionMinor, $line->exponent));
            $say('line_share_text', (string) $t::format((int) $line->vendorShareMinor, $line->exponent));
            $say('line_base_text', (string) $t::format($line->baseMinor, $line->exponent));
        }
        $say('line_sums_exactly', (bool) (
            (int) $line->commissionMinor + (int) $line->vendorShareMinor === $line->baseMinor
        ));
    }
    return;
}

// =========================================================================
if ($verb === 'money-ledger') {
    // THE LEDGER ROWS AND THEIR UNITS, and whether the event balances.
    $vendor = $vendorId();
    $rows = $db->getResults(
        'SELECT event_key, account, amount_minor, currency, exponent
           FROM `' . $db->prefix() . 'tmc_ledger_entries`
          WHERE vendor_user_id = %d ORDER BY id DESC LIMIT 12',
        [$vendor]
    );
    $byEvent = [];
    foreach ($rows as $r) {
        $say('row_' . (string) $r['account'], (string) $r['amount_minor']
            . ' ' . (string) $r['currency'] . '/1e' . (string) $r['exponent']);
        $byEvent[(string) $r['event_key']][] = $r;
    }
    foreach ($byEvent as $event => $lines) {
        $sum = 0;
        $exponents = [];
        foreach ($lines as $l) {
            $sum += (int) $l['amount_minor'];
            $exponents[(string) $l['exponent']] = true;
        }
        $say('event_lines', count($lines));
        $say('event_zero_sum', (bool) ($sum === 0));
        $say('event_exponents', implode(',', array_keys($exponents)));
        break;      // the newest event is the one this round made
    }
    $say('ledger_exponents_for_vendor', implode(',', array_map(
        static fn (array $r): string => (string) $r['exponent'],
        $db->getResults(
            'SELECT DISTINCT exponent FROM `' . $db->prefix() . 'tmc_ledger_entries`
              WHERE vendor_user_id = %d ORDER BY exponent',
            [$vendor]
        )
    )));
    return;
}

// =========================================================================
if ($verb === 'money-balance') {
    // THE BALANCE, AND THE UNIT IT IS IN. On `alpha.66` there is no unit in
    // the answer at all, which is what `__no_key__` says.
    $vendor = $vendorId();
    $balance = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorBalance::class)->of($vendor);
    foreach (['earned', 'pending', 'eligible', 'reserved', 'paid', 'unrecorded'] as $key) {
        $say('balance_' . $key, (string) ($balance[$key] ?? '__no_key__'));
    }
    $say('balance_currency', (string) ($balance['currency'] ?? '__no_key__'));
    $say('balance_exponent', array_key_exists('exponent', $balance) ? (int) $balance['exponent'] : '__no_key__');
    $say('balance_unit_unreadable', array_key_exists('unit_unreadable', $balance)
        ? ($balance['unit_unreadable'] ? 'yes' : 'no')
        : '__no_key__');
    if (array_key_exists('exponent', $balance)
        && class_exists(\Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText::class)) {
        $t = \Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText::class;
        $say('earned_text', (string) $t::format((int) $balance['earned'], (int) $balance['exponent']));
    }
    // AND A LEGACY ROW BESIDE A NEW ONE. A line with no unit is at the
    // vendor's first-recorded one, so the two must add up exactly rather
    // than one of them being wrong by ten thousand.
    $legacy = $db->getVar(
        'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_order_items`
          WHERE vendor_user_id = %d AND (`tmc_exponent` IS NULL)',
        [$vendor]
    );
    $say('lines_without_a_unit', $legacy === null ? '__unreadable__' : (int) $legacy);
    $withUnit = $db->getVar(
        'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_order_items`
          WHERE vendor_user_id = %d AND `tmc_exponent` IS NOT NULL',
        [$vendor]
    );
    $say('lines_with_a_unit', $withUnit === null ? '__unreadable__' : (int) $withUnit);
    return;
}

// =========================================================================
if ($verb === 'money-legacy') {
    // A LINE IN THE OLD SHAPE, written by hand, beside the new one — the
    // «historical data is not reinterpreted» measurement. The row holds
    // ۱۰۰٬۰۰۰ تومان with NO unit, which is exactly what `alpha.66` wrote.
    //
    // ⛔ THE BALANCE IS READ BEFORE AND AFTER, and the claim is the DELTA.
    // This shop is a BORROWED one that already carries six unitless lines
    // from earlier rounds' fixtures, so «the total is exactly this» and «one
    // line has no unit» were both assertions about somebody else's data —
    // they failed on a tree that was completely right. What this fixture owns
    // is the ONE row it writes, so what it may assert is the one row's effect
    // (`alpha.34`: measure the baseline, do not assume it).
    $vendor = $vendorId();
    $balanceOf = static function () use ($c, $vendor): array {
        return $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorBalance::class)->of($vendor);
    };
    $before = $balanceOf();
    $table = $db->prefix() . 'tmc_order_items';
    $countUnitless = static function () use ($db, $vendor): ?int {
        $n = $db->getVar(
            'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_order_items`
              WHERE vendor_user_id = %d AND `tmc_exponent` IS NULL',
            [$vendor]
        );
        return $n === null ? null : (int) $n;
    };
    $unitlessBefore = $countUnitless();
    $db->execute(
        'INSERT INTO `' . $table . '`
         (wc_order_id, wc_order_item_id, wc_product_id, product_id, vendor_user_id, title, sku,
          quantity, unit_price_minor, base_minor, tax_minor, commission_minor, vendor_share_minor,
          rate_bp, rate_source, ledger_event, status, created_at, updated_at)
         VALUES (%d, %d, 0, 0, %d, %s, %s, 1, 100000, 100000, 0, 10000, 90000, 1000, %s, %s, %s, %s, %s)',
        [
            990066,
            990066,
            $vendor,
            'قلم قدیمی بدون واحد',
            'TMCP66-LEGACY',
            'general',
            'tmc-p66-legacy-event',
            'placed',
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'),
        ]
    );
    $row = $db->getRow(
        'SELECT id, base_minor, vendor_share_minor, `tmc_exponent` AS e
           FROM `' . $table . '` WHERE wc_order_item_id = %d',
        [990066]
    );
    $say('legacy_row', $row === null ? '__none__' : (int) $row['id']);
    $say('legacy_share_minor', $row === null ? '__none__' : (string) $row['vendor_share_minor']);
    $say('legacy_exponent', $row === null || $row['e'] === null ? '__none__' : (int) $row['e']);
    $after = $balanceOf();
    $unitlessAfter = $countUnitless();
    $say('earned_before', (string) ($before['earned'] ?? '__no_key__'));
    $say('earned_after', (string) ($after['earned'] ?? '__no_key__'));
    $say('earned_delta', (string) ((int) ($after['earned'] ?? 0) - (int) ($before['earned'] ?? 0)));
    $say('exponent_before', array_key_exists('exponent', $before) ? (int) $before['exponent'] : '__no_key__');
    $say('exponent_after', array_key_exists('exponent', $after) ? (int) $after['exponent'] : '__no_key__');
    $say('unitless_lines_added', $unitlessBefore === null || $unitlessAfter === null
        ? '__unreadable__'
        : (string) ($unitlessAfter - $unitlessBefore));
    return;
}

// =========================================================================
if ($verb === 'money-reset') {
    // ⛔ THE LEDGER ROWS GO TOO, and leaving them out cost a whole run. This
    // fixture's capture writes ledger entries at the FINER exponent, and
    // `MoneyUnitClaim` on `alpha.66`'s bytes counts distinct
    // `(currency, exponent)` — so one exponent-4 row left behind made every
    // later capture on that shop `books_mixed`, and the BEFORE half of the
    // next run reported «no line was captured» as a defect in a build that
    // worked. A fixture that spends something has to give it back
    // (`alpha.33`), and what this one spends includes the shop's single
    // money unit.
    //
    // The WooCommerce orders themselves are LEFT, deliberately: they are
    // ordinary paid orders on a disposable site, nothing reads them once
    // their marketplace lines and ledger rows are gone, and deleting a paid
    // order would move stock.
    $vendor = $vendorId();
    $itemsTable = $db->prefix() . 'tmc_order_items';
    $ledgerTable = $db->prefix() . 'tmc_ledger_entries';
    $forget = static function (array $where, array $params) use ($db, $itemsTable, $ledgerTable, $say): void {
        $events = $db->getResults(
            'SELECT DISTINCT ledger_event FROM `' . $itemsTable . '` WHERE ' . $where[0],
            $params
        );
        foreach ($events as $row) {
            $key = (string) ($row['ledger_event'] ?? '');
            if ($key === '') {
                continue;
            }
            $db->execute('DELETE FROM `' . $ledgerTable . '` WHERE event_key = %s', [$key]);
        }
        $db->execute('DELETE FROM `' . $itemsTable . '` WHERE ' . $where[0], $params);
    };
    $forget(['wc_order_item_id = %d'], [990066]);
    $post = get_page_by_path(TMC_P66_MONEY, OBJECT, 'product');
    if ($post instanceof \WP_Post) {
        $db->execute(
            'DELETE FROM `' . $productsTable . '` WHERE wc_product_id = %d',
            [(int) $post->ID]
        );
        $forget(['wc_product_id = %d'], [(int) $post->ID]);
        wp_delete_post((int) $post->ID, true);
        $say('money_product_deleted', 'yes');
    }
    // And the answer this round's own guard depends on: after a reset the
    // shop's books must hold ONE unit again, or the next run measures the
    // leftovers. Printed, so a failure to clean up is visible rather than
    // inferred three sections later.
    $units = $db->getResults(
        'SELECT DISTINCT currency, exponent FROM `' . $ledgerTable . '` WHERE vendor_user_id = %d',
        [$vendor]
    );
    $say('units_left', implode(',', array_map(
        static fn (array $r): string => (string) $r['currency'] . '/' . (string) $r['exponent'],
        $units
    )));
    $say('money_reset', 'done');
    return;
}

$say('unknown_verb', $verb);
