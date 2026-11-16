<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * The two figures a vendor may change on a REAL WooCommerce variation.
 *
 * ⛔ **WHY THIS PORT IS SO NARROW.** `alpha.105` gave the panel a reader for
 * imported variable products and `alpha.106` made the parent's price and stock
 * unwritable from five directions, because a variable parent carrying
 * `manage_stock = true` and a quantity of nought takes the whole product —
 * every child with it — out of sale. The owner's §3 now asks for the other
 * half: the vendor must be able to correct a CHILD's regular price, sale price
 * and stock, on the real ids, without the WooCommerce admin.
 *
 * So the port names the child and the parent in every call and nothing else is
 * reachable through it:
 *
 *  - **there is no verb that creates a variation**, so no call can make a
 *    second combination. Creating is §4's door and goes through
 *    `tmc_product_variations`;
 *  - **there is no verb that deletes one**, so «حذف ناخواستهٔ ترکیب‌ها» is a
 *    thing this interface cannot express;
 *  - **there is no verb that touches the parent.** The parent id is an
 *    argument so that the implementation can PROVE the child belongs to it —
 *    `$parent->get_children()` is the only membership WooCommerce recognises —
 *    and an architecture test reads the implementation's bytes to keep every
 *    setter off it;
 *  - **the two prices move together.** `applySale()` has been called from
 *    inside the price branch since `alpha.6`, and `alpha.83` moved all four
 *    sale fields out of `SENSITIVE` with the regular price for exactly this
 *    reason: a vendor who drops a sale and raises the price must not get the
 *    raise live over a sale still running.
 *
 * **Every verb is compare-and-set, and the expected value is what the vendor
 * was SHOWN.** `alpha.49`'s rule: consent is about the value that was on the
 * screen. `alpha.50` then measured that a read followed by a write is not a
 * guard — WooCommerce reduces stock from `wc_maybe_reduce_stock_levels()` in
 * one unconditional statement with no transaction — so the condition has to
 * ride inside the statement, and for the prices the read-back has to be the
 * proof (`WC_Product::save()` returns the id whether it wrote or not,
 * `alpha.28`).
 */
interface StorefrontVariationWriterInterface
{
    /**
     * The shop's price scale, or `null` when it could not be read.
     *
     * Here rather than guessed by the caller, because this is the class that
     * writes the figure and a price read in one scale and written in another
     * moves by a factor of a hundred (`alpha.39`, measured on the Dokan order
     * reader). The vendor types a decimal and `DecimalAmount` turns it into
     * minor units AT THIS SCALE — exact or refused, never rounded.
     */
    public function priceExponent(): ?int;

    /**
     * The regular and sale price of one child, as ONE unit of work.
     *
     * `null` for a price means «this child has no price of that kind», which
     * is a real state WooCommerce stores as an empty string: a variation with
     * no regular price is not purchasable, and a variation with no sale price
     * is simply not on sale. It is NOT «leave it alone» — the caller decides
     * that by not calling.
     *
     * @param ?int $regularMinor      the asked-for regular price, in minor units
     * @param ?int $saleMinor         the asked-for sale price, in minor units
     * @param ?int $seenRegularMinor  what the screen showed as the regular price
     * @param ?int $seenSaleMinor     what the screen showed as the sale price
     * @return array{outcome:string, found_regular:?int, found_sale:?int, exponent:?int}
     *         `written` · `moved` (somebody changed it since the render, and
     *         `found_*` say to what) · `not_a_child` · `not_variable` ·
     *         `no_woocommerce` · `unreadable` (the shop's price scale could
     *         not be read, so no figure here has a meaning) · `bad_sale` (a
     *         sale that is not below the regular price, which WooCommerce
     *         would silently ignore) · `not_written`
     */
    public function writePricesIfUnchanged(
        int $parentWcId,
        int $variationId,
        ?int $regularMinor,
        ?int $saleMinor,
        ?int $seenRegularMinor,
        ?int $seenSaleMinor
    ): array;

    /**
     * One child's stock, written only while the shop still holds `$seen`.
     *
     * ⛔ **It turns management on for the CHILD and never for the parent.** A
     * variation with `manage_stock = false` has no `_stock` row of its own and
     * inherits the parent's answer; a vendor typing a number for that child is
     * asking for the child to be counted, which is what WooCommerce's own
     * «Manage stock?» tick on the variation means. The parent's flag is left
     * exactly as found — that is the `alpha.106` protection and this port does
     * not weaken it.
     *
     * `$seen` of `null` means the screen showed «به‌ازای والد» — no managed
     * number — and is the one case where there is no row to guard. It is
     * accepted as the FIRST declaration of a number for this child, and
     * refused if a number has appeared in the meantime.
     *
     * @return array{outcome:string, found:?int}
     *         `written` · `moved` · `not_a_child` · `not_variable` ·
     *         `no_woocommerce` · `not_written`
     */
    public function writeStockIfUnchanged(
        int $parentWcId,
        int $variationId,
        int $stock,
        ?int $seen
    ): array;
}
