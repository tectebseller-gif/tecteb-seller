<?php
/**
 * The states «اعلان قرمز بر اساس دیده‌نشده» has to be right in, on a real install.
 *
 * Three things this round needs that no browser run can set up for itself:
 *
 *  1. **Two managers.** The first thing the owner asked to be shown is that one
 *     manager reading does not clear the other's badge, and that needs a second
 *     real WordPress account with the review capability and nothing else.
 *  2. **A queue with a known size**, so «badge said N» can be checked against
 *     the number of things actually waiting rather than against itself.
 *  3. **A product with no WooCommerce post, a draft one and a published one**,
 *     because «مشاهدهٔ محصول» has three branches and a screenshot of one proves
 *     nothing about the other two.
 *
 * Everything goes through the plugin's own services — `ManageProducts` to
 * submit, `ReviewProducts` to decide — never an UPDATE behind the code's back.
 * That is the `alpha.17` rule: a fixture that writes a status column directly
 * produces a combination no real path produces, and then the screen is measured
 * for a site nobody has.
 *
 * It PRINTS what the browser run asserts against, so the numbers live in one
 * place (the `alpha.22` rule).
 *
 *   wp eval-file tools/review-badge-state.php report
 *   wp eval-file tools/review-badge-state.php seed
 *   wp eval-file tools/review-badge-state.php submit <product_id>
 *   wp eval-file tools/review-badge-state.php prepare <product_id>
 *   wp eval-file tools/review-badge-state.php propose <product_id>
 *   wp eval-file tools/review-badge-state.php forget <ana|babak|all>
 *   wp eval-file tools/review-badge-state.php marks <ana|babak>
 *   wp eval-file tools/review-badge-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it creates users, products and submissions. On the owner's
// site that is not a fixture, it is damage.
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
$argument = (string) ($args[1] ?? '');

/**
 * The second manager — an ADMINISTRATOR, and that is measured rather than tidy.
 *
 * The first attempt gave this account `subscriber` plus `tmc_review_products`,
 * which reads like the narrowest possible second reviewer. It never saw the
 * page: WooCommerce keeps any user without `edit_posts` out of wp-admin
 * entirely (`woocommerce_prevent_admin_access`), so the browser landed on
 * `/my-account/` and «Babak has no marks» was true about a redirect rather than
 * about this feature. Two administrators is also the STRICTER test of what the
 * owner asked: they share a role and every capability, so a badge that differs
 * between them can only be per USER.
 */
const TMC_BABAK_LOGIN = 'demo-reviewer';
const TMC_BABAK_PASS = 'demo-reviewer-2026';
/**
 * And somebody who is allowed into wp-admin but NOT allowed to review.
 *
 * An editor has `edit_posts`, so WooCommerce lets them in — which makes them the
 * only account on this install that actually tests the plugin's own capability
 * gate. A customer bounced by WooCommerce would pass «the page did not render»
 * for a reason that has nothing to do with our code.
 */
const TMC_OUTSIDER_LOGIN = 'demo-editor';
const TMC_OUTSIDER_PASS = 'demo-editor-2026';
const TMC_VENDOR_LOGIN = 'demo-vendor';

$c = Bootstrap::container();
/**
 * The first manager, named rather than found.
 *
 * `get_users(['role' => 'administrator', 'number' => 1])` looked like «the site
 * owner» and stopped being that the moment this tool gave the SECOND manager the
 * administrator role: the query returned id 28, both managers resolved to the
 * same person, and seven checks about «per manager» reported failures about a
 * feature that was working. Ana is `tmcowner`, by login.
 */
$ana = (int) (get_user_by('login', 'tmcowner')?->ID
    ?: (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC'])[0] ?? 1));
wp_set_current_user($ana);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return true;
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var ProductDecisionRepositoryInterface $decisions */
$decisions = $c->get(ProductDecisionRepositoryInterface::class);
/** @var ReviewSeen $seen */
$seen = $c->get(ReviewSeen::class);
/** @var ReviewSeenStoreInterface $store */
$store = $c->get(ReviewSeenStoreInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);
/** @var ProductRevisionRepositoryInterface $revisions */
$revisions = $c->get(ProductRevisionRepositoryInterface::class);

/** One account, made once, reused, and given the role it needs. */
$account = static function (string $login, string $pass, string $role, string $email): int {
    $user = get_user_by('login', $login);
    if ($user instanceof WP_User) {
        if (!in_array($role, (array) $user->roles, true)) {
            $user->set_role($role);
        }
        return (int) $user->ID;
    }
    $id = wp_create_user($login, $pass, $email);
    if (is_wp_error($id)) {
        return 0;
    }
    (new WP_User((int) $id))->set_role($role);
    return (int) $id;
};

$babak = static fn (): int => $account(TMC_BABAK_LOGIN, TMC_BABAK_PASS, 'administrator', 'reviewer@example.test');
/** The editor: in wp-admin, and with no marketplace capability of any kind. */
$outsider = static function () use ($account): int {
    $id = $account(TMC_OUTSIDER_LOGIN, TMC_OUTSIDER_PASS, 'editor', 'editor@example.test');
    if ($id > 0) {
        $user = new WP_User($id);
        foreach (Capabilities::all() as $capability) {
            $user->remove_cap($capability);
        }
    }
    return $id;
};

$vendorId = static function (): int {
    $user = get_user_by('login', TMC_VENDOR_LOGIN);
    return $user instanceof WP_User ? (int) $user->ID : 0;
};

$whoever = static function (string $name) use ($ana, $babak): int {
    return $name === 'babak' ? $babak() : $ana;
};

/** One submission, through both writes a real submission makes. */
$submit = static function (int $id) use ($products, $decisions, $vendorId): bool {
    $product = $products->find($id);
    if ($product === null) {
        return false;
    }
    if (!$products->updateStatus($id, ProductStatus::Submitted, '')) {
        return false;
    }
    return $decisions->record($id, $product->vendorUserId, $vendorId() ?: $product->vendorUserId, ProductDecision::SUBMITTED, '');
};

$report = static function () use ($products, $seen, $store, $ana, $babak, $outsider, $vendorId): void {
    $babakId = $babak();
    $waiting = $products->countAwaitingReview();
    printf("ana=%d babak=%d outsider=%d vendor=%d\n", $ana, $babakId, $outsider(), $vendorId());
    printf("waiting=%d unseen_ana=%d unseen_babak=%d\n", $waiting, $seen->unseenCount($ana), $seen->unseenCount($babakId));
    printf("marks_ana=%d marks_babak=%d\n", count($store->seenBy($ana)), count($store->seenBy($babakId)));
    // Which products are waiting, and under which token — the numbers the
    // browser run compares its screen against.
    $ids = [];
    foreach ($products->forManager(null, '', 200, 0) as $product) {
        $ids[] = $product->id;
    }
    foreach ($products->submissionsOf($ids) as $productId => $token) {
        printf("awaiting id=%d token=%s\n", $productId, $token);
    }
    // And the three «مشاهدهٔ محصول» cases, by id, so the run can open each.
    foreach ($products->forManager(null, '', 200, 0) as $product) {
        printf(
            "product id=%d status=%s wc=%s shop_status=%s title=%s\n",
            $product->id,
            $product->status->value,
            $product->isProjected() ? (string) $product->wcProductId : '-',
            $product->isProjected() ? (string) (get_post_status((int) $product->wcProductId) ?: 'gone') : '-',
            $product->details->title === '' ? '(empty)' : $product->details->title
        );
    }
};

switch ($command) {
    case 'seed':
        $vendor = $vendorId();
        if ($vendor <= 0) {
            echo "refused=1 reason=no_demo_vendor\n";
            return;
        }
        $babakId = $babak();
        if ($babakId <= 0) {
            echo "refused=1 reason=could_not_create_second_manager\n";
            return;
        }
        // Three submissions with real titles, and ONE with an empty title — the
        // placeholder «بدون عنوان — محصول #N» has to be visible on a real screen
        // in both the manager's list and the vendor's, and an untitled draft is
        // the only thing that shows it.
        $made = [];
        foreach ([
            'ماسک N95 پنج‌لایه',
            'دستکش نیتریل بدون پودر',
            'ترمومتر تماسی دیجیتال',
            '',
        ] as $title) {
            $id = $products->create(
                $vendor,
                new ProductDetails(
                    title: $title,
                    type: 'simple',
                    categoryKey: 'gloves',
                    brand: 'مدیکو',
                    shortDescription: 'نمونهٔ آزمایشی برای سنجش نشان اعلان.',
                    priceMinor: 480000,
                    sku: 'BADGE-' . (count($made) + 1) . '-' . wp_generate_password(4, false),
                    stock: 6
                ),
                ProductStatus::Draft
            );
            if ($id > 0 && $submit($id)) {
                $made[] = $id;
            }
        }
        // Printed in creation order, so the browser run can name «the untitled
        // one» and «the one to prepare» rather than picking whatever this
        // install has accumulated over eleven rounds.
        printf("seeded=%s seeded_untitled=%s\n", implode(',', $made), (string) ($made[3] ?? ''));
        $outsider();
        $report();
        break;

    case 'submit':
        $id = (int) $argument;
        printf("submitted=%s id=%d\n", $submit($id) ? 'yes' : 'no', $id);
        $report();
        break;

    case 'prepare':
        // The DRAFT branch of «مشاهدهٔ محصول»: a WooCommerce post that exists and
        // is not published. `SyncCatalog::prepare()` is the plugin's own path —
        // the same one the review screen's «آماده‌سازی» button uses — so this is
        // not a hand-made post that no real flow produces.
        $id = (int) $argument;
        $result = $catalog->prepare($id);
        // `OperationResult`, read as an object. The first run of this tool cast
        // it to a string and took PHP down with it — and an evidence script that
        // dies mid-run reports nothing about the thing it was measuring.
        printf("prepared id=%d ok=%s code=%s\n", $id, $result->ok ? 'yes' : 'no', $result->code);
        $report();
        break;

    case 'propose':
        // An unanswered proposal on a live product, so the «تغییرهای پیشنهادی
        // اعمال نشده‌اند» sentence has something real to be about.
        $id = (int) $argument;
        $product = $products->find($id);
        if ($product === null) {
            echo "refused=1 reason=no_such_product\n";
            return;
        }
        $revisionId = $revisions->create($id, $product->vendorUserId, [
            'details' => ['title' => $product->details->title . ' — نسخهٔ پیشنهادی'],
            'specs' => [],
            'images' => [],
            'main_image_id' => 0,
        ]);
        printf("proposed id=%d revision=%d\n", $id, $revisionId);
        $report();
        break;

    case 'forget':
        // What «هیچ‌کس هنوز ندیده» looks like, on demand: the marks go and the
        // products stay exactly where they were. A fixture that reset the queue
        // instead would be measuring a different site.
        $targets = $argument === 'all' ? ['ana', 'babak'] : [$argument === '' ? 'ana' : $argument];
        foreach ($targets as $name) {
            $id = $whoever($name);
            printf("forgot=%s id=%d ok=%s\n", $name, $id, $store->forget($id) ? 'yes' : 'no');
        }
        $report();
        break;

    case 'marks':
        $id = $whoever($argument === '' ? 'ana' : $argument);
        foreach ($store->seenBy($id) as $productId => $token) {
            printf("mark user=%d product=%d token=%s\n", $id, $productId, $token);
        }
        printf("marks=%d user=%d\n", count($store->seenBy($id)), $id);
        break;

    case 'reset':
        // Gives back everything this tool spends: the marks AND the products it
        // created. A fixture that leaves its own submissions behind makes the
        // next run's «waiting=4» a different number every time (`alpha.33`).
        $store->forget($ana);
        $store->forget($babak());
        $removed = [];
        foreach ($products->forManager(null, 'BADGE-', 200, 0) as $product) {
            if ($products->updateStatus($product->id, ProductStatus::Archived, 'پاک‌سازی ابزار شواهد')) {
                $removed[] = $product->id;
            }
        }
        printf("reset_marks=yes archived=%s\n", implode(',', $removed));
        $report();
        break;

    case 'report':
    default:
        $report();
        break;
}
