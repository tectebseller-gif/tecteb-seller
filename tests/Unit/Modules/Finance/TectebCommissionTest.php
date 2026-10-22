<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;

/**
 * WHAT THIS PROVES: the owner's commission model computes what §5 says it
 * computes, and refuses the rest by name.
 *
 * Every clause of §5 that can be decided without a database is asserted here,
 * because that is where the arithmetic lives: a percentage of the
 * after-discount amount, a fixed amount per unit multiplied by the quantity,
 * zero and unset meaning the same result by two different routes, and a value
 * that cannot be stored exactly refused rather than rounded.
 */
final class TectebCommissionTest extends TestCase
{
    private TectebCommissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TectebCommissionCalculator();
    }

    /** §5-الف: «مبلغ ثابت به ازای هر عدد است و در تعداد ضرب می‌شود» — ۲۰٬۰۰۰ × ۳ = ۶۰٬۰۰۰. */
    public function testAFixedCommissionIsPerUnitAndMultipliesByTheQuantity(): void
    {
        $outcome = $this->calculator->calculate(
            Money::of(300000),
            3,
            TectebCommission::fixed('20000'),
            'product',
            7
        );

        self::assertTrue($outcome->isCalculated());
        // AT THE FINER UNIT. From `alpha.67` an outcome comes back at
        // `CommissionPrecision::exponentFor()`, four places past the order's
        // own, so ۶۰٬۰۰۰ تومان is `600000000` at `exponent = 4`. The decimal
        // string is asserted beside it, because that is what a person reads
        // and it is the sentence the owner's example is written in.
        self::assertSame(4, $outcome->commission?->exponent);
        self::assertSame(600000000, $outcome->commission?->minor);
        self::assertSame('60000.0000', $outcome->commission?->toDecimal());
        self::assertSame(2400000000, $outcome->vendorShare?->minor);
        self::assertSame('240000.0000', $outcome->vendorShare?->toDecimal());
        // The snapshot has to explain the multiplication a year later: ۶۰٬۰۰۰
        // alone does not say whether it was ۲۰٬۰۰۰×۳ or ۶۰٬۰۰۰×۱.
        self::assertSame(TectebCommission::FIXED, $outcome->snapshot?->commissionType);
        self::assertSame(3, $outcome->snapshot?->quantity);
        self::assertSame(200000000, $outcome->snapshot?->unitAmountMinor);
    }

    /** §5-ب: the percentage is taken on the amount the line actually paid. */
    public function testAPercentageIsTakenOnTheAmountGivenAndNothingElse(): void
    {
        // 450,000 paid for the line after a discount, 10% commission.
        $outcome = $this->calculator->calculate(
            Money::of(450000),
            2,
            TectebCommission::percent(1000),
            'product',
            7
        );

        self::assertSame(450000000, $outcome->commission?->minor);
        self::assertSame('45000.0000', $outcome->commission?->toDecimal());
        self::assertSame(4050000000, $outcome->vendorShare?->minor);
        self::assertSame('405000.0000', $outcome->vendorShare?->toDecimal());
        // Quantity does NOT multiply a percentage: the line total already
        // carries it, and multiplying again would charge twice.
        self::assertSame(1000, $outcome->snapshot?->rateBasisPoints);
        self::assertSame(TectebCommission::PERCENT, $outcome->snapshot?->commissionType);
        self::assertSame(0, $outcome->snapshot?->unitAmountMinor);
    }

    /**
     * §5-ج: «تعیین‌نشده یا صفر یعنی بدون کمیسیون» — and both are a RESULT.
     *
     * The pair matters. `needsConfiguration` was the old answer to an unset
     * rate and it is what made a missing rate a blocker; here both routes
     * produce a calculated outcome whose commission is zero and whose vendor
     * share is the whole line.
     */
    public function testNoRuleAndAZeroRuleBothProduceNoCommissionAndAreStillCalculated(): void
    {
        foreach ([
            'none' => TectebCommission::none(),
            'zero percent' => TectebCommission::percent(0),
            'zero fixed' => TectebCommission::fixed('0'),
        ] as $label => $rule) {
            $outcome = $this->calculator->calculate(Money::of(450000), 2, $rule, 'none', 7);
            self::assertTrue($outcome->isCalculated(), $label . ' must not be a refusal');
            self::assertSame(0, $outcome->commission?->minor, $label);
            self::assertSame(4500000000, $outcome->vendorShare?->minor, $label);
            self::assertSame('450000.0000', $outcome->vendorShare?->toDecimal(), $label);
        }
    }

    /**
     * §5-ح: zero is a value, invalid input is not — and they must not collapse.
     *
     * `'0'` saves a rule that computes nothing. `'abc'`, `'120'` and `'-5'`
     * save nothing at all and each says which field was wrong. A panel that
     * coerced any of them would write a share of somebody's sale that nobody
     * chose (`alpha.45`, one floor down).
     */
    public function testZeroIsAValueAndInvalidInputIsRefusedByName(): void
    {
        $zero = TectebCommission::fromInput('percent', '0');
        self::assertSame('', $zero['reason']);
        self::assertSame(0, $zero['rule']?->percentBasisPoints);
        self::assertFalse($zero['rule']?->isNone(), 'an explicit zero is a decision, not an absence');

        foreach ([
            ['percent', 'abc', TectebCommission::BAD_PERCENT],
            ['percent', '120', TectebCommission::BAD_PERCENT],
            ['percent', '-5', TectebCommission::BAD_PERCENT],
            ['percent', '', TectebCommission::BAD_PERCENT],
            ['percent', '12.345', TectebCommission::BAD_PERCENT],
            ['fixed', '-1', TectebCommission::BAD_FIXED],
            ['fixed', 'خیلی', TectebCommission::BAD_FIXED],
            ['fixed', '', TectebCommission::BAD_FIXED],
            ['share', '10', TectebCommission::BAD_TYPE],
        ] as [$type, $value, $reason]) {
            $answer = TectebCommission::fromInput($type, $value);
            self::assertNull($answer['rule'], $type . ' ' . $value . ' must be refused');
            self::assertSame($reason, $answer['reason'], $type . ' ' . $value);
        }
    }

    /**
     * «۱۲٫۵»، «١٢٫٥» و «12.5» یک نتیجه می‌دهند — و بیشتر از آن پذیرفته نمی‌شود.
     *
     * The owner's manual staging test found this: the box's own hint gives
     * «مثلاً ۱۲٫۵» as the example, and typing exactly that saved nothing. The
     * conversion is at the FORM boundary (`fromInput()`), so `DecimalAmount`
     * stays as strict as WooCommerce's own `wc_format_decimal()` output.
     *
     * The second half of the test is the part that matters as much: a
     * GROUPING separator is refused by name. Reading one wrong moves an
     * amount by a factor of a thousand, and the conservative direction on
     * somebody's share of a sale is not to guess.
     */
    public function testPersianAndArabicDigitsReachTheSameRuleAsAsciiOnes(): void
    {
        $expected = ['percent', 1250, '12.50'];
        foreach ([
            'ascii' => '12.5',
            'persian' => "\u{06F1}\u{06F2}\u{066B}\u{06F5}",
            'arabic' => "\u{0661}\u{0662}\u{066B}\u{0665}",
            'mixed' => "12\u{066B}5",
            'padded' => " \u{06F1}\u{06F2}\u{066B}\u{06F5}\u{200C} ",
        ] as $name => $typed) {
            $answer = TectebCommission::fromInput('percent', $typed);
            self::assertSame('', $answer['reason'], $name . ' must be accepted');
            self::assertSame($expected[0], $answer['rule']?->type, $name);
            self::assertSame($expected[1], $answer['rule']?->percentBasisPoints, $name);
            self::assertSame($expected[2], $answer['rule']?->displayValue(), $name);
        }

        $fixed = TectebCommission::fromInput('fixed', "\u{06F2}\u{06F0}\u{06F0}\u{06F0}\u{06F0}");
        self::assertSame('', $fixed['reason']);
        self::assertSame('20000', $fixed['rule']?->fixedPerUnit);

        // Still out of range, and now for the RIGHT reason: before this round
        // «۱۲۰» and «۱۲٫۵» answered the same thing, so the message could not
        // tell a manager which of the two problems they had.
        self::assertSame(
            TectebCommission::BAD_PERCENT,
            TectebCommission::fromInput('percent', "\u{06F1}\u{06F2}\u{06F0}")['reason']
        );
        // A grouping separator is NOT a digit and is not translated.
        foreach (["\u{06F2}\u{06F0}\u{066C}\u{06F0}\u{06F0}\u{06F0}", '20,000'] as $grouped) {
            $answer = TectebCommission::fromInput('fixed', $grouped);
            self::assertNull($answer['rule'], $grouped . ' must be refused');
            self::assertSame(TectebCommission::BAD_FIXED, $answer['reason'], $grouped);
        }
    }

    /**
     * `fromStored()` stays ASCII-strict, and that is deliberate.
     *
     * A row can only hold what `fromInput()` let through, so a non-ASCII
     * stored value is somebody else's data — and «no commission» is the
     * documented safe reading of a row this model did not write.
     */
    public function testAStoredValueInPersianDigitsIsReadAsNoCommission(): void
    {
        $stored = TectebCommission::fromStored('percent', "\u{06F1}\u{06F2}\u{066B}\u{06F5}");
        self::assertTrue($stored->isNone());
    }

    /** Two decimal places of a percentage survive the round trip, as text. */
    public function testAPercentageKeepsItsTwoDecimalPlacesWithoutBecomingAFloat(): void
    {
        $rule = TectebCommission::fromInput('percent', '12.5')['rule'];
        self::assertSame(1250, $rule?->percentBasisPoints);
        self::assertSame('12.50', $rule?->displayValue());

        $stored = TectebCommission::fromStored('percent', $rule?->displayValue());
        self::assertSame(1250, $stored->percentBasisPoints);
    }

    /**
     * §5-ح: a fixed amount with more precision than the LINE'S UNIT can hold
     * is REFUSED, not rounded — and `alpha.67` moved where that line falls.
     *
     * ⛔ Until `alpha.67` the conversion happened against the order's own
     * exponent, so `۲۰٬۰۰۰٫۵۰` was unpayable in تومان and this test asserted
     * the refusal. The owner's no-rounding decision gives the line four more
     * places (`CommissionPrecision`), so that amount now FITS exactly and
     * refusing it would be the old defect upside down. The refusal is still
     * here and still named — it has simply moved to five places, which is one
     * more than the arithmetic can hold.
     */
    public function testAFixedAmountFinerThanTheLineCanHoldIsRefusedRatherThanRounded(): void
    {
        // Four places past تومان: exact, and recorded as it was typed.
        $exact = $this->calculator->calculate(
            Money::of(300000, 'IRT', 0),
            1,
            TectebCommission::fixed('20000.5'),
            'product',
            7
        );
        self::assertTrue($exact->isCalculated());
        self::assertSame(200005000, $exact->commission?->minor);
        self::assertSame('20000.5000', $exact->commission?->toDecimal());

        // FIVE places: one more than the unit holds, so refused by name.
        $tooFine = $this->calculator->calculate(
            Money::of(300000, 'IRT', 0),
            1,
            TectebCommission::fixed('20000.00001'),
            'product',
            7
        );
        self::assertFalse($tooFine->isCalculated());
        self::assertSame(TectebCommissionCalculator::FIXED_NOT_EXACT, $tooFine->reason);
    }

    /**
     * THE OWNER'S WORKED EXAMPLE, exactly as it is written.
     *
     * «مبلغ قلم ۱۰۱ تومان، نرخ ۱۲٫۵٪، کمیسیون دقیقاً ۱۲٫۶۲۵ تومان و سهم
     * تأمین‌کننده دقیقاً ۸۸٫۳۷۵ تومان.» Asserted as integers at the unit the
     * arithmetic produces AND as the decimal strings a person reads, because
     * one of those alone can be right while the other is wrong.
     */
    public function testTheOwnersWorkedExampleIsExactToTheThousandthOfAToman(): void
    {
        $outcome = $this->calculator->calculate(
            Money::of(101, 'IRT', 0),
            1,
            TectebCommission::percent(1250),
            'product',
            7
        );

        self::assertTrue($outcome->isCalculated());
        self::assertSame(4, $outcome->commission?->exponent, 'four places, derived from the rate denominator');
        self::assertSame(126250, $outcome->commission?->minor);
        self::assertSame('12.6250', $outcome->commission?->toDecimal());
        self::assertSame(883750, $outcome->vendorShare?->minor);
        self::assertSame('88.3750', $outcome->vendorShare?->toDecimal());
        // The base comes back in the same unit, so the identity below is
        // about three numbers of one kind.
        self::assertSame(1010000, $outcome->base?->minor);
        self::assertSame('101.0000', $outcome->base?->toDecimal());
        self::assertSame(
            $outcome->base?->minor,
            (int) $outcome->commission?->minor + (int) $outcome->vendorShare?->minor,
            'C + V must be B exactly'
        );
        // And the snapshot says there was no rounding, rather than which
        // rounding there was.
        self::assertSame('exact@v2', $outcome->snapshot?->roundingPolicy);
    }

    /** An amount whose percentage lands on a whole تومان is still exact. */
    public function testAnAmountWhoseCommissionIsWholeIsAlsoExact(): void
    {
        $outcome = $this->calculator->calculate(
            Money::of(1000, 'IRT', 0),
            1,
            TectebCommission::percent(1250),
            'product',
            7
        );

        self::assertTrue($outcome->isCalculated());
        self::assertSame('125.0000', $outcome->commission?->toDecimal());
        self::assertSame('875.0000', $outcome->vendorShare?->toDecimal());
    }

    /**
     * MULTIPLE QUANTITY AND A DISCOUNT, on one approved commission base.
     *
     * The percentage is taken on what the line actually paid — quantity is
     * already in that figure and a discount has already come off it — so
     * three units of ۱۰۱ discounted to ۲۵۰ owe ۱۲٫۵٪ of ۲۵۰ and not of ۳۰۳.
     */
    public function testQuantityAndDiscountAreBothAlreadyInTheBase(): void
    {
        $threeUnits = $this->calculator->calculate(
            Money::of(303, 'IRT', 0),
            3,
            TectebCommission::percent(1250),
            'product',
            7
        );
        self::assertSame('37.8750', $threeUnits->commission?->toDecimal());
        self::assertSame('265.1250', $threeUnits->vendorShare?->toDecimal());

        $discounted = $this->calculator->calculate(
            Money::of(250, 'IRT', 0),
            3,
            TectebCommission::percent(1250),
            'product',
            7
        );
        self::assertSame('31.2500', $discounted->commission?->toDecimal());
        self::assertSame('218.7500', $discounted->vendorShare?->toDecimal());
        self::assertSame(3, $discounted->snapshot?->quantity);
    }

    /**
     * A unit too fine to hold an exact commission is refused BY NAME.
     *
     * An order captured at three decimal places would need seven and `Money`
     * carries six. The owner ruled out both ways of hiding that: «با تبدیل
     * بی‌صدا یا تغییر exponent ارز مشکل را پنهان نکن».
     */
    public function testAUnitTooFineForAnExactCommissionIsRefusedRatherThanRounded(): void
    {
        $outcome = $this->calculator->calculate(
            Money::of(101000, 'XXX', 3),
            1,
            TectebCommission::percent(1250),
            'product',
            7
        );

        self::assertFalse($outcome->isCalculated());
        self::assertSame(TectebCommissionCalculator::PRECISION_UNAVAILABLE, $outcome->reason);

        // Two places still works, exactly at the ceiling.
        $atTwo = $this->calculator->calculate(
            Money::of(10100, 'USD', 2),
            1,
            TectebCommission::percent(1250),
            'product',
            7
        );
        self::assertTrue($atTwo->isCalculated());
        self::assertSame(6, $atTwo->commission?->exponent);
        self::assertSame('12.625000', $atTwo->commission?->toDecimal());
    }

    /**
     * The undecided case, refused by name rather than capped.
     *
     * A fixed amount larger than the line would make the vendor's share
     * negative, and capping it would invent a rate the owner never set. The
     * owner has not said which is right, so nothing is recorded and the line
     * is reported — the question is in the delivery document.
     */
    public function testAFixedCommissionLargerThanTheLineIsRefusedAndNotCapped(): void
    {
        $outcome = $this->calculator->calculate(
            Money::of(50000),
            1,
            TectebCommission::fixed('80000'),
            'product',
            7
        );
        self::assertFalse($outcome->isCalculated());
        self::assertSame(TectebCommissionCalculator::EXCEEDS_LINE, $outcome->reason);
        self::assertNull($outcome->vendorShare, 'nothing is calculated, so nothing is negative');
    }

    /** A line of «zero units» is not a line, and a negative amount is not a sale. */
    public function testAnImpossibleLineIsRefusedByName(): void
    {
        $zeroUnits = $this->calculator->calculate(Money::of(1000), 0, TectebCommission::percent(1000), 'product', 7);
        self::assertSame(TectebCommissionCalculator::BAD_QUANTITY, $zeroUnits->reason);

        $negative = $this->calculator->calculate(Money::of(-1000), 1, TectebCommission::percent(1000), 'product', 7);
        self::assertSame(TectebCommissionCalculator::NEGATIVE_BASE, $negative->reason);
    }

    /**
     * §5-ی: the snapshot says WHERE the rule came from.
     *
     * A variable product whose variation has no rule of its own is charged by
     * its parent's, and a line that cannot be explained a year later without
     * knowing which of the two it was is not auditable.
     */
    public function testTheSnapshotRecordsWhereTheRuleCameFrom(): void
    {
        foreach (['variation', 'product', 'none'] as $source) {
            $outcome = $this->calculator->calculate(
                Money::of(100000),
                1,
                TectebCommission::percent(500),
                $source,
                7
            );
            self::assertSame($source, $outcome->snapshot?->rateSource);
        }
    }

    /** Unreadable stored data is «no commission», never a guessed percentage. */
    public function testUnreadableStoredDataReadsAsNoCommission(): void
    {
        foreach ([
            ['percent', 'abc'],
            ['percent', '900'],
            ['fixed', '-3'],
            ['something', '10'],
            ['', ''],
            [null, null],
        ] as [$type, $value]) {
            self::assertTrue(
                TectebCommission::fromStored($type, $value)->isNone(),
                'unreadable data must not become a rate'
            );
        }
    }

    /** V + C is B by construction, over a grid rather than one example. */
    public function testTheShareAndTheCommissionAlwaysAddUpToWhatWasPaid(): void
    {
        foreach ([0, 1, 7, 999, 450000, 1000003] as $baseMinor) {
            foreach ([0, 1, 250, 1000, 3333, 10000] as $bp) {
                $outcome = $this->calculator->calculate(
                    Money::of($baseMinor),
                    1,
                    TectebCommission::percent($bp),
                    'product',
                    7
                );
                self::assertTrue($outcome->isCalculated(), $baseMinor . '/' . $bp);
                // Against the RESCALED base, which is the one the two figures
                // are in. Comparing them against the argument's integer was
                // the right assertion while everything was at one exponent
                // and is a unit error now.
                self::assertSame(
                    $outcome->base?->minor,
                    (int) $outcome->commission?->minor + (int) $outcome->vendorShare?->minor,
                    'C + V must equal B for ' . $baseMinor . ' at ' . $bp . 'bp'
                );
                // And the base really is the same money: the argument times
                // ten thousand, with nothing lost.
                self::assertSame(
                    $baseMinor * 10000,
                    $outcome->base?->minor,
                    'the base must be the same amount in the finer unit'
                );
            }
        }
    }
}
