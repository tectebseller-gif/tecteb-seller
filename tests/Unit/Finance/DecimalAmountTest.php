<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;

/**
 * WHAT THIS PROVES: the one conversion between a storefront's decimal string
 * and `Money`'s integer minor units is exact, and refuses rather than rounds.
 *
 * `alpha.38` had a single hardcoded scale in the whole financial surface —
 * `(refundMinor + taxRefundMinor) / 100` — and it disagreed with the exponent
 * everything else used, so a ریال refund went out at a hundredth of its value.
 * The scale is now a value that travels with the amount, and this file is
 * where the arithmetic that moves between the two is pinned down.
 *
 * In the unit suite on purpose: no WordPress, no database, no WooCommerce. If
 * money arithmetic needs any of those to be checked, it is in the wrong place.
 */
final class DecimalAmountTest extends TestCase
{
    /**
     * WHAT THIS PROVES: a zero-decimal currency round-trips unchanged — which
     * is the case the shipped defect got wrong.
     */
    public function testAZeroDecimalCurrencyRoundTripsUnchanged(): void
    {
        self::assertSame(100000, DecimalAmount::toMinor('100000', 0));
        self::assertSame('100000', DecimalAmount::toDecimal(100000, 0));
        // And NOT one thousand, which is what dividing by a hundred gave.
        self::assertNotSame('1000', DecimalAmount::toDecimal(100000, 0));
    }

    /**
     * WHAT THIS PROVES: WooCommerce writes `'100000.00'` as happily as
     * `'100000'`, and trailing zeros are not precision.
     */
    public function testTrailingZerosAreNotPrecision(): void
    {
        self::assertSame(100000, DecimalAmount::toMinor('100000.00', 0));
        self::assertSame(100000, DecimalAmount::toMinor('100000.', 0));
        self::assertSame(1050, DecimalAmount::toMinor('10.50', 2));
        self::assertSame(1050, DecimalAmount::toMinor('10.5', 2));
    }

    /**
     * WHAT THIS PROVES: significant places the exponent cannot hold are
     * REFUSED, not rounded away.
     *
     * `(int) round((float) '1.005' * 100)` answers 100 or 101 depending on the
     * platform's float, and either way it answers. Null is the only honest
     * answer, and the caller turns it into a named refusal.
     */
    public function testPrecisionThatCannotBeStoredIsRefused(): void
    {
        self::assertNull(DecimalAmount::toMinor('1.005', 2));
        self::assertNull(DecimalAmount::toMinor('100000.50', 0), 'half a ریال is not storable at exponent 0');
        self::assertNull(DecimalAmount::toMinor('0.1', 0));
    }

    /** WHAT THIS PROVES: anything that is not a plain decimal is refused. */
    public function testOnlyPlainDecimalsAreAccepted(): void
    {
        foreach (['', ' ', 'abc', '1e3', '1,000', '۱۰۰', '--1', '1.2.3', '+5', '0x10'] as $bad) {
            self::assertNull(DecimalAmount::toMinor($bad, 2), 'refused: ' . var_export($bad, true));
        }
        // Whitespace around a good value is tolerated, because wpdb and
        // WooCommerce both hand strings back with it from time to time.
        self::assertSame(500, DecimalAmount::toMinor('  5.00 ', 2));
    }

    /** WHAT THIS PROVES: negatives keep their sign in both directions. */
    public function testNegativesKeepTheirSign(): void
    {
        self::assertSame(-1050, DecimalAmount::toMinor('-10.50', 2));
        self::assertSame('-10.50', DecimalAmount::toDecimal(-1050, 2));
        self::assertSame(0, DecimalAmount::toMinor('-0.00', 2));
    }

    /**
     * WHAT THIS PROVES: a value too large for a PHP integer is refused rather
     * than saturated.
     *
     * `(int) '99999999999999999999'` is `PHP_INT_MAX`, silently. A money
     * conversion that saturates is a money conversion that invents a number.
     */
    public function testAValueTooLargeForAnIntegerIsRefused(): void
    {
        self::assertNull(DecimalAmount::toMinor('99999999999999999999', 0));
        self::assertSame(PHP_INT_MAX, DecimalAmount::toMinor((string) PHP_INT_MAX, 0));
    }

    /** WHAT THIS PROVES: the exponent bound is `Money`'s, and it is enforced. */
    public function testTheExponentBoundIsEnforced(): void
    {
        self::assertNull(DecimalAmount::toMinor('1', -1));
        self::assertNull(DecimalAmount::toMinor('1', DecimalAmount::MAX_EXPONENT + 1));
        self::assertNull(DecimalAmount::toDecimal(1, -1));
        self::assertSame(1000000, DecimalAmount::toMinor('1', 6));
        self::assertSame('1.000000', DecimalAmount::toDecimal(1000000, 6));
    }

    /**
     * WHAT THIS PROVES: `toMinorFloor()` rounds towards zero, and is only ever
     * right for a ceiling somebody else gave us.
     */
    public function testTheFloorVariantRoundsTowardsZero(): void
    {
        self::assertSame(100000, DecimalAmount::toMinorFloor('100000.99', 0));
        self::assertSame(1050, DecimalAmount::toMinorFloor('10.509', 2));
        self::assertSame(-1050, DecimalAmount::toMinorFloor('-10.509', 2), 'towards zero, not towards minus infinity');
        // And the exact conversion still refuses the same input, which is why
        // there are two methods rather than one lenient one.
        self::assertNull(DecimalAmount::toMinor('10.509', 2));
    }

    /**
     * WHAT THIS PROVES: minor units print back with the exponent's places, so
     * a storefront reads what was stored.
     */
    public function testMinorUnitsPrintWithTheExponentsPlaces(): void
    {
        self::assertSame('0.05', DecimalAmount::toDecimal(5, 2));
        self::assertSame('0.00', DecimalAmount::toDecimal(0, 2));
        self::assertSame('0', DecimalAmount::toDecimal(0, 0));
        self::assertSame('1.00', DecimalAmount::toDecimal(100, 2));
    }

    /**
     * WHAT THIS PROVES: every value survives a round trip at its own scale.
     *
     * The property the whole class exists for, asserted over a spread rather
     * than one example — including the amount from the owner's own scenario.
     */
    public function testEveryAmountSurvivesARoundTripAtItsOwnScale(): void
    {
        foreach ([0, 1, 7, 99, 100, 1050, 100000, 2450000, 987654321] as $minor) {
            foreach ([0, 2, 3] as $exponent) {
                $text = DecimalAmount::toDecimal($minor, $exponent);
                self::assertNotNull($text);
                self::assertSame(
                    $minor,
                    DecimalAmount::toMinor($text, $exponent),
                    "round trip {$minor} at 1e{$exponent} via {$text}"
                );
            }
        }
    }
}
