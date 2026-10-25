<?php
/**
 * The fixture for this round's browser run — on the disposable site only.
 *
 * Makes an approved vendor, five `product_cat` terms and five imported rows
 * whose WooCommerce products carry real relationships: one category, three
 * categories, a Persian category, only the default term, and none at all.
 * That is the shape the preview card has to describe.
 *
 * ⛔ Gives back everything it spends (`alpha.45`): `reset` removes exactly the
 * user, posts, terms and rows it made, and nothing else. It never touches a
 * term or a product it did not create.
 *
 *   wp eval-file tools/alpha81-ui-state.php seed|report|reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\CategoryLinkPlan;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

// ⛔ Both halves, and the database check FIRST — written in the exact shape
// `DisposableOnlyToolsTest` measures from these bytes. The guard's own test
// caught this file with an `in_array()` spelling it could not recognise, which
// is the point of asserting the shape rather than the intent: a refusal the
// guard cannot see is a refusal nobody can rely on.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    echo "refused=1 reason=not_a_disposable_database\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    echo "refused=1 reason=not_a_local_site\n";
    return;
}

const FIXTURE = 'tmc_a81_ui';

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

$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class ($manager) implements CapabilityCheckerInterface {
    public function __construct(private int $id)
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
});

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
$command = (string) ($args[0] ?? 'report');
$state = static function (): array {
    $raw = get_option(FIXTURE, []);
    return is_array($raw) ? $raw + ['vendor' => 0, 'terms' => [], 'products' => [], 'rows' => []] : ['vendor' => 0, 'terms' => [], 'products' => [], 'rows' => []];
};

switch ($command) {
    case 'seed':
        $f = $state();

        // ONE approved vendor, through the documented road — never by writing
        // a status (`alpha.17`).
        // ⛔ Looked up by LOGIN first, not only through the stored option. A
        // run that died before `update_option()` leaves the account behind
        // with nothing pointing at it, and then every later seed answers
        // «that username already exists» — a true sentence about the
        // previous run reported as a failure of this one (`alpha.33`).
        $existing = get_user_by('login', 'a81vendor');
        if ($existing) {
            $f['vendor'] = (int) $existing->ID;
        }
        if ((int) $f['vendor'] === 0 || !get_userdata((int) $f['vendor'])) {
            $uid = wp_insert_user([
                'user_login' => 'a81vendor',
                'user_pass' => wp_generate_password(20),
                'user_email' => 'a81vendor@example.test',
                'role' => 'customer',
            ]);
            if (is_wp_error($uid)) {
                $say('error', $uid->get_error_message());
                return;
            }
            $f['vendor'] = (int) $uid;
        }
        // An APPROVED vendor, through `ReviewApplication` and never by
        // writing a status: the service is the only thing that may admit a
        // shop (`alpha.17`).
        /** @var VendorRepositoryInterface $vendors */
        $vendors = $c->get(VendorRepositoryInterface::class);
        if ($vendors->findApplicationByUser((int) $f['vendor']) === null) {
            $vendors->saveDraft((int) $f['vendor'], new ApplicantDetails(
                'فروشگاه ت۸۱',
                'شرکت ت۸۱',
                'a81@example.test',
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
        update_option(FIXTURE, $f);
        $say('vendor', $f['vendor']);
        $say('vendor_status', (string) ($vendors->findApplicationByUser((int) $f['vendor'])?->status->value ?? '__none__'));

        // Five terms, each with an explicit slug: `wp_insert_term()` dedupes
        // on the SLUG, so two terms sharing a name collapse into one without
        // it — which is the `alpha.80` fixture mistake this round had to fix.
        // ⛔ `root` FIRST. It was last, and every child was then created
        // with `parent => 0` because the root's id was not in the fixture
        // yet — so the tree was flat, every path was a bare leaf, and the
        // browser check for the full ancestry failed about a card that
        // renders ancestry correctly.
        $wanted = [
            'root' => 'ت۸۱ تجهیزات پزشکی',
            'injection' => 'ت۸۱ تزریق',
            'glue_a' => 'ت۸۱ چسب',
            'glue_b' => 'ت۸۱ چسب',
            'persian' => 'ت۸۱ پانسمان و زخم‌پوش',
        ];
        foreach ($wanted as $key => $name) {
            $slug = 'a81-' . str_replace('_', '-', $key);
            $term = get_term_by('slug', $slug, 'product_cat');
            if (!$term) {
                $made = wp_insert_term($name, 'product_cat', [
                    'slug' => $slug,
                    'parent' => $key === 'root' ? 0 : (int) ($f['terms']['root'] ?? 0),
                ]);
                if (is_wp_error($made)) {
                    $say('term_error_' . $key, $made->get_error_message());
                    continue;
                }
                $f['terms'][$key] = (int) $made['term_id'];
            } else {
                $f['terms'][$key] = (int) $term->term_id;
            }
            if ($key === 'root') {
                update_option(FIXTURE, $f);
            }
        }
        $say('terms', $f['terms']);

        $plan = [
            'one' => ['title' => 'ت۸۱ سرنگ', 'terms' => ['injection']],
            'many' => ['title' => 'ت۸۱ چسب سه‌کاره', 'terms' => ['injection', 'glue_a', 'glue_b']],
            'persian' => ['title' => 'ت۸۱ گاز استریل', 'terms' => ['persian']],
            'default' => ['title' => 'ت۸۱ ماسک', 'terms' => []],
            'none' => ['title' => 'ت۸۱ دستکش', 'terms' => []],
        ];
        $runId = 'a81-ui-' . gmdate('Ymd-His');
        foreach ($plan as $name => $spec) {
            if (($f['products'][$name] ?? 0) > 0 && get_post((int) $f['products'][$name])) {
                $say('wc_' . $name, $f['products'][$name]);
                // Printed on this path too: a report whose shape depends on
                // whether the fixture already existed is a report a caller
                // cannot read, and an absent key parses as «empty».
                clean_post_cache((int) $f['products'][$name]);
                $say(
                    'wc_' . $name . '_terms',
                    wp_get_object_terms((int) $f['products'][$name], 'product_cat', ['fields' => 'ids'])
                );
                continue;
            }
            $wc = new \WC_Product_Simple();
            $wc->set_name($spec['title']);
            $wc->set_status('publish');
            $wc->set_regular_price('150000');
            $wc->set_manage_stock(true);
            $wc->set_stock_quantity(5);
            $wcId = (int) $wc->save();
            $ids = [];
            foreach ($spec['terms'] as $termKey) {
                $ids[] = (int) ($f['terms'][$termKey] ?? 0);
            }
            // `set_category_ids([])` is where WooCommerce substitutes its own
            // default term — which is exactly the `default` case, and is why
            // `none` is given no relationship at all instead.
            if ($name !== 'none') {
                $wc->set_category_ids(array_values(array_filter($ids)));
                $wc->save();
            } else {
                // ⛔ The relationship row is removed DIRECTLY, and the reason
                // is a measurement rather than a preference:
                // `wp_set_object_terms($id, [], 'product_cat')` does not
                // leave this product without a category — a fresh read still
                // answered `[15]`, WooCommerce's `default_product_cat`. So on
                // WooCommerce a genuinely category-less product is not
                // reachable through ordinary saves at all, which is the same
                // substitution `alpha.80` measured at intake
                // (`row_none_category_key=15`).
                //
                // The state is still worth having: it is what a product looks
                // like once somebody deletes its only term, and
                // `CategoryLink::NONE` has to be distinguishable from
                // `DEFAULT_ONLY`.
                global $wpdb;
                $wpdb->query($wpdb->prepare(
                    "DELETE tr FROM {$wpdb->term_relationships} tr
                       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                      WHERE tr.object_id = %d AND tt.taxonomy = 'product_cat'",
                    $wcId
                ));
                clean_post_cache($wcId);
                clean_object_term_cache($wcId, 'product_cat');
            }
            $f['products'][$name] = $wcId;
            $say('wc_' . $name, $wcId);
            clean_post_cache($wcId);
            // Read cold: `wc_get_product()` is an instance cache, so reading
            // straight after a write reports what we sent rather than what is
            // there (`alpha.50`).
            $say('wc_' . $name . '_terms', wp_get_object_terms($wcId, 'product_cat', ['fields' => 'ids']));

            $rowId = $products->create(
                (int) $f['vendor'],
                new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                    title: $spec['title'],
                    sku: 'A81-' . $name,
                    priceMinor: 150000,
                    stock: 5
                ),
                \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Draft
            );
            $products->link($rowId, $wcId);
            $products->stampImportRun($rowId, $runId);
            $f['rows'][$name] = $rowId;
        }
        $f['run'] = $runId;
        update_option(FIXTURE, $f);
        $say('rows', $f['rows']);
        $say('run', $runId);
        break;

    case 'report':
        $f = $state();
        $say('vendor', $f['vendor']);
        $say('terms', $f['terms']);
        $say('products', $f['products']);
        $say('rows', $f['rows']);
        foreach ($f['rows'] as $name => $rowId) {
            $row = $products->find((int) $rowId);
            $say('key_' . $name, $row === null ? '__no_row__' : $row->details->categoryKey);
        }
        $plan = $c->get(CategoryLinkPlan::class)->plan();
        $say('available', $plan['available']);
        $say('scanned', $plan['scanned']);
        $say('counts', $plan['counts']);
        $say('groups', count($plan['groups']));
        break;

    case 'reset':
        $f = $state();
        global $wpdb;
        foreach ($f['rows'] as $rowId) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_products WHERE id = %d", (int) $rowId));
        }
        foreach ($f['products'] as $wcId) {
            if (get_post((int) $wcId)) {
                wp_delete_post((int) $wcId, true);
            }
        }
        foreach ($f['terms'] as $termId) {
            if (get_term((int) $termId, 'product_cat')) {
                wp_delete_term((int) $termId, 'product_cat');
            }
        }
        if ((int) $f['vendor'] > 0 && get_userdata((int) $f['vendor'])) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_vendor_applications WHERE user_id = %d", (int) $f['vendor']));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_vendor_profiles WHERE user_id = %d", (int) $f['vendor']));
            wp_delete_user((int) $f['vendor']);
        }
        delete_option(FIXTURE);
        $say('reset', 'done');
        break;

    default:
        $say('usage', 'seed|report|reset');
}
