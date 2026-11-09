<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Domain\OrderInconsistency;

/**
 * WHAT THIS PROVES: `Unchecked` is never a finding, and the seven classes §5
 * names are exactly the seven this enum reports.
 */
final class OrderInconsistencyTest extends TestCase
{
    /**
     * ⛔ THE ONE RULE THIS ENUM EXISTS FOR. «نتوانستم بپرسم» must never be
     * counted among the findings, because a screen that summed the findings
     * and got nought would then print a clean bill off a check that did not
     * run — the mistake `alpha.97` paid for on a readiness gate.
     */
    public function testUncheckedIsNotAFinding(): void
    {
        self::assertNotContains(OrderInconsistency::Unchecked, OrderInconsistency::findings());
        self::assertFalse(OrderInconsistency::Unchecked->touchesMoney());
    }

    /** The seven §5 asks for, by name and in its order. */
    public function testTheSevenClassesAreTheSevenTheOrderNames(): void
    {
        self::assertSame([
            'order_without_line',
            'line_without_accrual',
            'duplicate_accrual',
            'cancelled_without_reversal',
            'reversal_without_cancel',
            'unbalanced_event',
            'vendor_or_status_mismatch',
        ], array_map(
            static fn (OrderInconsistency $c): string => $c->value,
            OrderInconsistency::findings()
        ));
    }

    /** Every case of the enum is either a finding or `Unchecked`. */
    public function testNoCaseIsLeftOutOfBothLists(): void
    {
        $accounted = array_map(
            static fn (OrderInconsistency $c): string => $c->value,
            [...OrderInconsistency::findings(), OrderInconsistency::Unchecked]
        );
        $all = array_map(static fn (OrderInconsistency $c): string => $c->value, OrderInconsistency::cases());
        sort($accounted);
        sort($all);
        self::assertSame($all, $accounted);
    }

    /**
     * Which findings mean money may be wrong. Pinned because the screen
     * leads with them, and a class that stopped being «about money» without
     * anybody noticing would quietly drop down the page.
     */
    public function testWhichFindingsTouchMoney(): void
    {
        $money = [];
        foreach (OrderInconsistency::cases() as $class) {
            if ($class->touchesMoney()) {
                $money[] = $class->value;
            }
        }
        self::assertSame([
            'line_without_accrual',
            'duplicate_accrual',
            'cancelled_without_reversal',
            'reversal_without_cancel',
            'unbalanced_event',
        ], $money);
    }
}
