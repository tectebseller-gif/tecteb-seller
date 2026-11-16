<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * WHEN THE KIND OF A PRODUCT MAY CHANGE — one rule, read at both ends.
 *
 * §3 states the decision rather than asking for one: «فروشنده برای محصول تازه
 * باید بتواند محصول ساده یا متغیر ایجاد کند. برای محصول موجود، تبدیل نوع فقط
 * زمانی مجاز است که کل مسیر از ثبت تا تأیید مدیر، ساخت ویژگی و تنوع، موجودی و
 * همگام‌سازی WooCommerce ایمن باشد.» So the question is not what we would like
 * to allow — it is which part of that path exists.
 *
 * ⛔ AND THE PART THAT DOES NOT EXIST IS THE LAST ONE, measured from the
 * projector rather than assumed. `WooCommerceProjector::project()` chooses the
 * WooCommerce class in `blank()`, and `blank()` is reached only when
 * `linkedProduct()` returns null — that is, only for a product that has no
 * shop post yet (`WooCommerceProjector.php:121-123`). For a product that is
 * already in the shop the existing `WC_Product` object is reused and its type
 * is never touched. Nothing else in the plugin writes the `product_type` term.
 *
 * So on `alpha.107` bytes, flipping a LINKED product from simple to variable:
 *
 *  - was accepted by `ManageProducts::save()` whenever the figures were ours;
 *  - was recorded as a pending proposal and could be approved;
 *  - left `tmc_products.type = variable` beside a WooCommerce product that is
 *    still simple — and `projectVariations()` early-returns for anything that
 *    is not a `WC_Product_Variable`, so every combination the vendor then
 *    built stayed in our tables and never reached a buyer.
 *
 * A request that can be made, queued and approved and still does nothing is
 * worse than a refusal: the vendor and the manager both believe it happened.
 * And the opposite direction is worse still — variable to simple would leave
 * the shop's `product_variation` children parented to a product that no longer
 * admits them, which «تنوع‌های قدیمی WooCommerce دوباره ساخته یا حذف نشوند»
 * and «سوابق سفارش‌های قبلی محفوظ بمانند» both forbid touching and nothing
 * here would clean up.
 *
 * Hence: free before the product reaches the shop, refused afterwards, and the
 * refusal says what to do instead. ⛔ Nothing is deleted, nothing is rebuilt,
 * and no existing product's type is corrected by this rule — it only declines
 * to accept a new instruction it cannot carry out.
 *
 * ⛔ THE OLDER CODE KEEPS ITS NAME. `type_change_not_allowed` has been in
 * audit rows since `alpha.106` and means «the figures belong to the shop»;
 * renaming it would make twenty versions of trail unreadable (`alpha.57`). The
 * new case gets a new code of its own.
 */
final class ProductTypeChange
{
    /** The figures live in the shop, so the structure is not ours to restate. */
    public const NOT_OURS = 'type_change_not_allowed';

    /** The product is already in the shop, where its kind cannot be changed from here. */
    public const ALREADY_IN_SHOP = 'type_change_needs_new_product';

    /**
     * The reason this change must be refused, or null when it may go through.
     *
     * @param bool $linkedToShop whether the row points at a WooCommerce product.
     *                           A row fact rather than a read, deliberately:
     *                           a read that fails would have to be treated as
     *                           «linked» anyway, so the cheap answer and the
     *                           safe answer are the same one.
     * @param bool $figuresAreOurs `PriceAndStockOrigin::isOurs()`
     */
    public static function refusal(
        string $from,
        string $to,
        bool $linkedToShop,
        bool $figuresAreOurs
    ): ?string {
        if ($from === $to) {
            return null;
        }
        // Ownership first: it is the older and the wider statement, and a row
        // whose figures are the shop's is refused for that reason whether or
        // not it happens to be linked.
        if (!$figuresAreOurs) {
            return self::NOT_OURS;
        }
        return $linkedToShop ? self::ALREADY_IN_SHOP : null;
    }
}
