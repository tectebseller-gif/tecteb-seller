<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * FIN-01, and nothing else.
 *
 *   B = the amount actually paid for the item, after discount, before tax
 *   C = round(B × rate / 100)
 *   V = B − C
 *
 * V is computed by subtraction rather than by a second multiplication, so
 * V + C is B by construction — not by luck, and not by a third rounding that
 * might disagree with the first two. Shipping is not in B (A.2), and tax is
 * not in B; both are the caller's job to exclude, which is why the parameter
 * is named for what it must already be.
 */
final class CommissionCalculator
{
    /**
     * @param Money $baseAfterDiscountBeforeTax B, already net of discount and
     *        excluding tax and shipping
     */
    public function calculate(
        Money $baseAfterDiscountBeforeTax,
        CommissionRate $rate,
        string $rateSource,
        int $vendorUserId,
        string $discountAllocation = ''
    ): CommissionOutcome {
        if (!$rate->isSet()) {
            // The one thing this class must never do: invent a rate.
            return CommissionOutcome::needsConfiguration('rate_unset');
        }
        if ($baseAfterDiscountBeforeTax->isNegative()) {
            return CommissionOutcome::needsConfiguration('negative_base');
        }

        $base = $baseAfterDiscountBeforeTax;
        $commissionMinor = RoundingPolicy::apply($base->minor * (int) $rate->basisPoints, CommissionRate::MAX_BP);
        $commission = Money::of($commissionMinor, $base->currency, $base->exponent);
        $vendorShare = $base->subtract($commission);

        return CommissionOutcome::calculated($base, $commission, $vendorShare, new CommissionSnapshot(
            (int) $rate->basisPoints,
            $rateSource,
            RoundingPolicy::describe(),
            $base->currency,
            $base->exponent,
            $vendorUserId,
            $base->minor,
            $commission->minor,
            $vendorShare->minor,
            $discountAllocation
        ));
    }
}
