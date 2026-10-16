<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * ONE ORDER LINE, ONE COMMISSION — under the owner's per-product model.
 *
 *   B = what was actually paid for the line, after discount, before tax
 *   C = `none`    → 0
 *       `percent` → round(B × bp / 10000)
 *       `fixed`   → per-unit amount × quantity
 *   V = B − C
 *
 * `CommissionCalculator` is NOT changed and is NOT called from here. It
 * implements FIN-01's percentage-with-inheritance rule, which several hundred
 * recorded lines were produced by, and rewriting it would rewrite how those
 * lines are explained. Two rules, two classes, each saying in its docblock
 * that the other exists — the shape `alpha.52` settled on for `FieldMerge`
 * and `ProposedBrand`.
 *
 * **What this class refuses rather than guesses.** V is computed by
 * subtraction, so V + C is B by construction; and a fixed amount larger than
 * the line it is taken from is NOT silently capped. Capping would invent a
 * rate the owner never set, and taking more than the line holds would make V
 * negative — a vendor owing money because somebody typed a big number. The
 * owner has not decided which of those is right, so the line is refused by
 * name (`EXCEEDS_LINE`), it is reported, and the question is asked.
 *
 * Shipping and tax are not in B: they are the caller's to exclude, which is
 * why the parameter is named for what it must already be — «ارسال و مالیات در
 * مبنا نیست». Nothing here multiplies or divides a `float`.
 */
final class TectebCommissionCalculator
{
    /** The quantity has to be a real count; a line of «zero units» is not one. */
    public const BAD_QUANTITY = 'commission_quantity_invalid';
    /** The per-unit amount carries more precision than this currency has. */
    public const FIXED_NOT_EXACT = 'commission_fixed_not_exact';
    /** A fixed commission bigger than the line — an open decision, not a cap. */
    public const EXCEEDS_LINE = 'commission_exceeds_line';
    /** A line that paid a negative amount is not a sale to take a share of. */
    public const NEGATIVE_BASE = 'negative_base';

    /**
     * @param Money  $baseAfterDiscountBeforeTax B — already net of discount,
     *               excluding tax and shipping, in the ORDER's own unit
     * @param int    $quantity how many units the line carried
     * @param string $ruleSource where the rule was found — a variation's own
     *               setting, its parent product's, or nowhere
     */
    public function calculate(
        Money $baseAfterDiscountBeforeTax,
        int $quantity,
        TectebCommission $rule,
        string $ruleSource,
        int $vendorUserId,
        string $discountAllocation = ''
    ): CommissionOutcome {
        if ($quantity < 1) {
            return CommissionOutcome::needsConfiguration(self::BAD_QUANTITY);
        }
        if ($baseAfterDiscountBeforeTax->isNegative()) {
            return CommissionOutcome::needsConfiguration(self::NEGATIVE_BASE);
        }

        $base = $baseAfterDiscountBeforeTax;
        $unitMinor = 0;

        if ($rule->type === TectebCommission::FIXED) {
            // Converted HERE and not when the manager typed it, against the
            // line's own exponent: the shop's display setting is not the
            // order's unit, and `alpha.39` paid for assuming it was. A value
            // that will not fit exactly is refused rather than rounded —
            // `DecimalAmount` returns null precisely so that nobody has to
            // decide which way to round somebody's share.
            $perUnit = DecimalAmount::toMinor($rule->fixedPerUnit, $base->exponent);
            if ($perUnit === null || $perUnit < 0) {
                return CommissionOutcome::needsConfiguration(self::FIXED_NOT_EXACT);
            }
            $unitMinor = $perUnit;
            $commissionMinor = $perUnit * $quantity;
        } elseif ($rule->type === TectebCommission::PERCENT) {
            $commissionMinor = RoundingPolicy::apply(
                $base->minor * (int) $rule->percentBasisPoints,
                CommissionRate::MAX_BP
            );
        } else {
            // `none` — and this is a RESULT, not a refusal. «تعیین‌نشده یا
            // صفر یعنی بدون کمیسیون»، and a product with no rule still sells.
            $commissionMinor = 0;
        }

        if ($commissionMinor > $base->minor) {
            return CommissionOutcome::needsConfiguration(self::EXCEEDS_LINE);
        }

        $commission = Money::of($commissionMinor, $base->currency, $base->exponent);
        $vendorShare = $base->subtract($commission);

        return CommissionOutcome::calculated($base, $commission, $vendorShare, new CommissionSnapshot(
            $rule->type === TectebCommission::PERCENT ? (int) $rule->percentBasisPoints : 0,
            $ruleSource,
            RoundingPolicy::describe(),
            $base->currency,
            $base->exponent,
            $vendorUserId,
            $base->minor,
            $commission->minor,
            $vendorShare->minor,
            $discountAllocation,
            $rule->type,
            $quantity,
            $unitMinor
        ));
    }
}
