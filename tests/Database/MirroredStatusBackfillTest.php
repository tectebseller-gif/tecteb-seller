<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0032ProductMirroredStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0038ObservedMirroredStatusBackfill;

/**
 * Migration 38: the stamp the Dokan intake dropped, filled from the product
 * that is still there — and only for the rows it is for.
 *
 * ⛔ **With a REAL `posts` table**, because the whole step is one `UPDATE …
 * JOIN wp_posts`. A fixture that faked the join would be testing a sentence.
 * `DatabaseTestCase::resetPostMeta()` makes the same argument for `postmeta`
 * one table over.
 */
final class MirroredStatusBackfillTest extends DatabaseTestCase
{
    private const VENDOR = 930;

    private WpDatabase $db;
    private DbProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetPosts();
        $this->resetSchema($this->db);
        $this->products = new DbProductRepository($this->db, new SystemClock());
    }

    protected function tearDown(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->wpdb->prefix . 'posts`');
        parent::tearDown();
    }

    /** The 184 rows' case: observed, no stamp, product still published. */
    public function testItFillsAnObservedRowFromTheLiveProductStatus(): void
    {
        $this->post(9101, 'publish');
        $id = $this->observedRow(9101);
        $this->clearStamp($id);

        $this->step()->up($this->db);

        self::assertSame('publish', $this->stampOf($id));
        self::assertTrue($this->step()->verify($this->db));
    }

    /** Every status is recorded as the shop holds it, not mapped. */
    public function testItRecordsWhateverTheShopHolds(): void
    {
        $cases = ['publish' => 9111, 'draft' => 9112, 'pending' => 9113, 'private' => 9114, 'future' => 9115];
        $ids = [];
        foreach ($cases as $status => $wcId) {
            $this->post($wcId, $status);
            $ids[$status] = $this->observedRow($wcId);
            $this->clearStamp($ids[$status]);
        }

        $this->step()->up($this->db);

        foreach ($cases as $status => $wcId) {
            self::assertSame($status, $this->stampOf($ids[$status]), $status);
        }
    }

    /**
     * ⛔ A row the MARKETPLACE created is left alone.
     *
     * A stamp freezes the status write, so stamping a marketplace-owned
     * product would stop the projector maintaining a status that IS this
     * marketplace's to decide.
     */
    public function testItNeverStampsAMarketplaceOwnedRow(): void
    {
        $this->post(9121, 'publish');
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'مال خودمان', priceMinor: 1),
            ProductStatus::Published,
            LinkOwnership::Marketplace,
            9121
        );

        $this->step()->up($this->db);

        self::assertNull($this->stampOf($id), 'ours stays unstamped');
    }

    /** A stamp that is already there is never rewritten. */
    public function testItNeverOverwritesAnExistingStamp(): void
    {
        $this->post(9131, 'publish');
        $id = $this->observedRow(9131);
        $this->setStamp($id, 'pending');

        $this->step()->up($this->db);

        self::assertSame('pending', $this->stampOf($id));
    }

    /**
     * ⛔ A row whose product has been DELETED gets nothing — and `verify()`
     * does not wait for it.
     *
     * The condition is the same condition, so what `up()` cannot reach
     * `verify()` does not hold the chain open for (`alpha.89`). A backfill
     * that invented `draft` for such a row would have written the very value
     * the transfer guard exists to refuse.
     */
    public function testARowWithNoProductIsSkippedAndDoesNotBlockTheChain(): void
    {
        $id = $this->observedRow(9141);            // no post row at all
        $this->clearStamp($id);

        $this->step()->up($this->db);

        self::assertNull($this->stampOf($id));
        self::assertTrue($this->step()->verify($this->db), 'the chain must not lock on it');
    }

    /** A row with no WooCommerce link at all is skipped the same way. */
    public function testARowWithNoLinkIsSkipped(): void
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'بی‌فروشگاه', priceMinor: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            null
        );

        $this->step()->up($this->db);

        self::assertNull($this->stampOf($id));
        self::assertTrue($this->step()->verify($this->db));
    }

    /** A post that is not a product is not a product. */
    public function testItIgnoresPostsThatAreNotProducts(): void
    {
        $this->post(9151, 'publish', 'page');
        $id = $this->observedRow(9151);
        $this->clearStamp($id);

        $this->step()->up($this->db);

        self::assertNull($this->stampOf($id));
    }

    /** Running it twice changes nothing: the second pass has nothing to do. */
    public function testItIsIdempotent(): void
    {
        $this->post(9161, 'publish');
        $id = $this->observedRow(9161);
        $this->clearStamp($id);

        $this->step()->up($this->db);
        $first = $this->stampOf($id);
        $this->step()->up($this->db);

        self::assertSame($first, $this->stampOf($id));
        self::assertSame('publish', $this->stampOf($id));
    }

    /** `verify()` says «not yet» while a fillable row is still waiting. */
    public function testVerifyIsFalseWhileAFillableRowWaits(): void
    {
        $this->post(9171, 'publish');
        $id = $this->observedRow(9171);
        $this->clearStamp($id);

        self::assertFalse($this->step()->verify($this->db));
        $this->step()->up($this->db);
        self::assertTrue($this->step()->verify($this->db));
    }

    /** No posts table at all: nothing to backfill, and no exception. */
    public function testWithoutAPostsTableItDoesNothingAndVerifies(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->wpdb->prefix . 'posts`');
        $id = $this->observedRow(9181);
        $this->clearStamp($id);

        $this->step()->up($this->db);

        self::assertNull($this->stampOf($id));
        self::assertTrue($this->step()->verify($this->db));
    }

    public function testItIsStepThirtyEight(): void
    {
        self::assertSame(38, $this->step()->version());
        self::assertSame('0038_observed_mirrored_status_backfill', $this->step()->id());
    }

    /**
     * ⛔ And it adds, alters and drops NOTHING.
     *
     * The round's schema number moves, so the step must say out loud that it
     * is a backfill: a reader who sees «۳۷ → ۳۸» and assumes a column would
     * plan the wrong rollback.
     */
    public function testItTouchesNoColumnOrTable(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2)
            . '/src/Modules/Product/Infrastructure/Migrations/M0038ObservedMirroredStatusBackfill.php'
        );
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        foreach (['ALTER TABLE', 'CREATE TABLE', 'DROP TABLE', 'ADD COLUMN', 'DROP COLUMN'] as $ddl) {
            self::assertStringNotContainsString($ddl, $code, 'step 38 is a backfill: ' . $ddl);
        }
    }

    // ============================================================== fixtures

    private function step(): M0038ObservedMirroredStatusBackfill
    {
        return new M0038ObservedMirroredStatusBackfill();
    }

    /** A real `posts` table with the three columns the step names. */
    private function resetPosts(): void
    {
        $table = $this->wpdb->prefix . 'posts';
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $table . '`');
        $this->wpdb->pdo()->exec(
            'CREATE TABLE `' . $table . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_type VARCHAR(20) NOT NULL DEFAULT \'post\',
                post_status VARCHAR(20) NOT NULL DEFAULT \'publish\',
                PRIMARY KEY (ID)
            ) DEFAULT CHARSET=utf8mb4'
        );
    }

    private function post(int $id, string $status, string $type = 'product'): void
    {
        $stmt = $this->wpdb->pdo()->prepare(
            'INSERT INTO `' . $this->wpdb->prefix . 'posts` (ID, post_type, post_status)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, $type, $status]);
    }

    private function observedRow(int $wcProductId): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'کالای ' . $wcProductId, priceMinor: 450000),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            $wcProductId
        );
    }

    /**
     * The state the Dokan intake left behind.
     *
     * `create()` without the eighth argument already leaves it NULL, so this
     * is belt and braces — and it asserts the fixture really is in the state
     * the test is about (`alpha.78`).
     */
    private function clearStamp(int $productId): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            'UPDATE `' . $this->wpdb->prefix . 'tmc_products`
                SET `' . M0032ProductMirroredStatus::MIRRORED . '` = NULL WHERE id = %d',
            $productId
        ));
        self::assertNull($this->stampOf($productId));
    }

    private function setStamp(int $productId, string $status): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            'UPDATE `' . $this->wpdb->prefix . 'tmc_products`
                SET `' . M0032ProductMirroredStatus::MIRRORED . '` = %s WHERE id = %d',
            $status,
            $productId
        ));
    }

    private function stampOf(int $productId): ?string
    {
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT `' . M0032ProductMirroredStatus::MIRRORED . '` FROM `'
            . $this->wpdb->prefix . 'tmc_products` WHERE id = %d',
            $productId
        ));
        return $value === null ? null : (string) $value;
    }

    /** Unused but kept honest: the column the scope is written on. */
    public function testTheScopeColumnIsTheOwnershipColumn(): void
    {
        self::assertSame('link_ownership', M0010LinkOwnership::COLUMN);
    }
}
