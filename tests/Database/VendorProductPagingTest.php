<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductListView;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use TmcWpStubs\State;

/**
 * EVERY PRODUCT OF A SHOP WITH MORE THAN ONE PAGE IS REACHABLE — walked
 * page by page through the real area router, against a real database.
 *
 * The owner's shop has 184 products and `PER_PAGE` is 20, so the list has
 * ten pages and nine of them were 404 (see `VendorPagerAddressTest` for
 * core's two rules and why). The address is fixed there; what is measured
 * HERE is the thing the owner actually needs: that walking the pager visits
 * each of their products exactly once, that a search and a status filter
 * survive the walk, that a page number past the end is not a dead end, and
 * that no page ever shows another shop's product.
 *
 * Forty-five products rather than 184: three pages is the smallest catalogue
 * where a middle page exists, and a middle page is where an off-by-one in the
 * offset shows up. The count is asserted, not assumed.
 */
final class VendorProductPagingTest extends DatabaseTestCase
{
    private const VENDOR = 9501;
    private const OTHER_VENDOR = 9502;
    private const SEEDED = 45;

    private ProductRepositoryInterface $products;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->resetSchema($db);
        $this->bootPlugin(false);
        Bootstrap::container()->get(MigrationRunner::class)->run();

        $clock = new SystemClock();
        $vendors = new DbVendorRepository($db, $clock);
        $vendors->upsertProfile(self::VENDOR, 'تجهیزات تک‌طب', true, false);
        $vendors->upsertProfile(self::OTHER_VENDOR, 'فروشگاه دیگر', true, false);
        $this->products = Bootstrap::container()->get(ProductRepositoryInterface::class);

        for ($n = 1; $n <= self::SEEDED; $n++) {
            // Two in three are published and carry the Persian phrase, so
            // «filter by status» and «search» each select THIRTY rows — more
            // than one page of twenty, which is the only way paging a filter
            // or a search is reachable at all. One in three is a draft with a
            // different title, so «only matches» is a measurable claim.
            $published = $n % 3 !== 0;
            $this->seed(
                self::VENDOR,
                ($published ? 'چسب تزریق شمارهٔ ' : 'دستکش شمارهٔ ') . $n,
                'SKU-' . $n,
                $published ? ProductStatus::Published : ProductStatus::Draft
            );
        }
        $this->seed(self::OTHER_VENDOR, 'محصول فروشگاه دیگر', 'SKU-OTHER', ProductStatus::Published);

        State::loginAs(self::VENDOR, []);
    }

    private function seed(int $vendor, string $title, string $sku, ProductStatus $status): void
    {
        $id = $this->products->create(
            $vendor,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 200000, sku: $sku, stock: 3),
            $status
        );
        self::assertGreaterThan(0, $id, 'the fixture row was stored');
    }

    /** @param array<string,string> $query */
    private function render(array $query): string
    {
        $_GET = $query;
        $_POST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return (new ProductArea(Bootstrap::container()))->render(new VendorAreaView(
            Request::capture(),
            self::VENDOR,
            new VendorUrls(
                'https://example.test/vendor/',
                'https://example.test/vendor/application/',
                'https://example.test/vendor/store/',
                'https://example.test/vendor/staff/',
                'https://example.test/vendor/invite/',
                'https://example.test/vendor/products/'
            ),
            '<input type="hidden" name="_wpnonce" value="t" />',
            null
        ));
    }

    /** @return list<string> the SKU of every row on the rendered page, in order */
    private static function skusOn(string $html): array
    {
        preg_match_all('#SKU-[0-9A-Z]+#', $html, $found);
        return array_values(array_unique($found[0]));
    }

    /** The page a pager link points at, or 1 when it carries no page parameter. */
    private static function pageOf(string $href): int
    {
        $query = [];
        parse_str((string) parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_QUERY), $query);
        return isset($query[VendorUrls::PAGE_PARAM]) ? (int) $query[VendorUrls::PAGE_PARAM] : 1;
    }

    /** @return list<string> */
    private static function pagerLinks(string $html): array
    {
        if (preg_match('#<nav class="tv-pager".*?</nav>#s', $html, $nav) !== 1) {
            return [];
        }
        preg_match_all('#href="([^"]+)"#', $nav[0], $found);
        return $found[1];
    }

    /**
     * WHAT THIS PROVES: following «صفحه بعد» from page one to the end visits
     * all forty-five products, each exactly once, and stops.
     */
    public function testWalkingTheWholePagerVisitsEveryProductOnce(): void
    {
        $pages = (int) ceil(self::SEEDED / ProductListView::PER_PAGE);
        self::assertSame(3, $pages, 'the fixture really is a multi-page catalogue');

        $seen = [];
        $page = 1;
        $visited = 0;
        while ($page !== 0 && $visited < 10) {
            $visited++;
            $html = $this->render($page > 1 ? [VendorUrls::PAGE_PARAM => (string) $page] : []);
            $onThisPage = self::skusOn($html);
            self::assertNotSame([], $onThisPage, 'page ' . $page . ' has rows');
            foreach ($onThisPage as $sku) {
                self::assertArrayNotHasKey($sku, $seen, $sku . ' appeared on page ' . $page . ' and page ' . ($seen[$sku] ?? 0));
                $seen[$sku] = $page;
            }
            $next = 0;
            foreach (self::pagerLinks($html) as $href) {
                if (self::pageOf($href) === $page + 1) {
                    $next = $page + 1;
                }
            }
            $page = $next;
        }
        self::assertSame(3, $visited, 'the walk ended on the last page rather than looping');
        self::assertCount(self::SEEDED, $seen, 'every seeded product was reachable');
    }

    /**
     * WHAT THIS PROVES: «صفحه قبل» from the middle page goes back to the bare
     * first-page address, and that address is the one the first page has.
     */
    public function testThePreviousLinkFromTheMiddlePageReturnsToTheFirst(): void
    {
        $html = $this->render([VendorUrls::PAGE_PARAM => '2']);
        $links = self::pagerLinks($html);
        self::assertCount(2, $links, 'the middle page offers both directions');
        self::assertSame(1, self::pageOf($links[0]));
        self::assertStringNotContainsString(VendorUrls::PAGE_PARAM, html_entity_decode($links[0], ENT_QUOTES));
        self::assertSame(3, self::pageOf($links[1]));
    }

    /**
     * WHAT THIS PROVES: a page number past the end is not a dead end. It used
     * to render the empty state WITHOUT a pager, so the only way back was the
     * browser's history or retyping the URL — and the empty state's advice
     * («وضعیت دیگری را ببینید») was wrong, because the products are one page
     * back.
     */
    public function testAPageNumberPastTheEndShowsTheLastPage(): void
    {
        $last = $this->render([VendorUrls::PAGE_PARAM => '3']);
        $beyond = $this->render([VendorUrls::PAGE_PARAM => '99']);
        self::assertSame(self::skusOn($last), self::skusOn($beyond), 'page 99 answers with the last page');
        self::assertNotSame([], self::pagerLinks($beyond), 'and it has a pager to come back with');
        self::assertStringNotContainsString('هنوز محصولی ثبت نکرده‌اید', $beyond);
    }

    /**
     * WHAT THIS PROVES: a Persian search survives paging, in both directions —
     * the rows on page two still match, and the links still carry the term.
     * RED before this round: the pager built its link from the status alone.
     */
    public function testAPersianSearchSurvivesPaging(): void
    {
        $matching = self::SEEDED - (int) floor(self::SEEDED / 3);
        self::assertGreaterThan(ProductListView::PER_PAGE, $matching, 'the search spans more than one page');

        $first = $this->render(['q' => 'چسب تزریق']);
        self::assertCount(ProductListView::PER_PAGE, self::skusOn($first), 'a full page of matches');
        self::assertStringNotContainsString('دستکش شمارهٔ', $first, 'and nothing that does not match');

        $links = self::pagerLinks($first);
        self::assertNotSame([], $links);
        $next = html_entity_decode($links[count($links) - 1], ENT_QUOTES);
        self::assertStringContainsString('q=', $next, 'the next-page link carries the search');

        $query = [];
        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
        $second = $this->render(array_map('strval', $query));
        self::assertStringNotContainsString('دستکش شمارهٔ', $second, 'page two is still the search result');
        self::assertCount($matching - ProductListView::PER_PAGE, self::skusOn($second));
        self::assertSame([], array_intersect(self::skusOn($first), self::skusOn($second)));
    }

    /**
     * WHAT THIS PROVES: the status tab survives paging and selects the same
     * subset on every page.
     */
    public function testTheStatusFilterSurvivesPaging(): void
    {
        $first = $this->render(['status' => ProductStatus::Published->value]);
        self::assertStringNotContainsString('دستکش شمارهٔ', $first, 'only published rows');
        $links = self::pagerLinks($first);
        self::assertNotSame([], $links, 'the published subset is itself more than one page');

        $query = [];
        parse_str((string) parse_url(html_entity_decode($links[count($links) - 1], ENT_QUOTES), PHP_URL_QUERY), $query);
        self::assertSame(ProductStatus::Published->value, $query['status'] ?? '');
        $second = $this->render(array_map('strval', $query));
        self::assertStringNotContainsString('دستکش شمارهٔ', $second);
    }

    /**
     * WHAT THIS PROVES: no page of this shop's list — first, middle, last or
     * one past the end — ever shows the other shop's product.
     */
    public function testNoPageEverShowsAnotherShopsProduct(): void
    {
        foreach ([[], [VendorUrls::PAGE_PARAM => '2'], [VendorUrls::PAGE_PARAM => '3'], [VendorUrls::PAGE_PARAM => '99']] as $query) {
            $html = $this->render($query);
            self::assertStringNotContainsString('SKU-OTHER', $html, 'another shop leaked into ' . json_encode($query));
            self::assertStringNotContainsString('محصول فروشگاه دیگر', $html);
        }
    }

    /**
     * WHAT THIS PROVES: a shop with one page gets no pager and no page
     * parameter anywhere — the control for all of the above, so «the pager is
     * always drawn» cannot be what makes them pass.
     */
    public function testAOnePageShopHasNoPager(): void
    {
        State::loginAs(self::OTHER_VENDOR, []);
        $_GET = [];
        $html = (new ProductArea(Bootstrap::container()))->render(new VendorAreaView(
            Request::capture(),
            self::OTHER_VENDOR,
            new VendorUrls('https://example.test/vendor/', 'https://example.test/vendor/application/', '', '', '', 'https://example.test/vendor/products/'),
            '<input type="hidden" name="_wpnonce" value="t" />',
            null
        ));
        self::assertStringContainsString('SKU-OTHER', $html, 'its own product is there');
        self::assertSame([], self::pagerLinks($html), 'one page needs no pager');
    }
}
