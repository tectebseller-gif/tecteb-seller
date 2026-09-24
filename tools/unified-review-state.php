<?php
/**
 * «یک ویرایش فروشنده، یک پرسش» — the owner's own run, reproduced and measured.
 *
 * What they did, and what happened: they edited the title and the long
 * description of a product in WooCommerce, set its SEO in Rank Math, and then
 * the vendor changed ONLY the short description and resubmitted. The review
 * screen asked about three fields, two of which nobody had asked to change.
 *
 * No unit test can settle this. The question is about what WooCommerce KEEPS
 * (it filters on the way in), what `wp_unique_post_slug()` does to an address,
 * and whether a field a person edited in wp-admin survives a projection. So
 * this runs on a real WooCommerce, in one PHP pass, and every number it prints
 * is read back off the product rather than from the value that was handed over.
 *
 *   wp eval-file tools/unified-review-state.php run
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it edits product titles and descriptions in WooCommerce,
// moves rows between statuses and changes a public slug. On the owner's site
// that is not a fixture, it is damage.
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
/** @var ProductDecisionRepositoryInterface $decisions */
$decisions = $c->get(ProductDecisionRepositoryInterface::class);

if (!$catalog->isAvailable() || $storefront === null) {
    $say('woocommerce', false, ['reason' => 'not_available']);
    return;
}

$published = $products->inStatus(ProductStatus::Published, 20);
if ($published === []) {
    $say('fixture', false, ['reason' => 'no_published_product']);
    return;
}
usort($published, static fn ($a, $b): int => $a->id <=> $b->id);
$product = $published[0];
$productId = $product->id;
$vendorUserId = $product->vendorUserId;

// ------------------------------------------------------------------ helpers
$wc = static fn (int $id): ?WC_Product => wc_get_product($id) instanceof WC_Product ? wc_get_product($id) : null;
$titleOf = static fn (int $id): string => $wc($id)?->get_name() ?? '(none)';
$shortOf = static fn (int $id): string => (string) ($wc($id)?->get_short_description() ?? '(none)');
$longOf = static fn (int $id): string => (string) ($wc($id)?->get_description() ?? '(none)');
$slugOf = static fn (int $id): string => (string) ($wc($id)?->get_slug() ?? '(none)');
$setShort = static function (string $value) use ($products, $productId): void {
    $p = $products->find($productId);
    $products->updateDetails($productId, $p->details->with(['shortDescription' => $value]), $p->rowVersion);
};
$questions = static function () use ($storefront, $products, $productId): array {
    $out = [];
    foreach ($storefront->compare($products->find($productId)) as $field) {
        if ($field->needsDecision()) {
            $out[] = $field->key;
        }
    }
    return $out;
};
$verdicts = static function () use ($storefront, $products, $productId): string {
    $out = [];
    foreach ($storefront->compare($products->find($productId)) as $field) {
        $out[] = $field->key . ':' . ($field->verdict ?? '-');
    }
    return implode('|', $out);
};

// -------------------------------------------------------------------- reset
//
// Ownership AND the baseline are established the way a manager establishes
// them — through the same `accept` the review screen calls. A fixture that
// wrote either of them behind the code's back could pass while the code is
// broken.
$wcId = (int) ($product->wcProductId ?? 0);
if ($wcId > 0) {
    foreach (ProjectedFieldOwnership::FIELDS as $field) {
        foreach ([
            ProjectedFieldOwnership::MANAGER_PREFIX,
            ProjectedFieldOwnership::PENDING_PREFIX,
            ProjectedFieldOwnership::STAMP_PREFIX,
        ] as $prefix) {
            delete_post_meta($wcId, $prefix . $field);
        }
    }
}
$products->saveBaseline($productId, null);
$setShort('توضیح کوتاه پایه برای این اجرا');
$catalog->publish($productId);
$claimed = 0;
foreach (ProjectedFieldOwnership::FIELDS as $field) {
    if ($review->resolveField($productId, $field, 'accept')->ok) {
        $claimed++;
    }
}
$product = $products->find($productId);
$wcId = (int) ($product->wcProductId ?? 0);
$baseline = $product->baseline;
$say('reset', $wcId > 0 && $claimed === count(ProjectedFieldOwnership::FIELDS) && $baseline !== null, [
    'product' => $productId,
    'wc' => $wcId,
    'claimed' => $claimed,
    'baseline_fields' => $baseline === null ? 0 : count($baseline->all()),
    'questions' => count($questions()),
]);
if ($wcId <= 0) {
    return;
}

// ============================================== the owner's own scenario
//
// «عنوان، توضیح کامل و تنظیمات واقعی Rank Math را در ووکامرس ویرایش کردم.
//   فروشنده توضیح کوتاه را تغییر داد و دوباره برای بررسی فرستاد.»
// The manager sets the address from the marketplace side first, so the slug
// has a stamp — without one there is nothing for the guard to be measured
// against, and a falsification run would «pass» for lack of a precondition
// rather than because the guard works.
$MARKETPLACE_SLUG = 'marketplace-address-' . gmdate('His');
$seoSaved = $review->setSeo($productId, $MARKETPLACE_SLUG, '', '');
$say('the_marketplace_sets_an_address_once', $seoSaved->ok && $slugOf($wcId) === $MARKETPLACE_SLUG, [
    'code' => $seoSaved->code,
    'slug' => $slugOf($wcId),
]);

$MANAGER_TITLE = 'عنوانی که مدیر در ووکامرس نوشت ' . gmdate('His');
$MANAGER_LONG = 'متن کاملی که مدیر خودش نوشته است ' . gmdate('His');
$MANAGER_SLUG = 'manager-chosen-address-' . gmdate('His');
$p = $wc($wcId);
$p->set_name($MANAGER_TITLE);
$p->set_description($MANAGER_LONG);
$p->set_slug($MANAGER_SLUG);
$p->save();
clean_post_cache($wcId);
$MANAGER_SLUG = $slugOf($wcId);       // what WordPress actually kept
$say('manager_edits_in_woocommerce', $titleOf($wcId) === $MANAGER_TITLE && $longOf($wcId) === $MANAGER_LONG, [
    'title' => mb_substr($titleOf($wcId), 0, 20),
    'slug' => $MANAGER_SLUG,
]);

$VENDOR_SHORT = 'توضیح کوتاه تازهٔ فروشنده ' . gmdate('His');
$setShort($VENDOR_SHORT);
$asked = $questions();
$say('one_vendor_edit_asks_nothing', $asked === [], [
    'questions' => $asked === [] ? 'none' : implode(',', $asked),
    'verdicts' => $verdicts(),
]);

// --------------------------------------------- approval writes ONE field
$product = $products->find($productId);
$seen = $storefront->fingerprints($product);
$products->updateStatus($productId, ProductStatus::Submitted, '');
$approved = $review->approve($productId, $seen);
$say(
    'approval_writes_only_what_the_vendor_changed',
    $approved->ok
        && $shortOf($wcId) === $VENDOR_SHORT
        && $titleOf($wcId) === $MANAGER_TITLE
        && $longOf($wcId) === $MANAGER_LONG,
    [
        'code' => $approved->code,
        'short_applied' => $shortOf($wcId) === $VENDOR_SHORT ? 'yes' : 'no',
        'manager_title_survived' => $titleOf($wcId) === $MANAGER_TITLE ? 'yes' : 'no',
        'manager_long_survived' => $longOf($wcId) === $MANAGER_LONG ? 'yes' : 'no',
    ]
);

// --------------------------------------------- the record now agrees
//
// «برای محصول متصل، ووکامرس مرجع اطلاعات نهایی تأییدشده باشد؛ فروشنده همان
//  مقادیر فعلیِ فیلدهای مجاز خود را ببیند.»
$product = $products->find($productId);
$say('the_record_agrees_with_the_shop', $product->details->title === $titleOf($wcId), [
    'record_title' => mb_substr($product->details->title, 0, 20),
    'shop_title' => mb_substr($titleOf($wcId), 0, 20),
    'one_version' => $product->details->title === $titleOf($wcId) ? 'yes' : 'no',
    'baseline_fields' => $product->baseline === null ? 0 : count($product->baseline->all()),
]);

// ------------------------------------- the slug the manager chose survives
$setShort('توضیح کوتاه بعدی ' . gmdate('His'));
$catalog->publish($productId);
$say('a_vendor_save_does_not_move_the_address', $slugOf($wcId) === $MANAGER_SLUG, [
    'slug' => $slugOf($wcId),
    'manager_chose' => $MANAGER_SLUG,
    // What an unguarded projection would have put back.
    'marketplace_would_write' => $MARKETPLACE_SLUG,
]);

// ------------------------------------------- a real conflict still asks
//
// Both sides move the SAME field, to different values. Exactly one question.
$p = $wc($wcId);
$p->set_short_description('توضیح کوتاهی که مدیر نوشت ' . gmdate('His'));
$p->save();
clean_post_cache($wcId);
$setShort('توضیح کوتاه متفاوتِ فروشنده ' . gmdate('His'));
$asked = $questions();
$say('a_real_conflict_asks_once', $asked === ['short_description'], [
    'questions' => $asked === [] ? 'none' : implode(',', $asked),
    'verdicts' => $verdicts(),
]);
// Settled the way the screen settles it, so the run can continue.
$review->resolveField($productId, 'short_description', 'accept');

// ------------------ a manager edit between opening the page and approving
$product = $products->find($productId);
$seen = $storefront->fingerprints($product);
$TYPO_FIX = 'عنوانی که مدیر وسط بررسی اصلاح کرد ' . gmdate('His');
$p = $wc($wcId);
$p->set_name($TYPO_FIX);
$p->save();
clean_post_cache($wcId);
$products->updateStatus($productId, ProductStatus::Submitted, '');
$refused = $review->approve($productId, $seen);
$say(
    'an_edit_during_review_refuses_the_approval',
    !$refused->ok && $refused->code === 'storefront_moved' && $titleOf($wcId) === $TYPO_FIX,
    [
        'code' => $refused->code,
        'fields' => (string) ($refused->context['fields'] ?? '-'),
        'edit_survived' => $titleOf($wcId) === $TYPO_FIX ? 'yes' : 'no',
    ]
);
// Pressing again, having seen the new values, goes through.
$product = $products->find($productId);
$again = $review->approve($productId, $storefront->fingerprints($product));
$say('and_pressing_again_goes_through', $again->ok && $titleOf($wcId) === $TYPO_FIX, [
    'code' => $again->code,
    'title' => mb_substr($titleOf($wcId), 0, 24),
]);

// ================================================ the manager's message
$NOTE = 'قیمت با بازار نمی‌خواند؛ لطفاً اصلاح کنید ' . gmdate('His');
// «نیازمند اصلاح» is a decision about a product IN the queue; the state
// machine refuses it on a published one, which is correct and not what this
// stage is about.
$products->updateStatus($productId, ProductStatus::Submitted, '');
$changes = $review->requestChanges($productId, $NOTE);
$latest = $decisions->latestForVendor($productId, $vendorUserId);
$say('the_managers_message_reaches_the_vendor', $changes->ok && $latest?->note === $NOTE, [
    'code' => $changes->code,
    'stored' => $latest === null ? 'none' : mb_substr($latest->note, 0, 24),
    'decision' => $latest?->decision ?? '-',
    'has_date' => ($latest?->createdAt ?? '') !== '' ? 'yes' : 'no',
]);

// One shop cannot read another's. Asked with a vendor id that does not own
// this product; the scope is in the query, not in the caller's memory.
$say('one_shop_cannot_read_anothers', $decisions->latestForVendor($productId, $vendorUserId + 9999) === null, [
    'other_vendor_sees' => $decisions->latestForVendor($productId, $vendorUserId + 9999) === null ? 'nothing' : 'something',
]);

// A resubmission and a rejection ADD rows; nothing removes one.
// Measured by the newest row's ID, not by a count: `forProduct()` caps its
// page, so a count saturates after enough runs and would then report «the
// history stopped growing» about a table that is growing fine.
$beforeId = $decisions->forProduct($productId, null, 1)[0]->id ?? 0;
$products->updateStatus($productId, ProductStatus::Submitted, '');
$decisions->record($productId, $vendorUserId, 0, ProductDecision::SUBMITTED, '');
$review->reject($productId, 'این نسخه هم اشکال دارد');   // adds, never removes
$after = $decisions->forProduct($productId, null, 5);
$afterId = $after[0]->id ?? 0;
$stillThere = false;
foreach ($decisions->forProduct($productId, null, 50) as $row) {
    if ($row->id === $beforeId) {
        $stillThere = true;
    }
}
$say('history_only_grows', $afterId >= $beforeId + 2 && ($beforeId === 0 || $stillThere), [
    'newest_before' => $beforeId,
    'newest_after' => $afterId,
    'earlier_row_still_there' => $stillThere ? 'yes' : 'n/a',
    'newest' => $after[0]->decision ?? '-',
]);

// ================================================ the manager's catalogue
//
// The product is archived now — the state in which it used to disappear.
$archived = $products->forManager(ProductStatus::Archived, '', 50);
$found = false;
foreach ($archived as $row) {
    if ($row->id === $productId) {
        $found = true;
    }
}
$counts = $products->countsByStatusForManager();
$say('a_rejected_product_is_still_findable', $found, [
    'in_archived_list' => $found ? 'yes' : 'no',
    'wc_status' => (string) (get_post_status($wcId) ?: 'gone'),
    'counts_add_up' => array_sum($counts) === $products->countForManager() ? 'yes' : 'no',
    'total' => $products->countForManager(),
]);

// Put it back, so the run is repeatable and the demo is left usable.
$products->updateStatus($productId, ProductStatus::Draft, '');
$products->updateStatus($productId, ProductStatus::Submitted, '');
$restored = $review->approve($productId, $storefront->fingerprints($products->find($productId)));
$say('restored', $restored->ok && $products->find($productId)?->status === ProductStatus::Published, [
    'code' => $restored->code,
    'status' => $products->find($productId)?->status->value ?? '-',
]);
