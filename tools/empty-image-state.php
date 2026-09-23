<?php
/**
 * «مقدار خالی» و «حذف تصویر اصلی» — the two things the marketplace owned and
 * could not actually do, measured on a real WooCommerce.
 *
 * Both defects have the same shape: a value that means something was read as
 * a value that means nothing.
 *
 *   * `acceptProposal()` asked whether a proposal existed by asking whether
 *     it was empty. A vendor who cleared their short description proposed the
 *     empty string; the manager's «خواستهٔ فروشنده اعمال شود» then deleted the
 *     proposal, left the old text on the shop, stamped that old text as the
 *     marketplace's own work — and reported success.
 *   * The projector wrote the featured picture only `if (mainImageId > 0)`,
 *     so «بدون تصویر اصلی» never reached WooCommerce at all. The record said
 *     one thing, the product page showed another, and every projection
 *     reported that it had synchronised them.
 *
 * Neither is visible to a unit test. The first is about what WooCommerce
 * KEEPS after a save (it filters values on the way in, and it does not always
 * refuse loudly); the second is about what `WC_Product_Data_Store_CPT` does
 * with a zero. So this runs in one PHP pass on the disposable install and
 * reads every number back off the product.
 *
 * Every stage prints one line for the shell to assert on:
 *
 *   wp eval-file tools/empty-image-state.php run
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it empties product descriptions, removes featured
// pictures and moves ownership between the manager and the marketplace. On
// the owner's site that is not a fixture, it is damage. Both disposable
// databases are named because the clean-install demo is the second one and
// this phase's evidence lives there.
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
// The SAME product every run, by the lowest id rather than by whatever order
// the query returned. Two runs that pick different rows are two different
// measurements wearing one name.
usort($published, static fn ($a, $b): int => $a->id <=> $b->id);
$product = $published[0];
$productId = $product->id;

// ------------------------------------------------------------------ helpers
$shortOf = static function (int $wcId): string {
    $p = wc_get_product($wcId);
    return $p instanceof WC_Product ? (string) $p->get_short_description() : '(none)';
};
$titleOf = static function (int $wcId): string {
    $p = wc_get_product($wcId);
    return $p instanceof WC_Product ? (string) $p->get_name() : '(none)';
};
$arrangement = static function (int $wcId): string {
    $p = wc_get_product($wcId);
    return $p instanceof WC_Product
        ? 'main:' . (int) $p->get_image_id() . '|gallery:' . implode(',', array_map('intval', $p->get_gallery_image_ids()))
        : 'none';
};
$mainOf = static function (int $wcId): int {
    $p = wc_get_product($wcId);
    return $p instanceof WC_Product ? (int) $p->get_image_id() : -1;
};
$galleryOf = static function (int $wcId): array {
    $p = wc_get_product($wcId);
    return $p instanceof WC_Product ? array_map('intval', $p->get_gallery_image_ids()) : [];
};
$meta = static fn (int $wcId, string $prefix, string $field): string
    => (string) get_post_meta($wcId, $prefix . $field, true);
$hasMeta = static fn (int $wcId, string $prefix, string $field): bool
    => metadata_exists('post', $wcId, $prefix . $field);
$setShort = static function (string $value) use ($products, $productId): void {
    $p = $products->find($productId);
    $products->updateDetails($productId, $p->details->with(['shortDescription' => $value]), $p->rowVersion);
};

// -------------------------------------------------------------------- reset
//
// Ownership is given back through the SAME `accept` the review screen calls,
// never by writing stamps behind the code's back: a fixture that reaches
// around the thing it is testing can pass while the thing is broken.
$cleared = 0;
$wcId = (int) ($product->wcProductId ?? 0);
if ($wcId > 0) {
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
}
// A gallery is needed before any of this means anything: a product with one
// picture cannot show that an order was preserved, and «skipped, no gallery»
// is a stage that looks exactly like a stage that passed.
$pool = array_map('intval', get_posts([
    'post_type' => 'attachment',
    'post_mime_type' => 'image',
    'numberposts' => 3,
    'fields' => 'ids',
    'orderby' => 'ID',
    'order' => 'ASC',
]));
if (count($pool) >= 3) {
    $products->saveImages($productId, $pool, $pool[0]);
}
$setShort('توضیح کوتاه پایه برای این اجرا');
$catalog->publish($productId);
$claimed = 0;
foreach (ProjectedFieldOwnership::FIELDS as $field) {
    if ($review->resolveField($productId, $field, 'accept')->ok) {
        $claimed++;
    }
}
$catalog->publish($productId);
$product = $products->find($productId);
$wcId = (int) ($product->wcProductId ?? 0);
$say('reset', $wcId > 0 && $claimed === count(ProjectedFieldOwnership::FIELDS) && count($pool) >= 3, [
    'product' => $productId,
    'wc' => $wcId,
    'cleared' => $cleared,
    'claimed' => $claimed,
    'pictures' => count($pool),
    'arrangement' => $arrangement($wcId),
]);
if ($wcId <= 0) {
    return;
}

// ============================================================ defect 1
//
// «اعمال مقدار خالی در پذیرش پیشنهاد»

// ------------------------------------------------------- hold_empty_short
//
// The manager edits the short description in WooCommerce, so the field
// becomes theirs. The vendor then CLEARS theirs, and the projector holds the
// empty string as a proposal — a meta row that exists and says nothing.
$MANAGER_SHORT = 'توضیح کوتاهی که مدیر نوشته ' . gmdate('His');
$wcProduct = wc_get_product($wcId);
$wcProduct->set_short_description($MANAGER_SHORT);
$wcProduct->save();
$setShort('');
$catalog->publish($productId);

$pendingExists = $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'short_description');
$pendingValue = $meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'short_description');
$say('hold_empty_short', $pendingExists && $pendingValue === '' && $shortOf($wcId) === $MANAGER_SHORT, [
    'pending_row' => $pendingExists ? 'present' : 'absent',
    'pending_len' => strlen($pendingValue),
    'storefront_len' => strlen($shortOf($wcId)),
    'manager_text_survived' => $shortOf($wcId) === $MANAGER_SHORT ? 'yes' : 'no',
]);

// ------------------------------------------ accept_empty_clears_the_field
//
// The decisive measurement. Before the fix this reported success, deleted the
// proposal and left the manager's text exactly where it was.
$product = $products->find($productId);
$accepted = $review->resolveField($productId, 'short_description', 'accept');
$afterAccept = $shortOf($wcId);
$stamp = $meta($wcId, ProjectedFieldOwnership::STAMP_PREFIX, 'short_description');
$say(
    'accept_empty_clears_the_field',
    $accepted->ok
        && $afterAccept === ''
        && !$hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'short_description')
        && $stamp === ProjectedFieldOwnership::fingerprint(''),
    [
        'code' => $accepted->code,
        'storefront_len' => strlen($afterAccept),
        'really_empty' => $afterAccept === '' ? 'yes' : 'no',
        'pending_cleared' => $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'short_description') ? 'no' : 'yes',
        'stamp_matches_stored_value' => $stamp === ProjectedFieldOwnership::fingerprint($afterAccept) ? 'yes' : 'no',
    ]
);

// ---------------------------------------------- empty_survives_the_sync
//
// «اجرای دوباره و همگام‌سازی بعدی نتیجه را برنگرداند». The field belongs to
// the marketplace again, so the next projection writes the marketplace's
// value — which is the empty one.
$catalog->publish($productId);
$afterSync = $shortOf($wcId);
$product = $products->find($productId);
$again = $review->resolveField($productId, 'short_description', 'accept');
$say('empty_survives_the_sync', $afterSync === '' && $again->ok && $shortOf($wcId) === '', [
    'after_sync_len' => strlen($afterSync),
    'second_accept' => $again->code,
    'after_second_accept_len' => strlen($shortOf($wcId)),
]);

// --------------------------------------- absent_proposal_is_not_an_empty_one
//
// The other direction, and the reason presence cannot be derived from the
// value. With NO proposal row at all, «خواستهٔ فروشنده اعمال شود» has to mean
// the marketplace's current value — not «clear the field».
$VENDOR_SHORT = 'توضیح کوتاه تازهٔ فروشنده ' . gmdate('His');
$wcProduct = wc_get_product($wcId);
$wcProduct->set_short_description('متن دیگری که مدیر گذاشته');
$wcProduct->save();
$setShort($VENDOR_SHORT);
$catalog->publish($productId);
// Remove the row entirely: this is the legacy shape, where the field was
// frozen before any projection could record a proposal.
delete_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'short_description');
$product = $products->find($productId);
$acceptedAbsent = $review->resolveField($productId, 'short_description', 'accept');
$say('absent_proposal_is_not_an_empty_one', $acceptedAbsent->ok && $shortOf($wcId) === $VENDOR_SHORT, [
    'code' => $acceptedAbsent->code,
    'storefront_len' => strlen($shortOf($wcId)),
    'took_the_record_value' => $shortOf($wcId) === $VENDOR_SHORT ? 'yes' : 'no',
    'not_emptied' => $shortOf($wcId) === '' ? 'no' : 'yes',
]);

// ------------------------------------- an_empty_title_proposal_is_refused
//
// «اعتبارسنجی فیلدهای اجباری، مثل عنوان، حفظ شود». The row is written here
// by hand because projection will not produce one — the projector substitutes
// «بدون عنوان» for an empty title. A guard that is only ever exercised by the
// thing that cannot happen is a guard nobody has tested.
$titleBefore = $titleOf($wcId);
update_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'title', '');
$product = $products->find($productId);
$refused = $review->resolveField($productId, 'title', 'accept');
$say(
    'an_empty_title_proposal_is_refused',
    !$refused->ok
        && $refused->code === 'title_required'
        && $titleOf($wcId) === $titleBefore
        && $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'title'),
    [
        'code' => $refused->code,
        'title_unchanged' => $titleOf($wcId) === $titleBefore ? 'yes' : 'no',
        'proposal_kept' => $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'title') ? 'yes' : 'no',
    ]
);
delete_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'title');

// --------------------------------- a_silent_save_failure_is_not_a_success
//
// «شکست ذخیره، موفقیت کاذب یا حذف پیشنهاد ایجاد نکند».
//
// The failure is injected the way a real one arrives: not as an exception —
// `writeField()` already catches those — but as another plugin's filter that
// quietly puts the old value back. `WC_Product::save()` then returns the
// product id exactly as it does on success, so a boolean read of it cannot
// tell the two apart. Only a read-back can.
$HELD = 'پیشنهاد فروشنده که باید بماند ' . gmdate('His');
$wcProduct = wc_get_product($wcId);
$wcProduct->set_short_description('متن مدیر پیش از شکست ذخیره');
$wcProduct->save();
$STUCK = $shortOf($wcId);
$setShort($HELD);
$catalog->publish($productId);

$revert = static function (array $data) use ($STUCK): array {
    $data['post_excerpt'] = $STUCK;
    return $data;
};
add_filter('wp_insert_post_data', $revert, 99);
$product = $products->find($productId);
$failed = $review->resolveField($productId, 'short_description', 'accept');
remove_filter('wp_insert_post_data', $revert, 99);
clean_post_cache($wcId);

$say(
    'a_silent_save_failure_is_not_a_success',
    !$failed->ok
        && $shortOf($wcId) === $STUCK
        && $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'short_description'),
    [
        'code' => $failed->code,
        'reported_success' => $failed->ok ? 'yes' : 'no',
        'storefront_unchanged' => $shortOf($wcId) === $STUCK ? 'yes' : 'no',
        'proposal_kept' => $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'short_description') ? 'yes' : 'no',
    ]
);

// And the retry, with the filter gone, goes through — a refusal that could
// not be recovered from would be its own defect.
$product = $products->find($productId);
$retried = $review->resolveField($productId, 'short_description', 'accept');
$say('and_the_retry_goes_through', $retried->ok && $shortOf($wcId) === $HELD, [
    'code' => $retried->code,
    'storefront' => mb_substr($shortOf($wcId), 0, 24),
]);

// ============================================================ defect 2
//
// «اعمال حذف تصویر اصلی در مسیر همگام‌سازی»

// The pictures belong to the marketplace again before any of this: the
// question is what a SYNC does, not what a manager may override.
$product = $products->find($productId);
$products->saveImages($productId, $pool, $pool[0]);
$catalog->publish($productId);
$review->resolveField($productId, 'images', 'accept');
$catalog->publish($productId);
$before = $arrangement($wcId);

// ------------------------------- marketplace_removes_the_main_picture
//
// The record changes to «بدون تصویر اصلی» and keeps its gallery. This is a
// reachable state, not a synthetic one: it is exactly what `resolveField`'s
// «نسخهٔ ووکامرس بماند» writes home when a manager has cleared the featured
// picture in WooCommerce, and it goes through the same `saveImages()`.
$products->saveImages($productId, $pool, 0);
$catalog->publish($productId);
$mainAfter = $mainOf($wcId);
$galleryAfter = $galleryOf($wcId);
$say(
    'marketplace_removes_the_main_picture',
    $mainAfter === 0 && $galleryAfter === $pool,
    [
        'was' => $before,
        'now' => $arrangement($wcId),
        'featured_removed' => $mainAfter === 0 ? 'yes' : 'no',
        'gallery_order_kept' => $galleryAfter === $pool ? 'yes' : 'no',
        'first_gallery_picture_promoted' => $mainAfter === ($pool[0] ?? -1) ? 'yes' : 'no',
    ]
);

// -------------------------------------- removal_survives_the_next_sync
$catalog->publish($productId);
$say('removal_survives_the_next_sync', $mainOf($wcId) === 0 && $galleryOf($wcId) === $pool, [
    'now' => $arrangement($wcId),
]);

// ------------------------------------------------- all_pictures_removed
$products->saveImages($productId, [], 0);
$catalog->publish($productId);
$say('all_pictures_removed', $mainOf($wcId) === 0 && $galleryOf($wcId) === [], [
    'now' => $arrangement($wcId),
]);

// ------------------------------ manager_owned_pictures_are_not_cleared
//
// The same scenario with the field decided the other way. Nothing may be
// overwritten without an explicit decision, and a removal is an overwrite.
$products->saveImages($productId, $pool, $pool[0]);
$catalog->publish($productId);
$kept = $review->resolveField($productId, 'images', 'keep');
$guardedArrangement = $arrangement($wcId);
$products->saveImages($productId, $pool, 0);
$catalog->publish($productId);
$say(
    'manager_owned_pictures_are_not_cleared',
    $kept->ok && $arrangement($wcId) === $guardedArrangement && $mainOf($wcId) > 0,
    [
        'decision' => $kept->code,
        'manager_had' => $guardedArrangement,
        'now' => $arrangement($wcId),
        'featured_kept' => $mainOf($wcId) > 0 ? 'yes' : 'no',
    ]
);

// --------------------------------- unsettled_pictures_are_not_cleared
//
// And the third state: a product older than the stamps, where nobody knows
// who wrote the arrangement. The marketplace writes nothing and records the
// removal as a proposal for somebody to answer.
$review->resolveField($productId, 'images', 'accept');
$products->saveImages($productId, $pool, $pool[0]);
$catalog->publish($productId);
delete_post_meta($wcId, ProjectedFieldOwnership::STAMP_PREFIX . 'images');
delete_post_meta($wcId, ProjectedFieldOwnership::MANAGER_PREFIX . 'images');
delete_post_meta($wcId, ProjectedFieldOwnership::PENDING_PREFIX . 'images');
$legacyArrangement = $arrangement($wcId);
$products->saveImages($productId, $pool, 0);
$catalog->publish($productId);
$say(
    'unsettled_pictures_are_not_cleared',
    $arrangement($wcId) === $legacyArrangement
        && $mainOf($wcId) > 0
        && $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'images'),
    [
        'legacy_had' => $legacyArrangement,
        'now' => $arrangement($wcId),
        'featured_kept' => $mainOf($wcId) > 0 ? 'yes' : 'no',
        'recorded_as_a_proposal' => $hasMeta($wcId, ProjectedFieldOwnership::PENDING_PREFIX, 'images') ? 'yes' : 'no',
    ]
);

// ----------------------------------------------------- pictures_restored
//
// The unsettled field is answered — which applies the held removal — and the
// record is then put back, so the run can be repeated and the demo site is
// left with a product that has a picture.
$settled = $review->resolveField($productId, 'images', 'accept');
$afterSettle = $mainOf($wcId);
$products->saveImages($productId, $pool, $pool[0]);
$catalog->publish($productId);
$setShort('توضیح کوتاه پایه برای این اجرا');
$catalog->publish($productId);
$say(
    'pictures_restored',
    $settled->ok && $afterSettle === 0 && $mainOf($wcId) === $pool[0] && $galleryOf($wcId) === array_slice($pool, 1),
    [
        'answering_the_proposal_removed_it' => $afterSettle === 0 ? 'yes' : 'no',
        'now' => $arrangement($wcId),
        'short_description' => mb_substr($shortOf($wcId), 0, 24),
    ]
);
