<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductCatalogueRow;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductCatalogueState;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductCatalogueView;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

/**
 * WHAT THIS PROVES: every link on the manager's product list is the same
 * address with one thing changed, and a new question always starts on page one.
 *
 * The rules are small and there are many of them, which is exactly why they are
 * tested here rather than read off a rendered page: a chip that quietly drops
 * the search term resets the manager's filter, and on a catalogue of hundreds
 * of products that means the row they were about to decide on is no longer on
 * the screen. Nothing in a screenshot shows a missing query parameter.
 */
final class ProductCatalogueStateTest extends ContractTestCase
{
    private const BASE = 'https://example.test/wp-admin/admin.php?page=tmc-product-review';

    public function testThePlainFirstPageOfAnUnfilteredListIsTheBareAddress(): void
    {
        self::assertSame(self::BASE, $this->state()->link(), 'nothing to say means nothing to add');
        self::assertSame(self::BASE, $this->state()->selfLink());
    }

    public function testAStatusChipKeepsTheSearchTheRowCountAndTheSortAndDropsThePage(): void
    {
        $state = $this->state(status: 'published', search: 'آمبوبگ', page: 7, perPage: 50, sort: ProductSort::Title);
        $link = $state->link(['status' => 'draft']);

        self::assertStringContainsString('status=draft', $link);
        self::assertStringContainsString('q=', $link);
        self::assertStringContainsString('per_page=50', $link);
        self::assertStringContainsString('orderby=title', $link);
        self::assertStringNotContainsString('paged', $link, 'a new filter starts on page one');
        self::assertStringNotContainsString('status=published', $link);
    }

    public function testTheAllChipClearsTheStatusItIsMeantToClear(): void
    {
        $link = $this->state(status: 'archived', search: 'x')->link(['status' => null]);
        self::assertStringNotContainsString('status', $link);
        self::assertStringContainsString('q=x', $link);
    }

    public function testPageOneHasExactlyOneAddress(): void
    {
        $state = $this->state(page: 2);
        self::assertStringNotContainsString(
            'paged',
            $state->link(['paged' => 1]),
            'paged=1 and no paged at all must not be two different URLs'
        );
        self::assertStringContainsString('paged=3', $state->link(['paged' => 3]));
    }

    public function testTheWayBackFromAProductCarriesThePageItWasOpenedFrom(): void
    {
        $state = $this->state(status: 'submitted', search: 'سرنگ', page: 4);
        self::assertStringContainsString('paged=4', $state->selfLink());
        self::assertStringContainsString('status=submitted', $state->selfLink());
    }

    public function testOnlyTheThreeOfferedRowCountsAreHonoured(): void
    {
        self::assertSame(20, ProductCatalogueState::perPage(20));
        self::assertSame(50, ProductCatalogueState::perPage(50));
        self::assertSame(100, ProductCatalogueState::perPage(100));
        // Everything else is the default — including the two ways of asking for
        // «all of them».
        self::assertSame(20, ProductCatalogueState::perPage(0));
        self::assertSame(20, ProductCatalogueState::perPage(-5));
        self::assertSame(20, ProductCatalogueState::perPage(21));
        self::assertSame(20, ProductCatalogueState::perPage(100000));
    }

    public function testTheSentenceUnderTheFiltersCountsTheWholeResult(): void
    {
        $state = $this->state(page: 1, perPage: 20)->withCounts(234, ['published' => 234], 0);
        self::assertSame(1, $state->firstRow());
        self::assertSame(20, $state->lastRow());
        self::assertSame(12, $state->pages());

        $last = $this->state(page: 12, perPage: 20)->withCounts(234, [], 0);
        self::assertSame(221, $last->firstRow());
        self::assertSame(234, $last->lastRow(), 'the last page stops at the total, not at a multiple of the page size');

        $empty = $this->state()->withCounts(0, [], 0);
        self::assertSame(0, $empty->firstRow());
        self::assertSame(1, $empty->pages(), 'no rows is still one page, not zero pages');
    }

    public function testFilteredIsWhateverNarrowsTheList(): void
    {
        self::assertFalse($this->state()->isFiltered());
        self::assertTrue($this->state(status: 'draft')->isFiltered());
        self::assertTrue($this->state(search: 'x')->isFiltered());
        self::assertTrue($this->state(onlyRevisions: true)->isFiltered());
    }

    /**
     * WHAT THIS PROVES: the sentence and the numbers a manager reads are the
     * ones the owner asked for — «نمایش ۱ تا ۲۰ از ۲۳۴ محصول», in Persian
     * digits, with the pager windowed rather than 12 numbers long.
     */
    public function testTheRenderedListSaysWhichRowsOfHowManyAreOnTheScreen(): void
    {
        $state = $this->state(page: 6, perPage: 20)->withCounts(234, ['published' => 234], 2);
        $html = ProductCatalogueView::render([$this->row()], $state);

        self::assertStringContainsString('نمایش ۱۰۱ تا ۱۲۰ از ۲۳۴ محصول', $html);
        self::assertStringContainsString('صفحهٔ قبل', $html);
        self::assertStringContainsString('صفحهٔ بعد', $html);
        self::assertStringContainsString('<span class="tmc-pager__page is-current" aria-current="page">۶</span>', $html);
        self::assertStringContainsString('tmc-pager__gap', $html, '12 pages are windowed, not listed');
        // The row itself: a summary, and one way in.
        self::assertStringContainsString('مشاهده و بررسی', $html);
        self::assertStringContainsString('SKU: AMB-1', $html);
        self::assertStringContainsString('نسخهٔ پیشنهادی در انتظار', $html);
        self::assertStringContainsString('۲ محصول منتشرشده نسخهٔ پیشنهادی بی‌پاسخ دارد.', $html);
        // …and nothing a decision needs.
        self::assertStringNotContainsString('name="decision"', $html);
        self::assertStringNotContainsString('tmc-review__gallery', $html);
    }

    /** WHAT THIS PROVES: a row with no picture says so rather than drawing a broken image. */
    public function testARowWithoutAPictureSaysSo(): void
    {
        $html = ProductCatalogueView::render(
            [$this->row(thumbnail: '')],
            $this->state()->withCounts(1, [], 0)
        );
        self::assertStringContainsString('بدون تصویر', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    private function row(string $thumbnail = 'https://example.test/t.png'): ProductCatalogueRow
    {
        return new ProductCatalogueRow(
            11,
            'آمبوبگ سیلیکونی',
            'AMB-1',
            'فروشگاه نمونه',
            ProductStatus::Published,
            true,
            'publish',
            '2026-09-26 11:30:00',
            $thumbnail,
            true
        );
    }

    private function state(
        string $status = '',
        string $search = '',
        int $page = 1,
        int $perPage = ProductCatalogueState::PER_PAGE,
        ProductSort $sort = ProductSort::LastChanged,
        bool $onlyRevisions = false
    ): ProductCatalogueState {
        return new ProductCatalogueState(
            $status,
            $search,
            $page,
            $perPage,
            $sort,
            $onlyRevisions,
            0,
            [],
            0,
            self::BASE
        );
    }
}
