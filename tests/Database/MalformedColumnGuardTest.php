<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0024PurchaseStep;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0025BrandRegistry;
use Tecteb\Marketplace\Modules\Product\Infrastructure\ProductTableShape;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;

/**
 * THE OPEN FINDING, closed — and the healthy path left alone.
 *
 * Carried open through `alpha.45`, `alpha.46` and `alpha.47`: a column of the
 * right name and the wrong shape makes the migration's `verify()` refuse, which
 * is correct, and does nothing at all to the WRITE. Measured on `alpha.45` with
 * `purchase_step` nullable and defaulting to nought — the schema version stayed
 * at 23, saving a product still landed (`wrote=1`), the wrong default went with
 * it, and `PurchaseQuantity::of()` turned the nought back into 1. So nothing
 * looked wrong on any screen and the column stayed invisible. The protection
 * was an ORDER: confirm the structure, then save products.
 *
 * §6 of the owner's instruction: «اگر ساختار لازم سالم نیست، نوشتن وابسته
 * نباید دادهٔ غلط ذخیره کند یا موفق گزارش شود. شمارهٔ ساختار به‌تنهایی کافی
 * نیست. مسیر سالم را بی‌دلیل متوقف نکن.» All three halves are asserted here,
 * and the last one matters as much as the first: every other test in this
 * suite writing products IS the proof that the healthy path still works, and
 * the last test here says it in one place.
 *
 * The column is altered with real DDL on the real table, because the subject is
 * what SQL accepts — and SQL accepting it is the whole reason the finding
 * existed.
 */
final class MalformedColumnGuardTest extends DatabaseTestCase
{
    private const VENDOR = 71;

    private WpDatabase $db;
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $this->table = $this->wpdb->prefix . 'tmc_products';
        (new DbVendorRepository($this->db, new SystemClock()))
            ->upsertProfile(self::VENDOR, 'داروخانه هفتاد و یک', true, false);
    }

    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    /** The baseline, measured rather than assumed. */
    public function testASoundTableSavesProducts(): void
    {
        self::assertSame([], $this->repository()->unsoundColumns());
        $created = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز استریل'));
        self::assertTrue($created->ok, $created->code);
        self::assertCount(1, $this->repository()->forVendor(self::VENDOR));
    }

    /**
     * THE EXACT SHAPE THAT WAS INVISIBLE: present, right name, wrong default.
     *
     * `INT NULL DEFAULT 0` is what `alpha.45`'s own evidence script injected,
     * and what it measured was `wrote=1`.
     */
    public function testAColumnWithTheRightNameAndTheWrongShapeRefusesTheWrite(): void
    {
        $this->alter('`' . M0024PurchaseStep::STEP . '` INT NULL DEFAULT 0');

        $shape = new ProductTableShape($this->db, $this->table);
        self::assertSame([M0024PurchaseStep::STEP], $shape->unsoundColumns());

        $refused = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز استریل'));
        self::assertFalse($refused->ok);
        self::assertSame('schema_unsound', $refused->code);
        self::assertSame(M0024PurchaseStep::STEP, $refused->context['columns']);
        self::assertSame([], $this->repository()->forVendor(self::VENDOR), 'and NOTHING was written');
    }

    /**
     * The repository refuses even when nobody asked it to.
     *
     * The second half of the guard, and the one the finding needs: a path that
     * forgot to ask the service must still not be able to store wrong data.
     */
    public function testTheRepositoryItselfRefusesAWriteOnAnUnsoundTable(): void
    {
        $this->alter('`' . M0024PurchaseStep::STEP . '` INT NULL DEFAULT 0');
        $products = $this->repository();

        self::assertSame(0, $products->create(self::VENDOR, $this->details('گاز استریل'), ProductStatus::Draft));
        self::assertFalse($products->updateInventory(1, 5, 'SKU-X', 1, null, 1));
        self::assertFalse($products->updateDetails(1, $this->details('گاز استریل'), ''));
        self::assertSame([], $products->forVendor(self::VENDOR));
    }

    /**
     * A MISSING column is the same answer, so «the structure is behind» needs
     * no separate question — «شمارهٔ ساختار به‌تنهایی کافی نیست».
     */
    public function testAMissingColumnIsTheSameRefusal(): void
    {
        self::assertNotNull($this->db->execute(
            'ALTER TABLE `' . $this->table . '` DROP COLUMN `' . M0025BrandRegistry::BRAND_TERM . '`'
        ));
        $shape = new ProductTableShape($this->db, $this->table);
        self::assertSame([M0025BrandRegistry::BRAND_TERM], $shape->unsoundColumns());

        $refused = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز استریل'));
        self::assertSame('schema_unsound', $refused->code);
    }

    /** A nullable column where the write needs NOT NULL is caught too. */
    public function testANullableColumnTheWriteNeedsFilledIsCaught(): void
    {
        $this->alter('`stock` INT NULL');
        $shape = new ProductTableShape($this->db, $this->table);
        self::assertSame(['stock'], $shape->unsoundColumns());
    }

    /**
     * One read per request, and a fresh request sees the truth.
     *
     * The memo is what keeps the healthy path cheap; a test that altered the
     * column and asked the SAME object again would be asserting the memo, so
     * both are asserted: the old object still says sound, a new one does not.
     */
    public function testTheAnswerIsMemoisedForTheRequestAndFreshAfterIt(): void
    {
        $warm = new ProductTableShape($this->db, $this->table);
        self::assertSame([], $warm->unsoundColumns());

        $this->alter('`' . M0024PurchaseStep::STEP . '` INT NULL DEFAULT 0');
        self::assertSame([], $warm->unsoundColumns(), 'the same request keeps its answer');
        $warm->forget();
        self::assertSame([M0024PurchaseStep::STEP], $warm->unsoundColumns());
        self::assertSame([M0024PurchaseStep::STEP], (new ProductTableShape($this->db, $this->table))->unsoundColumns());
    }

    /**
     * AN UNREADABLE TABLE IS NOT SOUND — and this test said the opposite until
     * `alpha.49`.
     *
     * It used to assert `isSound()` is true here, with an argument: refusing
     * every product save because a read failed stops a working site over
     * something that is not evidence of anything. The owner read `alpha.48`
     * and rejected that argument — «ساختار نامعلوم با سالم یکی نباشد» — and
     * the measurement on `alpha.48`'s own bytes is why they are right:
     * `reads_as_sound=yes problem=product_table_unreadable wrote=1`. The class
     * KNEW the read had failed and wrote anyway, which is exactly the shape of
     * the finding it was built to close.
     *
     * So the old assertions are gone rather than loosened. A test that writes
     * a defective rule down keeps the suite green and the author blind
     * (`alpha.46`), and this one did for a round.
     */
    public function testATableThatCannotBeReadIsUnknownAndNotSound(): void
    {
        $shape = new ProductTableShape($this->db, $this->wpdb->prefix . 'tmc_no_such_table');
        self::assertSame([], $shape->unsoundColumns(), 'no column can be named, because none was read');
        self::assertFalse($shape->isKnown());
        self::assertFalse($shape->isSound(), 'unknown is not sound');
        self::assertSame('product_table_unreadable', $shape->lastProblem());
    }

    /**
     * And it has its OWN refusal code, because «این ستون‌ها غلط‌اند» must not
     * be said about a question nobody could answer.
     *
     * The service is pointed at a repository whose table is not there, which
     * is the only honest way to make the read fail without a mock: the shape
     * is read from `information_schema` for a table name, and a name that does
     * not exist produces the same empty answer a broken read does.
     */
    public function testAnUnknownStructureRefusesTheWriteUnderItsOwnName(): void
    {
        self::assertNotNull(
            $this->db->execute('RENAME TABLE `' . $this->table . '` TO `' . $this->table . '_away`'),
            'the fixture could not take the table away'
        );
        try {
            $products = $this->repository();
            self::assertFalse($products->structureKnown());
            $refused = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز استریل'));
            self::assertFalse($refused->ok);
            self::assertSame('schema_unknown', $refused->code);
            self::assertSame('', (string) ($refused->context['columns'] ?? ''), 'and it names no column');
        } finally {
            $this->db->execute('RENAME TABLE `' . $this->table . '_away` TO `' . $this->table . '`');
        }
    }

    /**
     * A COLUMN OF THE RIGHT NAME AND THE WRONG TYPE — the second half of what
     * `alpha.48` did not ask.
     *
     * `stock` narrowed from `int` to `tinyint` keeps its name, its NOT NULL and
     * its default of nought: every question `alpha.48` asked. Measured on its
     * bytes, the vendor's 500 was stored as 127 and the save said
     * `inventory_saved`. Nothing raised an error because `wpdb` strips the
     * strict SQL modes itself, which is the ordinary WordPress configuration.
     */
    public function testAColumnOfTheWrongTypeIsUnsound(): void
    {
        $this->alter('`stock` TINYINT NOT NULL DEFAULT 0');
        $shape = new ProductTableShape($this->db, $this->table);
        self::assertSame(['stock'], $shape->unsoundColumns());
        self::assertFalse($shape->isSound());

        $refused = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز استریل'));
        self::assertSame('schema_unsound', $refused->code);
        self::assertSame('stock', $refused->context['columns']);
        self::assertSame([], $this->repository()->forVendor(self::VENDOR), 'and NOTHING was written');
    }

    /**
     * Narrower is a loss; WIDER is not.
     *
     * A `bigint` where the migration declares `int` holds everything an `int`
     * holds, so refusing it would stop a site somebody had widened on purpose
     * — «مسیر سالم را بی‌دلیل متوقف نکن». Within a family only: a number
     * stored as text is not more room, it is a different column, and the test
     * below says so.
     */
    public function testAWiderIntegerOfTheSameFamilyIsStillSound(): void
    {
        $this->alter('`stock` BIGINT NOT NULL DEFAULT 0');
        self::assertSame([], (new ProductTableShape($this->db, $this->table))->unsoundColumns());
        $created = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز استریل'));
        self::assertTrue($created->ok, $created->code);
    }

    /** A number kept as text is a different column, not a wider one. */
    public function testANumberStoredAsTextIsUnsound(): void
    {
        $this->alter("`stock` VARCHAR(20) NOT NULL DEFAULT '0'");
        self::assertSame(['stock'], (new ProductTableShape($this->db, $this->table))->unsoundColumns());
    }

    /**
     * And the same question for the text columns, where the loss is truncation.
     *
     * A `varchar(5)` title keeps its name, its NOT NULL and its empty default
     * and silently keeps five characters of what the vendor typed.
     */
    public function testATextColumnTooShortToHoldWhatIsWrittenIsUnsound(): void
    {
        $this->alter("`title` VARCHAR(5) NOT NULL DEFAULT ''");
        self::assertSame(['title'], (new ProductTableShape($this->db, $this->table))->unsoundColumns());
    }

    /** A longer one is more room, so it is sound. */
    public function testALongerTextColumnIsStillSound(): void
    {
        $this->alter("`title` VARCHAR(255) NOT NULL DEFAULT ''");
        self::assertSame([], (new ProductTableShape($this->db, $this->table))->unsoundColumns());
    }

    /** And the healthy path, after the shape is put back. */
    public function testPuttingTheShapeBackLetsTheWriteThroughAgain(): void
    {
        $this->alter('`' . M0024PurchaseStep::STEP . '` INT NULL DEFAULT 0');
        self::assertSame('schema_unsound', $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز'))->code);

        $this->alter('`' . M0024PurchaseStep::STEP . '` INT NOT NULL DEFAULT 1');
        $created = $this->service()->save(self::VENDOR, self::VENDOR, 0, $this->details('گاز'));
        self::assertTrue($created->ok, $created->code);
        self::assertCount(1, $this->repository()->forVendor(self::VENDOR));
    }

    // ---------------------------------------------------------------- helpers

    private function alter(string $definition): void
    {
        self::assertNotNull(
            $this->db->execute('ALTER TABLE `' . $this->table . '` MODIFY COLUMN ' . $definition),
            'the fixture could not reshape the column'
        );
    }

    private function repository(): DbProductRepository
    {
        return new DbProductRepository($this->db, new SystemClock());
    }

    private function service(): ManageProducts
    {
        $clock = new SystemClock();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $products = $this->repository();
        $templates = new DbSpecTemplateRepository($this->db, $clock);
        $variations = new DbVariationRepository($this->db, $clock);
        return new ManageProducts(
            $products,
            $templates,
            new DbProductRevisionRepository($this->db, $clock),
            new ProductReadiness($templates, $variations),
            new SyncCatalog($products, $variations, new FakeCatalogProjector(), $audit),
            new FakeProductImages(),
            new StaffAccess(new DbStaffRepository($this->db, $clock), new DbVendorRepository($this->db, $clock)),
            new ProductPublishPolicy(new WpOptionStore()),
            new ProductStateMachine(),
            $audit
        );
    }

    private function details(string $title): ProductDetails
    {
        return new ProductDetails(
            title: $title,
            type: 'simple',
            categoryKey: 'gauze',
            shortDescription: 'یک جمله',
            priceMinor: 120000,
            sku: 'SKU-SHAPE',
            stock: 4
        );
    }
}
