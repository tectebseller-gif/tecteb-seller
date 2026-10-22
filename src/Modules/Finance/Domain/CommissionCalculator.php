<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * FIN-01, and nothing else.
 *
 *   B = the amount actually paid for the item, after discount, before tax
 *   C = B × rate / 100, EXACTLY
 *   V = B − C
 *
 * V is computed by subtraction rather than by a second multiplication, so
 * V + C is B by construction — not by luck. Shipping is not in B (A.2), and
 * tax is not in B; both are the caller's job to exclude, which is why the
 * parameter is named for what it must already be.
 *
 * ⛔ **`C` was `round(B × rate / 100)` until `alpha.67`.** The owner's
 * decision is that no rounding is permitted anywhere — «هیچ گردکردنی مجاز
 * نیست» — and a current instruction from the owner outranks a settled
 * decision (the order of authority at the top of `CLAUDE.md`), so FIN-01's
 * own half-up rule goes with it. Both calculators now compute at the
 * exponent `CommissionPrecision` derives, and this one says so beside the
 * other rather than quietly keeping a second rule: two classes that disagree
 * about how much a sale owes is the `alpha.66` finding in a worse place.
 *
 * **Lines already recorded under the old rule are not touched.** Their
 * snapshots say `half_up@v1` and keep saying it; `recover()` in
 * `RecordCommission` reads the figures off the ledger rows and never
 * recomputes them.
 *
 * `TectebCommissionCalculator` is the owner's per-product model and is NOT
 * called from here; each class names the other so a third is not invented
 * (the `FieldMerge`/`ProposedBrand` shape from `alpha.52`).
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

        // The unit this line is computed in, derived from the rate's own
        // denominator. `null` is a refusal and never a licence to round.
        $exponent = CommissionPrecision::exponentFor($baseAfterDiscountBeforeTax->exponent);
        if ($exponent === null) {
            return CommissionOutcome::needsConfiguration(
                TectebCommissionCalculator::PRECISION_UNAVAILABLE
            );
        }
        $base = $baseAfterDiscountBeforeTax->rescale($exponent);
        if ($base === null) {
            return CommissionOutcome::needsConfiguration(TectebCommissionCalculator::BASE_TOO_LARGE);
        }
        // Asked BEFORE the multiplication: PHP promotes an integer overflow
        // to a float silently, so a check that reads the product is reading a
        // number that has already stopped being exact.
        $bp = (int) $rate->basisPoints;
        if ($bp !== 0 && intdiv(PHP_INT_MAX, abs($bp)) < abs($base->minor)) {
            return CommissionOutcome::needsConfiguration(TectebCommissionCalculator::BASE_TOO_LARGE);
        }
        $numerator = $base->minor * $bp;
        if ($numerator % CommissionRate::MAX_BP !== 0) {
            // Unreachable while `CommissionPrecision::EXTRA_SCALE` matches
            // the denominator — which the architecture suite asserts — and
            // refused rather than rounded if it ever stops matching.
            return CommissionOutcome::needsConfiguration(
                TectebCommissionCalculator::PRECISION_UNAVAILABLE
            );
        }
        $commissionMinor = intdiv($numerator, CommissionRate::MAX_BP);
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
