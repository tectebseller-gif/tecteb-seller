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
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\FakeStorefrontFields;

/**
 * WHAT THIS PROVES: a write that did not happen is not a write that happened,
 * and a restore that did not happen is not reported as one.
 *
 * `alpha.30` made the approval path read the storefront's answer. These are the
 * answers it still did not read — all of them on the way IN, and all found by
 * the owner reading the source:
 *
 *   1. `approveRevision()` called `saveSpecs()` and `saveImages()` and looked at
 *      neither. The spec case is the quiet one: the product keeps its old
 *      medical answers, readiness passes BECAUSE they are all still there, the
 *      projection goes out, a baseline is recorded about them, and the revision
 *      is marked approved. The vendor's edit never happened.
 *   2. `restoreProduct()` wrote three times and checked nothing, so the message
 *      said «محصول به حالت قبل برگشت» about a product that had not.
 *   3. `saveImages()` threw away the `DELETE`'s answer, so a failed delete let
 *      the inserts leave the OLD pictures and the new ones side by side — and
 *      returned `true`.
 *
 * Every test here measures the rows, not the message code: the marketplace
 * record, the storefront, the revision and the agreement, before and after.
 */
final class SaveAndRestoreFailureTest extends DatabaseTestCase
{
    private const VENDOR = 73;
    private const MANAGER = 9;

    /** @var list<int> the gallery every test starts from */
    private const GALLERY = [811, 812, 813];

    private FailingDatabase $db;
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
        $real = new WpDatabase($this->wpdb);
        $this->resetSchema($real);
        // Everything the code under test touches goes through the wrapper, so a
        // test can refuse ONE statement and watch what the real tables do.
        $this->db = new FailingDatabase($real);

        $clock = new SystemClock();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->products = new DbProductRepository($this->db, $clock);
        $this->revisions = new DbProductRevisionRepository($this->db, $clock);
        $templates = new DbSpecTemplateRepository($this->db, $clock);
        $variations = new DbVariationRepository($this->db, $clock);
        $vendors = new DbVendorRepository($this->db, $clock);
        $staff = new DbStaffRepository($this->db, $clock);
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
            new DbProductDecisionRepository($this->db, $clock)
        );

        $vendors->upsertProfile(self::VENDOR, 'داروخانه نمونه', true, false);
    }

    // ------------------------------------------- the gallery, in the repository

    /**
     * A failed `DELETE` must not turn a replacement into an addition.
     *
     * This is the one that returned `true`. Nothing downstream could have
     * caught it, which is why the owner's words were «صرفاً افزودن بررسی در
     * caller کافی نیست».
     */
    public function testAFailedGalleryDeleteLeavesTheOldPicturesAloneAndSaysSo(): void
    {
        $productId = $this->publishedProduct();
        self::assertSame(self::GALLERY, $this->galleryOf($productId), 'the state before');
        self::assertSame(self::GALLERY[0], $this->products->find($productId)?->mainImageId);

        $this->db->failWhen(['DELETE FROM', 'tmc_product_images']);
        $ok = $this->products->saveImages($productId, [901, 902], 901);

        // The rows first, on purpose: this is the assertion whose failure PRINTS
        // what `alpha.30` did — the old three and the new two in one gallery.
        $after = $this->products->find($productId);
        self::assertSame(self::GALLERY, $after?->imageIds, 'the old pictures, and only the old pictures');
        self::assertFalse($ok, 'alpha.30 answered true about a gallery it had mixed');
        self::assertCount(1, $this->db->refused, 'and the delete really was the statement that failed');
        self::assertSame(self::GALLERY[0], $after?->mainImageId, 'and the featured one did not move');
        self::assertSame(
            0,
            count(array_intersect([901, 902], $after?->imageIds ?? [])),
            'nothing of the new list reached the product'
        );
    }

    /** …and a failed insert must not leave half a gallery either. */
    public function testAFailedPictureInsertLeavesNoHalfWrittenGallery(): void
    {
        $productId = $this->publishedProduct();

        // The delete goes through; the SECOND of the three new pictures does not.
        $this->db->failWhen(['INSERT INTO', 'tmc_product_images'], 2);
        $ok = $this->products->saveImages($productId, [901, 902, 903], 901);

        self::assertFalse($ok);
        $after = $this->products->find($productId);
        self::assertSame(
            self::GALLERY,
            $after?->imageIds,
            'alpha.30 left the product holding only the first new picture'
        );
        self::assertSame(self::GALLERY[0], $after?->mainImageId, 'and the featured id never moved either');
    }

    /** The same rule one step down: the medical answers are together or not at all. */
    public function testAFailedSpecInsertLeavesTheAnswersAsTheyWere(): void
    {
        $productId = $this->publishedProduct();
        self::assertTrue($this->products->saveSpecs($productId, ['material' => 'لاتکس', 'size' => 'M'], 3));
        self::assertSame(['material' => 'لاتکس', 'size' => 'M'], $this->products->find($productId)?->specs);

        $this->db->failWhen(['INSERT INTO', 'tmc_product_specs'], 2);
        $ok = $this->products->saveSpecs($productId, ['material' => 'نیتریل', 'size' => 'L'], 4);

        self::assertFalse($ok);
        $after = $this->products->find($productId);
        self::assertSame(
            ['material' => 'لاتکس', 'size' => 'M'],
            $after?->specs,
            'alpha.30 left one answer new and one old'
        );
        self::assertSame(3, $after?->specSchemaVersion, 'and the schema version over the mixture');
    }

    // ------------------------------------------ the revision, in the application

    /**
     * The owner's first scenario: the optional spec did not save, and the
     * product still passes readiness — so nothing else in the method would ever
     * have noticed.
     */
    public function testAnOptionalSpecThatDidNotSaveStopsTheRevisionFromBeingApproved(): void
    {
        $productId = $this->publishedProduct();
        self::assertTrue($this->products->saveSpecs($productId, ['material' => 'لاتکس'], 1));
        $approved = $this->review->approve($productId, []);
        self::assertTrue($approved->ok, $approved->code);

        // The state before, read from the rows.
        $before = $this->products->find($productId);
        $baselineBefore = $this->baselineOf($productId);
        $shopTitleBefore = $this->shopTitle($productId);
        $writesBefore = $this->projectionCount($productId);
        self::assertSame(['material' => 'لاتکس'], $before?->specs);
        self::assertNotSame([], $baselineBefore, 'the agreement exists, so a wrong one would be recorded over it');

        $revisionId = $this->propose('دستکش نیتریل', ['material' => 'نیتریل']);
        // Only the revision's own write fails; the restore's write goes through.
        $this->db->failWhen(['INSERT INTO', 'tmc_product_specs'], 1);

        $result = $this->review->approveRevision($revisionId, $this->storefront->fingerprints(
            $this->products->find($productId)
        ));

        self::assertFalse($result->ok, 'alpha.30 answered revision_approved');
        self::assertSame('revision_write_failed', $result->code);
        self::assertSame('specs', $result->context['part']);
        self::assertSame('yes', $result->context['restored']);

        // And the rows, all four of them.
        $after = $this->products->find($productId);
        self::assertSame(['material' => 'لاتکس'], $after?->specs, 'the answers the shopper had, unchanged');
        self::assertSame('دستکش لاتکس', $after?->details->title, 'and the title came back with them');
        self::assertSame(
            ProductRevision::PENDING,
            $this->revisions->find($revisionId)?->status,
            'pending is the only state the manager can try again from'
        );
        self::assertSame($baselineBefore, $this->baselineOf($productId), 'no agreement about a value nobody wrote');
        self::assertSame($shopTitleBefore, $this->shopTitle($productId), 'and the shop was never touched');
        self::assertSame($writesBefore, $this->projectionCount($productId), 'no projection went out');
    }

    /**
     * The owner's fourth scenario: the restore itself fails, and the message
     * must not claim the product is as it was.
     */
    public function testARestoreThatFailedIsSaidOutLoudRatherThanAssumed(): void
    {
        $productId = $this->publishedProduct();
        $approved = $this->review->approve($productId, []);
        self::assertTrue($approved->ok, $approved->code);
        $baselineBefore = $this->baselineOf($productId);
        $shopTitleBefore = $this->shopTitle($productId);

        $revisionId = $this->propose('دستکش نیتریل', []);
        // The storefront refuses the projection, and the gallery cannot be put
        // back: the revision's own write did the first DELETE, the restore's
        // does the second.
        $this->projector->refusesToProject = [$productId];
        $this->db->failWhen(['DELETE FROM', 'tmc_product_images'], 2);

        $result = $this->review->approveRevision($revisionId, $this->storefront->fingerprints(
            $this->products->find($productId)
        ));

        self::assertFalse($result->ok);
        self::assertSame('sync_failed', $result->code);
        self::assertSame('no', $result->context['restored'] ?? '', 'alpha.30 said nothing at all about the restore');
        self::assertStringContainsString('images', (string) ($result->context['restore_failed'] ?? ''));

        // The sentence the manager reads is the one this test exists for.
        $message = (string) ProductMessages::notice('sync_failed', $result->context);
        self::assertStringContainsString('برگرداندن', $message, 'the message names the restore');
        self::assertStringContainsString('تصویرها', $message, 'and what did not come back');
        self::assertStringNotContainsString(
            'چیزی نیمه‌کاره نمانده',
            $message,
            'alpha.30 told the manager nothing was left half-done'
        );

        // And the state it warns about, measured rather than asserted from the code.
        $after = $this->products->find($productId);
        self::assertSame('دستکش لاتکس', $after?->details->title, 'the values that COULD go back, did');
        self::assertSame([901, 902], $after?->imageIds, 'and the ones that could not are still the proposal\'s');
        self::assertSame(ProductRevision::PENDING, $this->revisions->find($revisionId)?->status);
        self::assertSame($baselineBefore, $this->baselineOf($productId), 'no agreement was recorded');
        self::assertSame($shopTitleBefore, $this->shopTitle($productId));
    }

    /**
     * And the readiness refusal says it too: «نسخه قبول نشد» is true and no
     * longer the whole truth when part of the proposal is still on the product.
     */
    public function testAReadinessRefusalWhoseRestoreFailedNamesBoth(): void
    {
        $productId = $this->publishedProduct();
        self::assertTrue($this->review->approve($productId, [])->ok);

        // The proposal empties the gallery, which readiness refuses — and the
        // restore cannot put the pictures back.
        $revisionId = $this->propose('دستکش نیتریل', [], []);
        $this->db->failWhen(['DELETE FROM', 'tmc_product_images'], 2);

        $result = $this->review->approveRevision($revisionId, $this->storefront->fingerprints(
            $this->products->find($productId)
        ));

        self::assertFalse($result->ok);
        self::assertSame('revision_not_restored', $result->code, 'alpha.30 answered missing_image and stopped there');
        self::assertSame('missing_image', $result->context['reason']);
        self::assertStringContainsString('images', (string) ($result->context['restore_failed'] ?? ''));
        self::assertSame(ProductRevision::PENDING, $this->revisions->find($revisionId)?->status);
        self::assertSame([], $this->products->find($productId)?->imageIds, 'and the product really is without pictures');
    }

    // -------------------------------------------------------------- helpers

    /** @return list<int> */
    private function galleryOf(int $productId): array
    {
        return $this->products->find($productId)?->imageIds ?? [];
    }

    /** @return array<string,string> */
    private function baselineOf(int $productId): array
    {
        return $this->products->find($productId)?->baseline?->all() ?? [];
    }

    private function shopTitle(int $productId): string
    {
        $product = $this->products->find($productId);
        return $product === null ? '' : ($this->storefront->storefrontValues($product)['title'] ?? '');
    }

    private function projectionCount(int $productId): int
    {
        $wcId = (int) ($this->products->find($productId)?->wcProductId ?? 0);
        return $this->projector->writes[$wcId] ?? 0;
    }

    /**
     * A vendor proposal on a published product: sensitive, so it becomes a
     * revision and the record keeps its values until somebody approves.
     *
     * @param array<string,string> $specs
     * @param list<int>|null       $images null keeps the gallery it has
     */
    private function propose(string $title, array $specs, ?array $images = [901, 902]): int
    {
        $productId = $this->onlyProductId();
        $product = $this->products->find($productId);
        self::assertNotNull($product);
        $gallery = $images ?? $product->imageIds;
        foreach ($gallery as $mediaId) {
            $this->images->give((int) $mediaId, self::VENDOR);
        }
        $saved = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $product->details->with(['title' => $title]),
            $specs,
            $gallery,
            $gallery === [] ? 0 : (int) $gallery[0],
            $product->rowVersion
        );
        self::assertSame('revision_requested', $saved->code, $saved->code);
        $revisionId = $this->revisions->pendingFor($productId)?->id ?? 0;
        self::assertGreaterThan(0, $revisionId);
        return $revisionId;
    }

    private function onlyProductId(): int
    {
        $rows = $this->products->inStatus(ProductStatus::Published, 5);
        if ($rows === []) {
            $rows = $this->products->inStatus(ProductStatus::Submitted, 5);
        }
        self::assertNotSame([], $rows);
        return $rows[0]->id;
    }

    /** A product with a real three-picture gallery, submitted and ready. */
    private function publishedProduct(): int
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, $this->details());
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];
        foreach (self::GALLERY as $mediaId) {
            $this->images->give($mediaId, self::VENDOR);
        }
        $saved = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details(),
            [],
            self::GALLERY,
            self::GALLERY[0],
            $this->products->find($productId)?->rowVersion ?? ''
        );
        self::assertTrue($saved->ok, $saved->code);
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        return $productId;
    }

    private function details(string $title = 'دستکش لاتکس'): ProductDetails
    {
        return new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: 'SKU-73',
            stock: 5
        );
    }
}
