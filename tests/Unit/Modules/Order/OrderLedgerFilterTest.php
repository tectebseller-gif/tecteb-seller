<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Application\OrderLedgerReport;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;

/**
 * What the detailed report was asked for, and what it refuses to guess.
 *
 * Pure: a filter is normalised without a database, so the rule a page and a
 * CSV export both read is readable on its own.
 */
final class OrderLedgerFilterTest extends TestCase
{
    public function testAnEmptyRequestIsTheFirstPageOfEverything(): void
    {
        $filter = OrderLedgerFilter::fromInput([]);
        self::assertNull($filter->vendorUserId);
        self::assertNull($filter->wcOrderId);
        self::assertSame('recent', $filter->sort);
        self::assertSame(OrderLedgerFilter::PER_PAGE[0], $filter->perPage);
        self::assertSame(1, $filter->page);
        self::assertSame(0, $filter->offset());
        self::assertFalse($filter->isNarrowed());
        self::assertSame([], $filter->toQuery(), 'the unfiltered first page has ONE address');
    }

    /**
     * An id of nought is «absent», not «narrow to nothing».
     *
     * A `0` typed into «شناسهٔ سفارش» must not silently empty the table, and a
     * `(int)` cast would make those two the same value.
     */
    public function testNoughtAndRubbishAreAbsentRatherThanAFilter(): void
    {
        foreach (['0', '-4', 'abc', '', '  ', '1.5', '12e3'] as $raw) {
            $filter = OrderLedgerFilter::fromInput(['order' => $raw]);
            self::assertNull($filter->wcOrderId, $raw);
            self::assertFalse($filter->isNarrowed(), $raw);
        }
        self::assertSame(12300, OrderLedgerFilter::fromInput(['order' => '12300'])->wcOrderId);
    }

    /**
     * Persian digits and the thousands separator a person actually types.
     *
     * `alpha.66`'s rule: the boundary translates and the layer below never
     * guesses. A separator is NOT invented — only removed — because guessing
     * one moves a figure by a thousand.
     */
    public function testAnAmountAcceptsPersianDigitsAndGroupingAndNothingElse(): void
    {
        self::assertSame(206010000, OrderLedgerFilter::fromInput(['min_amount' => '۲۰۶٬۰۱۰٬۰۰۰'])->minAmountMinor);
        self::assertSame(206010000, OrderLedgerFilter::fromInput(['min_amount' => '206,010,000'])->minAmountMinor);
        self::assertSame(500, OrderLedgerFilter::fromInput(['min_amount' => ' 500 '])->minAmountMinor);
        foreach (['12.5', 'abc', '-3', '۱۲٫۵'] as $bad) {
            self::assertNull(OrderLedgerFilter::fromInput(['min_amount' => $bad])->minAmountMinor, $bad);
        }
    }

    /** A reversed range is a typo with one reading, so it is read. */
    public function testAReversedAmountRangeIsSwappedRatherThanEmptied(): void
    {
        $filter = OrderLedgerFilter::fromInput(['min_amount' => '500', 'max_amount' => '100']);
        self::assertSame(100, $filter->minAmountMinor);
        self::assertSame(500, $filter->maxAmountMinor);
    }

    /**
     * A date that is not the shape an `<input type="date">` sends is left off.
     *
     * Rather than becoming a window the person did not type — on a financial
     * report the difference is «nothing was sold» and «I looked in the wrong
     * place».
     */
    public function testOnlyAnIsoDateIsAccepted(): void
    {
        self::assertSame('2026-11-09', OrderLedgerFilter::fromInput(['from' => '2026-11-09'])->fromLocalDate);
        foreach (['yesterday', '09/11/2026', '2026-11', '۱۴۰۵-۰۸-۱۸', ''] as $bad) {
            self::assertSame('', OrderLedgerFilter::fromInput(['from' => $bad])->fromLocalDate, $bad);
        }
    }

    /**
     * A sort, a page size and a status come from fixed lists.
     *
     * Anything else is the default rather than a value reaching an `ORDER BY`
     * or a `WHERE`.
     */
    public function testEveryChoiceComesFromAFixedList(): void
    {
        self::assertSame('amount_high', OrderLedgerFilter::fromInput(['sort' => 'amount_high'])->sort);
        self::assertSame('recent', OrderLedgerFilter::fromInput(['sort' => 'base_minor; DROP TABLE x'])->sort);
        self::assertSame(100, OrderLedgerFilter::fromInput(['per_page' => 100])->perPage);
        self::assertSame(25, OrderLedgerFilter::fromInput(['per_page' => 100000])->perPage);
        self::assertSame(OrderItemStatus::Cancelled, OrderLedgerFilter::fromInput(['shipping' => 'cancelled'])->shipping);
        self::assertNull(OrderLedgerFilter::fromInput(['shipping' => 'nonsense'])->shipping);
        self::assertSame('paid', OrderLedgerFilter::fromInput(['payment' => 'PAID'])->payment);
        self::assertSame('', OrderLedgerFilter::fromInput(['payment' => 'maybe'])->payment);
        self::assertSame('fixed', OrderLedgerFilter::fromInput(['commission' => 'fixed'])->commissionKind);
        self::assertSame('', OrderLedgerFilter::fromInput(['commission' => 'half'])->commissionKind);
    }

    public function testTheOffsetFollowsThePage(): void
    {
        $filter = OrderLedgerFilter::fromInput(['per_page' => 50, 'paged' => '4']);
        self::assertSame(150, $filter->offset());
        self::assertSame(0, $filter->onPage(1)->offset());
        self::assertSame(1, $filter->onPage(0)->page, 'page nought is page one, not a negative offset');
    }

    /**
     * WHAT THIS PROVES: a copy keeps every field.
     *
     * A «next page» link is built from a copy, and a copier that dropped a
     * field would put the link on a different set of rows from the page it was
     * printed on — which looks like a pagination bug and is a scoping one.
     */
    public function testACopyKeepsEveryFieldButTheOneItChanges(): void
    {
        $filter = OrderLedgerFilter::fromInput([
            'vendor' => '102', 'order' => '12300', 'product' => '9341', 'variation' => '9793',
            'title' => 'دفیبریلاتور', 'sku' => 'AED-5', 'shipping' => 'placed', 'payment' => 'paid',
            'commission' => 'percent', 'from' => '2026-11-01', 'to' => '2026-11-30',
            'min_amount' => '100', 'max_amount' => '900', 'sort' => 'oldest', 'per_page' => 50,
        ]);
        $next = $filter->onPage(3);
        self::assertSame(3, $next->page);
        foreach (array_diff_key($filter->toQuery(), ['paged' => '']) as $key => $value) {
            self::assertSame($value, $next->toQuery()[$key] ?? null, $key . ' was dropped by the copy');
        }
        self::assertSame('3', $next->toQuery()['paged'] ?? null);
    }

    /**
     * WHAT THIS PROVES: scoping to a shop overwrites, it does not validate.
     *
     * A vendor's request that carried another shop's id reads their own shop.
     * Returning an error instead would tell them the other shop exists.
     */
    public function testScopingToAShopOverwritesWhateverWasTyped(): void
    {
        $filter = OrderLedgerFilter::fromInput(['vendor' => '999']);
        self::assertSame(999, $filter->vendorUserId);
        self::assertSame(102, $filter->forVendor(102)->vendorUserId);
    }

    /**
     * Three amounts, never one — and a cancelled line is in exactly one of
     * them.
     *
     * «حفظ مبلغ تاریخی فروش با باقی‌ماندن تعهد مالی معتبر تفاوت دارد.»
     */
    public function testTheThreeTotalsSeparateWhatWasSoldFromWhatIsOwed(): void
    {
        $totals = OrderLedgerReport::totalsOf([
            self::row(1000, 'placed', 'awaiting_completion'),
            self::row(400, 'cancelled', 'void'),
            self::row(600, 'delivered', 'complete'),
        ]);
        self::assertSame(2000, $totals['historic'], 'everything that was ever sold');
        self::assertSame(1600, $totals['valid'], 'minus the one nobody owes');
        self::assertSame(1000, $totals['pending'], 'and only the one nobody has completed');
        self::assertSame('IRT', $totals['currency']);
        self::assertSame(0, $totals['exponent']);
        self::assertSame('', $totals['refused']);
    }

    /** An unrecorded share counts as nothing at all — not as zero (FIN-02). */
    public function testAnUnrecordedShareIsNotAZero(): void
    {
        $totals = OrderLedgerReport::totalsOf([
            self::row(1000, 'placed', 'awaiting_completion'),
            [...self::row(0, 'placed', 'unrecorded'), 'vendor_share_minor' => null],
        ]);
        self::assertSame(1000, $totals['historic']);
        self::assertSame(2, $totals['lines'], 'the line is still a line');
    }

    /**
     * Two exponents of one currency are combined exactly; two currencies are
     * not combined at all.
     */
    public function testTwoExponentsCombineAndTwoCurrenciesRefuse(): void
    {
        $totals = OrderLedgerReport::totalsOf([
            [...self::row(100, 'placed', 'complete'), 'exponent' => 0],
            [...self::row(883750, 'placed', 'complete'), 'exponent' => 4],
        ]);
        self::assertSame(4, $totals['exponent']);
        self::assertSame(1883750, $totals['historic'], '۱۰۰ plus ۸۸٫۳۷۵ is ۱۸۸٫۳۷۵');

        $mixed = OrderLedgerReport::totalsOf([
            self::row(100, 'placed', 'complete'),
            [...self::row(100, 'placed', 'complete'), 'currency' => 'USD'],
        ]);
        self::assertSame('mixed_currencies', $mixed['refused']);
        self::assertNull($mixed['historic'], 'a refused answer never carries a figure');
    }

    /** @return array<string,mixed> */
    private static function row(int $share, string $status, string $settlement): array
    {
        return [
            'vendor_share_minor' => $share,
            'item_status' => $status,
            'settlement_state' => $settlement,
            'currency' => 'IRT',
            'exponent' => 0,
        ];
    }
}
