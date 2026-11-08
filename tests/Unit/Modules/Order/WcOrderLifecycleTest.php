<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderDisposition;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderLifecycle;

/**
 * What each WooCommerce order status means for this marketplace.
 *
 * Pure: the mapping has no WooCommerce in it, which is the point — the rule
 * that decides whether somebody's money is voided must be readable without a
 * site to read it on.
 */
final class WcOrderLifecycleTest extends TestCase
{
    /**
     * The only status that voids an accrual, plus the one that is the same
     * fact under WordPress's own name.
     */
    public function testOnlyACancelledOrATrashedOrderVoidsTheAccrual(): void
    {
        $voids = [];
        foreach (WcOrderLifecycle::known() as $status) {
            if (WcOrderLifecycle::of($status)->voidsTheAccrual()) {
                $voids[] = $status;
            }
        }
        sort($voids);
        self::assertSame(['cancelled', 'trash'], $voids);
    }

    public function testEveryKnownStatusMapsToSomethingOtherThanUnknown(): void
    {
        foreach (WcOrderLifecycle::known() as $status) {
            self::assertNotSame(
                WcOrderDisposition::Unknown,
                WcOrderLifecycle::of($status),
                $status . ' is in the table and maps to nothing'
            );
        }
    }

    /**
     * The other half of the same guard: a disposition this release can produce
     * must be reachable from a status in the table.
     *
     * Without this, a case could be added to the enum, read by a screen, and
     * never arrive — which is the `alpha.80` shape («a function with no
     * caller») on an enum.
     */
    public function testEveryDispositionButUnknownIsReachableFromTheTable(): void
    {
        $seen = [];
        foreach (WcOrderLifecycle::known() as $status) {
            $seen[WcOrderLifecycle::of($status)->value] = true;
        }
        foreach (WcOrderDisposition::cases() as $case) {
            if ($case === WcOrderDisposition::Unknown) {
                continue;
            }
            self::assertArrayHasKey(
                $case->value,
                $seen,
                $case->value . ' can be produced by nothing in the status table'
            );
        }
    }

    /** @return list<array{0:string, 1:WcOrderDisposition}> */
    public static function statuses(): array
    {
        return [
            ['pending', WcOrderDisposition::AwaitingPayment],
            ['on-hold', WcOrderDisposition::AwaitingPayment],
            ['checkout-draft', WcOrderDisposition::AwaitingPayment],
            ['failed', WcOrderDisposition::PaymentFailed],
            ['processing', WcOrderDisposition::Paid],
            ['completed', WcOrderDisposition::Paid],
            ['cancelled', WcOrderDisposition::Void],
            ['trash', WcOrderDisposition::Void],
            ['refunded', WcOrderDisposition::Refunded],
        ];
    }

    /** @dataProvider statuses */
    public function testEachStatusReadsAsItsDisposition(string $status, WcOrderDisposition $expected): void
    {
        self::assertSame($expected, WcOrderLifecycle::of($status));
    }

    /**
     * WooCommerce's two spellings of one status.
     *
     * `WC_Order::get_status()` answers `cancelled`; the post status is
     * `wc-cancelled`. A mapping that knew one of them would be right on one
     * hook and wrong on the other, and the wrong answer here is «do nothing
     * about a cancelled order» — the defect this round closes.
     */
    public function testThePrefixedPostStatusReadsTheSameAsTheBareOne(): void
    {
        foreach (WcOrderLifecycle::known() as $status) {
            self::assertSame(
                WcOrderLifecycle::of($status),
                WcOrderLifecycle::of('wc-' . $status),
                'wc-' . $status . ' and ' . $status . ' disagree'
            );
        }
    }

    public function testCaseAndSurroundingSpaceAreNotAStatusOfTheirOwn(): void
    {
        self::assertSame(WcOrderDisposition::Void, WcOrderLifecycle::of('  CANCELLED '));
        self::assertSame(WcOrderDisposition::Void, WcOrderLifecycle::of('WC-Cancelled'));
    }

    /**
     * A status a plugin registered and this release has never heard of.
     *
     * Answers `Unknown`, which writes nothing. The alternative — a default
     * that fell through to «paid» or to «void» — would let any plugin's custom
     * status move somebody's money.
     */
    public function testAnUnmappedStatusIsUnknownRatherThanAGuess(): void
    {
        self::assertSame(WcOrderDisposition::Unknown, WcOrderLifecycle::of('awaiting-shipment'));
        self::assertSame(WcOrderDisposition::Unknown, WcOrderLifecycle::of('wc-partially-paid'));
        self::assertSame(WcOrderDisposition::Unknown, WcOrderLifecycle::of(''));
        self::assertSame(WcOrderDisposition::Unknown, WcOrderLifecycle::of('   '));
        self::assertFalse(WcOrderLifecycle::of('awaiting-shipment')->voidsTheAccrual());
        self::assertFalse(WcOrderLifecycle::of('awaiting-shipment')->isPaid());
    }

    /**
     * A failed payment does NOT void, deliberately.
     *
     * Reversing here would mean re-accruing on the retry — a second document
     * under a second key, or a key the unique index refuses. A status that
     * moves back and forth must not move money back and forth. The sale is
     * still unsettleable because no completion has been recorded, which is
     * where that protection lives.
     */
    public function testAFailedPaymentDoesNotVoidAndIsNotPaid(): void
    {
        $failed = WcOrderLifecycle::of('failed');
        self::assertFalse($failed->voidsTheAccrual());
        self::assertFalse($failed->isPaid());
        self::assertFalse($failed->needsADecision());
    }

    /**
     * A refunded order is a person's decision, not this plugin's.
     *
     * «ثبت لغو را با استرداد واقعی یکی نگیر؛ تعیین تکلیف پول باید بر اساس
     * جریان ووکامرس و تصمیم مدیر مجاز باشد.»
     */
    public function testARefundedOrderNeedsADecisionAndVoidsNothingByItself(): void
    {
        $refunded = WcOrderLifecycle::of('refunded');
        self::assertTrue($refunded->needsADecision());
        self::assertFalse($refunded->voidsTheAccrual());
    }

    public function testOnlyProcessingAndCompletedReadAsPaid(): void
    {
        $paid = [];
        foreach (WcOrderLifecycle::known() as $status) {
            if (WcOrderLifecycle::of($status)->isPaid()) {
                $paid[] = $status;
            }
        }
        sort($paid);
        self::assertSame(['completed', 'processing'], $paid);
    }
}
