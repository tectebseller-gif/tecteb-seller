<?php
/**
 * «ویرایش مدیر ← پیشنهاد فروشنده ← تأیید» — the whole cycle, measured.
 *
 * The defect the owner named: `WooCommerceProjector::project()` rewrote the
 * title, the short description and the full description on every run, so a
 * manager's correction in WooCommerce disappeared on the vendor's next save
 * and nothing recorded the loss. The audit trail said «projected».
 *
 * A unit test cannot answer this. The question is about what WooCommerce
 * KEEPS — it runs its own filters on the way in, and a comparison against
 * what we sent rather than what came back reports every product as
 * manager-edited. So this runs on a real WooCommerce, in one PHP pass, and
 * every number it prints is read back from the product rather than from the
 * value that was handed over.
 *
 * Six stages, each printed as one line the shell can assert on:
 *
 *   project   the marketplace writes the product and stamps what it wrote
 *   edit      a manager edits the title and the short description in
 *             WooCommerce, exactly as the editor would
 *   resave    the vendor saves again — the manager's text must survive and
 *             the vendor's must be held as a proposal
 *   keep      the manager says «my version stays»: the marketplace stops
 *             writing the field AND the product record is brought into line,
 *             so there are not two versions of the same product
 *   accept    on the other field, the vendor's proposal wins and the field
 *             belongs to the marketplace again
 *   prepare   a product that is NOT approved gets a storefront row, and that
 *             row must be a draft — opening an editor must not publish
 *
 *   wp eval-file tools/ownership-state.php run
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it edits a product's title in WooCommerce and moves rows
// between statuses. On the owner's site that is not a fixture, it is damage.
// Two independent facts, the same pair every other tool here trusts — except
// that this one names BOTH disposable databases, because the clean-install
// demo is a second one and the evidence for this phase lives there.
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

$say = static function (string $stage, bool $ok, array $fields): void {
    $line = 'stage=' . $stage . ' ok=' . ($ok ? 'true' : 'false');
    foreach ($fields as $key => $value) {
        $line .= ' ' . $key . '=' . str_replace(' ', '_', (string) $value);
    }
    echo $line . "\n";
};

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);
/** @var ReviewProducts $review */
$review = $c->get(ReviewProducts::class);
/** @var StorefrontFieldsInterface|null $storefront */
$storefront = $c->get(StorefrontFieldsInterface::class);

if (!$catalog->isAvailable() || $storefront === null) {
    $say('woocommerce', false, ['reason' => 'not_available']);
    return;
}

$published = $products->inStatus(ProductStatus::Published, 20);
if ($published === []) {
    $say('fixture', false, ['reason' => 'no_published_product']);
    return;
}
// The SAME product every run, chosen by the lowest id rather than by
// whatever order the query happened to return. Two runs that pick different
// rows are two different measurements wearing one name — and the first
// falsification run did exactly that, because a status change in one stage
// reordered the list for the next run.
usort($published, static fn ($a, $b): int => $a->id <=> $b->id);
$product = $published[0];

// And the ownership state it spends is given back before it is spent again.
// A fixture that leaves a decision behind measures the LAST run on the
// second go: `keep` sets «the manager owns this field», and a later run then
// finds a field nobody is allowed to write and reports a guard failure about
// a guard that is working.
$reset = static function (int $wcId): int {
    if ($wcId <= 0) {
        return 0;
    }
    $cleared = 0;
    foreach (ProjectedFieldOwnership::FIELDS as $field) {
        foreach ([
            ProjectedFieldOwnership::MANAGER_PREFIX,
            ProjectedFieldOwnership::PENDING_PREFIX,
            ProjectedFieldOwnership::STAMP_PREFIX,
        ] as $prefix) {
            if (delete_post_meta($wcId, $prefix . $field)) {
                $cleared++;
            }
        }
    }
    return $cleared;
};
$cleared = $reset((int) ($product->wcProductId ?? 0));

// Clearing the metas leaves the product looking like one an older version
// projected — which is a real state, and the one `legacy-upgrade-check.sh`
// exists to measure. It is NOT the state this run is about: everything below
// is about a product the marketplace demonstrably owns, so the fixture has
// to establish that ownership before the manager touches anything.
//
// It is established the way a manager would establish it — through the same
// `accept` the review screen calls — rather than by writing stamps behind the
// code's back. A fixture that reaches around the thing it is testing is a
// fixture that can pass while the thing is broken.
$catalog->publish($product->id);
$claimed = 0;
foreach (ProjectedFieldOwnership::FIELDS as $field) {
    if ($review->resolveField($product->id, $field, 'accept')->ok) {
        $claimed++;
    }
}
$catalog->publish($product->id);
$say('reset', $claimed === count(ProjectedFieldOwnership::FIELDS), [
    'product' => $product->id,
    'cleared' => $cleared,
    'claimed' => $claimed,
]);

// ---------------------------------------------------------------- project
$projected = $catalog->publish($product->id);
$product = $products->find($product->id);
$wcId = (int) ($product->wcProductId ?? 0);
$say('project', $projected->ok && $wcId > 0, [
    'product' => $product->id,
    'wc' => $wcId,
    'code' => $projected->code,
]);
if ($wcId <= 0) {
    return;
}

$read = static function (int $wcId, string $field): string {
    $wcProduct = wc_get_product($wcId);
    if (!$wcProduct instanceof WC_Product) {
        return '';
    }
    return $field === 'title' ? (string) $wcProduct->get_name() : (string) $wcProduct->get_short_description();
};

// ------------------------------------------------------------------- edit
//
// What a manager does in the WooCommerce editor. Through `WC_Product`, not a
// direct row write: the editor's own save path runs the same filters, and a
// fixture that skipped them would be comparing values the real thing never
// produces.
$MANAGER_TITLE = 'عنوان ویرایش‌شدهٔ مدیر ' . gmdate('His');
$MANAGER_SHORT = 'توضیح کوتاهی که مدیر نوشته است';
$wcProduct = wc_get_product($wcId);
$wcProduct->set_name($MANAGER_TITLE);
$wcProduct->set_short_description($MANAGER_SHORT);
$wcProduct->save();
$say('edit', $read($wcId, 'title') === $MANAGER_TITLE, [
    'title' => mb_substr($read($wcId, 'title'), 0, 24),
]);

// ----------------------------------------------------------------- resave
//
// The vendor saves. Before `alpha.26` this line alone destroyed the two
// values above, and the only trace was a successful projection.
$vendorTitle = 'عنوان تازهٔ فروشنده ' . gmdate('His');
$products->updateDetails(
    $product->id,
    $product->details->with(['title' => $vendorTitle]),
    $product->rowVersion
);
$catalog->publish($product->id);

$titleNow = $read($wcId, 'title');
$pendingTitle = (string) get_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'title', true);
$say('resave', $titleNow === $MANAGER_TITLE && $pendingTitle === $vendorTitle, [
    'storefront' => mb_substr($titleNow, 0, 24),
    'pending' => mb_substr($pendingTitle, 0, 24),
    'survived' => $titleNow === $MANAGER_TITLE ? 'yes' : 'no',
]);

// The review screen has to be able to SEE this, not just the projector.
$product = $products->find($product->id);
$fields = $storefront->compare($product);
$titleField = null;
foreach ($fields as $field) {
    if ($field->key === 'title') {
        $titleField = $field;
    }
}
$say('compare', $titleField !== null
    && $titleField->owner === StorefrontField::OWNER_MANAGER
    && $titleField->needsDecision(), [
    'owner' => $titleField?->owner ?? '-',
    'needs_decision' => $titleField !== null && $titleField->needsDecision() ? 'yes' : 'no',
]);

// ------------------------------------------------------------------- keep
$kept = $review->resolveField($product->id, 'title', 'keep');
$product = $products->find($product->id);
$say('keep', $kept->ok
    && $read($wcId, 'title') === $MANAGER_TITLE
    // The record agrees with the shop now. This is the owner's constraint:
    // «راه‌حل نباید به دو نسخهٔ ناسازگار از اطلاعات محصول منجر شود».
    && $product->details->title === $MANAGER_TITLE, [
    'code' => $kept->code,
    'storefront' => mb_substr($read($wcId, 'title'), 0, 24),
    'record' => mb_substr($product->details->title, 0, 24),
    'one_version' => $product->details->title === $read($wcId, 'title') ? 'yes' : 'no',
]);

// And it stays kept: another projection must not undo the decision.
$catalog->publish($product->id);
$say('keep_survives_projection', $read($wcId, 'title') === $MANAGER_TITLE, [
    'storefront' => mb_substr($read($wcId, 'title'), 0, 24),
    'pending' => (string) get_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'title', true) === ''
        ? 'none'
        : 'still_asking',
]);

// ----------------------------------------------------------------- accept
//
// The other field, decided the other way. The vendor's short description was
// held back by the manager's edit; accepting it writes it and hands the field
// back to the marketplace.
$product = $products->find($product->id);
$vendorShort = 'توضیح کوتاه پیشنهادی فروشنده ' . gmdate('His');
$products->updateDetails(
    $product->id,
    $product->details->with(['shortDescription' => $vendorShort]),
    $product->rowVersion
);
$catalog->publish($product->id);
$heldShort = (string) get_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'short_description', true);
$say('hold_short', $heldShort === $vendorShort && $read($wcId, 'short') === $MANAGER_SHORT, [
    'pending' => mb_substr($heldShort, 0, 24),
]);

$product = $products->find($product->id);
$accepted = $review->resolveField($product->id, 'short_description', 'accept');
$say('accept', $accepted->ok && $read($wcId, 'short') === $vendorShort, [
    'code' => $accepted->code,
    'storefront' => mb_substr($read($wcId, 'short'), 0, 24),
    'pending_cleared' => (string) get_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'short_description', true) === ''
        ? 'yes'
        : 'no',
]);

// Back in the marketplace's hands: the next vendor save must land normally.
$product = $products->find($product->id);
$nextShort = 'توضیح کوتاه بعدی فروشنده ' . gmdate('His');
$products->updateDetails(
    $product->id,
    $product->details->with(['shortDescription' => $nextShort]),
    $product->rowVersion
);
$catalog->publish($product->id);
$say('accept_returns_the_field', $read($wcId, 'short') === $nextShort, [
    'storefront' => mb_substr($read($wcId, 'short'), 0, 24),
]);

// --------------------------------------------------------------- category
//
// The category and the pictures follow the same rule, and the category is the
// one that would be invisible: nobody notices a product quietly moving branch
// until the shop's menu stops listing it.
$product = $products->find($product->id);
$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 5]);
$otherTerm = 0;
foreach ($terms as $term) {
    if ($term instanceof WP_Term && (string) $term->term_id !== $product->details->categoryKey) {
        $otherTerm = (int) $term->term_id;
        break;
    }
}
if ($otherTerm > 0) {
    wp_set_object_terms($wcId, [$otherTerm], 'product_cat');
    clean_post_cache($wcId);
    $catalog->publish($product->id);
    $after = array_map('intval', wc_get_product($wcId)->get_category_ids());
    $say('category_edit_survives', in_array($otherTerm, $after, true), [
        'moved_to' => $otherTerm,
        'now' => implode('|', $after),
    ]);
}

// ----------------------------------------------------------------- render
//
// The review page, rendered with a REAL row in the queue.
//
// `alpha.21` learned this the hard way: a page that is only ever rendered
// against an empty table says nothing about rendering one row, and the audit
// screen went three versions giving a fatal on its first real row because
// the only test that opened it opened it empty. So a product is moved into
// the queue first, and the page is rendered with it there.
$product = $products->find($product->id);
$products->updateStatus($product->id, ProductStatus::Submitted, '');
ob_start();
try {
    (new Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage($c))->render();
    $html = (string) ob_get_clean();
    $rendered = true;
} catch (Throwable $e) {
    ob_end_clean();
    $html = '';
    $rendered = false;
    $say('render_error', false, ['message' => str_replace(' ', '_', $e->getMessage())]);
}
$product = $products->find($product->id);
$hasImage = str_contains($html, '<img src=') && str_contains($html, 'tmc-review__gallery');
$hasPath = $product->details->categoryKey !== ''
    && str_contains($html, '›');
$say('render', $rendered && $hasImage && $hasPath, [
    'bytes' => strlen($html),
    'gallery' => $hasImage ? 'yes' : 'no',
    'category_path' => $hasPath ? 'yes' : 'no',
    'prepare_button' => str_contains($html, 'value="prepare"') ? 'yes' : 'n/a',
    'editor_link' => str_contains($html, 'post.php?post=') ? 'yes' : 'no',
    // The thing the owner said must NOT be the whole answer.
    'bare_counter_only' => str_contains($html, 'tmc-review__gallery') ? 'no' : 'yes',
]);
$products->updateStatus($product->id, ProductStatus::Published, '');

// ---------------------------------------------------------------- pictures
//
// The two edits a sorted id list could not see, on a product that IS stamped
// — so this is about the comparison rather than about legacy data.
$product = $products->find($product->id);
$arrangement = static function (int $wcId): string {
    $p = wc_get_product($wcId);
    return $p instanceof WC_Product
        ? 'main:' . (int) $p->get_image_id() . '|gallery:' . implode(',', array_map('intval', $p->get_gallery_image_ids()))
        : 'none';
};
// The fixture gives itself a gallery when it has none. A product with one
// picture cannot demonstrate a reordering, and «skipped, no gallery» is a
// stage that looks exactly like a stage that passed.
$product = $products->find($product->id);
if (count($product->imageIds) < 3) {
    $pool = get_posts([
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'numberposts' => 3,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    if (count($pool) >= 3) {
        $products->saveImages($product->id, array_map('intval', $pool), (int) $pool[0]);
        $product = $products->find($product->id);
        // Re-projected so the marketplace legitimately owns this arrangement
        // before the manager touches it — otherwise the swap below would be
        // measuring an unstamped field rather than the comparison.
        $catalog->publish($product->id);
    }
}
$wcProduct = wc_get_product($wcId);
$gallery = array_map('intval', $wcProduct->get_gallery_image_ids());
$wasMain = (int) $wcProduct->get_image_id();
if ($gallery === []) {
    $say('images', false, ['reason' => 'product_has_no_gallery', 'record' => count($product->imageIds)]);
} else {
    // Swap the featured picture with the last gallery one AND reverse what is
    // left. Under the old encoding both are the identical sorted string.
    $promoted = array_pop($gallery);
    $wcProduct->set_image_id($promoted);
    $wcProduct->set_gallery_image_ids(array_reverse(array_merge($gallery, [$wasMain])));
    $wcProduct->save();
    $managerArrangement = $arrangement($wcId);

    $product = $products->find($product->id);
    $products->updateDetails($product->id, $product->details->with(['brand' => 'برند ' . gmdate('His')]), $product->rowVersion);
    $catalog->publish($product->id);
    $say('image_swap_survives_a_sync', $arrangement($wcId) === $managerArrangement, [
        'manager' => $managerArrangement,
        'now' => $arrangement($wcId),
        'pending' => (string) get_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'images', true) === ''
            ? 'none'
            : 'recorded',
    ]);

    // And the manager can hand it back. Accepting writes the marketplace's
    // arrangement — which is the vendor's, featured picture and order — and
    // running it twice must not move anything the second time.
    $product = $products->find($product->id);
    $accepted = $review->resolveField($product->id, 'images', 'accept');
    $afterAccept = $arrangement($wcId);
    $product = $products->find($product->id);
    $again = $review->resolveField($product->id, 'images', 'accept');
    $say('accept_images_is_idempotent', $accepted->ok && $again->ok && $arrangement($wcId) === $afterAccept, [
        'after_first' => $afterAccept,
        'after_second' => $arrangement($wcId),
    ]);

    // A product with no featured picture at all is a state WooCommerce allows
    // and the comparison has to be able to represent.
    $wcProduct = wc_get_product($wcId);
    $wcProduct->set_image_id(0);
    $wcProduct->save();
    $product = $products->find($product->id);
    $catalog->publish($product->id);
    $say('no_featured_picture_is_seen_as_a_change', str_starts_with($arrangement($wcId), 'main:0'), [
        'now' => $arrangement($wcId),
        'pending' => (string) get_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'images', true) === ''
            ? 'none'
            : 'recorded',
    ]);
    // Put it back so the run can be repeated.
    $product = $products->find($product->id);
    $review->resolveField($product->id, 'images', 'accept');
    $say('images_restored', $arrangement($wcId) !== 'none', ['now' => $arrangement($wcId)]);
}

// ---------------------------------------------------------------- prepare
//
// A product waiting for review, given a storefront row so the manager can use
// WooCommerce's editor and Rank Math's box before approving it. The whole
// promise is that this cannot publish anything.
$drafts = $products->inStatus(ProductStatus::Draft, 5);
$waiting = $drafts[0] ?? null;
if ($waiting === null) {
    $say('prepare', false, ['reason' => 'no_draft_product']);
    return;
}
$prepared = $review->prepareStorefront($waiting->id);
$waiting = $products->find($waiting->id);
$preparedId = (int) ($waiting->wcProductId ?? 0);
$status = $preparedId > 0 ? (string) get_post_status($preparedId) : '';
$say('prepare', $prepared->ok && $preparedId > 0 && $status === 'draft', [
    'code' => $prepared->code,
    'wc' => $preparedId,
    'status' => $status === '' ? 'none' : $status,
    'purchasable' => $preparedId > 0 && wc_get_product($preparedId)?->is_purchasable() ? 'yes' : 'no',
]);
