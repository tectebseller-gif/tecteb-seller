<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Application\VendorFundedDiscount;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;

/**
 * WHAT THIS PROVES: a discount nobody's own code explains does not reach a
 * vendor's share, and one the vendor's own code explains still does.
 *
 * ### The measurement that made this a defect rather than a worry
 *
 * The first test below is the arithmetic, run through the real calculator
 * rather than asserted from the docblock: it shows what a site-wide coupon
 * actually costs the vendor. Without it, «a global discount comes out of the
 * share» is a claim about code somebody would have to re-derive to check.
 *
 * ### Why the conjunction is the rule
 *
 * WooCommerce cannot say which coupon discounted which line, so the rule
 * cannot either. What it can say is «this line lost money AND the codes
 * applied do not all belong to its vendor», and both halves are needed:
 * refusing on a coupon's mere presence would stop a mixed basket where the
 * host shop's own coupon touched only the host shop's goods, which is the
 * normal case and must keep selling.
 */
final class VendorFundedDiscountTest extends TestCase
{
    /**
     * ⛔ THE FINDING, as a number: a 20% site-wide coupon on a 100,000 line
     * with a 10% commission takes 18,000 off the vendor and 2,000 off Tecteb.
     *
     * Run through `TectebCommissionCalculator` so the split is the one the
     * capture would actually record, not one this test computed for itself.
     */
    public function testAGlobalDiscountIsPaidMostlyByTheVendor(): void
    {
        $calculator = new TectebCommissionCalculator();
        $rule = TectebCommission::percent(1000);          // 10.00%

        // The same call the capture makes: a base already after discount and
        // before tax, which is exactly where a coupon has landed by the time
        // `WcOrderReader` reads `get_total()`.
        $full = $calculator->calculate(Money::of(100000, 'IRT', 0), 1, $rule, 'product', 7);
        $discounted = $calculator->calculate(Money::of(80000, 'IRT', 0), 1, $rule, 'product', 7);

        self::assertNotNull($full->vendorShare);
        self::assertNotNull($discounted->vendorShare);
        self::assertNotNull($full->commission);
        self::assertNotNull($discounted->commission);

        // ⛔ IN THE CALCULATOR'S OWN UNIT, not the order's.
        //
        // A commission is recorded at four more decimal places than the
        // order's currency, because the rate's denominator is 10,000 and the
        // owner's rule is that nothing rounds (`alpha.67`). So 20,000 تومان
        // of discount is 200,000,000 here, and the factor is asserted rather
        // than assumed — a test that hard-coded تومان would have passed on a
        // build that silently started rounding.
        $exponent = $full->vendorShare->exponent;
        self::assertSame(4, $exponent, 'four extra places, from MAX_BP');
        $factor = 10 ** $exponent;

        $lostByVendor = $full->vendorShare->minor - $discounted->vendorShare->minor;
        $lostByTecteb = $full->commission->minor - $discounted->commission->minor;

        self::assertSame(
            20000 * $factor,
            $lostByVendor + $lostByTecteb,
            'the whole discount is funded by the two of them'
        );
        self::assertSame(18000 * $factor, $lostByVendor, 'and nine tenths of it by the vendor');
        self::assertSame(2000 * $factor, $lostByTecteb);
    }

    /** A line nothing was taken off is never this rule's business. */
    public function testAnUndiscountedLinePasses(): void
    {
        $answer = VendorFundedDiscount::check(100000, 100000, ['SUMMER20'], true);

        self::assertTrue($answer['ok'], 'a coupon that did not touch THIS line says nothing about it');
        self::assertSame(VendorFundedDiscount::OK, $answer['reason']);
        self::assertSame(0, $answer['discount_minor']);
    }

    /** The vendor's own code taking money off their own line is the approved rule. */
    public function testTheVendorsOwnCodeExplainsTheirOwnDiscount(): void
    {
        $answer = VendorFundedDiscount::check(100000, 80000, [], true);

        self::assertTrue($answer['ok']);
        self::assertSame(20000, $answer['discount_minor']);
    }

    /** And anybody else's does not. */
    public function testAForeignCodeOnADiscountedLineIsRefusedAndNamed(): void
    {
        $answer = VendorFundedDiscount::check(100000, 80000, ['SUMMER20'], true);

        self::assertFalse($answer['ok']);
        self::assertSame(VendorFundedDiscount::FOREIGN_DISCOUNT, $answer['reason']);
        self::assertSame(20000, $answer['discount_minor']);
        self::assertSame(['SUMMER20'], $answer['codes'], 'the manager is told WHICH code');
    }

    /**
     * A vendor's own code beside a foreign one: refused, because attribution
     * is not available.
     *
     * The conservative direction on somebody else's money (`alpha.46`). If
     * WooCommerce ever exposes a per-line breakdown this is the assertion that
     * would change, and it would change deliberately.
     */
    public function testAForeignCodeBesideTheVendorsOwnIsStillRefused(): void
    {
        $answer = VendorFundedDiscount::check(100000, 80000, ['SITEWIDE'], true);

        self::assertFalse($answer['ok']);
        self::assertSame(VendorFundedDiscount::FOREIGN_DISCOUNT, $answer['reason']);
    }

    /** Money off a line with no coupon at all: something else moved it. */
    public function testADiscountWithNoCouponAtAllIsRefusedUnderItsOwnName(): void
    {
        $answer = VendorFundedDiscount::check(100000, 90000, [], false);

        self::assertFalse($answer['ok']);
        self::assertSame(VendorFundedDiscount::UNEXPLAINED_DISCOUNT, $answer['reason']);
        self::assertNotSame(
            VendorFundedDiscount::FOREIGN_DISCOUNT,
            $answer['reason'],
            'no code was applied, so naming one would be a lie'
        );
    }

    /**
     * ⛔ Unreadable amounts refuse, and that is the opposite of
     * `CommissionLineCheck`'s posture on purpose.
     *
     * That rule abstains on an amount it cannot read exactly, because «is the
     * commission bigger than the line» is a question about two numbers. This
     * one is about a transfer out of somebody's share, and the direction that
     * is safe to be wrong in is refusal.
     */
    public function testUnreadableAmountsAreRefusedRatherThanAbstainedOn(): void
    {
        foreach ([[null, 80000], [100000, null], [null, null]] as [$subtotal, $total]) {
            $answer = VendorFundedDiscount::check($subtotal, $total, [], true);
            self::assertFalse($answer['ok']);
            self::assertSame(VendorFundedDiscount::UNREADABLE, $answer['reason']);
        }
    }

    /**
     * A total ABOVE its subtotal is not a discount, and is not refused.
     *
     * A fee, a tax arrangement or a rounding of a wholesale price can do
     * that, and none of them takes money out of a share. Refusing here would
     * have been the easy over-reach: a rule about discounts firing on
     * anything that is not equality.
     */
    public function testATotalAboveItsSubtotalIsNotADiscount(): void
    {
        $answer = VendorFundedDiscount::check(100000, 110000, ['SOMETHING'], true);

        self::assertTrue($answer['ok']);
        self::assertSame(0, $answer['discount_minor']);
    }

    /** Every refusal is listed, so a screen cannot carry a sentence for only some. */
    public function testEveryRefusalIsListed(): void
    {
        $listed = VendorFundedDiscount::refusals();

        foreach ([
            [100000, 80000, ['X'], true],
            [100000, 80000, [], false],
            [null, null, [], true],
        ] as [$subtotal, $total, $codes, $any]) {
            $answer = VendorFundedDiscount::check($subtotal, $total, $codes, $any);
            self::assertContains($answer['reason'], $listed, $answer['reason']);
        }
    }
}
