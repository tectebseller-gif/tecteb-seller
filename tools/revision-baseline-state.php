<?php
/**
 * The approval path, on a real WooCommerce, in one PHP pass.
 *
 * The owner read `alpha.29` and found three gaps in it, all in the path that
 * rewrites a LIVE product: `approveRevision()` had no lock on the shop side, no
 * new baseline, and no look at what the sync answered. The sequence that shows
 * why the baseline matters is theirs: «تأیید مقدار A، اصلاح محصول منتشرشده به B
 * و تأیید، سپس اصلاح مجدد به A و تأیید. در پایان مقدار واقعی ووکامرس و پروندهٔ
 * بازارگاه باید A باشد».
 *
 * Why it cannot be a unit test: every value here is read back off WooCommerce
 * after the write, because WooCommerce filters what it is given, and the whole
 * question is what the SHOP ends up holding — not what was handed to it.
 *
 *   wp eval-file tools/revision-baseline-state.php run
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it edits product titles in WooCommerce, moves rows between
// statuses, and deliberately makes a product save throw. On the owner's site
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
/** @var ProductRevisionRepositoryInterface $revisions */
$revisions = $c->get(ProductRevisionRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);
/** @var ReviewProducts $review */
$review = $c->get(ReviewProducts::class);
/** @var ManageProducts $manage */
$manage = $c->get(ManageProducts::class);
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
// The LOWEST id, not the first row the query happened to return: a fixture
// that picks a different product on a second run measures something else.
usort($published, static fn ($a, $b): int => $a->id <=> $b->id);
$product = $published[0];
$productId = $product->id;
$vendorUserId = $product->vendorUserId;

// ------------------------------------------------------------------ helpers
$wc = static fn (int $id): ?WC_Product => wc_get_product($id) instanceof WC_Product ? wc_get_product($id) : null;
$titleOf = static fn (int $id): string => $wc($id)?->get_name() ?? '(none)';
$shortOf = static fn (int $id): string => (string) ($wc($id)?->get_short_description() ?? '(none)');
$short = static fn (string $v): string => mb_substr($v, 0, 26);
$recordTitle = static fn (): string => $products->find($productId)?->details->title ?? '(none)';
$baselineTitle = static fn (): string => $products->find($productId)?->baseline?->get('title') ?? '(none)';
$seenNow = static fn (): array => $storefront->fingerprints($products->find($productId));
$verdicts = static function () use ($storefront, $products, $productId): string {
    $out = [];
    foreach ($storefront->compare($products->find($productId)) as $field) {
        $out[] = $field->key . ':' . ($field->verdict ?? '-');
    }
    return implode('|', $out);
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
/** A vendor edit to a published product: sensitive, so it becomes a revision. */
$propose = static function (array $changes) use ($manage, $products, $revisions, $productId, $vendorUserId): array {
    $p = $products->find($productId);
    $result = $manage->save(
        $vendorUserId,
        $vendorUserId,
        $productId,
        $p->details->with($changes),
        $p->specs,
        $p->imageIds,
        $p->mainImageId,
        $p->rowVersion
    );
    return [$result, $revisions->pendingFor($productId)?->id ?? 0];
};
/** WooCommerce refusing to save, from inside WooCommerce. */
$breakTheShop = static function (): callable {
    $thrower = static function (): void {
        throw new RuntimeException('simulated storefront failure');
    };
    add_action('woocommerce_before_product_object_save', $thrower, 1);
    return $thrower;
};
$fixTheShop = static function (callable $thrower): void {
    remove_action('woocommerce_before_product_object_save', $thrower, 1);
};

// -------------------------------------------------------------------- reset
//
// Ownership and the baseline are established the way a manager establishes
// them — through the same `accept` the review screen calls. A fixture that
// wrote either behind the code's back could pass while the code is broken.
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
$stale = $revisions->pendingFor($productId);
if ($stale !== null) {
    // A fixture that spends something has to be able to run twice: a revision
    // left pending by the previous run would make the first proposal below
    // supersede it rather than create one, and the ids would not line up.
    $review->rejectRevision($stale->id, 'پاک‌سازی پیش از اجرای تازه');
}
$products->saveBaseline($productId, null);
$A = 'عنوان الف ' . gmdate('His');
$p = $products->find($productId);
$products->updateDetails($productId, $p->details->with(['title' => $A]), $p->rowVersion);
$catalog->publish($productId);
$claimed = 0;
foreach (ProjectedFieldOwnership::FIELDS as $field) {
    if ($review->resolveField($productId, $field, 'accept')->ok) {
        $claimed++;
    }
}
$say('reset', $wcId > 0 && $claimed === count(ProjectedFieldOwnership::FIELDS) && $titleOf($wcId) === $A, [
    'product' => $productId,
    'wc' => $wcId,
    'claimed' => $claimed,
    'shop_title' => $short($titleOf($wcId)),
    'baseline_title' => $short($baselineTitle()),
    'pending_revisions' => $revisions->pendingFor($productId) === null ? 0 : 1,
]);
if ($wcId <= 0) {
    return;
}

// ===================================================== A → B → A, approved
//
// Three approvals of a PUBLISHED product. On `alpha.29` the first two look
// right and the third writes nothing: the baseline is still A from the reset,
// the record has just been set back to A, and «the vendor changed nothing» is
// true of the only two values anybody compares.
$B = 'عنوان ب ' . gmdate('His');
[$proposedB, $revisionB] = $propose(['title' => $B]);
$say('a_published_product_gets_a_revision', $proposedB->code === 'revision_requested' && $revisionB > 0, [
    'code' => $proposedB->code,
    'revision' => $revisionB,
    'shop_title_unchanged' => $titleOf($wcId) === $A ? 'yes' : 'no',
]);

$approvedB = $review->approveRevision($revisionB, $seenNow());
$say(
    'approving_the_revision_moves_the_agreement',
    $approvedB->ok && $titleOf($wcId) === $B && $recordTitle() === $B && $baselineTitle() === $B,
    [
        'code' => $approvedB->code,
        'shop_title' => $short($titleOf($wcId)),
        'record_title' => $short($recordTitle()),
        'baseline_title' => $short($baselineTitle()),
        'agreed' => $baselineTitle() === $titleOf($wcId) ? 'yes' : 'no',
    ]
);

[$proposedA, $revisionA] = $propose(['title' => $A]);
$approvedA = $review->approveRevision($revisionA, $seenNow());
$say(
    'and_a_revision_back_to_a_reaches_the_shop',
    $approvedA->ok && $titleOf($wcId) === $A && $recordTitle() === $A && $baselineTitle() === $A,
    [
        'code' => $approvedA->code,
        'shop_title' => $short($titleOf($wcId)),
        'record_title' => $short($recordTitle()),
        'baseline_title' => $short($baselineTitle()),
        'shop_is_a' => $titleOf($wcId) === $A ? 'yes' : 'no',
        // The alpha.29 symptom, named: the record says A and the shop says B.
        'two_versions' => $titleOf($wcId) === $recordTitle() ? 'no' : 'yes',
    ]
);

// ============================ a manager edit between drawing and approving
$seen = $seenNow();
$TYPO_FIX = 'عنوانی که مدیر وسط بررسی اصلاح کرد ' . gmdate('His');
$p = $wc($wcId);
$p->set_name($TYPO_FIX);
$p->save();
clean_post_cache($wcId);
$C = 'عنوان ج ' . gmdate('His');
[$proposedC, $revisionC] = $propose(['title' => $C]);
$refused = $review->approveRevision($revisionC, $seen);
$say(
    'an_edit_during_a_revision_review_refuses_it',
    !$refused->ok
        && $refused->code === 'storefront_moved'
        && $titleOf($wcId) === $TYPO_FIX
        && $revisions->find($revisionC)?->status === ProductRevision::PENDING,
    [
        'code' => $refused->code,
        'fields' => $refused->context['fields'] ?? '-',
        'edit_survived' => $titleOf($wcId) === $TYPO_FIX ? 'yes' : 'no',
        'still_pending' => $revisions->find($revisionC)?->status === ProductRevision::PENDING ? 'yes' : 'no',
    ]
);

// Pressing again, with what the page shows NOW. The revision goes through —
// and because the manager and the vendor have now moved the same field to
// different values, this is a real disagreement: exactly one question, on the
// title, with the manager's text still on the shop and the vendor's recorded
// beside it. Then the manager settles it, and there is one value again.
$again = $review->approveRevision($revisionC, $seenNow());
$askedAfter = $questions();
$verdictsAfter = $verdicts();
$settled = $review->resolveField($productId, 'title', 'accept');
$say(
    'and_pressing_again_asks_once_and_settles',
    $again->ok
        && $askedAfter === ['title']
        && $settled->ok
        && $titleOf($wcId) === $C
        && $recordTitle() === $C,
    [
        'code' => $again->code,
        'questions' => $askedAfter === [] ? 'none' : implode(',', $askedAfter),
        'verdicts' => $verdictsAfter,
        'settle' => $settled->code,
        'shop_title' => $short($titleOf($wcId)),
        'record_title' => $short($recordTitle()),
        'baseline_title' => $short($baselineTitle()),
        // The proposal must survive the refusal AND the reconciliation.
        'one_version' => $titleOf($wcId) === $recordTitle() ? 'yes' : 'no',
    ]
);

// ================================ an old stamp is not a manager's new edit
//
// The second gap, end to end. The manager edits the title in WooCommerce; the
// vendor changes only the short description; the approval adopts the manager's
// title as the new agreement WITHOUT stamping it — the marketplace did not
// write it and must not claim it. The stamp therefore still holds the title
// from two agreements ago.
//
// On `alpha.29` the manager's half was measured against that stamp, so the
// vendor's NEXT title change was called a conflict and held back: the approved
// revision never reached the shop.
$MANAGER_TITLE = 'عنوان مدیر بدون مهر ' . gmdate('His');
$p = $wc($wcId);
$p->set_name($MANAGER_TITLE);
$p->save();
clean_post_cache($wcId);
$STAMP_BEFORE = (string) get_post_meta($wcId, ProjectedFieldOwnership::STAMP_PREFIX . 'title', true);
$VENDOR_SHORT = 'توضیح کوتاه تازه ' . gmdate('His');
$pr = $products->find($productId);
$products->updateDetails($productId, $pr->details->with(['shortDescription' => $VENDOR_SHORT]), $pr->rowVersion);
$askedBefore = $questions();
$products->updateStatus($productId, ProductStatus::Submitted, '');
$approvedShort = $review->approve($productId, $seenNow());
$STAMP_AFTER = (string) get_post_meta($wcId, ProjectedFieldOwnership::STAMP_PREFIX . 'title', true);
$say(
    'the_managers_title_becomes_the_agreement_without_a_stamp',
    $approvedShort->ok
        && $askedBefore === []
        && $titleOf($wcId) === $MANAGER_TITLE
        && $baselineTitle() === $MANAGER_TITLE
        && $STAMP_AFTER === $STAMP_BEFORE,
    [
        'code' => $approvedShort->code,
        'questions' => $askedBefore === [] ? 'none' : implode(',', $askedBefore),
        'short_applied' => $shortOf($wcId) === $VENDOR_SHORT ? 'yes' : 'no',
        'baseline_is_the_managers' => $baselineTitle() === $MANAGER_TITLE ? 'yes' : 'no',
        // The rule the fix must not break: their value is agreed, not owned.
        'stamp_untouched' => $STAMP_AFTER === $STAMP_BEFORE ? 'yes' : 'no',
    ]
);

$D = 'عنوان د ' . gmdate('His');
[$proposedD, $revisionD] = $propose(['title' => $D]);
$verdictsBefore = $verdicts();
$approvedD = $review->approveRevision($revisionD, $seenNow());
$say(
    'and_the_old_stamp_is_not_a_conflict',
    $approvedD->ok && $titleOf($wcId) === $D && $baselineTitle() === $D,
    [
        'code' => $approvedD->code,
        'shop_title' => $short($titleOf($wcId)),
        'record_title' => $short($recordTitle()),
        'verdicts_before' => $verdictsBefore,
        // On alpha.29 the projector held the vendor's title as a proposal and
        // the shop kept the manager's — with nothing on any screen to say so.
        'vendor_title_reached_the_shop' => $titleOf($wcId) === $D ? 'yes' : 'no',
    ]
);

// ============================================ a sync that refused to write
$baselineBefore = $baselineTitle();
$products->updateStatus($productId, ProductStatus::Submitted, '');
$thrower = $breakTheShop();
$failed = $review->approve($productId, []);
$fixTheShop($thrower);
$say(
    'a_failed_sync_is_not_a_review',
    !$failed->ok
        && $failed->code === 'sync_failed'
        && $products->find($productId)?->status === ProductStatus::Submitted
        && $baselineTitle() === $baselineBefore,
    [
        'code' => $failed->code,
        'reason' => $failed->context['reason'] ?? '-',
        'restored' => $failed->context['restored'] ?? '-',
        'status' => $products->find($productId)?->status->value,
        'baseline_untouched' => $baselineTitle() === $baselineBefore ? 'yes' : 'no',
    ]
);

$recovered = $review->approve($productId, $seenNow());
$say('and_the_same_button_works_once_the_shop_does', $recovered->ok, [
    'code' => $recovered->code,
    'status' => $products->find($productId)?->status->value,
]);

$titleBefore = $titleOf($wcId);
$E = 'عنوان ه ' . gmdate('His');
[$proposedE, $revisionE] = $propose(['title' => $E]);
$thrower = $breakTheShop();
$failedRevision = $review->approveRevision($revisionE, []);
$fixTheShop($thrower);
$say(
    'a_failed_sync_leaves_the_revision_pending',
    !$failedRevision->ok
        && $failedRevision->code === 'sync_failed'
        && $revisions->find($revisionE)?->status === ProductRevision::PENDING
        && $recordTitle() !== $E
        && $titleOf($wcId) === $titleBefore,
    [
        'code' => $failedRevision->code,
        'reason' => $failedRevision->context['reason'] ?? '-',
        'still_pending' => $revisions->find($revisionE)?->status === ProductRevision::PENDING ? 'yes' : 'no',
        'record_rolled_back' => $recordTitle() !== $E ? 'yes' : 'no',
        'shop_untouched' => $titleOf($wcId) === $titleBefore ? 'yes' : 'no',
    ]
);

$recoveredRevision = $review->approveRevision($revisionE, $seenNow());
$say('and_the_revision_goes_through_afterwards', $recoveredRevision->ok && $titleOf($wcId) === $E, [
    'code' => $recoveredRevision->code,
    'shop_title' => $short($titleOf($wcId)),
]);

// -------------------------------------------------------------- restored
$final = $products->find($productId);
$say(
    'restored',
    $final?->status === ProductStatus::Published
        && $final->details->title === $titleOf($wcId)
        && $baselineTitle() === $titleOf($wcId)
        && $revisions->pendingFor($productId) === null,
    [
        'status' => $final?->status->value,
        'one_version' => $final?->details->title === $titleOf($wcId) ? 'yes' : 'no',
        'agreed' => $baselineTitle() === $titleOf($wcId) ? 'yes' : 'no',
        'pending_revisions' => $revisions->pendingFor($productId) === null ? 0 : 1,
    ]
);
