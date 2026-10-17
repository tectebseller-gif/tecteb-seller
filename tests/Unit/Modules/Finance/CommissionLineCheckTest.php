<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionLineCheck;
use Tecteb\Marketplace\Modules\Finance\Application\TectebCommissionSourceInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;

/**
 * WHAT THIS PROVES: the question «would this line leave the supplier nought or
 * less?» has one answer, it is the calculator's own, and it is asked of the
 * amount the line would actually pay.
 *
 * §1: «اگر کمیسیون ثابت پس از تخفیف از مبلغ قلم بیشتر شود، خرید آن قلم پیش از
 * دریافت وجه متوقف شود … سهم تأمین‌کننده هرگز نباید خودکار صفر یا منفی شود …
 * کمیسیون خالی یا صفر … مجاز بماند.»
 *
 * The hooks that ASK this at the right moment are measured on real WooCommerte
 * in `tools/alpha62-reproduction.sh`; what is measured here is the rule, on
 * every edge the owner named.
 */
final class CommissionLineCheckTest extends TestCase
{
    /** WHAT THIS PROVES: no rule at all is sellable, and so is a rule of nought. */
    public function testNoCommissionAndZeroCommissionAreBothSellable(): void
    {
        $none = $this->check(TectebCommission::none())->check(10, 0, '450000', 1, 'IRR', 0);
        self::assertTrue($none['ok']);
        self::assertSame(CommissionLineCheck::OK, $none['reason']);
        self::assertSame(0, $none['commission_minor']);

        $zero = $this->check(TectebCommission::percent(0))->check(10, 0, '450000', 1, 'IRR', 0);
        self::assertTrue($zero['ok']);
        self::assertSame(0, $zero['commission_minor']);
    }

    /** WHAT THIS PROVES: a percentage can never exceed the line it is taken from. */
    public function testAPercentageIsAlwaysSellable(): void
    {
        $answer = $this->check(TectebCommission::percent(10000))->check(10, 0, '450000', 1, 'IRR', 0);
        self::assertTrue($answer['ok']);
        self::assertSame(450000, $answer['commission_minor'], 'a hundred per cent is the whole line and not more');
    }

    /**
     * WHAT THIS PROVES: a fixed amount per unit multiplied by the quantity is
     * weighed against the WHOLE line, not against one unit.
     *
     * ۲۰٬۰۰۰ × ۳ = ۶۰٬۰۰۰ against a line of 60,000 is exactly sellable, and
     * one unit more on the rate is not.
     */
    public function testAFixedAmountIsWeighedAgainstTheWholeLine(): void
    {
        $exact = $this->check(TectebCommission::fixed('20000'))->check(10, 0, '60000', 3, 'IRR', 0);
        self::assertTrue($exact['ok']);
        self::assertSame(60000, $exact['commission_minor']);

        $over = $this->check(TectebCommission::fixed('20001'))->check(10, 0, '60000', 3, 'IRR', 0);
        self::assertFalse($over['ok']);
        self::assertSame(CommissionLineCheck::EXCEEDS_LINE, $over['reason']);
    }

    /**
     * WHAT THIS PROVES: the DISCOUNT is what decides, because the base is the
     * line's own post-discount total.
     *
     * The same rule and the same quantity: at full price the line is fine, and
     * after a discount that takes it under the commission it is refused. That
     * is §1's whole scenario — «کمیسیون ثابت پس از تخفیف».
     */
    public function testADiscountIsWhatTurnsASellableLineIntoARefusedOne(): void
    {
        $rule = $this->check(TectebCommission::fixed('100000'));
        self::assertTrue($rule->check(10, 0, '450000', 1, 'IRR', 0)['ok']);
        self::assertFalse($rule->check(10, 0, '90000', 1, 'IRR', 0)['ok'], 'discounted under the commission');
    }

    /**
     * WHAT THIS PROVES: «نمی‌دانم» is not «بد است» (`alpha.49`).
     *
     * An amount this code cannot read exactly is not evidence that the
     * commission exceeds it, and refusing a basket over a formatting question
     * would stop a storefront for a reason that is not a reason. The capture
     * path still refuses the line by name if it really is unsellable.
     */
    public function testAnUnreadableAmountIsNotTreatedAsARefusal(): void
    {
        $rule = $this->check(TectebCommission::fixed('100000'));
        foreach (['', 'abc', '450000.123456789012'] as $typed) {
            $answer = $rule->check(10, 0, $typed, 1, 'IRR', 0);
            self::assertTrue($answer['ok'], $typed);
            self::assertSame(CommissionLineCheck::UNREADABLE, $answer['reason'], $typed);
        }
    }

    /** WHAT THIS PROVES: a quantity of nought is not a line to judge. */
    public function testAQuantityOfNoughtIsNotJudged(): void
    {
        $answer = $this->check(TectebCommission::fixed('100000'))->check(10, 0, '10', 0, 'IRR', 0);
        self::assertTrue($answer['ok']);
        self::assertSame(CommissionLineCheck::UNREADABLE, $answer['reason']);
    }

    /**
     * WHAT THIS PROVES: the variation's own rule is asked for, and the source
     * travels, so this check and the capture read the same rule.
     */
    public function testTheVariationIsAskedAboutRatherThanTheParent(): void
    {
        $source = new class implements TectebCommissionSourceInterface {
            /** @var list<array{int,int}> */
            public array $asked = [];

            public function forItem(int $wcProductId, int $variationId = 0): array
            {
                $this->asked[] = [$wcProductId, $variationId];
                return ['rule' => TectebCommission::none(), 'source' => 'variation'];
            }
        };
        (new CommissionLineCheck($source, new TectebCommissionCalculator()))
            ->check(10, 42, '450000', 1, 'IRR', 0);
        self::assertSame([[10, 42]], $source->asked);
    }

    private function check(TectebCommission $rule): CommissionLineCheck
    {
        $source = new class ($rule) implements TectebCommissionSourceInterface {
            public function __construct(private readonly TectebCommission $rule)
            {
            }

            public function forItem(int $wcProductId, int $variationId = 0): array
            {
                return ['rule' => $this->rule, 'source' => 'product'];
            }
        };
        return new CommissionLineCheck($source, new TectebCommissionCalculator());
    }
}
