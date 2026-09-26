<?php
/**
 * A catalogue big enough to page through, on a disposable install.
 *
 * `alpha.32` rebuilt the manager's product list around a real `LIMIT`. Twenty
 * rows out of twenty-five prove the arithmetic; they do not prove that the
 * fourth page of a real catalogue holds the row a manager is looking for, that
 * a search reaches it, or that coming back from a product's own page lands on
 * the page it was opened from. That needs a catalogue, so this builds one —
 * «آزمون را با حداقل ۶۰ محصول آزمایشی در محیط یکبارمصرف انجام بده».
 *
 * It prints the numbers the browser run then asserts against, rather than
 * letting that run carry its own copy of them: a fixture and a test that each
 * know the expected total are two places for it to go stale, and the one that
 * goes stale silently is the test.
 *
 * One status is left deliberately EMPTY (`suspended`), because «شمارندهٔ صفر
 * کم‌رنگ‌تر باشد، اما گزینه همچنان قابل کلیک بماند» is a state that has to be
 * on the screen to be measured.
 *
 *   wp eval-file tools/catalogue-state.php seed [count]
 *   wp eval-file tools/catalogue-state.php report
 *   wp eval-file tools/catalogue-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it creates dozens of products, projects some of them into
// WooCommerce and deletes its own rows again. On the owner's site that is not a
// fixture, it is damage.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$command = (string) ($args[0] ?? 'report');
$count = max(1, (int) ($args[1] ?? 30));

$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
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

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var ProductRevisionRepositoryInterface $revisions */
$revisions = $c->get(ProductRevisionRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);
/** @var DatabaseInterface $db */
$db = $c->get(DatabaseInterface::class);

$table = $GLOBALS['wpdb']->prefix . 'tmc_products';
$marker = 'CAT-';

/** Rows this tool made, and no others: it deletes by ITS OWN sku prefix. */
$mine = static function () use ($db, $table, $marker): array {
    $rows = $db->getResults('SELECT id FROM `' . $table . '` WHERE sku LIKE %s', [$marker . '%']);
    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
};

/**
 * Everything that hangs off a product row goes with it.
 *
 * The first run of this tool deleted its products and left their proposals
 * behind, and the next run reported two unanswered proposals when it had made
 * one — a true count about rows whose product no longer existed. CLAUDE.md has
 * carried this rule since `alpha.10`; it applies to every table that hangs off
 * a product, not only off an order.
 */
$forget = static function (array $ids) use ($db): void {
    $prefix = $GLOBALS['wpdb']->prefix;
    foreach ($ids as $id) {
        foreach ([
            'tmc_product_revisions' => 'product_id',
            'tmc_product_specs' => 'product_id',
            'tmc_product_images' => 'product_id',
            'tmc_product_decisions' => 'product_id',
            'tmc_products' => 'id',
        ] as $suffix => $column) {
            $db->execute('DELETE FROM `' . $prefix . $suffix . '` WHERE ' . $column . ' = %d', [(int) $id]);
        }
    }
};

$counts = static function () use ($products): array {
    $out = [];
    foreach (ProductStatus::cases() as $status) {
        $out[$status->value] = $products->countForManager($status);
    }
    return $out;
};

$report = static function (string $stage) use ($products, $counts, $revisions, $mine): void {
    $all = $products->countForManager();
    $line = 'stage=' . $stage . ' total=' . $all . ' pages_at_20=' . (int) ceil($all / 20);
    foreach ($counts() as $status => $n) {
        $line .= ' ' . $status . '=' . $n;
    }
    echo $line . ' pending_revisions=' . $revisions->countPending() . "\n";

    // The landmark the browser run searches for: the lowest of this tool's own
    // ids, which the default order (last change, then id) puts on the last of
    // its pages. Printed by `report` as well as by `seed`, so the run can read
    // it without seeding again — a fixture that re-seeds itself halfway through
    // a measurement invalidates every number taken before it.
    $ids = $mine();
    $landmark = $ids === [] ? null : $products->find(min($ids));
    echo 'off_page_one_sku=' . ($landmark?->details->sku ?: '-')
        . ' off_page_one_id=' . ($landmark?->id ?? 0)
        . ' seeded_rows=' . count($ids) . "\n";
};

if ($command === 'reset') {
    $ids = $mine();
    $forget($ids);
    echo 'removed=' . count($ids) . "\n";
    $report('after_reset');
    return;
}

if ($command === 'report') {
    $report('report');
    return;
}

if ($command !== 'seed') {
    echo "usage: seed [count] | report | reset\n";
    return;
}

// Seeding is repeatable: its own rows go first, so running it twice does not
// double the total the browser run is about to assert.
$forget($mine());
// An earlier run of this tool deleted products and left their proposals
// behind, so the count said two where one had been made. Orphans are swept
// once, by the only definition that cannot delete somebody else's row: a
// proposal whose product is gone.
$db->execute(
    'DELETE FROM `' . $GLOBALS['wpdb']->prefix . 'tmc_product_revisions`'
        . ' WHERE product_id NOT IN (SELECT id FROM `' . $table . '`)'
);

$vendor = (int) (get_users(['search' => 'demo-vendor', 'number' => 1, 'fields' => 'ID'])[0] ?? $manager);
$images = array_map('intval', get_posts([
    'post_type' => 'attachment',
    'post_mime_type' => 'image',
    'numberposts' => 3,
    'fields' => 'ids',
    'orderby' => 'ID',
    'order' => 'ASC',
]));
$images = array_values(array_filter($images, static fn (int $id): bool => wp_get_attachment_image_url($id, 'thumbnail') !== false));

// Every status but `suspended`: an empty chip is one of the things this run has
// to show.
$cycle = [
    ProductStatus::Draft,
    ProductStatus::Submitted,
    ProductStatus::Published,
    ProductStatus::ChangesRequested,
    ProductStatus::Archived,
];
$brands = ['تک‌طب', 'مِدپلاس', 'آریامد', 'سلامت‌گستر'];

// A REAL `product_cat` term id, because since `alpha.25` the category is
// WooCommerce's taxonomy and a word typed here is not a category. Without it
// `prepare()` correctly refuses, and the WooCommerce column would then read
// «هنوز ساخته نشده» for every seeded row — a true sentence about the wrong
// fixture.
$category = (string) ($args[2] ?? '');
if ($category === '') {
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 5, 'fields' => 'ids']);
    $category = (string) (is_array($terms) ? ($terms[0] ?? '') : '');
}

$made = [];
for ($n = 1; $n <= $count; $n++) {
    $status = $cycle[($n - 1) % count($cycle)];
    $id = $products->create(
        $vendor,
        new ProductDetails(
            title: sprintf('کالای فهرست %03d', $n),
            categoryKey: $category,
            brand: $brands[($n - 1) % count($brands)],
            shortDescription: 'ردیف نمونه برای سنجش صفحه‌بندی فهرست مدیر.',
            priceMinor: 1000000 + ($n * 1000),
            sku: sprintf('%s%03d', $marker, $n),
            stock: 5 + $n
        ),
        $status
    );
    if ($id === 0) {
        echo "failed=create n={$n}\n";
        return;
    }
    // A picture on the first few rows, so the thumbnail column is measured
    // against a real attachment rather than against an empty box.
    if ($images !== [] && $n <= 6) {
        $products->saveImages($id, [$images[($n - 1) % count($images)]], $images[($n - 1) % count($images)]);
    }
    $made[] = ['id' => $id, 'status' => $status->value, 'sku' => sprintf('%s%03d', $marker, $n)];
}

// Two published rows get a storefront so the WooCommerce column and the editor
// link on the product's own page have something real to say.
$projected = 0;
$refused = '';
foreach ($made as $row) {
    if ($row['status'] !== ProductStatus::Published->value || $projected >= 2) {
        continue;
    }
    $result = $catalog->prepare($row['id']);
    if (!$result->ok && $result->code === 'already_published') {
        // `prepare()` is for a product that is NOT live yet; one that already
        // is goes through the path that publishes it.
        $result = $catalog->publish($row['id']);
    }
    if ($result->ok) {
        $projected++;
        continue;
    }
    // Named, not swallowed: a fixture that reports «0 projected» without saying
    // why is a fixture nobody can fix.
    $refused = $result->code;
}

// One unanswered proposal, on a published product: the list has to mark it and
// offer it as a filter of its own.
$proposal = 0;
foreach ($made as $row) {
    if ($row['status'] !== ProductStatus::Published->value) {
        continue;
    }
    $proposal = $revisions->create($row['id'], $vendor, [
        'details' => ['title' => $row['sku'] . ' — عنوان پیشنهادی فروشنده'],
    ]) > 0 ? $row['id'] : 0;
    break;
}

$all = $products->countForManager();
$perPage = 20;
$pages = (int) ceil($all / $perPage);

// The landmark the search has to reach: the row the default order puts last,
// which is the lowest id in the whole table — off page one by construction as
// long as there is more than one page.
$oldest = $products->forManager(null, '', 1, max(0, $all - 1));
$newest = $products->forManager(null, '', 1, 0);

echo 'category=' . $category . "\n";
echo 'seeded=' . count($made) . ' vendor=' . $vendor . ' images=' . count($images)
    . ' projected=' . $projected . ($refused !== '' ? ' projection_refused=' . $refused : '')
    . ' proposal_product=' . $proposal . "\n";
echo 'first_row_id=' . ($newest[0]->id ?? 0) . ' last_row_id=' . ($oldest[0]->id ?? 0)
    . ' last_row_sku=' . ($oldest[0]->details->sku !== '' ? $oldest[0]->details->sku : '-')
    . ' last_row_title=' . str_replace(' ', '_', $oldest[0]->details->title ?? '-') . "\n";
$report('after_seed');
echo 'pages_at_20=' . $pages . ' per_page_choices=20,50,100' . "\n";
