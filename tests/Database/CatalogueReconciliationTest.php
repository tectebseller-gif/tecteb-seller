<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\CatalogueLink;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;

/**
 * WHAT THIS PROVES: a shop's catalogue can be reconciled BY ID.
 *
 * §۴: «حداقل باید بتوان ۱۸۴ محصول #102 را با مجموعه شناسه‌ها (نه تعداد صرف)
 * تطبیق داد» — so every assertion here is about a SET of ids, and the one
 * assertion about a count is there to say that the count and the set agree.
 *
 * ⛔ AND IT CHANGES NOTHING. The last test reads every row of the catalogue
 * before and after a full walk and compares them, because «گزارش فقط‌خواندنی»
 * is a property to measure, not a sentence to write.
 */
final class CatalogueReconciliationTest extends DatabaseTestCase
{
    private const VENDOR = 102;
    private const OTHER_VENDOR = 128;

    private ProductRepositoryInterface $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(false);
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        // ⛔ A REAL `wp_posts`, because this reader JOINS it.
        //
        // The shared stub keeps posts in an array (`State::$posts`) and gives
        // this suite only a `postmeta` table, so a statement that joins
        // `wp_posts` simply fails and the page comes back `null` — which is
        // the correct answer for an unreadable read and the WRONG thing for a
        // test to be measuring. A double that cannot express the shop side
        // leaves the whole join path outside every suite (`alpha.43`,
        // `alpha.59`). It is created HERE rather than in `DatabaseTestCase`
        // on purpose: adding a table to the shared base would change what
        // every other test in this suite can see.
        $this->createPostsTable();
        Bootstrap::container()->get(MigrationRunner::class)->run();
        $this->products = Bootstrap::container()->get(ProductRepositoryInterface::class);
    }

    /**
     * The whole catalogue, walked page by page, every id exactly once.
     *
     * Fifty-seven rows and a page of twenty: three pages and a short last one,
     * which is the shape that catches an off-by-one in a keyset walk.
     */
    public function testEveryRowIsSeenExactlyOnceAcrossThePages(): void
    {
        $expected = [];
        for ($n = 0; $n < 57; $n++) {
            $expected[] = $this->row('TMC-' . $n, 4000 + $n, 'publish');
        }
        sort($expected);

        $seen = [];
        $after = 0;
        $pages = 0;
        do {
            $page = $this->products->reconciliationPage(self::VENDOR, 20, $after);
            self::assertNotNull($page, 'a page that could not be read is null, not short');
            foreach ($page as $row) {
                $seen[] = $row['id'];
                $after = $row['id'];
            }
            $pages++;
            self::assertLessThan(10, $pages, 'the walk must terminate');
        } while ($page !== []);

        sort($seen);
        self::assertSame($expected, $seen, 'the id SET, not the count');
        self::assertSame(count($expected), count(array_unique($seen)), 'no row twice');
        // …and the count agrees with the set, which is the only reason to
        // print a count at all.
        self::assertSame(57, $this->products->countForReconciliation(self::VENDOR));
    }

    /** Another shop's rows are not in this shop's reconciliation. */
    public function testOneShopsWalkNeverSeesAnothers(): void
    {
        $mine = [$this->row('MINE-1', 4100, 'publish'), $this->row('MINE-2', 4101, 'publish')];
        $theirs = $this->row('THEIRS-1', 4102, 'publish', self::OTHER_VENDOR);

        $page = $this->products->reconciliationPage(self::VENDOR, 50, 0);
        self::assertNotNull($page);
        $ids = array_map(static fn (array $r): int => $r['id'], $page);
        sort($mine);
        sort($ids);
        self::assertSame($mine, $ids);
        self::assertNotContains($theirs, $ids);
    }

    /**
     * ⛔ THE IMPORTED ROWS ARE IN IT. `forVendor()` filters
     * `link_ownership = marketplace`, which is right for every operational
     * screen and would make this report blind to the shop it was written for.
     */
    public function testAnImportedObservedRowIsPartOfTheReconciliation(): void
    {
        $marketplace = $this->row('OWNED-1', 4200, 'publish');
        $observed = $this->row('OBSERVED-1', 4201, 'publish', self::VENDOR, LinkOwnership::Observed);

        $page = $this->products->reconciliationPage(self::VENDOR, 50, 0);
        self::assertNotNull($page);
        $byId = [];
        foreach ($page as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertArrayHasKey($observed, $byId, 'the imported row is the point of the report');
        self::assertSame('observed', $byId[$observed]['link_ownership']);
        self::assertSame('marketplace', $byId[$marketplace]['link_ownership']);
        self::assertSame(
            CatalogueLink::LINKED,
            CatalogueLink::decide(
                $byId[$observed]['wc_product_id'],
                $byId[$observed]['post_type'],
                $byId[$observed]['shop_readable'],
                $byId[$observed]['rows_naming_it']
            )
        );
    }

    /**
     * The four leftovers of §۴, each with its id and its own reason — and
     * none of them called a zero or «the product is gone».
     */
    public function testEachKindOfMismatchIsNamedWithItsId(): void
    {
        $linked = $this->row('OK-1', 4300, 'publish');
        $noLink = $this->row('NOLINK-1', null, '');
        $missing = $this->row('GONE-1', 4999, '');          // no post with that id
        $wrongType = $this->row('PAGE-1', 4301, 'publish', self::VENDOR, LinkOwnership::Marketplace, 'page');

        $page = $this->products->reconciliationPage(self::VENDOR, 50, 0);
        self::assertNotNull($page);
        $answers = [];
        foreach ($page as $row) {
            $answers[$row['id']] = CatalogueLink::decide(
                $row['wc_product_id'],
                $row['post_type'],
                $row['shop_readable'],
                $row['rows_naming_it']
            );
        }
        self::assertSame(CatalogueLink::LINKED, $answers[$linked]);
        self::assertSame(CatalogueLink::TMC_ONLY, $answers[$noLink]);
        self::assertSame(CatalogueLink::WOO_MISSING, $answers[$missing]);
        self::assertSame(CatalogueLink::WRONG_TYPE, $answers[$wrongType]);
        // ⛔ A row that names nothing is not «duplicate» just because the
        // other rows naming nothing are many: `wc_product_id = NULL` matches
        // no row in the count, and 0 is not >1.
        foreach ($page as $row) {
            if ($row['wc_product_id'] === null) {
                self::assertSame(1, $row['rows_naming_it']);
            }
        }
    }

    /**
     * The other direction, by id: a shop product stamped for this vendor with
     * no row of ours.
     */
    public function testAShopProductWithNoRowIsListedByItsId(): void
    {
        $this->row('OK-1', 4400, 'publish');
        $orphan = $this->post(4401, 'publish', 'product');
        update_post_meta($orphan, WooCommerceProjector::VENDOR_META, self::VENDOR);
        // Another shop's orphan, which must NOT appear here.
        $theirs = $this->post(4402, 'publish', 'product');
        update_post_meta($theirs, WooCommerceProjector::VENDOR_META, self::OTHER_VENDOR);

        $rows = $this->products->shopProductsWithoutRow(self::VENDOR);
        self::assertNotNull($rows, 'a list that could not be read is null, not empty');
        self::assertSame([['wc_product_id' => 4401, 'post_status' => 'publish']], $rows);
    }

    /**
     * ⛔ AND THE WHOLE WALK WRITES NOTHING — measured, not promised.
     */
    public function testWalkingTheReconciliationChangesNoRow(): void
    {
        for ($n = 0; $n < 5; $n++) {
            $this->row('SNAP-' . $n, 4500 + $n, 'publish');
        }
        $this->row('SNAP-NOLINK', null, '');
        $orphan = $this->post(4600, 'publish', 'product');
        update_post_meta($orphan, WooCommerceProjector::VENDOR_META, self::VENDOR);

        $before = $this->snapshot();
        $after = 0;
        do {
            $page = $this->products->reconciliationPage(self::VENDOR, 2, $after);
            self::assertNotNull($page);
            foreach ($page as $row) {
                $after = $row['id'];
            }
        } while ($page !== []);
        $this->products->shopProductsWithoutRow(self::VENDOR);
        $this->products->countForReconciliation(self::VENDOR);

        self::assertSame($before, $this->snapshot(), 'not one byte of the catalogue may move');
    }

    /**
     * ⛔ A PAGE THAT CAME UP SHORT IS `null`, NEVER A SHORTER PAGE.
     *
     * Written because falsification found the hole, not because reading the
     * code did: the mutation «drop the short-read guard» SURVIVED the first
     * loop. Every other test here reads a healthy database, where the count
     * and the list always agree, so the guard was unreachable — and an
     * unreachable guard is byte for byte indistinguishable from an absent one
     * (`alpha.80`).
     *
     * The refusal is injected into the LIST statement by name and the count
     * is left working, which is exactly the shape the guard exists for:
     * `getResults()` answers «no rows» and «the statement failed» with the
     * same empty array (`alpha.8`), so without the comparison a reconciliation
     * of 57 products would report an empty catalogue as the truth.
     *
     * And the control is the second half: once the refusal stops, the same
     * call answers the whole page. A test that only proved `null` would pass
     * against a reader that always says `null`.
     */
    public function testAPageWhoseRowsCouldNotBeReadIsNullRatherThanEmpty(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $this->row('SHORT-' . $n, 4700 + $n, 'publish');
        }

        $failing = new FailingDatabase(new WpDatabase($this->wpdb));
        // The list, named by two things only it has: the derived column and
        // the join to the shop's own table. The `COUNT(*)` beside it has
        // neither, so it keeps working and the two answers disagree.
        $failing->failReadWhen(['rows_naming_it', 'LEFT JOIN']);
        $products = new DbProductRepository($failing, new SystemClock());

        self::assertNull(
            $products->reconciliationPage(self::VENDOR, 50),
            '⛔ a read that failed is not an empty catalogue'
        );

        $failing->stopFailing();
        $page = $products->reconciliationPage(self::VENDOR, 50);
        self::assertNotNull($page, 'and the control: a working read answers');
        self::assertCount(3, $page);
    }

    // ------------------------------------------------------------- fixture

    /** The shop's own table, with the columns this reader reads. */
    private function createPostsTable(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->wpdb->posts . '`');
        $this->wpdb->pdo()->exec(
            'CREATE TABLE `' . $this->wpdb->posts . '` (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_title TEXT NOT NULL,
                post_status VARCHAR(20) NOT NULL DEFAULT \'publish\',
                post_type VARCHAR(20) NOT NULL DEFAULT \'post\',
                PRIMARY KEY (ID),
                KEY type_status (post_type, post_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }


    /** @return list<array<string,mixed>> every catalogue row and every product post */
    private function snapshot(): array
    {
        $rows = $this->wpdb->get_results(
            'SELECT * FROM `' . $this->wpdb->prefix . M0005CreateProductTables::PRODUCTS . '` ORDER BY id ASC',
            ARRAY_A
        ) ?: [];
        $posts = $this->wpdb->get_results(
            'SELECT ID, post_type, post_status, post_title FROM `' . $this->wpdb->posts . '` ORDER BY ID ASC',
            ARRAY_A
        ) ?: [];
        return [$rows, $posts];
    }

    /**
     * One catalogue row, and the shop post it names when `$postStatus` is not
     * empty.
     */
    private function row(
        string $sku,
        ?int $wcProductId,
        string $postStatus,
        int $vendorUserId = self::VENDOR,
        LinkOwnership $ownership = LinkOwnership::Marketplace,
        string $postType = 'product'
    ): int {
        if ($wcProductId !== null && $postStatus !== '') {
            $this->post($wcProductId, $postStatus, $postType);
        }
        return $this->products->create(
            $vendorUserId,
            new ProductDetails(
                title: 'محصول ' . $sku,
                type: ProductType::SIMPLE,
                categoryKey: 'tape',
                priceMinor: 100000,
                sku: $sku,
                stock: 3
            ),
            ProductStatus::Draft,
            $ownership,
            $wcProductId
        );
    }

    /** One row in `wp_posts`, written directly: this is the shop's side. */
    private function post(int $id, string $status, string $type): int
    {
        $this->wpdb->query($this->wpdb->prepare(
            'INSERT INTO `' . $this->wpdb->posts . '` (ID, post_author, post_title, post_status, post_type)
             VALUES (%d, %d, %s, %s, %s)',
            $id,
            1,
            'shop product ' . $id,
            $status,
            $type
        ));
        return $id;
    }
}
