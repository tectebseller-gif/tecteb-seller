<?php
/**
 * What `alpha.80` does with a product that already has WooCommerce categories.
 *
 * The owner's instruction is that the existing `product_cat` relationships are
 * the reference and re-picking 1,070 categories by hand is not acceptable. Two
 * things have to be MEASURED on these bytes before a line is written, because
 * both decide the shape of the fix:
 *
 *  - `first-wins` — what the catalogue intake stores for a product carrying
 *    three categories. `ImportExistingProducts.php:808` reads
 *    `category_ids[0]`, so the claim is that one is kept arbitrarily and two
 *    are dropped with nothing said. That is the thing the owner forbids:
 *    «اولین دسته را خودسرانه انتخاب نکن و دسته‌های دیگر را بی‌صدا کنار نگذار».
 *
 *  - `accept-drops` — what happens to those three relationships when the one
 *    stored key is written back to the shop. `WooCommerceProjector::categoryIds()`
 *    returns a ONE-element list and the write is `set_category_ids()`, which
 *    replaces the whole set rather than adding to it. If that is so, the
 *    single-column model does not merely fail to record the other two: it
 *    DELETES them from WooCommerce on the first accepted write.
 *
 *  - `search` — the reported defect, against the real directory on this
 *    catalogue: a three-word Persian query finding nothing while its first
 *    word finds the very category meant.
 *
 * Read-only about the shop except where a verb says otherwise, and `reset`
 * gives back every user, post, term and row it made — nothing else.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha80-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$FIXTURE = 'tmc_p80_fixture';
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $value = (string) $value;
    echo $key . '=' . ($value === '' ? '__empty__' : $value) . "\n";
};

$command = (string) ($args[0] ?? 'report');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }
        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }
        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

$fixture = static function () use ($FIXTURE): array {
    $raw = get_option($FIXTURE, []);
    $base = ['vendor' => 0, 'terms' => [], 'products' => [], 'run' => '', 'rows' => []];
    return is_array($raw) ? $raw + $base : $base;
};
$remember = static function (array $f) use ($FIXTURE): void {
    update_option($FIXTURE, $f, false);
};

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var ProductCategoryDirectoryInterface $directory */
$directory = $c->get(ProductCategoryDirectoryInterface::class);

/** The marketplace row pointing at a WooCommerce product, read from the table. */
global $wpdb;
$rowFor = static function (int $wcId) use ($wpdb): ?array {
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tmc_products WHERE wc_product_id = %d", $wcId),
        ARRAY_A
    );
    return is_array($row) ? $row : null;
};

switch ($command) {
    case 'arrange':
        $f = $fixture();
        // A vendor to own the rows. Nothing is granted that selling needs.
        if ($f['vendor'] === 0 || !get_userdata($f['vendor'])) {
            $f['vendor'] = wp_insert_user([
                'user_login' => 'tmc_p80_vendor',
                'user_pass' => wp_generate_password(24),
                'role' => 'customer',
            ]);
        }
        // An APPROVED vendor, through `ReviewApplication` and not by writing a
        // status: `judge()` refuses an intake whose vendor is not one, and the
        // service is the only thing that may admit a shop (`alpha.17`).
        /** @var VendorRepositoryInterface $vendors */
        $vendors = $c->get(VendorRepositoryInterface::class);
        if ($vendors->findApplicationByUser((int) $f['vendor']) === null) {
            $vendors->saveDraft((int) $f['vendor'], new ApplicantDetails(
                'فروشگاه ت۸۰',
                'شرکت ت۸۰',
                'p80@example.test',
                '09120000080',
                'تهران، خیابان آزمایش، پلاک ۸۰',
                true
            ));
            $vendors->updateStatus(
                (int) $vendors->findApplicationByUser((int) $f['vendor'])->id,
                ApplicationStatus::Submitted
            );
        }
        $application = $vendors->findApplicationByUser((int) $f['vendor']);
        if ($application !== null && $application->status !== ApplicationStatus::Approved) {
            $c->get(ReviewApplication::class)->approve($application->id);
        }
        $say('vendor_status', (string) ($vendors->findApplicationByUser((int) $f['vendor'])?->status->value ?? '__none__'));
        // Four real `product_cat` terms, one Persian-named, two sharing a name
        // under different parents — the «نام مشابه با شناسهٔ متفاوت» case.
        // An explicit slug per term: `wp_insert_term()` dedupes on the SLUG,
        // so two terms that share a name under different parents — the
        // «نام مشابه با شناسهٔ متفاوت» case — collapse into one without it.
        $mk = static function (string $name, string $slug, int $parent = 0) {
            $existing = get_term_by('slug', $slug, 'product_cat');
            if ($existing instanceof WP_Term) {
                return (int) $existing->term_id;
            }
            $term = wp_insert_term($name, 'product_cat', ['parent' => $parent, 'slug' => $slug]);
            return is_wp_error($term) ? 0 : (int) $term['term_id'];
        };
        $root = $mk('ت۸۰ تجهیزات پزشکی', 'p80-root');
        $inject = $mk('ت۸۰ تزریق', 'p80-inject', $root);
        $f['terms'] = [
            'root' => $root,
            'glue' => $mk('ت۸۰ چسب', 'p80-glue', $root),
            'inject' => $inject,
            'same_a' => $mk('ت۸۰ لوازم جانبی', 'p80-same-a', $root),
            'same_b' => $mk('ت۸۰ لوازم جانبی', 'p80-same-b', $inject),
            'doomed' => $mk('ت۸۰ حذف‌شدنی', 'p80-doomed', $root),
        ];
        // Products: multi-category, single-category, Persian-only, none, and
        // one on a term this probe will delete.
        $shapes = [
            'multi' => [$f['terms']['glue'], $f['terms']['inject'], $f['terms']['same_a']],
            'single' => [$f['terms']['glue']],
            'persian' => [$f['terms']['root']],
            'none' => [],
            'doomed' => [$f['terms']['doomed']],
        ];
        foreach ($shapes as $name => $terms) {
            if (($f['products'][$name] ?? 0) > 0 && get_post((int) $f['products'][$name])) {
                continue;
            }
            $wc = new WC_Product_Simple();
            $wc->set_name('ت۸۰ محصول ' . $name);
            $wc->set_status('publish');
            $wc->set_regular_price('450000');
            $wc->set_manage_stock(true);
            $wc->set_stock_quantity(7);
            $wc->set_sku('SKU-P80-' . strtoupper($name));
            $wc->set_category_ids($terms);
            $f['products'][$name] = (int) $wc->save();
        }
        $remember($f);
        foreach ($f['products'] as $name => $id) {
            $say('wc_' . $name, $id);
            $say('wc_' . $name . '_terms', wc_get_product($id)->get_category_ids());
        }
        $say('terms', $f['terms']);
        $say('vendor', $f['vendor']);
        break;

    case 'intake':
        // Import them exactly as the manager's screen does.
        $f = $fixture();
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $ids = array_values(array_map('intval', $f['products']));
        $run = $f['run'] !== '' ? $f['run'] : $intake->newRunId();
        $result = $intake->importBatch($run, $ids, (int) $f['vendor']);
        $f['run'] = $run;
        $remember($f);
        $say('run', $run);
        $say('code', (string) ($result['code'] ?? '__absent__'));
        $say('counts', $result['counts'] ?? '__absent__');
        foreach ((array) ($result['results'] ?? []) as $r) {
            $say(
                'result_' . (int) ($r['wc_product_id'] ?? 0),
                (string) ($r['verdict'] ?? '?') . ':' . (string) ($r['reason'] ?? '')
            );
        }
        foreach ($f['products'] as $name => $wcId) {
            $row = $rowFor((int) $wcId);
            $say('row_' . $name . '_category_key', $row === null ? '__no_row__' : (string) $row['category_key']);
        }
        break;

    case 'first-wins':
        // The measurement §0 exists for: three categories in, one key stored.
        $f = $fixture();
        $wcId = (int) ($f['products']['multi'] ?? 0);
        $wc = $wcId > 0 ? wc_get_product($wcId) : null;
        $row = $rowFor($wcId);
        $terms = $wc === null ? [] : array_map('intval', $wc->get_category_ids());
        $say('wc_product', $wcId);
        $say('wc_terms', $terms);
        $say('wc_term_count', count($terms));
        $say('stored_category_key', $row === null ? '__no_row__' : (string) $row['category_key']);
        $say('stored_is_the_first_term', $row !== null && (string) $row['category_key'] === (string) ($terms[0] ?? ''));
        $say('terms_not_recorded_anywhere', count($terms) > 1 ? count($terms) - 1 : 0);
        break;

    case 'accept-drops':
        // Writing the one stored key back to the shop: does it ADD or REPLACE?
        $f = $fixture();
        $wcId = (int) ($f['products']['multi'] ?? 0);
        $row = $rowFor($wcId);
        $before = array_map('intval', wc_get_product($wcId)->get_category_ids());
        $say('terms_before', $before);
        $product = $products->find((int) ($row['id'] ?? 0));
        /** @var StorefrontFieldsInterface $fields */
        $fields = $c->get(StorefrontFieldsInterface::class);
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        $say('comparison_state', $fields->comparisonState($product));
        $outcome = $fields->acceptProposal($product, 'category');
        $say('accept_outcome', $outcome === null ? 'written' : $outcome);
        clean_post_cache($wcId);
        wp_cache_flush();
        $after = array_map('intval', wc_get_product($wcId)->get_category_ids());
        $say('terms_after', $after);
        $say('terms_lost', array_values(array_diff($before, $after)));
        $say('other_categories_silently_dropped', count(array_diff($before, $after)) > 0);
        break;

    case 'take-then-accept':
        // The decisive measurement. An OBSERVED row refuses («not_ours»), so
        // the question is what happens once the manager has accepted
        // management — the one documented road from `observed` to
        // `marketplace` — and the single stored key is written to the shop.
        $f = $fixture();
        $wcId = (int) ($f['products']['multi'] ?? 0);
        $row = $rowFor($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $before = array_map('intval', wc_get_product($wcId)->get_category_ids());
        $say('terms_before', $before);
        /** @var TransferOwnership $transfer */
        $transfer = $c->get(TransferOwnership::class);
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $risks = array_keys($transfer->risksFor($productId));
        $seen = [];
        foreach ($intake->driftOf($productId) as $field => $pair) {
            $seen[$field] = (string) ($pair[1] ?? '');
        }
        $taken = $transfer->take($productId, $risks, $seen);
        $say('take', $taken->ok ? 'ok' : (string) $taken->code);
        $say('link_ownership', (string) ($rowFor($wcId)['link_ownership'] ?? '?'));
        $product = $products->find($productId);
        /** @var StorefrontFieldsInterface $fields */
        $fields = $c->get(StorefrontFieldsInterface::class);
        $say('comparison_state', $product === null ? '__no_row__' : $fields->comparisonState($product));
        $say('stored_category_key', (string) ($rowFor($wcId)['category_key'] ?? '?'));
        $say('storefront_category_would_be', $product === null ? '?' : $fields->reconcile($product)['category'] ?? '__absent__');
        $outcome = $product === null ? 'no_row' : ($fields->acceptProposal($product, 'category') ?? 'written');
        $say('accept_outcome', $outcome);
        clean_post_cache($wcId);
        wp_cache_flush();
        $after = array_map('intval', wc_get_product($wcId)->get_category_ids());
        $say('terms_after', $after);
        $say('terms_lost', array_values(array_diff($before, $after)));
        $say('other_categories_silently_dropped', count(array_diff($before, $after)) > 0);
        break;

    case 'multi-detail':
        // What the ONE resolver every writer goes through makes of a
        // multi-id key, and what the review screen says about a
        // multi-category product BEFORE anybody accepts anything.
        //
        // The first half decides whether «keep all three in the column» is
        // even available: `WooCommerceProjector::categoryIds()` asks
        // `find($categoryKey)` and falls back to the shop's CURRENT ids when
        // that resolves to nothing. A comma key would therefore leave the
        // relationship alone — by accident, through a failed lookup, and
        // every screen that prints a path would show nothing. Measured so
        // the note can say which it is instead of reasoning about it.
        $f = $fixture();
        $wcId = (int) ($f['products']['multi'] ?? 0);
        $say('multi_wc_product', $wcId);
        $terms = $wcId > 0 && wc_get_product($wcId)
            ? array_map('intval', wc_get_product($wcId)->get_category_ids())
            : [];
        sort($terms);
        $say('multi_terms', $terms);
        foreach ([
            'comma' => implode(',', $terms),
            'first' => (string) ($terms[0] ?? ''),
            'second' => (string) ($terms[1] ?? ''),
        ] as $label => $key) {
            $found = $key === '' ? null : $directory->find($key);
            $say(
                'find_' . $label,
                $key . ' => ' . ($found === null
                    ? '__null__'
                    : $found->id . ' :: ' . ($found->path !== '' ? $found->path : $found->name))
            );
        }
        $stored = $rowFor($wcId);
        $row = $stored === null ? null : $products->find((int) $stored['id']);
        $fields = $c->get(StorefrontFieldsInterface::class);
        $say('row_found', $row !== null);
        $say('stored_category_key', $row === null ? '__no_row__' : $row->details->categoryKey);
        $say('link_ownership', (string) ($rowFor($wcId)['link_ownership'] ?? '?'));
        if ($row !== null) {
            $say('comparison_state', $fields->comparisonState($row));
            foreach ($fields->compare($row) as $field) {
                if ($field->key !== 'category') {
                    continue;
                }
                $say('category_verdict', $field->verdict);
                $say('category_marketplace', $field->marketplace);
                $say('category_storefront', $field->storefront);
                $say('category_has_pending', $field->hasPending);
            }
        }
        break;

    case 'project-drops':
        // Where does the guard belong? `take-then-accept` goes through
        // `acceptProposal()`, which is one path among several. An ORDINARY
        // projection — the normal publish/approve road — calls
        // `categoryIds()` too, and that returns a ONE-element list. If a
        // plain `project()` also narrows the set, then no apply-path guard is
        // enough: the guard has to sit in `categoryIds()` itself, and every
        // marketplace-owned multi-category product loses terms on every
        // publish rather than once.
        $f = $fixture();
        $wcId = (int) ($f['products']['multi'] ?? 0);
        $row = $rowFor($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $before = array_map('intval', wc_get_product($wcId)->get_category_ids());
        $say('terms_before', $before);
        /** @var TransferOwnership $transfer */
        $transfer = $c->get(TransferOwnership::class);
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $seen = [];
        foreach ($intake->driftOf($productId) as $field => $pair) {
            $seen[$field] = (string) ($pair[1] ?? '');
        }
        $taken = $transfer->take($productId, array_keys($transfer->risksFor($productId)), $seen);
        $say('take', $taken->ok ? 'ok' : (string) $taken->code);
        $say('link_ownership', (string) ($rowFor($wcId)['link_ownership'] ?? '?'));
        $say('stored_category_key', (string) ($rowFor($wcId)['category_key'] ?? '?'));
        $product = $products->find($productId);
        /** @var CatalogProjectorInterface $projector */
        $projector = $c->get(CatalogProjectorInterface::class);
        $say('projector_available', $projector->isAvailable());
        $outcome = $product === null ? ['__no_row__'] : $projector->project($product);
        foreach ($outcome as $k => $v) {
            $say('project_out.' . (string) $k, is_scalar($v) || is_array($v) ? $v : gettype($v));
        }
        clean_post_cache($wcId);
        wp_cache_flush();
        $after = array_map('intval', wc_get_product($wcId)->get_category_ids());
        $say('terms_after', $after);
        $say('terms_lost', array_values(array_diff($before, $after)));
        $say('plain_projection_drops_them', count(array_diff($before, $after)) > 0);
        // WHY it did not. A pre-existing product carries no
        // `_tmc_projected_category` stamp, so ownership of the field is
        // UNKNOWN and `FieldMerge` holds it as a proposal rather than writing
        // (`alpha.27`). That is the safe answer, and it is already here — so
        // the destructive moment is not the projection. It is the manager
        // accepting a proposal whose VALUE is the narrowed single id.
        $say('stamp_category', (string) get_post_meta($wcId, '_tmc_projected_category', true));
        $say('pending_category', (string) get_post_meta($wcId, '_tmc_pending_category', true));
        $say('held_as_a_proposal', get_post_meta($wcId, '_tmc_pending_category', true) !== '');
        break;

    case 'search':
        // The reported defect, on the real directory.
        $queries = ['ت۸۰ چسب تزریق پزشکی', 'ت۸۰ چسب', 'چسب تزریق', 'ت۸۰ تجهیزات چسب'];
        foreach ($queries as $q) {
            $hits = $directory->search($q, 40);
            $say('q[' . $q . ']_matched', $directory->countMatches($q));
            $say('q[' . $q . ']_names', array_map(static fn ($cat) => $cat->name, $hits));
        }
        $say('directory_total', $directory->total());
        break;

    case 'waiting':
        $say('waiting_rows', array_map(
            static fn (array $r): string => $r['id'] . ':' . $r['wc_product_id'] . ':' . $r['title'],
            $products->importedWithoutCategory(500)
        ));
        $say('waiting_total', $products->countImportedWithoutCategory());
        break;

    case 'delete-term':
        $f = $fixture();
        $id = (int) ($f['terms']['doomed'] ?? 0);
        $say('deleting_term', $id);
        $say('deleted', $id > 0 ? (wp_delete_term($id, 'product_cat') === true ? 'yes' : 'no') : 'no');
        break;

    case 'report':
        $f = $fixture();
        $say('vendor', $f['vendor']);
        $say('terms', $f['terms']);
        $say('products', $f['products']);
        $say('run', $f['run']);
        $say('waiting_total', $products->countImportedWithoutCategory());
        break;

    case 'reset':
        $f = $fixture();
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        if ($f['run'] !== '') {
            $r = $intake->rollback((string) $f['run']);
            $say('rollback', $r->ok ? 'ok' : (string) $r->code);
        }
        foreach ($f['products'] as $wcId) {
            if ((int) $wcId > 0) {
                wp_delete_post((int) $wcId, true);
            }
        }
        foreach ($f['terms'] as $termId) {
            if ((int) $termId > 0 && get_term((int) $termId, 'product_cat') instanceof WP_Term) {
                wp_delete_term((int) $termId, 'product_cat');
            }
        }
        if ((int) $f['vendor'] > 0 && get_userdata((int) $f['vendor'])) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $f['vendor']);
        }
        // Any marketplace row still pointing at a deleted fixture product.
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}tmc_products WHERE title LIKE 'ت۸۰ محصول %'"
        );
        delete_option($FIXTURE);
        $say('reset', 'done');
        $say('waiting_total', $products->countImportedWithoutCategory());
        break;

    default:
        $say('usage', 'arrange|intake|first-wins|accept-drops|take-then-accept|search|waiting|delete-term|report|reset');
}
