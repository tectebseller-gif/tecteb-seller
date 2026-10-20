<?php
/**
 * `alpha.65`: the three things the owner's manual staging test ran into.
 *
 * **Its own file, named for its round** (`alpha.54`, guarded by
 * `EvidenceToolsAreNotOverwrittenTest`). Everything this round adds is
 * reached through `class_exists()` / `method_exists()`, so the file LOADS on
 * the `alpha.65` tree as well as on a fixed one (`alpha.32`) — which is the
 * whole point: these verbs are meant to answer DIFFERENTLY.
 *
 * ### §1 — the catalogue, and a product with no price
 *
 * `catalogue-decompose` is READ-ONLY and prints the arithmetic rather than a
 * total: `post_type='product'` by `post_status`, the union's own total, and
 * the ids of the rows that come from branch B (a marketplace row with no
 * WooCommerce product behind it). The owner's 193 against WooCommerce's 188
 * has to be explained by a decomposition, not by making two numbers equal
 * («عددها را برای برابرشدن دستکاری نکن»).
 *
 * `price-*` builds three products with NO price meta at all — one `draft`,
 * one `pending`, one `publish` — because that is the state the owner's 34
 * conflicts are about, and because `pending` is the status
 * `WpDokanReader::productsAfter()` never reads.
 *
 * ### §2 — Persian digits in the commission box
 *
 * `commission-input` asks the domain the same question nine ways. It is pure,
 * so it answers on any tree, and the panel's own hint uses the spelling that
 * is refused.
 *
 * ### §3 — the gate, and what it actually depends on
 *
 * `gate-chain` prints every condition the gate evaluates, with the general
 * commission rate unset and then set, so «is the general rate still an
 * operative condition?» is answered by a measurement rather than by reading.
 *
 * Prints ONE key per line (`alpha.41`), and `reset` gives back what it
 * spends (`alpha.33`).
 *
 *   wp eval-file tests/Support/alpha65-probe.php <verb> [args]
 *     catalogue-decompose
 *     price-arrange · price-judge · price-intake · price-project
 *     dokan-arrange · dokan-plan
 *     commission-input · commission-save
 *     priceless-report
 *     gate-chain · gate-screen · estimate-share · trial-on · trial-off
 *     trial-order
 *     report · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
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

/** The fixture's own namespace, so nothing here collides with another round. */
const TMC_P65_SLUG = 'tmc-p65';
const TMC_P65_SELLER_LOGIN = 'tmc_p65_seller';

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

/**
 * The fixture's vendor — an EXISTING approved shop of this disposable site.
 *
 * Borrowed rather than created, and that is the `alpha.33` rule rather than
 * laziness: a fixture that makes a vendor has to unmake one, and unmaking a
 * shop takes its products, its settlement rows and its audit trail with it.
 * This verb spends nothing, so `reset` has nothing to give back.
 *
 * `can_sell = 1` and not the paper status, because «تأییدشده» is paperwork
 * and `can_sell` is the operational key (`alpha.17`); a shop that is approved
 * and cannot sell would make every intake measurement below about the wrong
 * thing.
 */
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

/**
 * One WooCommerce product with the EXACT price state asked for.
 *
 * `$price === null` means NO `_price`/`_regular_price` meta at all — not an
 * empty string and not nought. That distinction is the whole of §1-ب, so the
 * fixture has to be able to express it.
 */
$makeProduct = static function (string $suffix, string $status, ?string $price, int $author) use ($say): int {
    $existing = get_page_by_path(TMC_P65_SLUG . '-' . $suffix, OBJECT, 'product');
    if ($existing instanceof \WP_Post) {
        return (int) $existing->ID;
    }
    $post = [
        'post_type' => 'product',
        'post_title' => 'محصول آزمون ۶۵ — ' . $suffix,
        'post_name' => TMC_P65_SLUG . '-' . $suffix,
        'post_status' => $status,
        'post_author' => $author,
        'post_content' => 'توضیح آزمون',
    ];
    if ($status === 'future') {
        // WordPress normalises `future` with a past date straight to
        // `publish`, so a fixture that does not carry a date measures the
        // wrong status entirely — and would report that `future` is accepted.
        $when = gmdate('Y-m-d H:i:s', time() + 31536000);
        $post['post_date_gmt'] = $when;
        $post['post_date'] = $when;
    }
    $id = wp_insert_post($post, true);
    if (is_wp_error($id)) {
        $say('product_not_created', $id->get_error_code());
        return 0;
    }
    $id = (int) $id;
    wp_set_object_terms($id, 'simple', 'product_type');
    update_post_meta($id, '_sku', strtoupper(str_replace('-', '', TMC_P65_SLUG)) . '-' . strtoupper($suffix));
    update_post_meta($id, '_manage_stock', 'yes');
    update_post_meta($id, '_stock', '5');
    update_post_meta($id, '_stock_status', 'instock');
    update_post_meta($id, '_visibility', 'visible');
    if ($price !== null) {
        update_post_meta($id, '_regular_price', $price);
        update_post_meta($id, '_price', $price);
    }
    return $id;
};

/** What the SHOP holds for a price key — absence told apart from emptiness. */
$shopMeta = static function (int $wcId, string $key) use ($db): string {
    $rows = $db->getResults(
        'SELECT meta_value FROM `' . $db->prefix() . 'postmeta`
          WHERE post_id = %d AND meta_key = %s',
        [$wcId, $key]
    );
    if ($rows === []) {
        return '__absent__';
    }
    $value = (string) $rows[0]['meta_value'];
    return $value === '' ? '__empty__' : $value;
};

/** The three fixture products of this round, by suffix. */
$suffixes = ['draft-nopr', 'pending-nopr', 'publish-nopr', 'publish-priced', 'private-priced', 'future-priced'];
$fixtureIds = static function () use ($suffixes): array {
    $out = [];
    foreach ($suffixes as $suffix) {
        $post = get_page_by_path(TMC_P65_SLUG . '-' . $suffix, OBJECT, 'product');
        $out[$suffix] = $post instanceof \WP_Post ? (int) $post->ID : 0;
    }
    return $out;
};

// =========================================================================
if ($verb === 'catalogue-decompose') {
    // READ-ONLY. Prints the arithmetic of the unified list so the gap between
    // it and WooCommerce's own screen can be explained rather than closed.
    $rows = $db->getResults(
        'SELECT post_status, COUNT(*) AS n FROM `' . $db->prefix() . 'posts`
          WHERE post_type = %s GROUP BY post_status ORDER BY post_status',
        ['product']
    );
    $allPosts = 0;
    $wcListed = 0;
    foreach ($rows as $row) {
        $status = (string) $row['post_status'];
        $n = (int) $row['n'];
        $allPosts += $n;
        // The statuses WooCommerce's own «All» tab counts. `auto-draft` and
        // `trash` are posts that exist and that screen does not show.
        if (!in_array($status, ['auto-draft', 'trash'], true)) {
            $wcListed += $n;
        }
        $say('posts_' . $status, $n);
    }
    $say('posts_total', $allPosts);
    $say('posts_in_wc_all_tab', $wcListed);
    $say('posts_hidden_from_wc_all_tab', $allPosts - $wcListed);

    // Branch B: a marketplace row with NO product post behind it.
    $orphans = $db->getResults(
        'SELECT r.id, r.status, r.wc_product_id FROM `' . $productsTable . '` r
          WHERE NOT EXISTS (SELECT 1 FROM `' . $db->prefix() . 'posts` p2
                             WHERE p2.ID = r.wc_product_id AND p2.post_type = %s)
          ORDER BY r.id ASC',
        ['product']
    );
    $say('branch_b_rows', count($orphans));
    $say('branch_b_ids', implode(',', array_map(static fn (array $r): string => (string) $r['id'], $orphans)));
    $say('branch_b_statuses', implode(',', array_map(static fn (array $r): string => (string) $r['status'], $orphans)));
    $say('marketplace_rows_total', (int) ($db->getVar('SELECT COUNT(*) FROM `' . $productsTable . '`') ?? 0));

    $reader = $c->get(CatalogueReaderInterface::class);
    $page = $reader->unifiedPage([], 1, 0, 'updated');
    $say('unified_total', (int) ($page['total'] ?? -1));
    $say('unified_equals_posts_plus_branch_b', (int) ($page['total'] ?? -1) === $allPosts + count($orphans));
    foreach ((array) ($page['facets']['wc_status'] ?? []) as $status => $n) {
        $say('facet_wc_status_' . (string) $status, (int) $n);
    }
    foreach ((array) ($page['facets']['source'] ?? []) as $source => $n) {
        $say('facet_source_' . (string) $source, (int) $n);
    }
    foreach ((array) ($page['facets']['management'] ?? []) as $m => $n) {
        $say('facet_management_' . (string) $m, (int) $n);
    }
    return;
}

// =========================================================================
if ($verb === 'price-arrange') {
    $vendor = $vendorId();
    $say('vendor_user_id', $vendor);
    $ids = [
        'draft-nopr' => $makeProduct('draft-nopr', 'draft', null, $vendor),
        'pending-nopr' => $makeProduct('pending-nopr', 'pending', null, $vendor),
        'publish-nopr' => $makeProduct('publish-nopr', 'publish', null, $vendor),
        'publish-priced' => $makeProduct('publish-priced', 'publish', '450000', $vendor),
        // Two statuses this marketplace has no field for. They are in the
        // fixture so the refusal is MEASURED rather than argued: mapping them
        // to the nearest status we do have would make a restricted or a
        // scheduled product publicly live at the first projection.
        'private-priced' => $makeProduct('private-priced', 'private', '450000', $vendor),
        'future-priced' => $makeProduct('future-priced', 'future', '450000', $vendor),
    ];
    foreach ($ids as $suffix => $id) {
        $say('product_' . $suffix, $id);
        $say('status_' . $suffix, (string) get_post_status($id));
        $say('regular_price_' . $suffix, $shopMeta($id, '_regular_price'));
        $say('price_' . $suffix, $shopMeta($id, '_price'));
    }
    return;
}

// =========================================================================
if ($verb === 'price-judge') {
    $intake = $c->get(ImportExistingProducts::class);
    foreach ($fixtureIds() as $suffix => $id) {
        if ($id === 0) {
            $say('judge_' . $suffix, '__absent__');
            continue;
        }
        $verdict = $intake->judge($id, 0);
        $say('judge_' . $suffix, (string) $verdict['verdict'] . ':' . (string) ($verdict['reason'] ?? ''));
    }
    return;
}

// =========================================================================
if ($verb === 'price-intake') {
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
        $row = $db->getRow(
            'SELECT id, status, price_minor, link_ownership FROM `' . $productsTable . '`
              WHERE wc_product_id = %d ORDER BY id ASC LIMIT 1',
            [$id]
        );
        if ($row === null) {
            $say('row_' . $suffix, '__none__');
            continue;
        }
        $say('row_' . $suffix, (int) $row['id']);
        $say('row_status_' . $suffix, (string) $row['status']);
        $say('row_price_minor_' . $suffix, (string) $row['price_minor']);
        $say('row_ownership_' . $suffix, (string) $row['link_ownership']);
    }
    return;
}

// =========================================================================
if ($verb === 'price-project') {
    // Accept management of the PUBLISHED product that has no price, then make
    // the shop's own projection run — the sequence a manager walks. The two
    // prices are read from the ROWS before and after, because the point is
    // what landed on somebody's live product.
    $ids = $fixtureIds();
    // The suffix is an argument so the SAME verb can run the control: the
    // product that does have a price must still get it projected, and a
    // measurement that only ever looks at the broken case cannot tell a fix
    // from a feature that stopped working (`alpha.46`).
    $suffix = (string) ($args[1] ?? 'publish-nopr');
    $wcId = (int) ($ids[$suffix] ?? 0);
    $say('suffix', $suffix);
    if ($wcId === 0) {
        $say('fixture', '__absent__');
        return;
    }
    $row = $db->getRow(
        'SELECT id FROM `' . $productsTable . '` WHERE wc_product_id = %d ORDER BY id ASC LIMIT 1',
        [$wcId]
    );
    if ($row === null) {
        $say('row', '__none__');
        return;
    }
    $productId = (int) $row['id'];
    $say('product_id', $productId);
    $say('wc_product_id', $wcId);
    $say('shop_regular_price_before', $shopMeta($wcId, '_regular_price'));
    $say('shop_price_before', $shopMeta($wcId, '_price'));
    $say('shop_status_before', (string) get_post_status($wcId));
    // WHAT A BUYER WOULD SEE. The row value is the fact; this is the fact's
    // consequence, and «۰ تومان» where there was nothing is the public change
    // §1-ج says an intake must not make.
    $before = function_exists('wc_get_product') ? wc_get_product($wcId) : null;
    $say('price_html_before', $before === null ? '__unknown__' : wp_strip_all_tags($before->get_price_html()));

    $transfer = $c->get(TransferOwnership::class);
    $risks = $transfer->risksFor($productId);
    $seen = [];
    foreach ($transfer->storefrontDrift($productId) as $field => $pair) {
        $seen[$field] = (string) $pair['fingerprint'];
    }
    $say('risks', implode(',', array_keys($risks)));
    $say('drift_fields', implode(',', array_keys($seen)));
    $take = $transfer->take($productId, array_keys($risks), $seen);
    $say('take_code', $take->code);

    $sync = $c->get(SyncCatalog::class);
    $projected = $sync->publish($productId);
    $say('project_ok', (bool) ($projected->ok ?? false));
    $say('project_code', (string) ($projected->code ?? ''));
    clean_post_cache($wcId);
    $say('shop_regular_price_after', $shopMeta($wcId, '_regular_price'));
    $say('shop_price_after', $shopMeta($wcId, '_price'));
    $say('shop_status_after', (string) get_post_status($wcId));
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($wcId);
    }
    $product = function_exists('wc_get_product') ? wc_get_product($wcId) : null;
    $say('price_html_after', $product === null ? '__unknown__' : wp_strip_all_tags($product->get_price_html()));
    // Reported, and NOT claimed as protection: `is_purchasable` is false here
    // because this marketplace's OWN sales gate is shut, not because the
    // price write was harmless. Two different facts, kept apart.
    $say('wc_is_purchasable', $product !== null ? (bool) $product->is_purchasable() : '__unknown__');
    return;
}

// =========================================================================
if ($verb === 'dokan-arrange') {
    // DOKAN ITSELF IS NOT ACTIVATED and cannot be here: the copy on this
    // machine is the owner's, whose first line `require_once`s a
    // SourceGuardian-encrypted `abzarwp/abzarwp.php` and needs a loader that
    // does not exist in this environment (`alpha.53`). What is built instead
    // is exactly what `WpDokanReader::isAvailable()` asks for — the
    // `dokan_orders` table — plus a user with the `seller` role, so OUR
    // reader and OUR plan run. Dokan is `Not Run`, and that is said out loud
    // rather than implied by a green line.
    $table = $db->prefix() . 'dokan_orders';
    $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . $table . '` (
            `order_id` BIGINT UNSIGNED NOT NULL,
            `seller_id` BIGINT UNSIGNED NOT NULL,
            `order_total` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `net_amount` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `order_status` VARCHAR(30) NOT NULL DEFAULT "",
            PRIMARY KEY (`order_id`, `seller_id`)
        )',
        []
    );
    $say('dokan_orders_table', 'ready');
    $say('dokan_plugin_active', is_plugin_active('dokan-lite/dokan.php'));

    $seller = get_user_by('login', TMC_P65_SELLER_LOGIN);
    if (!$seller instanceof \WP_User) {
        $id = wp_insert_user([
            'user_login' => TMC_P65_SELLER_LOGIN,
            'user_pass' => wp_generate_password(20),
            'user_email' => TMC_P65_SELLER_LOGIN . '@example.invalid',
            'role' => 'customer',
        ]);
        if (is_wp_error($id)) {
            $say('seller_not_created', $id->get_error_code());
            return;
        }
        $seller = get_user_by('id', (int) $id);
    }
    $seller->add_role('seller');
    update_user_meta($seller->ID, 'dokan_profile_settings', ['store_name' => 'فروشگاه دکان آزمون ۶۵']);
    $say('seller_user_id', (int) $seller->ID);

    $made = [
        'dk-publish-nopr' => $makeProduct('dk-publish-nopr', 'publish', null, (int) $seller->ID),
        'dk-draft-nopr' => $makeProduct('dk-draft-nopr', 'draft', null, (int) $seller->ID),
        'dk-pending-nopr' => $makeProduct('dk-pending-nopr', 'pending', null, (int) $seller->ID),
        'dk-publish-frac' => $makeProduct('dk-publish-frac', 'publish', '450000.50', (int) $seller->ID),
        'dk-publish-priced' => $makeProduct('dk-publish-priced', 'publish', '450000', (int) $seller->ID),
    ];
    foreach ($made as $suffix => $id) {
        $say('product_' . $suffix, $id);
        $say('status_' . $suffix, (string) get_post_status($id));
        $say('price_' . $suffix, $shopMeta($id, '_price'));
    }
    return;
}

// =========================================================================
if ($verb === 'dokan-plan') {
    $reader = $c->get(DokanReaderInterface::class);
    $say('dokan_reader_available', (bool) $reader->isAvailable());
    $seller = get_user_by('login', TMC_P65_SELLER_LOGIN);
    $say('seller_user_id', $seller instanceof \WP_User ? (int) $seller->ID : 0);
    $read = $reader->products();
    $say('products_read', count($read));
    $byId = [];
    foreach ($read as $product) {
        $byId[(int) $product['wc_product_id']] = $product;
    }
    // Which of the fixture's products the READER even returned — the question
    // `post_status IN ('publish','draft')` answers by omission.
    foreach (['dk-publish-nopr', 'dk-draft-nopr', 'dk-pending-nopr', 'dk-publish-frac', 'dk-publish-priced'] as $suffix) {
        $post = get_page_by_path(TMC_P65_SLUG . '-' . $suffix, OBJECT, 'product');
        $id = $post instanceof \WP_Post ? (int) $post->ID : 0;
        $say('read_' . $suffix, $id === 0 ? '__absent__' : (isset($byId[$id]) ? 'yes' : 'no'));
        if (isset($byId[$id])) {
            $say('read_price_minor_' . $suffix, (string) $byId[$id]['price_minor']);
            // The three cases the single number cannot tell apart. Absent on
            // the `alpha.65` tree, where there was only the number.
            $say('read_price_recorded_' . $suffix, array_key_exists('price_recorded', $byId[$id])
                ? ($byId[$id]['price_recorded'] ? 'yes' : 'no')
                : '__absent__');
            $say('read_price_not_exact_' . $suffix, array_key_exists('price_not_exact', $byId[$id])
                ? ($byId[$id]['price_not_exact'] ? 'yes' : 'no')
                : '__absent__');
            $say('read_status_' . $suffix, (string) ($byId[$id]['post_status'] ?? '__absent__'));
        }
    }
    $import = $c->get(ImportFromDokan::class);
    $plan = $import->plan();
    $say('plan_products', count($plan->products));
    $counts = [];
    foreach ($plan->products as $line) {
        $verdictKey = (string) $line['verdict'] . ':' . (string) ($line['reason'] ?? '');
        $counts[$verdictKey] = ($counts[$verdictKey] ?? 0) + 1;
        $say('plan_' . (int) $line['dokan_id'], $verdictKey);
    }
    foreach ($counts as $key => $n) {
        $say('plan_count_' . $key, $n);
    }
    return;
}

// =========================================================================
if ($verb === 'commission-input') {
    // PURE. Nine spellings of the same two answers, so «۱۲٫۵ ذخیره نشد» is a
    // measurement rather than a report.
    $cases = [
        'percent_ascii' => ['percent', '12.5'],
        'percent_persian' => ['percent', "\u{06F1}\u{06F2}\u{066B}\u{06F5}"],
        'percent_arabic' => ['percent', "\u{0661}\u{0662}\u{066B}\u{0665}"],
        'percent_mixed' => ['percent', "12\u{066B}5"],
        'percent_persian_whole' => ['percent', "\u{06F1}\u{06F2}"],
        'percent_over' => ['percent', "\u{06F1}\u{06F2}\u{06F0}"],
        'percent_grouped' => ['percent', "\u{06F1}\u{066C}\u{06F2}"],
        'fixed_ascii' => ['fixed', '20000'],
        'fixed_persian' => ['fixed', "\u{06F2}\u{06F0}\u{06F0}\u{06F0}\u{06F0}"],
        'fixed_grouped' => ['fixed', "\u{06F2}\u{06F0}\u{066C}\u{06F0}\u{06F0}\u{06F0}"],
        'none_with_text' => ['none', "\u{06F1}\u{06F2}\u{066B}\u{06F5}"],
    ];
    foreach ($cases as $name => [$type, $value]) {
        $answer = TectebCommission::fromInput($type, $value);
        $rule = $answer['rule'];
        $say($name, $rule === null
            ? 'refused:' . (string) $answer['reason']
            : $rule->type . '/' . $rule->displayValue());
    }
    return;
}

// =========================================================================
if ($verb === 'commission-save') {
    // THE REAL PANEL PATH: its nonce, its capability, its two meta rows.
    $ids = $fixtureIds();
    $wcId = (int) ($ids['publish-priced'] ?? 0);
    if ($wcId === 0) {
        $say('fixture', '__absent__');
        return;
    }
    $typed = (string) ($args[1] ?? "\u{06F1}\u{06F2}\u{066B}\u{06F5}");
    $type = (string) ($args[2] ?? 'percent');
    $say('wc_product_id', $wcId);
    $say('typed', $typed);
    $say('typed_type', $type);
    $say('before_type', $shopMeta($wcId, '_tmc_commission_type'));
    $say('before_value', $shopMeta($wcId, '_tmc_commission_value'));

    $_POST = [
        'tmc_commission_panel' => wp_create_nonce('tmc_commission_panel'),
        'tmc_commission_type' => $type,
        'tmc_commission_value' => $typed,
    ];
    $_REQUEST = $_POST;
    \Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionPanel::save($wcId, $c);
    $notice = get_transient('tmc_commission_notice_' . (int) get_current_user_id());
    $say('outcome', is_array($notice) ? (string) ($notice['outcome'] ?? '') : '__none__');
    $say('reason', is_array($notice) ? (string) ($notice['reason'] ?? '') : '__none__');
    $say('after_type', $shopMeta($wcId, '_tmc_commission_type'));
    $say('after_value', $shopMeta($wcId, '_tmc_commission_value'));
    $stored = TectebCommission::fromStored(
        get_post_meta($wcId, '_tmc_commission_type', true),
        get_post_meta($wcId, '_tmc_commission_value', true)
    );
    $say('reads_back_as', $stored->type . '/' . $stored->displayValue());
    return;
}

// =========================================================================
if ($verb === 'gate-chain') {
    $gate = $c->get(OrderOperationsGate::class);
    $say('open_decisions', implode(',', OrderOperationsGate::OPEN_DECISIONS));
    $answer = $gate->check([]);
    $say('gate_ready', (bool) $answer['ready']);
    $say('gate_reason', (string) $answer['reason']);
    $say('gate_detail', (string) $answer['detail']);
    $say('gate_blockers', implode(',', $gate->blockers([])));

    // THE GENERAL RATE, asked the way the storefront screen asks it.
    $rates = $c->get(ResolveCommissionRate::class);
    $resolved = $rates->forItem([]);
    $say('general_rate_source', (string) $resolved['source']);
    $say('general_rate_bp', (string) ($resolved['rate']->basisPoints ?? '__none__'));
    $say('general_rate_is_set', (bool) ($resolved['rate']->isSet() ?? false));

    // Does the gate's answer MOVE when a product carries a Tecteb commission?
    // A fresh gate, because the answer is memoised per request by design.
    $ids = $fixtureIds();
    $wcId = (int) ($ids['publish-priced'] ?? 0);
    $say('probe_product', $wcId);
    $scoped = $gate->check(['product' => (string) $wcId, 'vendor' => (string) $vendorId()]);
    $say('gate_reason_scoped', (string) $scoped['reason']);
    $say('gate_reason_is_rate_unset', (string) $scoped['reason'] === OrderOperationsGate::RATE_UNSET);

    $trial = $c->get(TrialUnlock::class);
    $say('trial_requested', (bool) $trial->isRequested());
    $say('trial_permitted', (bool) $trial->isPermitted());
    $say('trial_active', (bool) $trial->isActive());
    $say('trial_refusal', (string) $trial->refusal());
    $say('trial_environment', (string) $trial->environmentName());
    $say('trial_option', TrialUnlock::OPTION);
    return;
}

// =========================================================================
if ($verb === 'gate-screen') {
    // The two sentences that contradict each other, read out of the RENDERED
    // markup of the one screen the owner was looking at.
    if (!defined('WP_ADMIN')) {
        define('WP_ADMIN', true);
    }
    $page = new \Tecteb\Marketplace\Modules\Product\Presentation\Admin\StorefrontPage($c);
    // `render()` ECHOES (every admin page here does), so the markup is
    // captured rather than returned — a probe that read the return value
    // would measure an empty string and report that no sentence is there,
    // about a screen that prints both of them.
    ob_start();
    $page->render(\Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture());
    $html = (string) ob_get_clean();
    $banner = 'فروش بازارگاه فعال است';
    $gateShut = "\u{062F}\u{0631}\u{0648}\u{0627}\u{0632}\u{0647}\u{0654} \u{0633}\u{0641}\u{0627}\u{0631}\u{0634}";
    $generalRate = 'نرخ کمیسیون عمومی';
    $say('html_bytes', strlen($html));
    $say('says_sales_active', str_contains($html, $banner));
    $say('says_order_gate', str_contains($html, $gateShut));
    $say('says_general_commission_rate', str_contains($html, $generalRate));
    $say('says_decisions_open', str_contains($html, 'decisions_open'));
    // The sentence the purchase table prints for the MANAGER. It names the
    // commission rate as a possible cause, and since `alpha.57` the rate is
    // not a cause of anything.
    $say('buyer_row_blames_rate', str_contains($html, 'نرخ کمیسیون یا تصمیم‌های مالی باز است'));
    // What this round puts in their place.
    $say('says_switch_on_gate_shut', str_contains($html, 'کلید فروش روشن است، ولی هیچ محصول بازارگاهی قابل خرید نیست'));
    $say('says_commission_model', str_contains($html, 'مدل کمیسیون'));
    $say('banner_is_warning', str_contains($html, 'tmc-notice--warning'));
    $say('banner_is_success', str_contains($html, 'tmc-notice--success'));
    return;
}

// =========================================================================
if ($verb === 'trial-on' || $verb === 'trial-off') {
    // The plugin's OWN switch, through its own service — not an option write.
    // `request()` only asks; `isPermitted()` still decides, so this verb
    // cannot open anything on a site that resolves to production. The caller
    // declares the environment with
    // `--exec='define("WP_ENVIRONMENT_TYPE","local");'`, which is how every
    // round since `alpha.7` has exercised this path.
    $trial = $c->get(TrialUnlock::class);
    $trial->request($verb === 'trial-on');
    $say('requested', (bool) $trial->isRequested());
    $say('permitted', (bool) $trial->isPermitted());
    $say('active', (bool) $trial->isActive());
    $say('environment', (string) $trial->environmentName());
    $gate = $c->get(OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) $answer['ready']);
    $say('gate_reason', (string) $answer['reason']);
    $say('gate_detail', (string) $answer['detail']);
    return;
}

// =========================================================================
if ($verb === 'estimate-share') {
    $ids = $fixtureIds();
    $wcId = (int) ($ids['publish-priced'] ?? 0);
    $row = $wcId === 0 ? null : $db->getRow(
        'SELECT id FROM `' . $productsTable . '` WHERE wc_product_id = %d ORDER BY id ASC LIMIT 1',
        [$wcId]
    );
    if ($row === null) {
        $say('row', '__none__');
        return;
    }
    $products = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
    $product = $products->find((int) $row['id']);
    if ($product === null) {
        $say('product', '__none__');
        return;
    }
    $estimate = $c->get(\Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare::class);
    $answer = $estimate->forProduct($product, gmdate('Y-m-d'));
    $say('estimate_is_calculated', (bool) $answer->isCalculated());
    $say('estimate_reason', (string) ($answer->reason ?? ''));
    $say('estimate_rate_source', (string) ($answer->snapshot->rateSource ?? ''));
    $say('estimate_rate_bp', (string) ($answer->snapshot->rateBasisPoints ?? ''));
    $say('estimate_commission_minor', (string) ($answer->commission->minor ?? ''));
    $say('estimate_vendor_share_minor', (string) ($answer->vendorShare->minor ?? ''));
    $say('shop_commission_type', $shopMeta($wcId, '_tmc_commission_type'));
    $say('shop_commission_value', $shopMeta($wcId, '_tmc_commission_value'));
    return;
}

// =========================================================================
if ($verb === 'trial-order') {
    // §3-و: ONE WooCommerce ORDER, NO PAYMENT GATEWAY.
    //
    // The order is created programmatically and completed with
    // `payment_complete()`, which writes the paid status and the date and
    // CONTACTS NOTHING: a gateway is an object WooCommerce looks up from the
    // order's `payment_method`, and this order has none. So «بدون پول واقعی»
    // is a property of there being no gateway installed, not of a flag — and
    // that is said rather than implied.
    //
    // `woocommerce_new_order` is one of the three hooks `OrderHooks` registers,
    // and it registers them only when the gate said the module may operate —
    // so this verb measures the gate as much as the capture.
    $gate = $c->get(OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) $answer['ready']);
    $say('gate_reason', (string) $answer['reason']);
    if (($answer['ready'] ?? false) !== true) {
        // Reported and stopped. An order created while the gate is shut would
        // be an order with no ledger line behind it, which is the single
        // thing `OrderOperationsGate` exists to prevent.
        $say('order_created', 'no');
        return;
    }
    $ids = $fixtureIds();
    $wcId = (int) ($ids['publish-priced'] ?? 0);
    if ($wcId === 0 || !function_exists('wc_create_order')) {
        $say('fixture', '__absent__');
        return;
    }
    $product = wc_get_product($wcId);
    if ($product === null || $product === false) {
        $say('product', '__absent__');
        return;
    }
    $say('wc_product_id', $wcId);
    $say('unit_price', (string) $product->get_price());
    $say('commission_type', $shopMeta($wcId, '_tmc_commission_type'));
    $say('commission_value', $shopMeta($wcId, '_tmc_commission_value'));

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
    $say('payment_method', (string) $order->get_payment_method());
    $say('available_gateways', function_exists('WC')
        ? (string) count(WC()->payment_gateways()->get_available_payment_gateways())
        : '__unknown__');
    $order->payment_complete();
    $say('order_status_after_payment_complete', (string) $order->get_status());

    // THE CAPTURE HOOK, fired where WooCommerce fires it.
    //
    // `wc_create_order()` fires `woocommerce_new_order` BEFORE any line
    // exists, so the capture that ran there saw an order with nothing in it
    // and recorded nothing — measured: `marketplace_lines=0`. That is a
    // property of building an order in two steps, not of the capture: the
    // classic checkout fires `woocommerce_checkout_order_processed` once the
    // order is complete, with `($order_id, $posted_data, $order)`
    // (`class-wc-checkout.php`), and that is the same hook `OrderHooks`
    // listens on. So it is fired here, with the same arguments, at the same
    // point in the order's life.
    //
    // ⛔ A FULL BROWSER CHECKOUT IS `Not Run`. This is the hook a checkout
    // fires, not a checkout — and the capture being idempotent on the order
    // item id is what makes firing it twice harmless rather than lucky.
    do_action('woocommerce_checkout_order_processed', $orderId, [], $order);

    // WHAT WAS RECORDED. Read from the repositories, not from the hook's
    // return: the question is what is on disk.
    $items = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class);
    $ledger = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class);
    $lines = $items->forOrder($orderId);
    $say('marketplace_lines', count($lines));
    foreach ($lines as $line) {
        $say('line_vendor', $line->vendorUserId);
        $say('line_quantity', $line->quantity);
        $say('line_unit_price_minor', $line->unitPriceMinor);
        $say('line_base_minor', $line->baseMinor);
        $say('line_commission_minor', $line->commissionMinor === null ? '__unknown__' : (string) $line->commissionMinor);
        $say('line_vendor_share_minor', $line->vendorShareMinor === null ? '__unknown__' : (string) $line->vendorShareMinor);
        $say('line_rate_bp', $line->rateBasisPoints === null ? '__unset__' : (string) $line->rateBasisPoints);
        $say('line_rate_source', $line->rateSource);
        $say('line_ledger_event', $line->ledgerEvent);
        $say('line_status', $line->status->value);
        if ($line->ledgerEvent !== '') {
            foreach ($ledger->forEvent($line->ledgerEvent) as $entry) {
                $say('ledger_' . $entry->reason, $entry->amount->minor . '/' . $entry->amount->currency . '/' . $entry->amount->exponent);
            }
        }
        $say('ledger_balances', (string) json_encode($ledger->balances($line->vendorUserId), JSON_UNESCAPED_UNICODE));
    }
    return;
}

// =========================================================================
if ($verb === 'priceless-report') {
    // §1-هـ, measured on the real install: PUBLISHED in WooCommerce, priced
    // nowhere. A read and nothing else — the verb has no companion that
    // repairs one, because the state is a question for a person.
    $inventory = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory::class);
    if (!method_exists($inventory, 'pricelessPublished')) {
        $say('reported', '__absent__');       // the `alpha.65` tree
        return;
    }
    $rows = $inventory->pricelessPublished(100);
    $say('reported', count($rows));
    foreach ($rows as $row) {
        $say('priceless_' . (int) $row['wc_product_id'], (string) $row['shop_price'] . '/' . (string) $row['ownership']);
    }
    $ids = $fixtureIds();
    foreach (['publish-nopr' => 'yes', 'publish-priced' => 'no'] as $suffix => $expected) {
        $wcId = (int) ($ids[$suffix] ?? 0);
        $found = 'no';
        foreach ($rows as $row) {
            if ((int) $row['wc_product_id'] === $wcId) {
                $found = 'yes';
            }
        }
        $say('listed_' . $suffix, $found);
    }
    return;
}

// =========================================================================
if ($verb === 'report') {
    foreach ($fixtureIds() as $suffix => $id) {
        $say('product_' . $suffix, $id);
    }
    $say('marketplace_rows_for_fixture', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $productsTable . '` WHERE sku LIKE %s',
        ['TMCP65-%']
    ) ?? 0));
    return;
}

// =========================================================================
if ($verb === 'reset') {
    // Gives back everything the fixture spent (`alpha.33`): the marketplace
    // rows, the WooCommerce posts, the seller role and the Dokan-shaped table.
    foreach (array_merge($suffixes, [
        'dk-publish-nopr', 'dk-draft-nopr', 'dk-pending-nopr', 'dk-publish-frac', 'dk-publish-priced',
    ]) as $suffix) {
        $post = get_page_by_path(TMC_P65_SLUG . '-' . $suffix, OBJECT, 'product');
        if (!$post instanceof \WP_Post) {
            continue;
        }
        $wcId = (int) $post->ID;
        $rows = $db->getResults('SELECT id FROM `' . $productsTable . '` WHERE wc_product_id = %d', [$wcId]);
        foreach ($rows as $row) {
            $productId = (int) $row['id'];
            foreach ([
                'tmc_product_images', 'tmc_product_specs', 'tmc_product_revisions',
                'tmc_product_decisions', 'tmc_review_seen', 'tmc_brand_requests',
            ] as $child) {
                $db->execute('DELETE FROM `' . $db->prefix() . $child . '` WHERE product_id = %d', [$productId]);
            }
            $db->execute('DELETE FROM `' . $productsTable . '` WHERE id = %d', [$productId]);
        }
        wp_delete_post($wcId, true);
    }
    $db->execute('DROP TABLE IF EXISTS `' . $db->prefix() . 'dokan_orders`', []);
    // The Dokan-shaped seller is the ONLY account this fixture makes, so it
    // is the only one it may unmake. The marketplace vendor is BORROWED
    // (see `$vendorId`) and deleting it would take a shop, its products and
    // its audit trail with it.
    $seller = get_user_by('login', TMC_P65_SELLER_LOGIN);
    if ($seller instanceof \WP_User) {
        wp_delete_user((int) $seller->ID);
    }
    $say('reset', 'done');
    return;
}

$say('usage', 'catalogue-decompose|price-arrange|price-judge|price-intake|price-project|dokan-arrange|dokan-plan|commission-input|commission-save|priceless-report|gate-chain|gate-screen|estimate-share|trial-on|trial-off|trial-order|report|reset');
