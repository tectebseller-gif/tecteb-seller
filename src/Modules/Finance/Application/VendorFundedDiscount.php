<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

/**
 * «IS THIS LINE'S DISCOUNT ONE THE VENDOR AGREED TO FUND?» — asked before
 * money is taken, because afterwards it comes out of their share.
 *
 * ### The arithmetic, which is the whole finding
 *
 * `WcOrderReader` reads a line's base as `$item->get_total()` — after
 * discount and before tax, which is the approved commission base (FIN-01).
 * `CaptureOrder` then records `commission = base × rate` and
 * `share = base − commission`. So a discount `D` on a marketplace line does
 * not come out of the marketplace: it is split, `D × rate` off Tecteb's
 * commission and **`D × (1 − rate)` off the vendor's share**.
 *
 * For a vendor's own code that is correct and approved — they wrote the code
 * for their own goods. For anybody else's it is the owner's §1 verbatim:
 * «هیچ تخفیف سراسری نباید ناخواسته از سهم فروشندگان کم شود.»
 *
 * ### Why the native WooCommerce path had to be closed too
 *
 * `ManageCoupons` refuses to CREATE a marketplace-wide code
 * (`global_coupon_undecided`), and that was read as the whole of the rule. It
 * is not. `CartPricing::couponData()` steps aside for a real `shop_coupon`
 * post — «WooCommerce's own coupon, WooCommerce's job» — and a site-wide
 * percentage coupon created in wp-admin applies to whatever it is scoped to,
 * marketplace products included. Nothing then looked at where the discount
 * came from. So the restriction the owner asked about WAS bypassable, by the
 * ordinary admin screen rather than by anything exotic: §1's «مسیر کوپن بومی
 * WooCommerce نیز بررسی شود تا محدودیت فوق قابل دورزدن نباشد».
 *
 * ### What it does NOT do
 *
 * ⛔ **It does not decide who funds a global discount.** That is a financial
 * decision and §12 reserves it: «برای تصمیم مالی یا تجاری از طرف مالک تصمیم
 * نگیر». Rewriting the base to the pre-discount figure would make Tecteb fund
 * it; leaving it is what already happens and makes the vendor fund it. So the
 * line is REFUSED before payment instead, which is the state the owner
 * actually asked for — a global discount that is not applied at all — and the
 * remedy belongs to the person who created the coupon: scope it to the shop's
 * own products, or decide the funding and reopen it.
 *
 * ⛔ **And it says nothing about a line that is not discounted.** A coupon
 * scoped to the host shop's own goods leaves every marketplace line with
 * `total == subtotal`, so a mixed basket sells exactly as before. The rule
 * fires on the conjunction — this line lost money AND the codes applied do
 * not explain it — never on the presence of a coupon.
 *
 * ### Attribution is not available, so the conjunction is conservative
 *
 * WooCommerce does not expose a reliable per-line-per-coupon breakdown, so
 * «which code discounted THIS line» cannot be answered. What can be answered
 * is «did this line lose money, and is every code applied one this line's
 * vendor owns». When a foreign code is present beside a vendor's own, the
 * answer is refusal — the conservative direction on a question about somebody
 * else's money (`alpha.46`).
 */
final class VendorFundedDiscount
{
    /** Nothing was taken off this line, or its own vendor's code explains it. */
    public const OK = 'ok';
    /** A code that is not this line's vendor's own took money off it. */
    public const FOREIGN_DISCOUNT = 'discount_not_funded_by_vendor';
    /** The line lost money and no coupon at all was applied. */
    public const UNEXPLAINED_DISCOUNT = 'discount_unexplained';
    /** One of the two amounts could not be read, so no claim is made. */
    public const UNREADABLE = 'discount_amounts_unreadable';

    /**
     * @param int|null $subtotalMinor what the line would have cost with no
     *        coupon, or null when it could not be read exactly
     * @param int|null $totalMinor what the line actually costs, same
     * @param list<string> $foreignCodes applied codes that are NOT this
     *        line's vendor's own marketplace codes
     * @param bool $anyCodeApplied whether the basket carries any code at all
     * @return array{ok:bool, reason:string, discount_minor:int, codes:list<string>}
     */
    public static function check(
        ?int $subtotalMinor,
        ?int $totalMinor,
        array $foreignCodes,
        bool $anyCodeApplied
    ): array {
        if ($subtotalMinor === null || $totalMinor === null) {
            // ⛔ Refused, not waved through. This rule exists because a
            // discount silently reaches a vendor's share, and «I could not
            // read the amounts» is the one answer under which that silence
            // would be invisible. `UNREADABLE` in `CommissionLineCheck`
            // abstains because a commission bigger than a line is a fact
            // about numbers; this one is about a transfer, and the
            // conservative direction on a transfer is no.
            return [
                'ok' => false,
                'reason' => self::UNREADABLE,
                'discount_minor' => 0,
                'codes' => array_values($foreignCodes),
            ];
        }
        $discount = $subtotalMinor - $totalMinor;
        if ($discount <= 0) {
            // Nothing was taken off — and a NEGATIVE difference is not this
            // rule's business either: a total above its subtotal is a fee or
            // a tax arrangement, not a discount out of a share.
            return ['ok' => true, 'reason' => self::OK, 'discount_minor' => 0, 'codes' => []];
        }
        if ($foreignCodes !== []) {
            return [
                'ok' => false,
                'reason' => self::FOREIGN_DISCOUNT,
                'discount_minor' => $discount,
                'codes' => array_values($foreignCodes),
            ];
        }
        if (!$anyCodeApplied) {
            // Money came off a line and no code did it. Something else
            // moved the total — another plugin on
            // `woocommerce_before_calculate_totals`, a programmatic
            // `set_discount` — and whatever it was, the vendor did not agree
            // to it. The wholesale ladder is NOT this case: it changes the
            // product's price, so WooCommerce computes the subtotal from the
            // adjusted price and `subtotal == total`.
            return [
                'ok' => false,
                'reason' => self::UNEXPLAINED_DISCOUNT,
                'discount_minor' => $discount,
                'codes' => [],
            ];
        }
        // Discounted, and every code applied belongs to this line's vendor.
        return ['ok' => true, 'reason' => self::OK, 'discount_minor' => $discount, 'codes' => []];
    }

    /** @return list<string> every reason this rule refuses for */
    public static function refusals(): array
    {
        return [self::FOREIGN_DISCOUNT, self::UNEXPLAINED_DISCOUNT, self::UNREADABLE];
    }
}
