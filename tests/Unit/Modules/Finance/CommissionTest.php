<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\RoundingPolicy;

/**
 * FIN-01 and FIN-02, asserted against the contract rather than against the
 * implementation — including the worked example the spec itself gives.
 */
final class CommissionTest extends TestCase
{
    private CommissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CommissionCalculator();
    }

    public function testTheWorkedExampleFromTheSpecification(): void
    {
        // B=900000, rate 10% → C=90000, V=810000 (Master Spec §23) — and from
        // `alpha.67` all three come back at the finer unit an exact
        // commission needs, so the decimal strings are asserted beside the
        // integers. The money is the same; the unit it is written in is not.
        $outcome = $this->calculator->calculate(
            Money::of(900000),
            CommissionRate::ofBasisPoints(1000),
            'general',
            7
        );

        self::assertTrue($outcome->isCalculated());
        self::assertSame(4, $outcome->commission?->exponent);
        self::assertSame('90000.0000', $outcome->commission?->toDecimal());
        self::assertSame('810000.0000', $outcome->vendorShare?->toDecimal());
    }

    /**
     * FIN-01's own rule is exact too, and `half_up` is never applied.
     *
     * The owner's decision covers every commission — «هیچ گردکردنی مجاز
     * نیست» — so this calculator lost its rounding with the other one, and
     * the pair is asserted together so neither can keep a second rule.
     */
    public function testThisCalculatorIsExactAndRecordsThatItWas(): void
    {
        $outcome = $this->calculator->calculate(
            Money::of(101, 'IRT', 0),
            CommissionRate::ofBasisPoints(1250),
            'general',
            7
        );

        self::assertTrue($outcome->isCalculated());
        self::assertSame('12.6250', $outcome->commission?->toDecimal());
        self::assertSame('88.3750', $outcome->vendorShare?->toDecimal());
        self::assertSame('exact@v2', $outcome->snapshot?->roundingPolicy);
        // And the historical name is still available, so a row recorded under
        // it can be told apart rather than becoming unexplainable.
        self::assertSame('half_up@v1', RoundingPolicy::describeHistoricalHalfUp());
    }

    /** @dataProvider amounts */
    public function testTheTwoSharesAlwaysAddUpToTheBase(int $base, int $rateBp): void
    {
        $outcome = $this->calculator->calculate(Money::of($base), CommissionRate::ofBasisPoints($rateBp), 'general', 7);

        self::assertTrue($outcome->isCalculated());
        self::assertSame(
            $outcome->base?->minor,
            ($outcome->commission?->minor ?? 0) + ($outcome->vendorShare?->minor ?? 0),
            "V + C must be exactly B for base {$base} at {$rateBp}bp"
        );
        self::assertSame(
            $base * 10000,
            $outcome->base?->minor,
            "the base must be the same money in the finer unit for {$base}"
        );
    }

    /** @return array<string,array{int,int}> awkward numbers on purpose */
    public static function amounts(): array
    {
        return [
            'rounds up at exactly a half' => [1005, 500],
            'tiny amount' => [1, 1234],
            'odd amount, odd rate' => [999_999, 733],
            'zero base' => [0, 1000],
            'full commission' => [123_456, 10000],
            'no commission' => [123_456, 0],
            'large' => [9_999_999_999, 1234],
        ];
    }

    public function testAnUnsetRateProducesNeedsConfigurationAndNotAZeroCommission(): void
    {
        $outcome = $this->calculator->calculate(Money::of(900000), CommissionRate::unset(), 'none', 7);

        self::assertFalse($outcome->isCalculated());
        self::assertSame(CommissionOutcome::NEEDS_CONFIGURATION, $outcome->state);
        self::assertSame('rate_unset', $outcome->reason);
        self::assertNull($outcome->commission, 'nothing may be presented as a calculated share');
    }

    public function testAnExplicitZeroRateIsAValidDecisionAndIsCalculated(): void
    {
        $outcome = $this->calculator->calculate(Money::of(900000), CommissionRate::ofBasisPoints(0), 'product', 7);

        self::assertTrue($outcome->isCalculated(), 'zero is a decision, unlike empty');
        self::assertSame(0, $outcome->commission?->minor);
        self::assertSame('900000.0000', $outcome->vendorShare?->toDecimal());
    }

    public function testEmptyStoredValuesNeverBecomeZero(): void
    {
        foreach ([null, '', false] as $stored) {
            self::assertFalse(CommissionRate::fromStored($stored)->isSet(), var_export($stored, true) . ' must read as unset');
        }
        self::assertTrue(CommissionRate::fromStored(0)->isSet());
        self::assertTrue(CommissionRate::fromStored('0')->isSet());
        self::assertTrue(CommissionRate::fromStored(0)->isZero());
    }

    public function testARateOutsideZeroToOneHundredPercentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommissionRate::ofBasisPoints(10001);
    }

    public function testTheSnapshotRecordsWhereTheRateCameFromAndHowItWasRounded(): void
    {
        $outcome = $this->calculator->calculate(Money::of(1005), CommissionRate::ofBasisPoints(500), 'vendor', 42, 'coupon:autumn');
        $snapshot = $outcome->snapshot?->toArray() ?? [];

        self::assertSame(500, $snapshot['rate_bp']);
        self::assertSame('vendor', $snapshot['rate_source']);
        self::assertSame(RoundingPolicy::describe(), $snapshot['rounding']);
        self::assertSame(42, $snapshot['vendor_id']);
        self::assertSame('coupon:autumn', $snapshot['discount_allocation']);
        self::assertSame('IRR', $snapshot['currency']);
    }

    public function testHalfUpRoundsAwayFromZeroOnTheHalf(): void
    {
        self::assertSame(3, RoundingPolicy::apply(5, 2), '2.5 rounds to 3');
        self::assertSame(2, RoundingPolicy::apply(3, 2), '1.5 rounds to 2');
        self::assertSame(-3, RoundingPolicy::apply(-5, 2), 'and symmetrically for negatives');
    }

    public function testMoneyOfDifferentCurrenciesCannotBeCombined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(100, 'IRR')->add(Money::of(100, 'USD'));
    }

    public function testAnAmountAndItsTenTimesLabelAreNotTheSameMoney(): void
    {
        // «تومان» is a label, not a licence to convert (FIN-01).
        self::assertFalse(Money::of(900000, 'IRR', 0)->equals(Money::of(900000, 'IRR', 1)));
    }

    public function testATransactionOnlyPassesWhenItsLinesCancel(): void
    {
        $base = Money::of(900000);
        $commission = Money::of(90000);
        $vendor = Money::of(810000);

        $balanced = (new LedgerTransaction('evt-1', 7, 'order-1', 'item-1'))
            ->add(LedgerAccount::CentralPayment, $base, 'paid')
            ->add(LedgerAccount::Commission, $commission->negate(), 'commission')
            ->add(LedgerAccount::VendorEarning, $vendor->negate(), 'vendor');
        self::assertTrue($balanced->balances());

        // The forbidden shortcut: hand the whole order amount to the vendor.
        $unbalanced = (new LedgerTransaction('evt-2', 7, 'order-1', 'item-1'))
            ->add(LedgerAccount::VendorEarning, $base, 'everything');
        self::assertFalse($unbalanced->balances(), 'a lone transfer to the vendor can never balance');
    }

    public function testAnEmptyTransactionIsNotBalancedItIsNothing(): void
    {
        self::assertFalse((new LedgerTransaction('evt-3', 7, 'o', 'i'))->balances());
    }

    public function testALineThatMovesNothingIsNotRecorded(): void
    {
        $transaction = (new LedgerTransaction('evt-4', 7, 'o', 'i'))
            ->add(LedgerAccount::CentralPayment, Money::of(1000), 'paid')
            ->add(LedgerAccount::TaxCollected, Money::of(0), 'no tax here')
            ->add(LedgerAccount::VendorEarning, Money::of(1000)->negate(), 'vendor');

        self::assertCount(2, $transaction->lines());
        self::assertTrue($transaction->balances());
    }
}
