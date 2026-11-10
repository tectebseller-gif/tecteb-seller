<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

/**
 * «WOULD THIS CODE, IF APPLIED, TAKE MONEY OFF A VENDOR WHO DOES NOT OWN IT?»
 * — asked at the moment the code is applied, which is the only moment at which
 * the answer can still be «no, and nothing happened».
 *
 * ### Why a second rule, beside `VendorFundedDiscount`
 *
 * They ask the same commercial question at two different instants, and the
 * instant is the whole point.
 *
 * `VendorFundedDiscount` asks it from the LINE: subtotal minus total, after
 * the fact. That is the only way to catch a discount that arrives by some
 * route nobody declared — another plugin on
 * `woocommerce_before_calculate_totals`, a programmatic `set_discount` — and
 * it is why that rule stays exactly as it is.
 *
 * But by the time a line shows a discount, `WC_Cart::apply_coupon()` has
 * already returned true and WooCommerce has already said «Coupon code applied
 * successfully.» The owner measured precisely that on staging: a `fixed_cart`
 * code of 1,000 toman on a marketplace product produced the refusal AND the
 * success message on one screen, and the basket fell from 64,567 to 63,567.
 * Both sentences were true about their own instant. The money still moved.
 *
 * So this rule asks it from the CODE, before anything is applied, and it needs
 * no arithmetic at all: at that instant every line still shows
 * `subtotal == total`, so subtraction would answer «no discount» and be right
 * and useless.
 *
 * ### The conjunction
 *
 * Refuse when, for any one line, all three hold:
 *
 *  1. the line belongs to a marketplace vendor (a line of the host shop's own
 *     goods is not this rule's business),
 *  2. the code is not that vendor's own marketplace code, and
 *  3. the code would reach that line.
 *
 * Point 3 is what keeps the rule from being «ban every WooCommerce coupon»,
 * which §4-الف forbids: a code scoped to the host shop's own product ids does
 * not reach a marketplace line, so it is allowed, unchanged, and the shopper
 * sees nothing.
 *
 * ### The two unknowns fail in opposite directions, deliberately
 *
 * `touches === null` means «there is a marketplace line, the code is foreign,
 * and I cannot tell whether it reaches the line». That refuses. The question
 * is already narrowed to one basket and one foreign code, and the conservative
 * direction on somebody else's money is no (`alpha.46`).
 *
 * ⛔ But an EMPTY line list — «I could not read the basket at all», «the
 * ownership service is not bound» — does NOT refuse, and that is not timidity.
 * This rule runs on every coupon application on the site, so a fault inside it
 * would stop the host shop's own codes too: the `alpha.60` trap, a guard wider
 * than the defect it came for. The narrow failure is affordable because the
 * blast radius is the difference between the two rules — `VendorFundedDiscount`
 * still stands behind this one and fires exactly when a line really did lose
 * money. Two layers, each erring in the direction its own reach can afford.
 */
final class ForeignDiscountScope
{
    /** No marketplace line is reached by a code its vendor does not own. */
    public const OK = 'ok';
    /** A code nobody's vendor owns would reach a marketplace line. */
    public const FOREIGN_CODE = 'global_discount_not_decided';

    /**
     * @param list<array{vendor:int|null, owned:bool, touches:bool|null, name?:string}> $lines
     *        one entry per basket line, in basket order
     * @return array{ok:bool, reason:string, product:string, vendor:int}
     */
    public static function check(array $lines): array
    {
        foreach ($lines as $line) {
            $vendor = $line['vendor'] ?? null;
            if (!is_int($vendor) || $vendor <= 0) {
                continue;               // the host shop's own goods
            }
            if (($line['owned'] ?? false) === true) {
                continue;               // this vendor wrote this code
            }
            if (($line['touches'] ?? null) === false) {
                continue;               // scoped away from this line
            }
            // true, or null meaning «cannot tell» — both refuse here.
            return [
                'ok' => false,
                'reason' => self::FOREIGN_CODE,
                'product' => is_string($line['name'] ?? null) ? $line['name'] : '',
                'vendor' => $vendor,
            ];
        }
        return ['ok' => true, 'reason' => self::OK, 'product' => '', 'vendor' => 0];
    }
}
