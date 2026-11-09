<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Domain\LineReconciliation;
use Tecteb\Marketplace\Modules\Order\Domain\OrderInconsistency;

/**
 * WHAT THIS PROVES: the seven classes of §5, each reachable, and a sound line
 * reporting nothing.
 *
 * ⛔ **THE CONTROL MATTERS AS MUCH AS THE FINDINGS.** A reconciliation that
 * reports something about every line is a reconciliation nobody reads, so the
 * first test here is the healthy one, and the FIN-02 case — a line with no
 * rate resolved, no share and no document — must come back empty: that state
 * is the documented one this plugin writes on purpose, and calling it a defect
 * would flag `184` perfectly ordinary rows.
 */
final class LineReconciliationTest extends TestCase
{
    /**
     * @param list<OrderInconsistency> $expected
     */
    private static function assertClasses(array $expected, array $actual, string $why): void
    {
        self::assertSame(
            array_map(static fn (OrderInconsistency $c): string => $c->value, $expected),
            array_map(static fn (OrderInconsistency $c): string => $c->value, $actual),
            $why
        );
    }

    /** A line that is exactly right reports nothing at all. */
    public function testASoundRecordedLineIsSilent(): void
    {
        self::assertClasses([], LineReconciliation::of(
            'order:12299:1', true, false, false, true, true, true, false, true, true
        ), 'a healthy accrual');
    }

    /** And a sound CANCELLED line — accrual, reversal, cancelled — too. */
    public function testASoundCancelledLineIsSilent(): void
    {
        self::assertClasses([], LineReconciliation::of(
            'order:12300:1', true, true, true, true, true, true, false, true, true
        ), 'cancelled and reversed is the correct end state');
    }

    /**
     * ⛔ FIN-02's own state is not a defect. An unresolvable rate records
     * nothing — no figures, no document — and the report already prints that
     * as «ثبت‌نشده». Flagging it would bury the real findings under it.
     */
    public function testALineWithNoRateAndNoShareIsNotAFinding(): void
    {
        self::assertClasses([], LineReconciliation::of(
            '', false, false, false, false, false, false, false, true, true
        ), 'FIN-02: nothing resolved, nothing recorded');
    }

    /** But a share with no document behind it is the half-written state. */
    public function testAShareWithNoDocumentIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::LineWithoutAccrual], LineReconciliation::of(
            '', false, false, false, true, false, false, false, true, true
        ), 'a figure with nothing behind it');
    }

    /** A line naming a key the ledger does not have is the same finding. */
    public function testAnAccrualKeyTheLedgerDoesNotHaveIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::LineWithoutAccrual], LineReconciliation::of(
            'order:12299:1', false, false, false, true, false, false, false, true, true
        ), 'the write that mattered did not happen');
    }

    public function testTwoLinesClaimingOneDocumentIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::DuplicateAccrual], LineReconciliation::of(
            'order:12299:1', true, false, false, true, true, true, true, true, true
        ), 'one sale counted twice');
    }

    public function testACancelledLineWithNoReversalIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::CancelledWithoutReversal], LineReconciliation::of(
            'order:12300:1', true, false, true, true, true, true, false, true, true
        ), 'the obligation still stands');
    }

    /**
     * The opposite, which no code path in this plugin can produce — nothing
     * un-cancels — so reaching it means a hand edit, and the reports are
     * counting a share the ledger gave back.
     */
    public function testAReversalBesideALineThatIsNotCancelledIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::ReversalWithoutCancel], LineReconciliation::of(
            'order:12300:1', true, true, false, true, true, true, false, true, true
        ), 'reversed but counted');
    }

    public function testADocumentThatDoesNotSumToNoughtIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::UnbalancedEvent], LineReconciliation::of(
            'order:12299:1', true, false, false, true, false, true, false, true, true
        ), 'the rows do not balance');
    }

    /** Two units inside one document is reported whatever the sum says. */
    public function testADocumentInTwoUnitsIsAFindingEvenWhenItLooksBalanced(): void
    {
        self::assertClasses([OrderInconsistency::UnbalancedEvent], LineReconciliation::of(
            'order:12299:1', true, false, false, true, true, false, false, true, true
        ), 'a sum across two units is a number in no unit');
    }

    public function testAVendorTheProductRowDoesNotNameIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::VendorOrStatusMismatch], LineReconciliation::of(
            'order:12299:1', true, false, false, true, true, true, false, false, true
        ), 'a share paid to the wrong shop looks like nothing');
    }

    public function testAStatusThisReleaseDoesNotKnowIsAFinding(): void
    {
        self::assertClasses([OrderInconsistency::VendorOrStatusMismatch], LineReconciliation::of(
            'order:12299:1', true, false, false, true, true, true, false, true, false
        ), 'a status nothing maps');
    }

    /**
     * ⛔ ONE LINE CAN CARRY TWO FINDINGS, and the order is the decision: the
     * mismatch is reported first because it is about WHO, and everything else
     * is about how much.
     */
    public function testALineCanReportSeveralClassesAtOnce(): void
    {
        self::assertClasses(
            [
                OrderInconsistency::VendorOrStatusMismatch,
                OrderInconsistency::DuplicateAccrual,
                OrderInconsistency::UnbalancedEvent,
                OrderInconsistency::CancelledWithoutReversal,
            ],
            LineReconciliation::of(
                'order:12300:1', true, false, true, true, false, true, true, false, true
            ),
            'four at once, in the fixed order'
        );
    }

    /**
     * ⛔ AND THE ABSENT-DOCUMENT CASE SHORT-CIRCUITS. A line with no accrual
     * cannot also be «cancelled without a reversal»: there is no document to
     * reverse, so reporting both would count one problem twice and send a
     * manager looking for a reversal that should not exist.
     */
    public function testAnAbsentDocumentDoesNotAlsoReportAMissingReversal(): void
    {
        self::assertClasses([OrderInconsistency::LineWithoutAccrual], LineReconciliation::of(
            '', false, false, true, true, false, false, false, true, true
        ), 'cancelled, with a share, and no document at all');
        self::assertClasses(
            [OrderInconsistency::VendorOrStatusMismatch, OrderInconsistency::LineWithoutAccrual],
            LineReconciliation::of('', false, false, true, true, false, false, false, false, true),
            'the who-question still comes first'
        );
    }

    /** Every class in the enum is reachable from this function. */
    public function testEveryFindingClassIsReachable(): void
    {
        $seen = [];
        foreach ([
            ['', false, false, false, true, false, false, false, true, true],
            ['e', true, false, false, true, true, true, true, true, true],
            ['e', true, false, true, true, true, true, false, true, true],
            ['e', true, true, false, true, true, true, false, true, true],
            ['e', true, false, false, true, false, true, false, true, true],
            ['e', true, false, false, true, true, true, false, false, true],
        ] as $args) {
            foreach (LineReconciliation::of(...$args) as $class) {
                $seen[$class->value] = true;
            }
        }
        // `OrderWithoutLine` is the one class this function cannot produce,
        // and deliberately: it is about an order we have NO row for, so it
        // comes from WooCommerce rather than from a line.
        $expected = [];
        foreach (OrderInconsistency::findings() as $class) {
            if ($class !== OrderInconsistency::OrderWithoutLine) {
                $expected[$class->value] = true;
            }
        }
        ksort($seen);
        ksort($expected);
        self::assertSame($expected, $seen, 'every line-side class is reachable');
    }
}
