<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * ONE ORDER LINE, ONE COMMISSION — under the owner's per-product model.
 *
 *   B = what was actually paid for the line, after discount, before tax
 *   C = `none`    → 0
 *       `percent` → B × bp / 10000, EXACTLY
 *       `fixed`   → per-unit amount × quantity
 *   V = B − C
 *
 * ⛔ **`percent` used to be `round(B × bp / 10000)`, and the owner has ruled
 * that out**: «هیچ گردکردنی مجاز نیست؛ نه به هزار تومان، نه به تومان کامل و
 * نه حذف اعشار», with a worked example — ۱۰۱ تومان at ۱۲٫۵٪ owes exactly
 * ۱۲٫۶۲۵ and leaves exactly ۸۸٫۳۷۵. Both figures are returned at the
 * exponent `CommissionPrecision::exponentFor()` derives from the rate's own
 * denominator, which is where the division leaves no remainder at all. B is
 * rescaled to the same unit first — losslessly, by a power of ten — so
 * `V + C = B` stays true BY CONSTRUCTION rather than by arithmetic luck.
 *
 * Still no `float` anywhere: a multiplication, an `intdiv()` whose remainder
 * is provably nought, and a subtraction.
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
     * This order's unit is too fine for an exact commission to be held.
     *
     * An order captured at `exponent = 3` or more would need `7`+ places and
     * `Money` carries `6`. Refused by name rather than rounded, and rather
     * than «quietly changing the currency's exponent to make room» — the two
     * things the owner named: «با تبدیل بی‌صدا یا تغییر exponent ارز مشکل را
     * پنهان نکن».
     */
    public const PRECISION_UNAVAILABLE = 'commission_precision_unavailable';
    /** The line's amount is so large that the finer unit overflows an integer. */
    public const BASE_TOO_LARGE = 'commission_base_too_large';

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

        // THE UNIT THE WHOLE LINE IS COMPUTED IN, derived from the rate's
        // denominator rather than chosen. `null` is a refusal, never a
        // licence to round.
        $exponent = CommissionPrecision::exponentFor($baseAfterDiscountBeforeTax->exponent);
        if ($exponent === null) {
            return CommissionOutcome::needsConfiguration(self::PRECISION_UNAVAILABLE);
        }
        // Lossless: a power of ten, same currency, nothing divided. It is
        // what makes `V + C = B` an identity instead of a hope.
        $base = $baseAfterDiscountBeforeTax->rescale($exponent);
        if ($base === null) {
            return CommissionOutcome::needsConfiguration(self::BASE_TOO_LARGE);
        }
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
            // Same rule as the percentage branch: asked before the
            // multiplication, because afterwards the number is a float and
            // the question cannot be answered from it.
            if ($perUnit !== 0 && intdiv(PHP_INT_MAX, $perUnit) < $quantity) {
                return CommissionOutcome::needsConfiguration(self::BASE_TOO_LARGE);
            }
            $commissionMinor = $perUnit * $quantity;
        } elseif ($rule->type === TectebCommission::PERCENT) {
            // EXACT, and provably so: `$base->minor` is already scaled by
            // `10 ** EXTRA_SCALE` relative to the order's own unit and
            // `MAX_BP` is `10 ** EXTRA_SCALE`, so the numerator is divisible
            // by the denominator with nothing left over. `intdiv()` rather
            // than `/` because `/` on two integers in PHP yields a float the
            // moment it does not divide evenly, and a float is the one thing
            // this module does not permit.
            //
            // The overflow is checked BEFORE the multiplication, not after:
            // PHP promotes an integer overflow to a float silently, so a
            // check that reads `$numerator` is reading a value that has
            // already lost its exactness.
            $bp = (int) $rule->percentBasisPoints;
            if ($bp !== 0 && intdiv(PHP_INT_MAX, abs($bp)) < abs($base->minor)) {
                return CommissionOutcome::needsConfiguration(self::BASE_TOO_LARGE);
            }
            $numerator = $base->minor * $bp;
            if ($numerator % CommissionRate::MAX_BP !== 0) {
                // Unreachable by the argument above, and checked anyway: a
                // remainder here would mean the derivation in
                // `CommissionPrecision` no longer matches the denominator,
                // and the honest answer to that is a refusal rather than the
                // rounding this round exists to remove.
                return CommissionOutcome::needsConfiguration(self::PRECISION_UNAVAILABLE);
            }
            $commissionMinor = intdiv($numerator, CommissionRate::MAX_BP);
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
