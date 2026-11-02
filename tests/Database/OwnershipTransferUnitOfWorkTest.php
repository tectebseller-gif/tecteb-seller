<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;

/**
 * Transferring operational ownership is TWO writes, and they are one unit.
 *
 * `TransferOwnership::move()` stamps the WooCommerce post and then writes
 * `link_ownership`. Until `alpha.90` only the first direction of failure was
 * handled — the comment in that method says «if this fails the row must NOT
 * change», which is about the POST refusing. Nothing said what happens when
 * the ROW refuses after the post has already moved, and that is the half that
 * costs:
 *
 *  - taking leaves a stamp on a post whose row says `observed`, and no other
 *    row can claim that post afterwards;
 *  - giving back leaves a row saying `marketplace` with no stamp, and THAT
 *    combination makes the next projection save a SECOND WooCommerce product
 *    (`SyncCatalog` asks the row for permission, `owns()` answers false,
 *    `project()` takes its `$isNew` branch) — while the retry can never
 *    finish, because `releaseStorefrontPost()` refuses a post that is not
 *    ours.
 *
 * Measured here on real MariaDB with the row write refused by name, and the
 * projector is a double that now carries BOTH of the real guards: a double
 * that answered `true` to every release made the stuck state inexpressible,
 * which is `alpha.59`'s rule in a new place.
 *
 * ⛔ One of those two guards has no behaviour test here, and the reason is
 * measured rather than assumed: `claimStorefrontPost()` refusing a post
 * another row owns cannot be reached through `move()` at all, because
 * `tmc_products` carries `UNIQUE KEY tmc_product_wc (wc_product_id)`
 * (migration 6) — a second row pointing at the same post is refused by the
 * INDEX before any projector is asked. It stays in the double because it is
 * what the real projector does, and it is a second line of defence for a
 * stamp whose row is gone.
 */
final class OwnershipTransferUnitOfWorkTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const VENDOR = 640;
    private const WC_PRODUCT = 7640;

    private WpDatabase $db;
    private DbProductRepository $products;
    private FakeCatalogProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        (new DbVendorRepository($this->db, $clock))
            ->upsertProfile(self::VENDOR, 'فروشگاه انتقال', true, false);
        $this->projector = new FakeCatalogProjector();
    }

    // =============================================== the healthy directions

    /** The control: both writes land, and the two halves agree. */
    public function testATakeThatSucceedsLeavesTheRowAndTheStampAgreeing(): void
    {
        $productId = $this->observedProduct();

        $result = $this->transfer()->take($productId);

        self::assertTrue($result->ok, (string) $result->code);
        self::assertSame('ownership_taken', $result->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($productId));
        self::assertTrue($this->projector->owns(self::WC_PRODUCT, $productId));
    }

    /** And back again, with the stamp removed and the row following it. */
    public function testAGiveBackThatSucceedsRemovesBothHalves(): void
    {
        $productId = $this->observedProduct();
        self::assertTrue($this->transfer()->take($productId)->ok);

        $result = $this->transfer()->giveBack($productId);

        self::assertTrue($result->ok, (string) $result->code);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($productId));
        self::assertFalse($this->projector->owns(self::WC_PRODUCT, $productId));
    }

    // ================================== the row refuses after the post moved

    /**
     * Taking: the stamp goes back, so nothing is left claiming a post this
     * marketplace does not run.
     */
    public function testWhenTheRowRefusesATakeThePostIsUnstamped(): void
    {
        $productId = $this->observedProduct();
        $failing = $this->failingOnOwnershipWrite();

        $result = $this->transfer($failing)->take($productId);

        self::assertFalse($result->ok);
        self::assertSame('storage_failed', $result->code, 'the whole pair is undone, so «nothing changed» is true');
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($productId));
        self::assertFalse(
            $this->projector->owns(self::WC_PRODUCT, $productId),
            'a stamp left here would stop any other row from ever claiming this post'
        );
    }

    /**
     * Giving back: the stamp goes back ON, which is what stops the duplicate.
     *
     * The assertion that matters is not the code — it is that the row and the
     * stamp still say the SAME thing afterwards, because it is the
     * disagreement that `project()` turns into a second product.
     */
    public function testWhenTheRowRefusesAGiveBackThePostIsStampedAgain(): void
    {
        $productId = $this->observedProduct();
        self::assertTrue($this->transfer()->take($productId)->ok);
        $failing = $this->failingOnOwnershipWrite();

        $result = $this->transfer($failing)->giveBack($productId);

        self::assertFalse($result->ok);
        self::assertSame('storage_failed', $result->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($productId));
        self::assertTrue(
            $this->projector->owns(self::WC_PRODUCT, $productId),
            'row marketplace + no stamp is the state that creates a duplicate product'
        );
    }

    /** And the retry then finishes, which the half-written state forbade. */
    public function testAGiveBackRetriesSuccessfullyAfterARefusedRowWrite(): void
    {
        $productId = $this->observedProduct();
        self::assertTrue($this->transfer()->take($productId)->ok);
        self::assertFalse($this->transfer($this->failingOnOwnershipWrite())->giveBack($productId)->ok);

        $again = $this->transfer()->giveBack($productId);

        self::assertTrue($again->ok, (string) $again->code);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($productId));
        self::assertFalse($this->projector->owns(self::WC_PRODUCT, $productId));
    }

    // ============================= and when the compensation ITSELF fails

    /**
     * «It went back» is a WRITE too, and claiming one that failed is the
     * `alpha.31` mistake. So the answer has its own name and the trail has
     * its own row — a manager who closes the page would otherwise have
     * nowhere to learn which post disagrees with its row.
     */
    public function testACompensationThatFailsIsNamedAndAudited(): void
    {
        $productId = $this->observedProduct();
        $failing = $this->failingOnOwnershipWrite();
        $this->projector->refuseRelease = true;

        $result = $this->transfer($failing)->take($productId);

        self::assertFalse($result->ok);
        self::assertSame('ownership_stamp_unrestored', $result->code);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($productId));
        self::assertTrue($this->projector->owns(self::WC_PRODUCT, $productId), 'the stamp really is still there');
        self::assertSame(
            1,
            $this->auditRowsFor(AuditEventCatalog::DOKAN_OWNERSHIP_HALF_WRITTEN),
            'the disagreement is recorded, not only flashed'
        );
    }

    /** The other direction of the same refusal, named the same way. */
    public function testAFailedReclaimOnGiveBackIsNamedToo(): void
    {
        $productId = $this->observedProduct();
        self::assertTrue($this->transfer()->take($productId)->ok);
        $failing = $this->failingOnOwnershipWrite();
        $this->projector->refuseClaim = true;

        $result = $this->transfer($failing)->giveBack($productId);

        self::assertFalse($result->ok);
        self::assertSame('ownership_stamp_unrestored', $result->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($productId));
        self::assertFalse($this->projector->owns(self::WC_PRODUCT, $productId));
    }

    /**
     * A SUCCESSFUL pair writes no half-written row — the control for the
     * assertion above, so «audited» cannot pass by the event being written
     * every time.
     */
    public function testASuccessfulTransferWritesNoHalfWrittenAuditRow(): void
    {
        $productId = $this->observedProduct();

        self::assertTrue($this->transfer()->take($productId)->ok);

        self::assertSame(0, $this->auditRowsFor(AuditEventCatalog::DOKAN_OWNERSHIP_HALF_WRITTEN));
        self::assertSame(1, $this->auditRowsFor(AuditEventCatalog::DOKAN_OWNERSHIP_CHANGED));
    }

    /**
     * A row with no WooCommerce product behind it has no first write to put
     * back, and «nothing changed» is then the whole truth.
     *
     * The short-circuit that says so is load-bearing: without it this answer
     * would be `ownership_stamp_unrestored` about a stamp that never existed,
     * and the manager would be sent to look at a post that is not there.
     */
    public function testARowWithNoStorefrontProductNeedsNoCompensation(): void
    {
        $productId = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'ردیفِ بی‌محصول ووکامرس', priceMinor: 1000),
            ProductStatus::Draft,
            LinkOwnership::Observed
        );
        self::assertGreaterThan(0, $productId);
        // Make even a compensation that DID run refuse, so the answer can
        // only be right because nothing was attempted.
        $this->projector->refuseRelease = true;

        $result = $this->transfer($this->failingOnOwnershipWrite())->take($productId);

        self::assertFalse($result->ok);
        self::assertSame('storage_failed', $result->code);
        self::assertSame(0, $this->auditRowsFor(AuditEventCatalog::DOKAN_OWNERSHIP_HALF_WRITTEN));
    }

    /**
     * Why the half-written give-back was unrecoverable, pinned directly.
     *
     * A control: it passes on both trees, and it is here because it is the
     * sentence the fix above rests on. A row saying `marketplace` whose post
     * carries no stamp cannot be handed back at ALL — the release refuses
     * outright — so without the compensation the only way out of that state
     * was editing the column by hand, while every projection in the meantime
     * saved another product.
     */
    public function testARowSayingMarketplaceWithNoStampCannotBeGivenBackAtAll(): void
    {
        $productId = $this->observedProduct();
        // Exactly the state the unguarded pair used to leave behind: the row
        // moved, the stamp did not.
        self::assertTrue($this->products->setLinkOwnership($productId, LinkOwnership::Marketplace));
        self::assertFalse($this->projector->owns(self::WC_PRODUCT, $productId));

        $result = $this->transfer()->giveBack($productId);

        self::assertFalse($result->ok);
        self::assertSame('storefront_refused', $result->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($productId), 'and it is stuck there');
    }

    // ------------------------------------------------------------- fixtures

    private function observedProduct(): int
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'دستگاه آزمون انتقال', priceMinor: 450000),
            ProductStatus::Draft,
            // The state every imported row starts in, written at creation
            // rather than patched afterwards: `observed` is what an intake
            // leaves behind and it is the only state `take()` moves from.
            LinkOwnership::Observed,
            self::WC_PRODUCT
        );
        self::assertGreaterThan(0, $id);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($id));
        // The stamp is NOT written by an intake (`alpha.55`), so the double
        // starts without it exactly as WooCommerce would.
        self::assertFalse($this->projector->owns(self::WC_PRODUCT, $id));
        return $id;
    }

    private function failingOnOwnershipWrite(): FailingDatabase
    {
        $failing = new FailingDatabase($this->db);
        // The one statement `setLinkOwnership()` sends, named by its table and
        // its column: nothing else in this plugin writes that column.
        $failing->failWhen(['UPDATE', 'tmc_products', M0010LinkOwnership::COLUMN]);
        return $failing;
    }

    private function transfer(?FailingDatabase $gateway = null): TransferOwnership
    {
        $products = $gateway === null
            ? $this->products
            : new DbProductRepository($gateway, new SystemClock());
        return new TransferOwnership(
            $products,
            $this->projector,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
        );
    }

    private function auditRowsFor(string $eventType): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_audit_events` WHERE event_type = %s',
            $eventType
        ));
    }
}
