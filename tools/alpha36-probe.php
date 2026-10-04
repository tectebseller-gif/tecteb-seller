<?php
// No `declare(strict_types=1)`: `wp eval-file` wraps the file in `eval()`, where
// a strict-types declaration is not the first statement of a script and PHP
// refuses to compile it. Every tool in this directory is written the same way.

/**
 * The three notification defects, MEASURED on whichever build is installed.
 *
 * One file, run twice by `tools/alpha36-reproduction.sh`: once with the plugin
 * directory holding the bytes that shipped as `alpha.36`, and once with
 * `alpha.37`. Each probe prints the same keys either way, so the two runs can
 * be put side by side and the difference IS the evidence. The script compares
 * them; nothing here asserts.
 *
 * **Why a probe and not the round's PHPUnit tests.** The new tests name classes
 * `alpha.36` does not have — `DbReviewSeenStore`, `M0021ReviewSeen`,
 * `ReviewQueueSql`, `findWithSubmission()`, `prepare()`. Run against the old
 * tree they would fatal at load, zero tests would run, and every «reproduced»
 * line would be reporting that nothing ran (`alpha.32`'s rule). So each probe
 * is written against the API that build HAS, discovered at runtime, and
 * measures the behaviour rather than the shape.
 *
 * Usage, through `wp eval-file`:
 *
 *     wp eval-file tools/alpha36-probe.php badge
 *     wp eval-file tools/alpha36-probe.php snapshot
 *     wp eval-file tools/alpha36-probe.php cap [count]
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it creates products, submissions and view marks. On the
// owner's site that is not a probe, it is damage.
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

$argv = $args ?? [];
$command = (string) ($argv[0] ?? 'badge');
$argument = (string) ($argv[1] ?? '');

$c = Bootstrap::container();
$products = $c->get(ProductRepositoryInterface::class);
$revisions = $c->get(ProductRevisionRepositoryInterface::class);
$decisions = $c->get(ProductDecisionRepositoryInterface::class);
$seen = $c->get(ReviewSeen::class);
$store = $c->get(ReviewSeenStoreInterface::class);
$db = $c->get(\Tecteb\Marketplace\Contracts\DatabaseInterface::class);

// The manager by LOGIN, never by «the first administrator»: that query returns
// a different person the day somebody else is given the role (`alpha.36`).
$manager = (int) (get_user_by('login', 'tmcowner')?->ID ?? 0);
$vendor = (int) (get_user_by('login', 'demo-vendor')?->ID ?? 0);
if ($manager <= 0 || $vendor <= 0) {
    echo "refused=1 reason=demo_accounts_missing\n";
    return;
}

/** Every mark this manager holds, through whichever reader the build has. */
$marksOf = static function (int $userId) use ($store, $products): array {
    if (method_exists($store, 'seenBy')) {           // alpha.36: one blob
        return $store->seenBy($userId);
    }
    $ids = [];                                       // alpha.37: bounded read
    foreach ($products->forManager(null, '', 2000, 0) as $product) {
        $ids[] = $product->id;
    }
    return $store->marksFor($userId, $ids);
};

$submit = static function (int $id) use ($products, $decisions, $vendor): bool {
    return $products->updateStatus($id, ProductStatus::Submitted, '')
        && $decisions->record($id, $vendor, $vendor, ProductDecision::SUBMITTED, '');
};

$make = static function (string $title) use ($products, $vendor, $submit): int {
    $id = $products->create(
        $vendor,
        new ProductDetails(
            title: $title,
            type: 'simple',
            categoryKey: 'gloves',
            priceMinor: 100000,
            sku: 'PROBE-' . wp_generate_password(6, false),
            stock: 1
        ),
        ProductStatus::Draft
    );
    return $id > 0 && $submit($id) ? $id : 0;
};

switch ($command) {
    case 'badge':
        // DEFECT 1 — «عدد اعلان باید در همان صفحه به‌روز شود».
        //
        // Measured in the order `wp-admin/admin.php` runs things, without a
        // browser: the number the MENU would print is built on `admin_menu`,
        // and the view is recorded by the page. So: build the menu, read the
        // label core would print, let the page run, and read the label again.
        //
        // On `alpha.36` the page's work happens in the render callback, which
        // core calls AFTER `admin-header.php` has printed the menu — so the
        // first label is what the manager sees and it is the number from
        // before their visit. On `alpha.37` the page runs on `load-{$hook}`,
        // which is before the header, and the label is repainted there.
        $store->forget($manager);
        wp_set_current_user($manager);
        $before = $seen->unseenCount($manager);

        // `wp-admin/menu.php` creates these; WP-CLI never loads it, and
        // `add_submenu_page()` then warns about iterating null. Initialised
        // here so the probe measures the plugin and not a missing include.
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        // The queue itself, in one page. The DEFAULT list is every product
        // sorted by last change, and on this install the twenty most recent
        // are published ones — so «the count did not move» was true of a page
        // that showed nothing waiting. Measured: a probe that opens the wrong
        // page reports the wrong thing about the right code.
        $_GET['page'] = 'tmc-product-review';
        $_GET['product'] = '';
        $_GET['status'] = 'submitted';
        $_GET['per_page'] = '100';
        $_REQUEST = $_GET;
        do_action('admin_menu');
        $labelAtMenuTime = tmc_probe_submenu_label();

        // The hook suffix from CORE's own function, which is what
        // `add_submenu_page()` used a moment ago. Writing the string by hand
        // gave `load-tecteb-marketplace_page_…` — a stub's convention, not
        // WordPress's — and the probe then fired a hook nothing listens to and
        // reported that the page had changed nothing.
        $hook = get_plugin_page_hookname('tmc-product-review', 'tmc-dashboard');
        printf("badge_hook=%s\n", $hook);
        do_action('load-' . $hook);
        $labelBeforeHeader = tmc_probe_submenu_label();   // what the header prints
        ob_start();
        do_action($hook);
        $html = (string) ob_get_clean();
        $labelAfterRender = tmc_probe_submenu_label();

        printf(
            "badge before=%d after=%d menu_label=%s header_label=%s render_label=%s page_rendered=%s\n",
            $before,
            $seen->unseenCount($manager),
            tmc_probe_number($labelAtMenuTime),
            tmc_probe_number($labelBeforeHeader),
            tmc_probe_number($labelAfterRender),
            str_contains($html, 'tmc-admin') ? 'yes' : 'no'
        );
        break;

    case 'snapshot':
        // DEFECT 2 — the detail page's reads, in the page's own order, with a
        // resubmission landing in the middle.
        //
        // Not a simulation of the page: the SAME methods the page calls, in
        // the same order, chosen at runtime from what this build has.
        // `alpha.36` reads the product (`find`), then the proposal
        // (`pendingFor`), then the token (`submissionsOf`) — three statements,
        // and the gap is between the first and the last. `alpha.37` reads all
        // three in one (`findWithSubmission`), so there is no gap to land in.
        $id = $make('PROBE عنوان نخست');
        if ($id <= 0) {
            echo "probe=snapshot refused=1 reason=could_not_seed\n";
            break;
        }
        $store->forget($manager);
        $single = method_exists($products, 'findWithSubmission');

        if ($single) {
            $shot = $products->findWithSubmission($id);
            $contentTitle = (string) ($shot['product']->details->title ?? '');
            $interfere = true;
        } else {
            $product = $products->find($id);
            $contentTitle = (string) ($product?->details->title ?? '');
            $revisions->pendingFor($id);              // the page's second read
            $interfere = true;
        }

        // The vendor edits and resubmits, between the reads above and the
        // token read below. Written straight to the column: this is the
        // CONCURRENT editor, and it carries no form stamp to pass the
        // optimistic guard with.
        if ($interfere) {
            $db->execute(
                'UPDATE `' . $db->prefix() . 'tmc_products` SET title = %s WHERE id = %d',
                ['PROBE عنوان دوم', $id]
            );
            $submit($id);
        }

        $token = $single
            ? (string) ($shot['submission'] ?? '')
            : (string) ($products->submissionsOf([$id])[$id] ?? '');
        $seen->markSeen($manager, [$id => $token]);
        $recorded = (string) ($marksOf($manager)[$id] ?? '');
        $current = $single
            ? (string) ($products->findWithSubmission($id)['submission'] ?? '')
            : (string) ($products->submissionsOf([$id])[$id] ?? '');

        // `this_seen` rather than the global count: the install has other
        // unseen products and the total says nothing about THIS row. The rule
        // is the one the count itself applies — a stored identity equal to the
        // current one is «seen» — applied to one product.
        printf(
            "snapshot reads=%s content=%s recorded=%s this_seen=%s current=%s unseen_total=%d\n",
            $single ? 'one' : 'three',
            $contentTitle === 'PROBE عنوان نخست' ? 'old' : 'new',
            $recorded === $current ? 'current' : 'older',
            $recorded === $current ? 'yes' : 'no',
            $current,
            $seen->unseenCount($manager)
        );
        // Give the row back.
        $products->updateStatus($id, ProductStatus::Draft, '');
        $products->deleteDraft($id);
        break;

    case 'cap':
        // DEFECT 3 — a queue bigger than the cap cannot reach nought.
        //
        // Recorded in pages of a hundred, as the list actually draws them, and
        // then the count is asked. On `alpha.36` the blob keeps five hundred
        // marks and the number cannot go below the overflow however much the
        // manager reads.
        $want = max(1, (int) ($argument === '' ? 600 : $argument));
        $made = [];
        for ($i = 1; $i <= $want; $i++) {
            $id = $make('PROBECAP-' . $i);
            if ($id > 0) {
                $made[] = $id;
            }
        }
        $store->forget($manager);
        $queue = $products->countAwaitingReview();
        $start = $seen->unseenCount($manager);
        // EVERY product, not just the ones this probe seeded: the install has
        // its own queue, and «the badge reached nought» is only a claim about
        // the whole of it. Paged a hundred at a time, which is what the list
        // draws with — a single `markSeen()` of six hundred tokens is a
        // scenario no manager can produce.
        $all = [];
        for ($offset = 0; ; $offset += 200) {
            $page = $products->forManager(null, '', 200, $offset);
            if ($page === []) {
                break;
            }
            foreach ($page as $product) {
                $all[] = $product->id;
            }
        }
        foreach (array_chunk($all, 100) as $chunk) {
            $seen->markSeen($manager, $products->submissionsOf($chunk));
        }
        printf(
            "cap seeded=%d queue=%d unseen_before=%d unseen_after=%d marks=%d\n",
            count($made),
            $queue,
            $start,
            $seen->unseenCount($manager),
            count($marksOf($manager))
        );
        foreach ($made as $id) {
            $products->updateStatus($id, ProductStatus::Draft, '');
            $products->deleteDraft($id);
        }
        // The marks go too. `forget()` is the build's own method and covers
        // both shapes — the blob on `alpha.36` and the rows on `alpha.37` —
        // so the probe does not have to know which table it is cleaning.
        $store->forget($manager);
        printf("cap cleaned waiting=%d\n", $products->countAwaitingReview());
        break;

    default:
        echo "usage: badge|snapshot|cap [count]\n";
}

/** The menu label core would print for the review page, out of `$submenu`. */
function tmc_probe_submenu_label(): string
{
    foreach ((array) ($GLOBALS['submenu']['tmc-dashboard'] ?? []) as $row) {
        if (is_array($row) && (string) ($row[2] ?? '') === 'tmc-product-review') {
            return (string) ($row[0] ?? '');
        }
    }
    return '';
}

/** The Latin number inside a bubble, or `none`. */
function tmc_probe_number(string $label): string
{
    return preg_match('/count-(\d+)/', $label, $m) === 1 ? $m[1] : 'none';
}
