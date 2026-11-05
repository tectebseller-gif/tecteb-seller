<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress\WpCatalogueReader;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;

/**
 * `statusesOf()` — the one field a status question needs, read in bulk.
 *
 * ### Why this test exists
 *
 * `ReconcileStorefrontStatus` used to call `detail()` once per row and read
 * `['status']` out of it and nothing else: a whole `wc_get_product()` load, a
 * brand term query and a permalink lookup, to learn one word. Fifty rows was
 * fifty product loads, and that is what made the whole-shop walk step 7 of the
 * wizard needs unaffordable — so the step went unmeasured instead of being
 * paid for.
 *
 * ⛔ **With a REAL `posts` table**, because the whole method is one `SELECT`
 * with a `post_type` filter and a separate count. A fixture that faked either
 * would be testing a sentence (`MirroredStatusBackfillTest` makes the same
 * argument one table over).
 *
 * Process-isolated because `isAvailable()` wants `class WooCommerce`, and
 * `WpDependencyProbeTest` is about that class being ABSENT.
 */
final class BulkProductStatusReadTest extends DatabaseTestCase
{
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetPosts();
    }

    protected function tearDown(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->wpdb->prefix . 'posts`');
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItReadsEveryAskedForStatusInOneAnswer(): void
    {
        $this->declareWooCommerce();
        $this->post(9319, 'publish');
        $this->post(9320, 'draft');
        $this->post(9321, 'pending');

        self::assertSame(
            [9319 => 'publish', 9320 => 'draft', 9321 => 'pending'],
            $this->reader()->statusesOf([9319, 9320, 9321])
        );
    }

    /**
     * ⛔ A product that is GONE is absent from a successful read, not an
     * error — and the caller turns that into `UNREADABLE` per row rather than
     * into agreement. «نمی‌دانم» about one row is not «نمی‌دانم» about the
     * page.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAProductThatIsGoneIsSimplyAbsent(): void
    {
        $this->declareWooCommerce();
        $this->post(9319, 'publish');

        self::assertSame([9319 => 'publish'], $this->reader()->statusesOf([9319, 404404]));
    }

    /**
     * ⛔ And an id WordPress has since given to a PAGE does not read that
     * page's status. `wc_get_product()` would refuse it, so this must too —
     * otherwise a stale `wc_product_id` could make a repair write `publish`
     * because some unrelated page is published.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnIdThatIsNoLongerAProductIsNotRead(): void
    {
        $this->declareWooCommerce();
        $this->post(9319, 'publish', 'page');
        $this->post(9320, 'publish', 'product_variation');

        self::assertSame([9320 => 'publish'], $this->reader()->statusesOf([9319, 9320]));
    }

    /** An empty question has an empty answer, and that is not a failure. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNothingAskedIsNotNothingReadable(): void
    {
        $this->declareWooCommerce();

        self::assertSame([], $this->reader()->statusesOf([]));
        self::assertSame([], $this->reader()->statusesOf([0, -4]));
    }

    /**
     * ⛔ Without WooCommerce there is no catalogue, so the answer is «I could
     * not read» and never «none of them are published» — the `alpha.68` rule
     * at the only place this class can apply it.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutWooCommerceItReadsNothingAndSaysSo(): void
    {
        $this->post(9319, 'publish');

        self::assertNull($this->reader()->statusesOf([9319]));
    }

    /**
     * ⛔ **A read that failed is `null`, and a count asked separately is the
     * only way to know.** `getResults()` answers `[]` for an empty table and
     * for a broken statement alike (`alpha.8`), so a short list against the
     * count is what tells them apart — and the whole answer is then `null`
     * rather than a map with holes in it, because holes read as «that product
     * is gone».
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAListShorterThanItsCountIsNoAnswerAtAll(): void
    {
        $this->declareWooCommerce();
        $this->post(9319, 'publish');
        $this->post(9320, 'publish');
        $gateway = new FailingDatabase($this->db);
        $gateway->failReadWhen(['SELECT ID, post_status']);

        self::assertNull($this->reader($gateway)->statusesOf([9319, 9320]));
    }

    /** And a count that could not be read is the same refusal, one step earlier. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACountThatCouldNotBeReadIsNoAnswerEither(): void
    {
        $this->declareWooCommerce();
        $this->post(9319, 'publish');
        $gateway = new FailingDatabase($this->db);
        $gateway->failReadWhen(['SELECT COUNT(*)']);

        self::assertNull($this->reader($gateway)->statusesOf([9319]));
    }

    // ================================================================ fixtures

    private function reader(?FailingDatabase $gateway = null): WpCatalogueReader
    {
        return new WpCatalogueReader($gateway ?? $this->db);
    }

    private function declareWooCommerce(): void
    {
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
    }

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
}
