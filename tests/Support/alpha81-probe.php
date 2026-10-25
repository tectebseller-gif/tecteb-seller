<?php
/**
 * Where a product's SEVERAL categories become one, measured — not guessed.
 *
 * The owner's instruction for this round makes preservation the requirement:
 * «مجموعهٔ کامل دسته‌ها باید حفظ شود، حتی وقتی والد و فرزند هم‌زمان متصل‌اند.
 * هیچ رابطه‌ای خودکار حذف یا اضافه نشود.» And the preview of `alpha.81` showed
 * nine such products on staging — five trolleys and four others, among them a
 * PARENT and a CHILD of the same branch.
 *
 * So the question this probe answers is not «does the preview hold them» —
 * `alpha.81` already holds them by name. It is the one the instruction asks:
 * «تمام نقاطی را پیدا کن که چند دسته را به یک دسته تبدیل می‌کنند»، and in
 * particular «ویرایش یا پذیرش بعدی محصول هم نباید مجموعهٔ دسته‌ها را به یک
 * دسته کاهش دهد».
 *
 * Four narrowing points were found by reading the source. This probe measures
 * which of them actually fires, in what order, and what it costs:
 *
 *   1. `ImportExistingProducts.php:808` — intake stores `category_ids[0]`.
 *      So the single key is ALREADY in our row before any manager acts, and
 *      `missingFields()` is satisfied by it. That is measured here as
 *      `row_*_category_key` next to the product's real term list.
 *   2. `ReviewProducts::firstId()` — the round trip through the review page
 *      keeps one id «not as the id list WooCommerce keeps» (its own docblock).
 *   3. `WooCommerceProjector::categoryIds()` — resolves the one key and writes
 *      `set_category_ids([$one])`, which REPLACES the set.
 *   4. `Request::postKey('category')` — `sanitize_key()` strips commas, so a
 *      form that ever posted «207,208» would store the term id `207208`.
 *      Measured directly, because a transport that silently fuses two ids is
 *      worse than one that refuses.
 *
 * ⛔ **Nothing on staging or production is touched.** This runs only on the
 * disposable database, against a fixture it builds itself, and `reset` gives
 * back every row, post, term and user it created — through the documented
 * `rollback()` for the intake, not by deleting marketplace rows behind it.
 *
 * It loads on BOTH trees (`alpha.32`): everything this round may add is asked
 * for with `method_exists`/`array_key_exists` and reported `__absent__` on the
 * base, so the same script can measure before and after.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 */


use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Security\CapabilityCheckerInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Application\CategoryLinkPlan;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryLinkReaderInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
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

$FIXTURE = 'tmc_p81_fixture';
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
$which = (string) ($args[1] ?? 'pair');
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
    $base = ['vendor' => 0, 'terms' => [], 'products' => [], 'run' => ''];
    return is_array($raw) ? $raw + $base : $base;
};
$remember = static function (array $f) use ($FIXTURE): void {
    update_option($FIXTURE, $f, false);
};

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);

global $wpdb;
$rowFor = static function (int $wcId) use ($wpdb): ?array {
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tmc_products WHERE wc_product_id = %d", $wcId),
        ARRAY_A
    );
    return is_array($row) ? $row : null;
};
/** The product's REAL relationship, read fresh from WooCommerce. */
$termsOf = static function (int $wcId): array {
    clean_post_cache($wcId);
    clean_term_cache([], 'product_cat');
    $wc = wc_get_product($wcId);
    return $wc instanceof WC_Product ? array_values(array_map('intval', $wc->get_category_ids())) : [];
};
/** Set equality, by id and not by count — the owner's own acceptance rule. */
$sameSet = static function (array $a, array $b): bool {
    sort($a);
    sort($b);
    return $a === $b;
};

switch ($command) {
    case 'arrange':
        $f = $fixture();
        if ($f['vendor'] === 0 || !get_userdata((int) $f['vendor'])) {
            $f['vendor'] = wp_insert_user([
                'user_login' => 'tmc_p81_vendor',
                'user_pass' => wp_generate_password(24),
                'role' => 'customer',
            ]);
        }
        /** @var VendorRepositoryInterface $vendors */
        $vendors = $c->get(VendorRepositoryInterface::class);
        if ($vendors->findApplicationByUser((int) $f['vendor']) === null) {
            $vendors->saveDraft((int) $f['vendor'], new ApplicantDetails(
                'فروشگاه ت۸۱',
                'شرکت ت۸۱',
                'p81@example.test',
                '09120000081',
                'تهران، خیابان آزمایش، پلاک ۸۱',
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

        // The shape the owner reported: two branches, and a PARENT with its
        // own CHILD so «والد و فرزند هم‌زمان متصل‌اند» is a real row and not a
        // sentence. An explicit slug per term, because `wp_insert_term()`
        // dedupes on the slug (`alpha.80`).
        $mk = static function (string $name, string $slug, int $parent = 0): int {
            $existing = get_term_by('slug', $slug, 'product_cat');
            if ($existing instanceof WP_Term) {
                return (int) $existing->term_id;
            }
            $term = wp_insert_term($name, 'product_cat', ['parent' => $parent, 'slug' => $slug]);
            return is_wp_error($term) ? 0 : (int) $term['term_id'];
        };
        $root = $mk('ت۸۱ تجهیزات', 'p81-root');
        $trolley = $mk('ت۸۱ ترالی', 'p81-trolley', $root);
        $f['terms'] = [
            'root' => $root,
            'trolley' => $trolley,
            'trolley_steel' => $mk('ت۸۱ ترالی استیل', 'p81-trolley-steel', $trolley),
            'other' => $mk('ت۸۱ مصرفی', 'p81-other', $root),
            'doomed' => $mk('ت۸۱ حذف‌شدنی', 'p81-doomed', $root),
        ];
        $shapes = [
            // Parent AND child of one branch — the owner's named case.
            'pair' => [$f['terms']['trolley'], $f['terms']['trolley_steel']],
            // Several independent branches.
            'branches' => [$f['terms']['trolley'], $f['terms']['other'], $f['terms']['root']],
            // The controls: one category, and none at all.
            'single' => [$f['terms']['other']],
            'none' => [],
            // A set one of whose terms this probe can delete.
            'deleted' => [$f['terms']['trolley'], $f['terms']['doomed']],
        ];
        foreach ($shapes as $name => $terms) {
            if (($f['products'][$name] ?? 0) > 0 && get_post((int) $f['products'][$name])) {
                continue;
            }
            $wc = new WC_Product_Simple();
            $wc->set_name('ت۸۱ محصول ' . $name);
            $wc->set_status('publish');
            $wc->set_regular_price('450000');
            $wc->set_manage_stock(true);
            $wc->set_stock_quantity(7);
            $wc->set_sku('SKU-P81-' . strtoupper($name));
            $wc->set_category_ids($terms);
            $f['products'][$name] = (int) $wc->save();
        }
        $remember($f);
        $say('terms', $f['terms']);
        foreach ($f['products'] as $name => $id) {
            $say('wc_' . $name, $id);
            $say('wc_' . $name . '_terms', $termsOf((int) $id));
        }
        $say('vendor', $f['vendor']);
        break;

    case 'intake':
        // §1 — narrowing point one, before any manager has acted.
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
        foreach ($f['products'] as $name => $wcId) {
            $row = $rowFor((int) $wcId);
            $terms = $termsOf((int) $wcId);
            $key = $row === null ? '__no_row__' : (string) $row['category_key'];
            $say('row_' . $name . '_terms', $terms);
            $say('row_' . $name . '_category_key', $key);
            $say(
                'row_' . $name . '_key_is_first_term',
                $key !== '' && $key === (string) ($terms[0] ?? '')
            );
            $say(
                'row_' . $name . '_terms_not_recorded',
                count($terms) > 1 ? count($terms) - 1 : 0
            );
            // The agreement baseline is already set-shaped (`StorefrontField`)
            // — so the collapse is in the row's own column, not in the stamp.
            $say(
                'row_' . $name . '_baseline_category',
                $row === null ? '__no_row__' : (string) ($row['baseline_category'] ?? '__absent__')
            );
        }
        break;

    case 'blocker':
        // §2 — does the stored first id already satisfy «نیازمند انتخاب یک
        // دسته»? If it does, the message the owner wants removed is NOT what
        // these nine products see, and the real defect is the silent choice.
        $f = $fixture();
        foreach ($f['products'] as $name => $wcId) {
            $row = $rowFor((int) $wcId);
            $product = $row === null ? null : $products->find((int) $row['id']);
            $say(
                'missing_' . $name,
                $product === null ? '__no_row__' : $product->details->missingFields()
            );
        }
        break;

    case 'plan':
        // What the preview says for each shape, on whichever tree.
        $f = $fixture();
        /** @var ProductCategoryLinkReaderInterface $links */
        $links = $c->get(ProductCategoryLinkReaderInterface::class);
        $plan = $c->get(CategoryLinkPlan::class);
        $built = $plan->plan();
        foreach ((array) ($built['groups'] ?? $built) as $k => $group) {
            if (!is_array($group)) {
                continue;
            }
            $say('plan_' . (string) ($group['bucket'] ?? $k), (int) ($group['count'] ?? count($group)));
        }
        $say('plan_keys', array_keys((array) $built));
        $byId = $links->linksFor(array_values(array_map('intval', $f['products'])));
        foreach ($f['products'] as $name => $wcId) {
            $link = $byId[(int) $wcId] ?? null;
            if ($link === null) {
                $say('link_' . $name . '_state', '__absent__');
                continue;
            }
            $say('link_' . $name . '_state', $link->state);
            $say('link_' . $name . '_terms', $link->termIds);
            $say('link_' . $name . '_valid', $link->validIds);
            $say('link_' . $name . '_definite_key', $link->definiteKey());
        }
        break;

    case 'take':
        // The one documented road from `observed` to `marketplace`.
        $f = $fixture();
        $wcId = (int) ($f['products'][$which] ?? 0);
        $row = $rowFor($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $say('which', $which);
        $say('terms_before', $termsOf($wcId));
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
        $say('terms_after', $termsOf($wcId));
        $say('stored_category_key', (string) ($rowFor($wcId)['category_key'] ?? '?'));
        break;

    case 'edit':
        // §3 — «ویرایش بعدی محصول هم نباید مجموعه را کاهش دهد». A save that
        // changes the PRICE and touches no category at all, through the same
        // service the vendor's form calls.
        $f = $fixture();
        $wcId = (int) ($f['products'][$which] ?? 0);
        $row = $rowFor($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $product = $productId > 0 ? $products->find($productId) : null;
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        $before = $termsOf($wcId);
        $say('which', $which);
        $say('terms_before', $before);
        $say('key_before', (string) ($row['category_key'] ?? '?'));
        /** @var ManageProducts $manage */
        $manage = $c->get(ManageProducts::class);
        // Readiness needs a main image, and this probe is about categories,
        // not about re-testing the image rule. Any existing attachment will
        // do; nothing is uploaded and nothing is deleted.
        $image = (int) ($product->mainImageId > 0
            ? $product->mainImageId
            : (get_posts(['post_type' => 'attachment', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0));
        $say('image', $image);
        $result = $manage->save(
            (int) $f['vendor'],
            (int) $f['vendor'],
            $productId,
            $product->details->with(['priceMinor' => 450000 + random_int(1000, 9000)]),
            $product->specs,
            $image > 0 ? [$image] : $product->imageIds,
            $image,
            $product->rowVersion
        );
        $say('save_code', (string) $result->code);
        $say('status', $product->status->value);
        $after = $termsOf($wcId);
        $say('terms_after', $after);
        $say('key_after', (string) ($rowFor($wcId)['category_key'] ?? '?'));
        $say('set_preserved', $sameSet($before, $after));
        $say('terms_lost', array_values(array_diff($before, $after)));
        $say('terms_added', array_values(array_diff($after, $before)));
        break;

    case 'give-image':
        // Fixture only: readiness needs an image and this probe is about
        // categories. One existing attachment is attached to every fixture
        // row; nothing is uploaded, and `reset` removes the rows anyway.
        $f = $fixture();
        $media = (int) (get_posts(['post_type' => 'attachment', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0);
        $say('media', $media);
        foreach ($f['products'] as $name => $wcId) {
            $row = $rowFor((int) $wcId);
            if ($row === null || $media === 0) {
                continue;
            }
            $pid = (int) $row['id'];
            $has = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tmc_product_images WHERE product_id = %d",
                $pid
            ));
            if ($has === 0) {
                $wpdb->insert($wpdb->prefix . 'tmc_product_images', [
                    'product_id' => $pid,
                    'media_id' => $media,
                    'sort_order' => 0,
                ]);
            }
            $wpdb->update($wpdb->prefix . 'tmc_products', ['main_image_id' => $media], ['id' => $pid]);
            $product = $products->find($pid);
            $say('images_' . $name, $product === null ? '__no_row__' : $product->imageIds);
        }
        break;

    case 'approve-revision':
        // THE decisive measurement for «پذیرش بعدی محصول هم نباید مجموعهٔ
        // دسته‌ها را به یک دسته کاهش دهد». A vendor edit on a live product
        // becomes a pending revision; this is the manager agreeing to it, on
        // the one road that exists, and `ReviewProducts::firstId()` sits on it.
        $f = $fixture();
        $wcId = (int) ($f['products'][$which] ?? 0);
        $row = $rowFor($wcId);
        $productId = (int) ($row['id'] ?? 0);
        $revisions = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface::class);
        $pending = $productId > 0 ? $revisions->pendingFor($productId) : null;
        $say('which', $which);
        $say('revision', $pending === null ? '__none__' : (string) $pending->id);
        if ($pending === null) {
            $say('refused', 'no_pending_revision');
            break;
        }
        $say('revision_payload_category', (string) (($pending->payload['details']['categoryKey'] ?? null) ?? '__absent__'));
        $before = $termsOf($wcId);
        $say('terms_before', $before);
        $say('key_before', (string) ($row['category_key'] ?? '?'));
        /** @var ReviewProducts $review */
        $review = $c->get(ReviewProducts::class);
        /** @var StorefrontFieldsInterface $fields */
        $fields = $c->get(StorefrontFieldsInterface::class);
        $product = $products->find($productId);
        $seen = [];
        if ($product !== null && method_exists($fields, 'fingerprints')) {
            $seen = $fields->fingerprints($product);
        }
        $out = $review->approveRevision((int) $pending->id, $seen);
        $say('approve_code', (string) $out->code);
        $after = $termsOf($wcId);
        $say('terms_after', $after);
        $say('key_after', (string) ($rowFor($wcId)['category_key'] ?? '?'));
        $say('set_preserved', $sameSet($before, $after));
        $say('terms_lost', array_values(array_diff($before, $after)));
        $say('relationships_silently_removed', count(array_diff($before, $after)) > 0);
        break;

    case 'accept':
        // §4 — the destructive moment of `alpha.80`, re-measured here on a
        // PAIR (parent and child) rather than three independent terms.
        $f = $fixture();
        $wcId = (int) ($f['products'][$which] ?? 0);
        $row = $rowFor($wcId);
        $product = $row === null ? null : $products->find((int) $row['id']);
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        /** @var StorefrontFieldsInterface $fields */
        $fields = $c->get(StorefrontFieldsInterface::class);
        $before = $termsOf($wcId);
        $say('which', $which);
        $say('terms_before', $before);
        $say('comparison_state', $fields->comparisonState($product));
        $say('stored_category_key', (string) ($row['category_key'] ?? '?'));
        $outcome = $fields->acceptProposal($product, 'category');
        $say('accept_outcome', $outcome === null ? 'written' : $outcome);
        $after = $termsOf($wcId);
        $say('terms_after', $after);
        $say('set_preserved', $sameSet($before, $after));
        $say('terms_lost', array_values(array_diff($before, $after)));
        $say('relationships_silently_removed', count(array_diff($before, $after)) > 0);
        break;

    case 'project':
        // The projection on its own: does writing our mirror out replace the
        // storefront's set even when nobody accepted anything?
        $f = $fixture();
        $wcId = (int) ($f['products'][$which] ?? 0);
        $row = $rowFor($wcId);
        $product = $row === null ? null : $products->find((int) $row['id']);
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        $before = $termsOf($wcId);
        $say('which', $which);
        $say('terms_before', $before);
        $projector = $c->get(\Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface::class);
        $out = $projector->project($product);
        $say('project_ok', is_object($out) && property_exists($out, 'ok') ? ($out->ok ? 'yes' : 'no') : '__absent__');
        $say('project_code', is_object($out) && property_exists($out, 'code') ? (string) $out->code : '__absent__');
        $after = $termsOf($wcId);
        $say('terms_after', $after);
        $say('set_preserved', $sameSet($before, $after));
        $say('terms_lost', array_values(array_diff($before, $after)));
        break;

    case 'restore-terms':
        // Give back what a destructive verb spent. The fixture remembers the
        // set it built, so this is a restoration and not a guess.
        $f = $fixture();
        $want = [
            'pair' => [$f['terms']['trolley'], $f['terms']['trolley_steel']],
            'branches' => [$f['terms']['trolley'], $f['terms']['other'], $f['terms']['root']],
            'single' => [$f['terms']['other']],
            'deleted' => [$f['terms']['trolley'], $f['terms']['doomed']],
        ];
        foreach ($want as $name => $terms) {
            $wcId = (int) ($f['products'][$name] ?? 0);
            if ($wcId === 0) {
                continue;
            }
            $terms = array_values(array_filter(array_map('intval', $terms), static function (int $id): bool {
                return $id > 0 && get_term($id, 'product_cat') instanceof WP_Term;
            }));
            wp_set_object_terms($wcId, $terms, 'product_cat');
            $say('restored_' . $name, $termsOf($wcId));
        }
        break;

    case 'transport':
        // §5 — the form transport. `sanitize_key()` strips commas, so a set
        // posted as «207,208» does not arrive as two ids: it arrives as one
        // id nobody has, and WordPress will not say so.
        $sample = '207,208,209';
        $say('posted', $sample);
        $say('through_sanitize_key', sanitize_key($sample));
        $say('through_sanitize_text_field', sanitize_text_field($sample));
        $say('fused_into_one_id', sanitize_key($sample) !== '' && !str_contains(sanitize_key($sample), ','));
        break;

    case 'delete-term':
        // One term of a set deleted in WooCommerce. «دستهٔ حذف‌شده» is one of
        // the acceptance cases the owner listed.
        $f = $fixture();
        $doomed = (int) ($f['terms']['doomed'] ?? 0);
        $wcId = (int) ($f['products']['deleted'] ?? 0);
        $say('terms_before', $termsOf($wcId));
        if ($doomed > 0 && get_term($doomed, 'product_cat') instanceof WP_Term) {
            wp_delete_term($doomed, 'product_cat');
            $say('deleted_term', $doomed);
        } else {
            $say('deleted_term', '__already_gone__');
        }
        $say('terms_after', $termsOf($wcId));
        $say('stored_category_key', (string) ($rowFor($wcId)['category_key'] ?? '?'));
        break;

    case 'report':
        $f = $fixture();
        $say('vendor', $f['vendor']);
        $say('terms', $f['terms']);
        $say('products', $f['products']);
        $say('run', $f['run']);
        $say('waiting_total', $products->countImportedWithoutCategory());
        foreach ($f['products'] as $name => $wcId) {
            $say('now_' . $name . '_terms', $termsOf((int) $wcId));
            $say('now_' . $name . '_key', (string) ($rowFor((int) $wcId)['category_key'] ?? '__no_row__'));
        }
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
        $wpdb->query("DELETE FROM {$wpdb->prefix}tmc_products WHERE title LIKE 'ت۸۱ محصول %'");
        delete_option($FIXTURE);
        $say('reset', 'done');
        break;

    default:
        $say(
            'usage',
            'arrange|intake|blocker|plan|take <which>|edit <which>|accept <which>|'
            . 'project <which>|approve-revision <which>|transport|delete-term|report|reset'
        );
}
