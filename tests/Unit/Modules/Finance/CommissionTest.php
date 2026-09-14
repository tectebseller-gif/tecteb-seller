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
        // B=900000, rate 10% → C=90000, V=810000 (Master Spec §23).
        $outcome = $this->calculator->calculate(
            Money::of(900000),
            CommissionRate::ofBasisPoints(1000),
            'general',
            7
        );

        self::assertTrue($outcome->isCalculated());
        self::assertSame(90000, $outcome->commission?->minor);
        self::assertSame(810000, $outcome->vendorShare?->minor);
    }

    /** @dataProvider amounts */
    public function testTheTwoSharesAlwaysAddUpToTheBase(int $base, int $rateBp): void
    {
        $outcome = $this->calculator->calculate(Money::of($base), CommissionRate::ofBasisPoints($rateBp), 'general', 7);

        self::assertTrue($outcome->isCalculated());
        self::assertSame(
            $base,
            ($outcome->commission?->minor ?? 0) + ($outcome->vendorShare?->minor ?? 0),
            "V + C must be exactly B for base {$base} at {$rateBp}bp"
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
        self::assertSame(900000, $outcome->vendorShare?->minor);
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
