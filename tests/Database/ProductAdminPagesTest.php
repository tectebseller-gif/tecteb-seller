<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\SpecTemplatesPage;
use Tecteb\Marketplace\Tests\Support\FakeProductCategoryDirectory;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use TmcWpStubs\State;

/**
 * The manager's product screens, rendered against the real schema.
 *
 * They belong in this suite rather than the contract one because they read the
 * queue out of the database on every render — which is the point: an empty
 * queue has to be an empty queue, not a fabricated one. From `alpha.32` it is
 * also where the paging is measured, and that needs a real `LIMIT` against
 * real rows: a page size asserted against a fake gateway proves the string was
 * built, not that twenty rows came back.
 */
final class ProductAdminPagesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);
    }

    /**
     * WHAT THIS PROVES: the review page is ONE list with filters, and the four
     * card sections that used to repeat the same products below it are gone.
     *
     * The absences are the assertions that matter. Until `alpha.32` this page
     * rendered the submission queue, the pending revisions, the catalogue, the
     * twenty most recent published products and an SEO form per product, one
     * after another.
     */
    public function testTheReviewPageIsOneListWithFiltersAndNoRepeatedCardSections(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $out = $this->render();

        // `alpha.57` shortened the title to one word and moved what the list
        // holds into a single sentence under it: «عنوان کوتاه». The SENTENCE
        // is what this checks, because it is the part that has to stay true —
        // the list holds every WooCommerce product as well as our own rows,
        // and opening it imports nothing.
        self::assertStringContainsString(
            '<h2 class="tmc-card__title">محصولات</h2>',
            $out,
            'a short title, not a sentence'
        );
        self::assertStringContainsString('هم محصول‌های ووکامرس و هم ردیف‌های بازارگاه، هر محصول یک بار', $out);
        self::assertStringContainsString('مجوز انتشار مستقیم', $out, 'a vendor-level permission stays on the list');
        self::assertStringContainsString('dir="rtl"', $out);

        foreach ([
            'محصول‌های در انتظار انتشار',
            'نسخه‌های پیشنهادی محصول‌های منتشرشده',
            'محصول‌های منتشرشده',
            'سئوی محصول — فقط مدیر',
        ] as $gone) {
            self::assertStringNotContainsString(
                $gone,
                $out,
                'the list must not render a full card section for every product: ' . $gone
            );
        }
        // The review card itself is the giveaway: no gallery, no specification
        // table, no field comparison on a list page.
        self::assertStringNotContainsString('tmc-review--full', $out);
        self::assertStringNotContainsString('tmc-review__gallery', $out);
    }

    /**
     * WHAT THIS PROVES: the filter row is seven buttons in the agreed order,
     * each with its own count badge, and exactly one of them is marked current.
     *
     * `.tmc-filter` rather than `.tmc-tab` is deliberate and is asserted: the
     * old class name has no rule in either stylesheet, which is why the owner
     * saw seven underlined links stacked down the page.
     */
    public function testTheStatusFiltersAreButtonsInOrderWithOneMarkedCurrent(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $out = $this->render();

        // ONLY the status row. From `alpha.56` this page has three more chip
        // rows — source, management, WooCommerce status — and they are three
        // separate questions, so counting every `.tmc-filter` on the page
        // would be counting four lists at once. The row is found by the
        // `aria-label` that says which question it asks, which is the same
        // label a screen reader uses to tell them apart.
        $statusRow = self::chipRow($out, 'وضعیت بازارگاه');
        // The class itself, not its children: `tmc-filter__label` and
        // `tmc-filter__count` share the prefix, and counting those instead
        // would report 23 filters and call it a pass.
        self::assertSame(
            7,
            preg_match_all('/class="tmc-filter[ "]/', $statusRow),
            'seven filters, no more and no fewer'
        );
        // Counted on the chip itself: the shell's own menu link also carries
        // `aria-current="page"`, so counting that attribute alone would be
        // counting the navigation.
        self::assertSame(
            1,
            substr_count($statusRow, 'class="tmc-filter is-current"'),
            'exactly one filter is the current one'
        );
        self::assertStringContainsString('<a class="tmc-filter is-current" aria-current="page"', $out);
        self::assertStringNotContainsString('class="tmc-tab', $out, 'the class with no stylesheet rule is gone');

        $order = ['همهٔ ردیف‌های بازارگاه', 'پیش‌نویس', 'ارسال‌شده برای بررسی', 'نیازمند اصلاح', 'منتشرشده', 'تعلیق‌شده', 'بایگانی'];
        $at = -1;
        foreach ($order as $label) {
            $found = strpos($statusRow, '<span class="tmc-filter__label">' . $label . '</span>');
            self::assertNotFalse($found, 'filter missing: ' . $label);
            self::assertGreaterThan($at, $found, 'the agreed order changed at: ' . $label);
            $at = $found;
        }
        // Zero is dimmer and still a link — a filter nobody can press is a
        // filter with no way back once the last row leaves a status.
        self::assertStringContainsString('<a class="tmc-filter is-empty" href=', $statusRow);
        self::assertStringContainsString('<span class="tmc-filter__count">۰</span>', $statusRow, 'counts are in Persian digits');
        self::assertStringContainsString('placeholder="جست‌وجوی عنوان، SKU یا برند…"', $out);

        // And the three facet rows of `alpha.56` are there, each saying which
        // question it asks. «وضعیت انتشار با وضعیت ورود/مالکیت قاتی نشود» is
        // a rule about the screen, so the screen is where it is checked.
        foreach (['منبع محصول', 'وضعیت مدیریت بازارگاه'] as $legend) {
            self::assertStringContainsString(
                '<span class="tmc-filters__legend">' . $legend . '</span>',
                $out,
                'the facet row must say which question it asks: ' . $legend
            );
        }
        // …and from `alpha.57` they are INSIDE «فیلترهای بیشتر», which is the
        // part of the instruction that is about where they sit rather than
        // what they say: «فیلترهای تخصصی منبع، مدیریت و وضعیت ووکامرس در
        // «فیلترهای بیشتر» قرار گیرند». Measured by position, because a page
        // that merely printed the word somewhere would pass a needle check.
        $more = strpos($out, '<details class="tmc-catalogue__more"');
        self::assertNotFalse($more, 'the specialist facets have no «فیلترهای بیشتر» box');
        $closed = strpos($out, '</details>', $more);
        self::assertNotFalse($closed);
        $inside = substr($out, $more, $closed - $more);
        foreach (['منبع محصول', 'وضعیت مدیریت بازارگاه'] as $legend) {
            self::assertStringContainsString(
                '<span class="tmc-filters__legend">' . $legend . '</span>',
                $inside,
                $legend . ' is still printed above the rows rather than folded away'
            );
        }
        // The marketplace status row is NOT in there: it is the filter the
        // manager uses every day, and «فیلترهای اصلی در دسترس بمانند».
        self::assertStringNotContainsString('aria-label="وضعیت بازارگاه"', $inside);
        // The sentence that tells the two «وضعیت» facets apart moved in beside
        // them, out of the page header where it was four rows away from either.
        self::assertStringContainsString('این دو یکی نیستند', $inside);
    }

    /**
     * WHAT THIS PROVES: whatever is narrowing the list is NAMED above the rows
     * and can be removed, one filter at a time or all at once.
     *
     * Two of the four chip rows now live inside a closed `<details>`, so «the
     * current chip is marked» is no longer enough on its own: a manager
     * looking at four rows of a catalogue of hundreds would see a short list
     * and no reason for it. «فیلتر فعال مشخص و قابل پاک‌کردن باشد.»
     *
     * The removal links are checked by ADDRESS rather than by their presence,
     * because a × that carried the filter it is meant to clear would look
     * identical and do nothing.
     */
    public function testEveryActiveFilterIsNamedAboveTheRowsAndCanBeRemoved(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);

        // Nothing is filtering: no bar at all. A row of furniture that says
        // «فیلترهای فعال: —» is the empty height this screen was rebuilt to
        // remove.
        self::assertStringNotContainsString(
            'class="tmc-catalogue__active"',
            $this->render(),
            'an unfiltered list must not draw an active-filter bar'
        );

        $out = $this->render(['q' => 'آمبو', 'status' => 'published', 'source' => 'woocommerce']);
        $bar = strpos($out, '<div class="tmc-catalogue__active">');
        self::assertNotFalse($bar, 'three filters are on and nothing says so');
        $end = strpos($out, '</div>', $bar);
        self::assertNotFalse($end);
        $bar = substr($out, $bar, $end - $bar);

        foreach ([
            'جست‌وجو: آمبو',
            'وضعیت بازارگاه: منتشرشده',
            'منبع: فقط در ووکامرس',
        ] as $named) {
            self::assertStringContainsString($named, $bar, 'an active filter is not named: ' . $named);
        }

        // Each × removes ITS OWN filter and keeps the other two. The pair of
        // assertions is the point: a link that dropped everything would pass
        // the first half.
        $removeSearch = strpos($bar, 'aria-label="برداشتن فیلتر جست‌وجو: آمبو"');
        self::assertNotFalse($removeSearch);
        $href = substr($bar, 0, $removeSearch);
        $href = substr($href, (int) strrpos($href, 'href="') + 6);
        $href = substr($href, 0, (int) strpos($href, '"'));
        self::assertStringNotContainsString('q=', $href, 'the × does not remove the search it names');
        self::assertStringContainsString('status=published', $href, 'removing the search dropped the status too');
        self::assertStringContainsString('source=woocommerce', $href, 'removing the search dropped the source too');

        // …and one link that removes all three, offered only when there is
        // more than one to remove.
        self::assertStringContainsString('برداشتن همهٔ فیلترها', $bar);
        $one = $this->render(['status' => 'published']);
        self::assertStringContainsString('وضعیت بازارگاه: منتشرشده', $one);
        self::assertStringNotContainsString(
            'برداشتن همهٔ فیلترها',
            $one,
            'a «clear all» beside a single filter that already has its own × is two buttons for one act'
        );

        // A facet inside the folded box opens the box, because a filter that
        // narrows the list from behind a closed `<summary>` is the worst of
        // both.
        self::assertStringContainsString('<details class="tmc-catalogue__more" open>', $out);
        self::assertStringContainsString(
            '<details class="tmc-catalogue__more">',
            $this->render(),
            'with no specialist facet on, the box stays closed'
        );
    }

    /**
     * WHAT THIS PROVES: the scope of each counter is on the screen, and the
     * low-use controls are folded away.
     *
     * The owner read «همه ۸» on one chip row and «همه ۱۹۳» on another, side by
     * side, and nothing on screen said the first counts marketplace rows and
     * the second counts the whole catalogue: «دامنهٔ شمارنده‌ها روشن باشد».
     * `aria-label` said it to a screen reader and to nobody else.
     */
    public function testEachCounterSaysWhatItCountsAndTheRareControlsAreFolded(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $out = $this->render();

        // No two «all» chips share a name any more.
        self::assertSame(
            0,
            substr_count($out, '<span class="tmc-filter__label">همه</span>'),
            'a chip called «همه» says nothing about which universe it counts'
        );
        foreach ([
            'همهٔ ردیف‌های بازارگاه',
            'هر منبعی',
            'هر وضعیت مدیریتی',
        ] as $allChip) {
            self::assertStringContainsString(
                '<span class="tmc-filter__label">' . $allChip . '</span>',
                $out,
                'the «all» chip of one facet is unnamed: ' . $allChip
            );
        }
        // And the marketplace status row says out loud, in text, that its
        // numbers are about our own rows only.
        self::assertStringContainsString(
            '<span class="tmc-filters__legend">وضعیت بازارگاه (فقط ردیف‌های بازارگاه)</span>',
            $out
        );

        // Rows-per-page and sort: folded, last, and open only when either is
        // away from its default.
        self::assertStringContainsString('<details class="tmc-catalogue__settings">', $out);
        self::assertStringContainsString(
            '<details class="tmc-catalogue__settings" open>',
            $this->render(['per_page' => '50']),
            'a page size the manager chose has to be visible, not hidden behind a summary'
        );
    }

    /**
     * One chip row, found by the `aria-label` that names its question.
     *
     * A test that counted chips across the whole page would be counting four
     * independent filters as one, and would pass for a page that had merged
     * them — which is the one thing this round's instruction forbids about
     * this screen.
     */
    private static function chipRow(string $html, string $label): string
    {
        $start = strpos($html, '<nav class="tmc-filters" aria-label="' . $label . '"');
        self::assertNotFalse($start, 'no chip row labelled: ' . $label);
        $end = strpos($html, '</nav>', $start);
        self::assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    /**
     * WHAT THIS PROVES: twenty-five products are read twenty at a time, by the
     * query, and the two pages together are the twenty-five — each exactly
     * once.
     *
     * The second half is the tiebreaker. Every one of these rows is written in
     * the same second, so `updated_at` cannot order them; without `id DESC`
     * beside it the boundary between page one and page two falls wherever
     * MariaDB feels like putting it, and a product shown twice means another
     * one is never shown at all.
     */
    public function testTwentyFiveProductsAreTwoPagesAndEveryRowAppearsExactlyOnce(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(25);

        $first = $this->render();
        self::assertStringContainsString('نمایش ۱ تا ۲۰ از ۲۵ محصول', $first);
        self::assertCount(20, $this->productIds($first), 'page one holds exactly the page size');
        self::assertStringContainsString('صفحهٔ بعد', $first);
        self::assertStringNotContainsString('صفحهٔ قبل', $first);

        $second = $this->render(['paged' => '2']);
        self::assertStringContainsString('نمایش ۲۱ تا ۲۵ از ۲۵ محصول', $second);
        self::assertCount(5, $this->productIds($second));
        self::assertStringContainsString('صفحهٔ قبل', $second);
        self::assertStringNotContainsString('صفحهٔ بعد', $second);

        $seen = [...$this->productIds($first), ...$this->productIds($second)];
        sort($seen);
        $expected = $ids;
        sort($expected);
        self::assertSame($expected, $seen, 'the two pages are the whole catalogue, each row once');
    }

    /**
     * WHAT THIS PROVES: the search is over the whole result, not the page that
     * happens to be on the screen.
     *
     * The product it looks for is the oldest id, which the default order puts
     * last — on page two. If the search ran over the twenty rows already
     * fetched it would find nothing, which is precisely how a list with a
     * client-side filter behaves.
     */
    public function testSearchFindsAProductThatIsNotOnTheFirstPage(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(25);
        $offPageOne = min($ids);

        self::assertNotContains($offPageOne, $this->productIds($this->render()), 'the fixture must put it off page one');

        $found = $this->render(['q' => 'SKU-001']);
        self::assertSame([$offPageOne], $this->productIds($found));
        self::assertStringContainsString('نمایش ۱ تا ۱ از ۱ محصول', $found);
        // A search keeps the filter it was typed inside, and drops the page.
        self::assertStringContainsString('<input type="hidden" name="q" value="SKU-001">', $this->render([
            'q' => 'SKU-001',
            'status' => 'draft',
        ]), 'the row-count form carries the search');
        self::assertStringNotContainsString('paged', $this->render(['q' => 'SKU-001', 'paged' => '2']));
    }

    /**
     * WHAT THIS PROVES: the row count is a choice among three, and anything
     * else is the default rather than an error — or a way to ask for every row.
     */
    public function testTheRowCountIsOneOfThreeAndNothingElse(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $this->seedProducts(25);

        $fifty = $this->render(['per_page' => '50']);
        self::assertCount(25, $this->productIds($fifty), 'fifty a page means one page here');
        self::assertStringContainsString('نمایش ۱ تا ۲۵ از ۲۵ محصول', $fifty);
        self::assertStringNotContainsString('صفحهٔ بعد', $fifty);

        // Not on the list of three: twenty, not «everything».
        self::assertCount(20, $this->productIds($this->render(['per_page' => '99999'])));
    }

    /**
     * WHAT THIS PROVES: «مشاهده و بررسی» opens one product's own page, with the
     * review tools on it, and the way back carries the search, the filter and
     * the page number.
     */
    public function testAProductsOwnPageCarriesTheReviewToolsAndTheWayBack(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(3, ProductStatus::Submitted);

        $out = $this->render([
            'product' => (string) $ids[0],
            'status' => 'submitted',
            'q' => 'کالا',
            'paged' => '2',
            'per_page' => '50',
            'orderby' => 'title',
        ]);

        self::assertStringContainsString('بازگشت به فهرست محصولات', $out);
        foreach (['status=submitted', 'q=', 'paged=2', 'per_page=50', 'orderby=title'] as $carried) {
            self::assertStringContainsString($carried, $out, 'the way back lost ' . $carried);
        }
        // The decision lives here now, not on the list.
        self::assertStringContainsString('tmc-review--full', $out);
        self::assertStringContainsString('name="decision" value="approve"', $out);
        self::assertStringContainsString('سابقهٔ تصمیم‌ها', $out);
        self::assertStringContainsString('سئوی محصول — فقط مدیر', $out);
        // And the list is not underneath it.
        self::assertStringNotContainsString('همهٔ محصولات بازارگاه', $out);
        self::assertStringNotContainsString('class="tmc-filter', $out);
    }

    /** WHAT THIS PROVES: a product id that matches nothing says so, and still offers the way back. */
    public function testAnUnknownProductIdSaysSoInsteadOfRenderingAnEmptyPage(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $out = $this->render(['product' => '99123']);

        self::assertStringContainsString('این محصول پیدا نشد.', $out);
        self::assertStringContainsString('بازگشت به فهرست محصولات', $out);
    }

    /**
     * WHAT THIS PROVES: the sort is applied by the query to the whole result,
     * not to the rows that happen to be on the screen.
     *
     * Measured by the boundary: alphabetically «کالای شمارهٔ 1» is first, and by
     * last change it is last — it was written first and every row shares the
     * same second, so the id breaks the tie. If the sort were applied after the
     * page was fetched, the first row would be the same under both.
     */
    public function testSortingIsAppliedToTheWholeResultAndNotToThePage(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(25);

        self::assertSame(max($ids), $this->productIds($this->render())[0], 'by default the newest change is first');
        self::assertSame(
            min($ids),
            $this->productIds($this->render(['orderby' => 'title']))[0],
            'alphabetically the first title is first, and it lives on the other page by default'
        );
        // A sort key nobody offers is the default order, not an error and not a
        // column name on its way to SQL.
        self::assertSame(max($ids), $this->productIds($this->render(['orderby' => 'id; DROP TABLE']))[0]);
    }

    /**
     * WHAT THIS PROVES: a published product with an unanswered proposal is
     * marked in the list and reachable as its own filter — «نسخه‌های پیشنهادی
     * نیز به‌وضوح قابل تشخیص و دسترسی باشند» — and the count under that filter
     * is the same query as the rows.
     */
    public function testAProposalIsMarkedOnItsRowAndIsAFilterOfItsOwn(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(3, ProductStatus::Published);
        $withProposal = $ids[1];
        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        self::assertGreaterThan(0, $revisions->create($withProposal, 7, ['details' => ['title' => 'عنوان تازه']]));

        $all = $this->render();
        self::assertStringContainsString('نسخهٔ پیشنهادی در انتظار', $all);
        self::assertSame(1, substr_count($all, 'tmc-catalogue__badge'), 'only the row that has one is marked');
        // ONE sentence about «needs review», by the one definition
        // `ReviewQueueSql::waiting()` holds. It said «نسخهٔ پیشنهادی بی‌پاسخ»
        // until `alpha.48` and counted revisions; the `submitted` chip counted
        // submitted products; and the owner read both at once.
        self::assertStringContainsString('۱ محصول نیازمند بررسی است', $all);

        // The OLD query key still works, because a manager may have bookmarked
        // it — and the new one is what the page writes.
        $only = $this->render(['revisions' => 'pending']);
        self::assertSame([$withProposal], $this->productIds($only));
        $only = $this->render(['review' => 'pending']);
        self::assertSame([$withProposal], $this->productIds($only));
        self::assertStringContainsString('نمایش ۱ تا ۱ از ۱ محصول', $only);
        self::assertStringContainsString('برداشتن این محدودیت', $only);
        self::assertStringContainsString('نیازمند بررسی', $only);
        // The chips count inside the same filter, so «همه» still equals the sum
        // of its parts.
        self::assertStringContainsString(
            '<span class="tmc-filter__label">همهٔ ردیف‌های بازارگاه</span><span class="tmc-filter__count">۱</span>',
            $only
        );
    }

    /**
     * WHAT THIS PROVES: every decision this page offers can be SUBMITTED.
     *
     * Not that it decides correctly — `ProductFlowTest` and
     * `BrandRegistryTest` do that against the services. What this asks is the
     * question `alpha.13` wrote down: does the branch RUN. A dispatch arm that
     * names a method the request object does not have is a 500 for the manager
     * and a green suite for us, and that is exactly what `alpha.48` shipped
     * into its own browser run before this existed.
     */
    public function testEveryDecisionThisPageOffersCanActuallyBeSubmitted(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(2, ProductStatus::Published);
        $product = $ids[0];
        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        $revisionId = $revisions->create($product, 7, [
            'details' => ['title' => 'عنوان تازه'],
            'stock_proposal' => ['value' => 3, 'baseline' => 5],
        ]);
        self::assertGreaterThan(0, $revisionId);

        // Each arm is submitted and the page must come back. A throw inside
        // `render()` fails the test, which is the whole point — and the
        // ACCEPTED/REFUSED outcome is not asserted here, because the nonce
        // check is the stub's and the services have their own tests.
        $arms = [
            ['subject' => 'revision', 'decision' => 'approve', 'subject_id' => (string) $revisionId],
            ['subject' => 'revision', 'decision' => 'approve', 'subject_id' => (string) $revisionId, 'accept_stock_conflict' => '1'],
            // The override WITH the number the page showed — a separate arm
            // because `alpha.49` made the hidden field part of the request and
            // a dispatch that cannot read it would 500 on exactly this POST
            // (which is how `$request->post()` reached a delivered package).
            [
                'subject' => 'revision', 'decision' => 'approve', 'subject_id' => (string) $revisionId,
                'accept_stock_conflict' => '1', 'seen_stock' => '2',
            ],
            ['subject' => 'revision', 'decision' => 'reject', 'subject_id' => (string) $revisionId, 'note' => 'نه'],
            ['subject' => 'product', 'decision' => 'approve', 'subject_id' => (string) $product],
            ['subject' => 'product', 'decision' => 'changes', 'subject_id' => (string) $product, 'note' => 'اصلاح'],
            ['subject' => 'product', 'decision' => 'reject', 'subject_id' => (string) $product, 'note' => 'رد'],
            ['subject' => 'brand', 'decision' => 'approve', 'subject_id' => '1'],
            ['subject' => 'brand', 'decision' => 'finish', 'subject_id' => '1'],
            ['subject' => 'brand', 'decision' => 'reject', 'subject_id' => '1', 'note' => 'نه'],
            ['subject' => 'seo', 'decision' => 'save', 'subject_id' => (string) $product],
            ['subject' => 'publishing', 'decision' => 'grant', 'subject_id' => '7'],
            ['subject' => 'publishing', 'decision' => 'revoke', 'subject_id' => '7'],
            ['subject' => 'storefront', 'decision' => 'prepare', 'subject_id' => (string) $product],
            ['subject' => 'correct', 'decision' => 'save', 'subject_id' => (string) $product],
            ['subject' => 'field', 'decision' => 'keep', 'subject_id' => (string) $product, 'field' => 'title'],
            ['subject' => 'field', 'decision' => 'accept', 'subject_id' => (string) $product, 'field' => 'title'],
            ['subject' => 'product_fields', 'decision' => 'keep', 'subject_id' => (string) $product],
            ['subject' => 'product_fields', 'decision' => 'accept', 'subject_id' => (string) $product],
        ];
        foreach ($arms as $fields) {
            $html = $this->submit($fields, ['product' => (string) $product]);
            self::assertNotSame(
                '',
                $html,
                $fields['subject'] . ':' . $fields['decision'] . ' rendered nothing'
            );
        }
    }

    /**
     * WHAT THIS PROVES: «عنوان (الفبایی فارسی)» is the Persian alphabet, read
     * by the query over the whole catalogue and not by the page.
     *
     * The fixture is chosen so that the two orders disagree everywhere: by
     * codepoint پ, چ, ژ and گ all land after ی, so a list that sorted by the
     * column would put «پالس‌اکسیمتر» and «گاز استریل» at the END. The
     * assertion is the whole sequence across BOTH pages, because a sort that
     * ran on the page would look right on page one and wrong at the boundary.
     */
    public function testPersianTitlesAreSortedInPersianOrderAcrossPages(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        // Deliberately created in an order that is neither alphabetical nor
        // reversed, so passing by accident is not possible.
        $titles = [
            'یدک‌کش', 'پالس‌اکسیمتر', 'آمبوبگ', 'گاز استریل', 'چسب زخم',
            'ژل الکترود', 'ماسک ۱۰', 'ماسک ۲', 'کیف کمک‌های اولیه', 'باند',
            'دستکش', 'سرنگ', 'ترمومتر', 'اکسیژن‌ساز', 'نبولایزر',
            'زیرانداز', 'فشارسنج', 'قیچی', 'لارنگوسکوپ', 'ویلچر',
            'هدست', 'نوار قلب', 'میکروسکوپ', 'الکل', 'بتادین',
        ];
        $this->seedProducts(count($titles), ProductStatus::Draft, $titles);

        $seen = [];
        foreach ([1, 2] as $page) {
            $html = $this->render(['orderby' => 'title', 'paged' => (string) $page]);
            preg_match_all('/class="tmc-catalogue__link"[^>]*>([^<]+)</u', $html, $matches);
            foreach ($matches[1] as $title) {
                $seen[] = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
            }
        }

        $expected = $titles;
        usort($expected, static fn (string $a, string $b): int => PersianCollation::sortKey($a) <=> PersianCollation::sortKey($b));
        self::assertSame($expected, $seen, 'the two pages together are the Persian alphabet');

        // The four letters Persian added, in their places rather than after ی.
        self::assertLessThan(
            array_search('یدک‌کش', $seen, true),
            array_search('پالس‌اکسیمتر', $seen, true),
            'پ before ی — by codepoint it is the other way round'
        );
        self::assertLessThan(array_search('ماسک ۱۰', $seen, true), array_search('ماسک ۲', $seen, true));
    }

    /**
     * WHAT THIS PROVES: a page number that no longer exists shows the last
     * page that does, keeps every filter, and says what happened.
     *
     * This is the state a manager reaches by deciding on the last product of
     * the last page: the list gets shorter under them and the browser asks for
     * a page that is gone.
     */
    public function testAPageThatNoLongerExistsShowsTheLastOneAndSaysSo(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $this->seedProducts(25);

        $out = $this->render(['paged' => '9', 'q' => 'کالا', 'per_page' => '20']);
        self::assertCount(5, $this->productIds($out), 'the last page, not an empty one');
        self::assertStringContainsString('نمایش ۲۱ تا ۲۵ از ۲۵ محصول', $out);
        self::assertStringContainsString('صفحهٔ ۹ دیگر وجود ندارد', $out);
        self::assertStringContainsString('صفحهٔ ۲', $out, 'and it says which page it did show');
        self::assertStringContainsString('value="کالا"', $out, 'the search survived the correction');

        // A result that is genuinely empty is a different sentence, and must
        // NOT claim a page disappeared.
        $empty = $this->render(['paged' => '9', 'q' => 'چنین چیزی نیست']);
        self::assertStringContainsString('چیزی با این عبارت پیدا نشد.', $empty);
        self::assertStringNotContainsString('دیگر وجود ندارد', $empty);
    }

    /**
     * WHAT THIS PROVES: the number on the menu counts PRODUCTS waiting for the
     * manager — submitted, or live with an unanswered proposal — and counts a
     * product that is both exactly once.
     */
    public function testTheWaitingCountIsProductsAndNotRows(): void
    {
        $this->bootPlugin(false);
        $products = new DbProductRepository(new WpDatabase($this->wpdb), new SystemClock());
        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        self::assertSame(0, $products->countAwaitingReview(), 'nothing waiting is nothing to show');

        $drafts = $this->seedProducts(2, ProductStatus::Draft);
        self::assertSame(0, $products->countAwaitingReview(), 'a draft is the vendor\'s own work');

        $submitted = $this->seedProducts(3, ProductStatus::Submitted);
        self::assertSame(3, $products->countAwaitingReview());

        $published = $this->seedProducts(1, ProductStatus::Published);
        $revisions->create($published[0], 7, ['details' => ['title' => 'عنوان تازه']]);
        self::assertSame(4, $products->countAwaitingReview(), 'an unanswered proposal is a decision too');

        // The same product, both ways at once: still one thing to look at.
        $revisions->create($submitted[0], 7, ['details' => ['title' => 'دوباره']]);
        self::assertSame(4, $products->countAwaitingReview(), 'one product, one row in the count');
        self::assertNotSame([], $drafts);
    }

    /**
     * WHAT THIS PROVES: an empty result says which emptiness it is, and offers
     * a way out of the filter that caused it.
     */
    public function testAnEmptyResultUnderAFilterOffersToClearIt(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $this->seedProducts(2);

        $byStatus = $this->render(['status' => 'suspended']);
        self::assertStringContainsString('در این وضعیت محصولی نیست.', $byStatus);
        self::assertStringContainsString('پاک‌کردن فیلترها', $byStatus);

        $bySearch = $this->render(['q' => 'چیزی که نیست']);
        self::assertStringContainsString('چیزی با این عبارت پیدا نشد.', $bySearch);
        self::assertStringContainsString('پاک‌کردن فیلترها', $bySearch);

        // Nothing narrowing anything: no clear-filters button to press.
        self::assertStringNotContainsString('پاک‌کردن فیلترها', $this->render(['status' => 'draft']));
    }

    public function testTheTemplateBuilderSaysAKeyNeverChangesAndAFieldIsNeverDeleted(): void
    {
        // WooCommerce ON: categories are ITS taxonomy, so the directory only
        // exists when it does. Booting without it and then seeding terms
        // tested nothing — the page correctly answered «no categories».
        $this->bootPlugin(true);
        State::loginAs(9, ['read', 'tmc_manage_spec_templates']);
        // A template attaches to a category that exists, so one has to exist.
        State::$terms = ['product_cat' => [
            42 => ['name' => 'بیهوشی و تنفسی', 'slug' => 'anesthesia', 'parent' => 0, 'count' => 0],
        ]];
        $out = $this->capture(fn () => (new SpecTemplatesPage(Bootstrap::container()))->render());
        State::$terms = [];

        self::assertStringContainsString('الگوهای مشخصات', $out);
        self::assertStringContainsString('فیلدهای پزشکی برای همه دسته‌ها نمایش داده نمی‌شوند', $out);
        self::assertStringContainsString('الزام قانونی نیست', $out, 'MED-01: no automatic medical claim');
        self::assertStringContainsString('ساخت الگو', $out);
        self::assertStringContainsString('هنوز الگویی ساخته نشده است.', $out);
        self::assertStringContainsString(
            'name="category_key" value="42"',
            $out,
            'the template is attached to a real product_cat term, not to a word typed here'
        );
        self::assertStringNotContainsString(
            'کلید دسته (انگلیسی)',
            $out,
            'the manager no longer INVENTS a category on this page'
        );
    }

    /**
     * No categories at all is a different sentence from no templates.
     *
     * Before `alpha.25` this page could not tell them apart, because it did
     * not read the taxonomy: it offered a key field, the manager typed a
     * word, and a category nobody could see came into being.
     */
    public function testWithNoWooCommerceCategoriesThePageSaysWhereToMakeThem(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_manage_spec_templates']);
        State::$terms = ['product_cat' => []];
        $out = $this->capture(fn () => (new SpecTemplatesPage(Bootstrap::container()))->render());
        State::$terms = [];

        self::assertStringContainsString('محصولات ← دسته‌بندی‌ها', $out);
        self::assertStringNotContainsString('ساخت الگو', $out, 'nothing to attach a template to yet');
    }

    /**
     * Render the review page with these query arguments and nothing else.
     *
     * `$_GET` is reset every time on purpose: a leftover `paged` from an
     * earlier assertion is how a test starts reporting about a page nobody
     * asked for.
     *
     * @param array<string,string> $query
     */
    /**
     * §3 at the PAGE level: a product with no readable WooCommerce copy is
     * never described as identical to it.
     *
     * The view's own cases live in `ProductReviewCardTest`; this one proves the
     * wiring — that `ProductReviewPage` asks `comparisonState()` and hands the
     * answer down.
     *
     * **A CONTROL, not a reproduction, and it says so.** In this environment
     * WooCommerce is absent, so the product is not projected and `alpha.40`
     * already returned its «not in WooCommerce yet» branch before reaching the
     * parity claim. This passes on both trees. The three empty-comparison
     * cases that `alpha.40` really did get wrong need a PROJECTED product
     * whose read fails, and those are measured in `ProductReviewCardTest`.
     */
    public function testAProductsPageNeverClaimsParityItCouldNotCheck(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $ids = $this->seedProducts(1, ProductStatus::Submitted);

        $out = $this->render(['product' => (string) $ids[0]]);

        self::assertStringContainsString('tmc-review--full', $out, 'the card really rendered');
        self::assertStringNotContainsString(
            'اطلاعات بازارگاه و ووکامرس برای این محصول یکی است',
            $out,
            'nothing compared the two sides, so nothing may claim they match'
        );
    }

    /**
     * THE PAGE CARRIES THE NUMBER IT SHOWED — `alpha.49`.
     *
     * A rendered page says nothing about its decisions (`alpha.13`, and the
     * browser's version of it in `alpha.48`), so the half that has to be
     * asserted HERE is the markup: the checkbox is worth nothing unless the
     * form also carries the stock the sentence above it quoted. Without the
     * hidden field the override is a blanket permission and the approval
     * cannot tell «I agree to overwrite 3» from «I agree to overwrite
     * whatever is there when this arrives».
     */
    public function testTheStockConflictNoticeCarriesTheNumberItShowed(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $product = $this->seedProducts(1, ProductStatus::Published)[0];
        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        // The row says 3; the proposal was measured against 5. That is the
        // conflict, and 3 is the number this page is about to print.
        self::assertGreaterThan(0, $revisions->create($product, 7, [
            'details' => ['title' => 'کالای شمارهٔ 1'],
            'stock_proposal' => ['value' => 9, 'baseline' => 5],
        ]));

        $html = $this->render(['product' => (string) $product]);

        self::assertStringContainsString('name="accept_stock_conflict"', $html);
        self::assertStringContainsString('<input type="hidden" name="seen_stock" value="3">', $html);
        self::assertStringContainsString('اکنون ۳', $html, 'and the sentence quotes the same number');
    }

    /** No disagreement, no control and no hidden field to carry. */
    public function testWithoutAConflictTheOverrideIsNotOfferedAtAll(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $product = $this->seedProducts(1, ProductStatus::Published)[0];
        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        self::assertGreaterThan(0, $revisions->create($product, 7, [
            'details' => ['title' => 'کالای شمارهٔ 1'],
            'stock_proposal' => ['value' => 9, 'baseline' => 3],
        ]));

        $html = $this->render(['product' => (string) $product]);

        self::assertStringNotContainsString('accept_stock_conflict', $html);
        self::assertStringNotContainsString('seen_stock', $html);
    }

    /**
     * The intake's POST arms, on the page they now live on.
     *
     * `alpha.48`'s rule, in a new place: four pages were registered with the
     * right slug and threw the moment they were opened, and the only test
     * that touched them asked whether they were registered. The intake moved
     * screens this round, so its three buttons are three dispatch arms nobody
     * had POSTed to on THIS page.
     *
     * What they must do without WooCommerce is still well defined: refuse,
     * and say which refusal it is. A capability check that answered «no
     * products selected» would be telling somebody who may not do intakes
     * that they picked nothing.
     */
    public function testTheIntakeRefusesByNameAndNeverOnTheWrongGround(): void
    {
        $this->bootPlugin(false);

        // No intake capability: refused on PERMISSION, whatever was selected.
        State::loginAs(9, ['read', 'tmc_review_products']);
        $out = $this->submit([
            'intake_action' => 'import',
            'wc_ids' => ['1229'],
            '_wpnonce' => wp_create_nonce('tmc_product_review'),
        ]);
        self::assertStringContainsString('دسترسی لازم برای ورود محصول را ندارید.', $out);

        // With it, and nothing ticked: refused on the SELECTION, which is a
        // different sentence and a different next step.
        State::loginAs(9, ['read', 'tmc_review_products', 'tmc_review_vendor']);
        $out = $this->submit([
            'intake_action' => 'import',
            '_wpnonce' => wp_create_nonce('tmc_product_review'),
        ]);
        self::assertStringContainsString('هیچ محصولی انتخاب نشده بود.', $out);

        // A bad nonce is refused before anything else is considered.
        $_GET = ['page' => ProductReviewPage::SLUG];
        $_POST = ['intake_action' => 'import', 'wc_ids' => ['1229'], '_wpnonce' => 'not-a-nonce'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $out = $this->capture(fn () => (new ProductReviewPage(Bootstrap::container()))->render());
        } finally {
            $_GET = [];
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        self::assertStringContainsString('درخواست معتبر نبود.', $out);

        // And «find a shop» says nothing at all: it is a search, and a
        // success message about a search reads like one about an import.
        State::loginAs(9, ['read', 'tmc_review_products', 'tmc_review_vendor']);
        $out = $this->submit([
            'intake_action' => 'find_shop',
            'shop_q' => 'فروشگاهی که نیست',
            '_wpnonce' => wp_create_nonce('tmc_product_review'),
        ]);
        self::assertStringNotContainsString('وارد شد', $out);
        self::assertStringNotContainsString('هیچ محصولی انتخاب نشده بود.', $out);
    }

    /**
     * An unknown `wc=` id is a named emptiness, not a blank page or a throw.
     *
     * The address is reachable by hand and by a stale link, and «محصولی با
     * این شناسه در ووکامرس نیست» is the answer that sends a manager back to
     * the list instead of to a support thread.
     */
    public function testAnUnknownWooCommerceIdSaysSoRatherThanRenderingNothing(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);

        $out = $this->render(['wc' => '99999']);

        self::assertStringContainsString('محصولی با این شناسه در ووکامرس نیست', $out);
        self::assertStringContainsString('بازگشت به فهرست', $out, 'and there is a way back');
    }

    /**
     * The review table must DRAW the category set it is about to APPLY.
     *
     * ⛔ `alpha.82` made the set sensitive, so a vendor's proposed categories
     * now become a revision and «تأیید تغییر» writes them. `diffTable()` built
     * its rows from `SENSITIVE` and compared with `(string) $current === (string)
     * $next`, which a set cannot ride — and `categoryKey` is `''` on both sides
     * of a multi-category change because `soleKey()` picks no winner. Measured
     * before the row existed: the table said «این پیشنهاد تفاوتی با نسخه فعلی
     * ندارد» about a revision that was going to move the product between
     * branches. That is the `alpha.49` rule one object along — consent is about
     * the value that was SHOWN — and the owner's own requirement that the set
     * be visible.
     *
     * The full path is asserted, not the leaf name: «لوازم جانبی» sits on six
     * branches of the owner's catalogue, so a row naming leaves only would let
     * a move between branches read as no change at all.
     */
    public function testTheReviewTableShowsTheWholeProposedCategorySet(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $directory = new FakeProductCategoryDirectory();
        $directory->terms = [
            244 => ['name' => 'تزریق', 'path' => 'تجهیزات پزشکی › تزریق'],
            245 => ['name' => 'سرنگ', 'path' => 'تجهیزات پزشکی › تزریق › سرنگ'],
            246 => ['name' => 'چسب', 'path' => 'مواد مصرفی › چسب'],
        ];
        Bootstrap::container()->bind(ProductCategoryDirectoryInterface::class, static fn () => $directory);

        $products = new DbProductRepository(new WpDatabase($this->wpdb), new SystemClock());
        $productId = $products->create(
            7,
            // The owner's own shape: a parent and a child of one branch.
            new ProductDetails(title: 'سرنگ ۵cc', categoryKey: '244', priceMinor: 100000, categoryIds: [244, 245]),
            ProductStatus::Published
        );
        self::assertSame([244, 245], $products->find($productId)?->details->categorySet());

        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        self::assertGreaterThan(0, $revisions->create($productId, 7, [
            'details' => ['title' => 'سرنگ ۵cc', 'categoryIds' => [244, 246]],
        ]));

        $out = $this->render(['product' => $productId]);
        // ⛔ The ROW, not the word. «برای دیدن فهرست دسته‌ها…» sits in the
        // manager-correction form on this very page, so a bare needle answers
        // about that sentence instead — `alpha.33`, in my own test.
        self::assertStringContainsString(
            '<th scope="row">دسته‌ها</th>',
            $out,
            'the set row must be headed, and headed differently from «دسته»'
        );
        self::assertStringNotContainsString(
            'این پیشنهاد تفاوتی با نسخه فعلی ندارد',
            $out,
            'the table must not call a category move «no difference»'
        );
        // BOTH sides, each id with its full path.
        foreach (['تجهیزات پزشکی › تزریق › سرنگ', 'مواد مصرفی › چسب'] as $path) {
            self::assertStringContainsString($path, $out, 'missing path: ' . $path);
        }
        self::assertStringContainsString('#۲۴۵', $out, 'the id the vendor is dropping');
        self::assertStringContainsString('#۲۴۶', $out, 'the id the vendor is asking for');
    }

    /**
     * And a revision written before `alpha.82` draws NO set row.
     *
     * Such a payload carries no `categoryIds` key, `detailsFromPayload()` keeps
     * the LIVE set for it, and a row about a change that will not be made is
     * the `alpha.13` fault in review clothing — it sends a manager to reject a
     * perfectly good title proposal. The same rule the brand and stock rows
     * have carried since `alpha.48`.
     */
    public function testAnOlderRevisionWithNoRecordedSetDrawsNoCategoryRow(): void
    {
        $this->bootPlugin(false);
        State::loginAs(9, ['read', 'tmc_review_products']);
        $directory = new FakeProductCategoryDirectory();
        $directory->terms = [
            244 => ['name' => 'تزریق', 'path' => 'تجهیزات پزشکی › تزریق'],
            245 => ['name' => 'سرنگ', 'path' => 'تجهیزات پزشکی › تزریق › سرنگ'],
        ];
        Bootstrap::container()->bind(ProductCategoryDirectoryInterface::class, static fn () => $directory);

        $products = new DbProductRepository(new WpDatabase($this->wpdb), new SystemClock());
        $productId = $products->create(
            7,
            new ProductDetails(title: 'سرنگ ۵cc', categoryKey: '244', priceMinor: 100000, categoryIds: [244, 245]),
            ProductStatus::Published
        );
        $revisions = new DbProductRevisionRepository(new WpDatabase($this->wpdb), new SystemClock());
        self::assertGreaterThan(0, $revisions->create($productId, 7, [
            'details' => ['title' => 'عنوان تازه'],
        ]));

        $out = $this->render(['product' => $productId]);
        self::assertStringContainsString('عنوان تازه', $out, 'the title proposal is still drawn');
        self::assertStringNotContainsString(
            '<th scope="row">دسته‌ها</th>',
            $out,
            'nothing is being asked about the set, so nothing is drawn about it'
        );
    }

    private function render(array $query = []): string
    {
        $_GET = ['page' => ProductReviewPage::SLUG, ...$query];
        try {
            return $this->capture(fn () => (new ProductReviewPage(Bootstrap::container()))->render());
        } finally {
            $_GET = [];
        }
    }

    /**
     * The same page, submitted to — because rendering it proves nothing about
     * its decisions.
     *
     * `alpha.13`'s rule, earned again in `alpha.48`: four pages were
     * registered with the right slug and the right capability and every one of
     * them threw the moment it was OPENED, and the only test that touched them
     * asked whether they were registered. This round's version of the same
     * mistake was `$request->post()` — a method `Request` does not have — on
     * the one branch no test POSTed to. The browser run found it; this is where
     * it would have been found a day earlier.
     *
     * @param array<string,string> $fields
     */
    private function submit(array $fields, array $query = []): string
    {
        $_GET = ['page' => ProductReviewPage::SLUG, ...$query];
        // A nonce the stub will VERIFY, from the stub's own generator. The
        // first version of this helper sent the string `test-nonce`, which
        // `wp_verify_nonce()` refuses — so `handleAction()` returned
        // `forbidden` before the dispatch and every arm below passed with the
        // bug still in place. A check that cannot fail is not a check
        // (`alpha.26`), and this one was falsified by putting the bug back.
        $_POST = [
            'tmc_review_nonce' => wp_create_nonce('tmc_product_review'),
            ...$fields,
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            return $this->capture(fn () => (new ProductReviewPage(Bootstrap::container()))->render());
        } finally {
            $_GET = [];
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    /**
     * The product ids the rendered list links to, in the order they appear.
     *
     * Read from the markup rather than from the repository: the question is
     * what the manager can see and press, and a row that was fetched but not
     * drawn is not on the page.
     *
     * @return list<int>
     */
    private function productIds(string $html): array
    {
        preg_match_all('/href="[^"]*product=(\\d+)[^"]*"/', $html, $matches);
        $ids = [];
        foreach ($matches[1] as $id) {
            $ids[(int) $id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * @return list<int> the ids, oldest first
     */
    /** @param list<string> $titles when given, the title of each row in order */
    private function seedProducts(int $count, ProductStatus $status = ProductStatus::Draft, array $titles = []): array
    {
        $products = new DbProductRepository(new WpDatabase($this->wpdb), new SystemClock());
        $ids = [];
        for ($n = 1; $n <= $count; $n++) {
            $ids[] = $products->create(
                7,
                new ProductDetails(
                    title: $titles[$n - 1] ?? sprintf('کالای شمارهٔ %d', $n),
                    categoryKey: 'general',
                    priceMinor: 100000 + $n,
                    sku: sprintf('SKU-%03d', $n),
                    stock: 3
                ),
                $status
            );
        }
        return $ids;
    }
}
