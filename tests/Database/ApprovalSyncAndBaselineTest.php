<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\FakeStorefrontFields;

/**
 * WHAT THIS PROVES: the approval path reads what the storefront answered, and
 * every approval leaves the agreement equal to what the shop holds.
 *
 * Three gaps, all in the same six lines of `ReviewProducts`, all found by the
 * owner reading `alpha.29`:
 *
 *   1. `approveRevision()` had no lock, no baseline and no look at the sync —
 *      so the path that rewrites a LIVE product was the only one without the
 *      three guards the queue's approval had.
 *   2. `catalog->publish()`'s result was discarded. A projection that refused
 *      ended in `product_reviewed`, with the record saying published and the
 *      shop holding nothing.
 *   3. And the baseline was recorded regardless, so the next merge measured
 *      the vendor's edit against values nobody ever agreed to.
 *
 * The one-assertion version of (1) and (3) is the invariant this class keeps
 * coming back to: **after any approval, the recorded baseline equals what the
 * storefront holds.** A→B→A is the sequence that breaks when it does not.
 */
final class ApprovalSyncAndBaselineTest extends DatabaseTestCase
{
    private const VENDOR = 71;
    private const MANAGER = 9;

    private DbProductRepository $products;
    private DbProductRevisionRepository $revisions;
    private ManageProducts $manage;
    private ReviewProducts $review;
    private FakeCatalogProjector $projector;
    private FakeProductImages $images;
    private FakeStorefrontFields $storefront;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        $this->resetSchema($db);

        $clock = new SystemClock();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->products = new DbProductRepository($db, $clock);
        $this->revisions = new DbProductRevisionRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $variations = new DbVariationRepository($db, $clock);
        $vendors = new DbVendorRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $this->images = new FakeProductImages();
        $this->projector = new FakeCatalogProjector();
        $this->storefront = new FakeStorefrontFields($this->products);
        $publishing = new ProductPublishPolicy(new WpOptionStore());
        $readiness = new ProductReadiness($templates, $variations);
        $states = new ProductStateMachine();
        $catalog = new SyncCatalog($this->products, $variations, $this->projector, $audit);

        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $this->revisions,
            $readiness,
            $catalog,
            $this->images,
            new StaffAccess($staff, $vendors),
            $publishing,
            $states,
            $audit
        );
        $this->review = new ReviewProducts(
            $this->products,
            $this->revisions,
            $templates,
            $readiness,
            $catalog,
            $publishing,
            $states,
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_PRODUCTS]),
            $this->storefront,
            new DbProductDecisionRepository($db, $clock)
        );

        $vendors->upsertProfile(self::VENDOR, 'داروخانه نمونه', true, false);
    }

    // ------------------------------------------------- the required sequence

    /**
     * Approve A, revise the live product to B and approve, revise back to A
     * and approve. The owner's words: «در پایان مقدار واقعی ووکامرس و پروندهٔ
     * بازارگاه باید A باشد و مبنا در هر مرحله درست به‌روز شود».
     *
     * On `alpha.29` the third approval is where it goes wrong, and it goes
     * wrong silently: the baseline is still A from the first approval, the
     * record has just been set back to A, so «the vendor changed nothing» is
     * true of the only two values anybody compares — and the shop keeps B for
     * ever. `FieldMergeTest` asserts that verdict directly; this asserts the
     * reason it can no longer arise.
     */
    public function testEveryApprovalLeavesTheBaselineEqualToWhatTheShopHolds(): void
    {
        $productId = $this->publish();
        $this->assertBaselineMatchesTheShop($productId, 'after the first approval');
        self::assertSame('دستکش لاتکس', $this->baselineOf($productId)['title'] ?? null);

        $this->approveRevisionTo($productId, 'دستکش نیتریل');
        $this->assertBaselineMatchesTheShop($productId, 'after the revision to B');
        self::assertSame('دستکش نیتریل', $this->baselineOf($productId)['title'] ?? null);

        $this->approveRevisionTo($productId, 'دستکش لاتکس');
        $this->assertBaselineMatchesTheShop($productId, 'after the revision back to A');
        self::assertSame(
            'دستکش لاتکس',
            $this->baselineOf($productId)['title'] ?? null,
            'the agreement has to come back with the value; a baseline stuck at A would make the return invisible'
        );
        self::assertSame('دستکش لاتکس', $this->products->find($productId)?->details->title);
        self::assertSame(
            'دستکش لاتکس',
            $this->storefront->storefrontValues($this->products->find($productId))['title'] ?? null,
            'and the shop is the same value, not the B nobody asked to keep'
        );
    }

    /**
     * A manager's WooCommerce edit made while the revision page was open is not
     * written over — the same refusal the queue's approval makes, on the path
     * that actually rewrites a published product.
     */
    public function testApprovingARevisionIsRefusedWhenTheShopMovedUnderTheReviewer(): void
    {
        $productId = $this->publish();
        $product = $this->products->find($productId);
        self::assertNotNull($product);
        $seen = $this->storefront->fingerprints($product);       // what the page showed

        // The manager fixes the title in WooCommerce while the page is open.
        $this->storefront->managerEdits['title'] = 'دستکش لاتکس استریل';
        $revisionId = $this->proposeTitle($productId, 'دستکش نیتریل');

        $refused = $this->review->approveRevision($revisionId, $seen);

        self::assertFalse($refused->ok, 'alpha.29 approved it and the manager\'s title was gone');
        self::assertSame('storefront_moved', $refused->code);
        self::assertSame('title', $refused->context['fields']);
        self::assertSame(ProductRevision::PENDING, $this->revisions->find($revisionId)?->status);
        self::assertSame(
            'دستکش لاتکس',
            $this->products->find($productId)?->details->title,
            'and nothing of the proposal was written'
        );
    }

    /**
     * A field the projection HELD is not reconciled and does not move the
     * agreement — found by this round's own evidence run on a real WooCommerce.
     *
     * The manager had edited the title in WooCommerce, so the projection held
     * the vendor's title as a proposal. `alpha.29`'s reconciliation then wrote
     * the manager's title into the marketplace record, and from that moment the
     * record equalled the baseline: the verdict became `skip`, the review screen
     * asked nothing, and the vendor's proposal was invisible. Recording the
     * shop's value as the agreed one is worse still — it erases the evidence
     * that the manager moved the field, and the next projection writes the
     * vendor's value straight over it.
     *
     * Deliberately the QUEUE's approval rather than a revision: that is the path
     * that reconciled on `alpha.29`, so it is the path where this can be shown
     * to have been broken.
     */
    public function testAFieldWithAHeldProposalIsNotReconciledAndDoesNotMoveTheAgreement(): void
    {
        $productId = $this->publish();
        $agreedTitle = $this->baselineOf($productId)['title'] ?? '';
        self::assertSame('دستکش لاتکس', $agreedTitle);

        // Back to the vendor for a correction — written through the
        // repository, because a product in review refuses a vendor edit and
        // that refusal is not what this test is about.
        self::assertTrue($this->products->updateStatus($productId, ProductStatus::Draft, ''));
        $edited = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('دستکش نیتریل'),
            [],
            $this->products->find($productId)?->imageIds ?? [],
            $this->products->find($productId)?->mainImageId ?? 0,
            $this->products->find($productId)?->rowVersion ?? ''
        );
        self::assertTrue($edited->ok, $edited->code);
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);

        // The manager has meanwhile rewritten the title in WooCommerce, so the
        // projection holds the vendor's value instead of writing it.
        $this->storefront->managerEdits['title'] = 'دستکش لاتکس استریل';
        $this->storefront->pending['title'] = 'دستکش نیتریل';

        $approved = $this->review->approve($productId);
        self::assertTrue($approved->ok, $approved->code);

        self::assertSame(
            'دستکش نیتریل',
            $this->products->find($productId)?->details->title,
            'the record keeps what the vendor asked for while the question is open'
        );
        self::assertSame(
            $agreedTitle,
            $this->baselineOf($productId)['title'] ?? '',
            'and the agreement stays where it was, so the manager\'s edit is still visible as a change'
        );
        // Every other field still reconciles and still agrees.
        $product = $this->products->find($productId);
        self::assertNotNull($product);
        $shop = $this->storefront->storefrontValues($product);
        foreach (['short_description', 'category', 'images'] as $field) {
            self::assertSame(
                $shop[$field],
                $product->baseline?->get($field),
                $field . ' is not in dispute, so it is agreed'
            );
        }
        // And the screen asks about the one that is.
        $asking = array_values(array_map(
            static fn ($row): string => $row->key,
            array_filter($this->storefront->compare($product), static fn ($row): bool => $row->needsDecision())
        ));
        self::assertSame(['title'], $asking);
    }

    // -------------------------------------------------- a sync that refused

    public function testAnApprovalWhoseSyncFailedIsNotAReviewAndLeavesTheProductInTheQueue(): void
    {
        $productId = $this->readyProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        $this->projector->refusesToProject = [$productId];

        $result = $this->review->approve($productId);

        self::assertFalse($result->ok, 'alpha.29 answered product_reviewed');
        self::assertSame('sync_failed', $result->code);
        self::assertSame('storage_failed', $result->context['reason']);
        self::assertSame('yes', $result->context['restored']);
        $product = $this->products->find($productId);
        self::assertSame(
            ProductStatus::Submitted,
            $product?->status,
            'published → published is not a transition, so a product left Published here could never be retried'
        );
        self::assertNull($product?->baseline, 'and no agreement is recorded about a shop that holds nothing');
    }

    public function testARevisionWhoseSyncFailedStaysPendingAndLeavesTheLiveProductAsItWas(): void
    {
        $productId = $this->publish();
        $revisionId = $this->proposeTitle($productId, 'دستکش نیتریل');
        $baselineBefore = $this->baselineOf($productId);
        $this->projector->refusesToProject = [$productId];

        $result = $this->review->approveRevision($revisionId);

        self::assertFalse($result->ok, 'alpha.29 answered revision_approved');
        self::assertSame('sync_failed', $result->code);
        self::assertSame(
            ProductRevision::PENDING,
            $this->revisions->find($revisionId)?->status,
            'pending is the only state the manager can try again from'
        );
        self::assertSame(
            'دستکش لاتکس',
            $this->products->find($productId)?->details->title,
            'the product a shopper is looking at is the one that was there'
        );
        self::assertSame($baselineBefore, $this->baselineOf($productId), 'and the agreement did not move');
    }

    /**
     * A suspension that could not take the product off the shelf is a failure,
     * and the record must not claim otherwise — the same rule as «توقف ناتمام
     * یک شکست است», on the manager's own button.
     */
    public function testASuspensionTheShopRefusedIsReportedAndNotRecorded(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;
        $this->projector->refusesToWithdraw = [$productId];

        $result = $this->review->suspend($productId, 'شکایت خریدار');

        self::assertFalse($result->ok);
        self::assertSame('sync_failed', $result->code);
        self::assertSame(
            ProductStatus::Published,
            $this->products->find($productId)?->status,
            'a record saying suspended while the shop still sells it is the worse of the two states'
        );
        self::assertSame('publish', $this->projector->statuses[$wcId], 'which is exactly what it still does');
    }

    /** WooCommerce absent is not a failed write: there is nothing to be out of step with. */
    public function testWithoutWooCommerceTheDecisionStillStands(): void
    {
        $this->projector->available = false;
        $productId = $this->readyProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);

        $result = $this->review->approve($productId);

        self::assertTrue($result->ok, $result->code);
        self::assertSame('product_reviewed', $result->code);
        self::assertSame(ProductStatus::Published, $this->products->find($productId)?->status);
        self::assertNull($this->products->find($productId)?->baseline, 'and no agreement is invented either');
    }

    // -------------------------------------------------------------- helpers

    /** @return array<string,string> */
    private function baselineOf(int $productId): array
    {
        return $this->products->find($productId)?->baseline?->all() ?? [];
    }

    private function assertBaselineMatchesTheShop(int $productId, string $when): void
    {
        $product = $this->products->find($productId);
        self::assertNotNull($product);
        self::assertSame(
            $this->storefront->storefrontValues($product),
            $product->baseline?->all(),
            'the recorded agreement must be what the shop holds — ' . $when
        );
    }

    /** One vendor revision, approved, on a product that is live. */
    private function approveRevisionTo(int $productId, string $title): void
    {
        $revisionId = $this->proposeTitle($productId, $title);
        $product = $this->products->find($productId);
        self::assertNotNull($product);
        $approved = $this->review->approveRevision($revisionId, $this->storefront->fingerprints($product));
        self::assertTrue($approved->ok, $approved->code);
        self::assertSame($title, $this->products->find($productId)?->details->title);
    }

    private function proposeTitle(int $productId, string $title): int
    {
        $saved = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details($title),
            [],
            $this->products->find($productId)?->imageIds ?? [],
            $this->products->find($productId)?->mainImageId ?? 0,
            $this->products->find($productId)?->rowVersion ?? ''
        );
        self::assertSame('revision_requested', $saved->code, $saved->code);
        $revisionId = $this->revisions->pendingFor($productId)?->id ?? 0;
        self::assertGreaterThan(0, $revisionId);
        return $revisionId;
    }

    private function publish(): int
    {
        $productId = $this->readyProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        $approved = $this->review->approve($productId);
        self::assertTrue($approved->ok, $approved->code);
        return $productId;
    }

    private function readyProduct(): int
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, $this->details());
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];
        $mediaId = 700 + $productId;
        $this->images->give($mediaId, self::VENDOR);
        $saved = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details(),
            [],
            [$mediaId],
            $mediaId,
            $this->products->find($productId)?->rowVersion ?? ''
        );
        self::assertTrue($saved->ok, $saved->code);
        return $productId;
    }

    private function details(string $title = 'دستکش لاتکس'): ProductDetails
    {
        return new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: 'SKU-71',
            stock: 5
        );
    }
}
