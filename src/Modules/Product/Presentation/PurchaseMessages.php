<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;

/**
 * What a shopper is told when a purchase is refused — and what they are not.
 *
 * The owner's instruction is the whole of this class: «پیام مشتری دربارهٔ
 * توقف خرید کوتاه و قابل‌فهم باشد؛ جزئیات نرخ کمیسیون و تصمیم‌های داخلی در
 * صفحهٔ مدیر نمایش داده شوند».
 *
 * So every sentence here is one short clause about the KALA — the thing the
 * shopper was trying to buy. None of them names a commission rate, an
 * unresolved decision, a gate, a ledger, or a vendor's suspension as an
 * administrative act. A shopper cannot act on any of that, and a shop that
 * tells its customers which internal decision is open is leaking its own
 * management into the storefront.
 *
 * The full reason is not lost: it goes to the audit log and to the manager's
 * screen (MarketplaceStatusPanel), where somebody can act on it.
 */
final class PurchaseMessages
{
    /** One short sentence, safe to show anybody. */
    public static function shopper(string $decision): string
    {
        return match ($decision) {
            // "Not available right now" covers every marketplace-wide stop
            // in the same words: from the shopper's side an emptied shelf and
            // an unsettled rule are the same event — this is not for sale
            // today — and the difference is the manager's business.
            PurchasePolicy::STOREFRONT_STOPPED,
            PurchasePolicy::ORDERS_BLOCKED => __('این کالا فعلاً برای فروش در دسترس نیست.', 'tecteb-marketplace-core'),
            PurchasePolicy::VENDOR_STOPPED => __('فروش این کالا فعلاً متوقف است.', 'tecteb-marketplace-core'),
            // A closure is a shop being away, not a shop in trouble, and the
            // shopper is told the one thing they can act on: it comes back.
            // No date, because the shop's own dates are its business and a
            // date that slips reads as a broken promise.
            PurchasePolicy::VENDOR_CLOSED => __('این فروشگاه موقتاً تعطیل است و بعد از بازگشایی دوباره سفارش می‌گیرد.', 'tecteb-marketplace-core'),
            PurchasePolicy::NOT_PUBLISHED => __('این کالا در حال حاضر در دسترس نیست.', 'tecteb-marketplace-core'),
            PurchasePolicy::OUT_OF_STOCK => __('این کالا ناموجود است.', 'tecteb-marketplace-core'),
            default => __('این کالا قابل خرید نیست.', 'tecteb-marketplace-core'),
        };
    }

    /** The same event, for a basket that already held the item. */
    public static function removedFromCart(string $productName): string
    {
        return sprintf(
            /* translators: %s: product name */
            __('«%s» از سبد شما برداشته شد، چون فعلاً برای فروش در دسترس نیست.', 'tecteb-marketplace-core'),
            $productName
        );
    }

    /** …and for an unpaid order whose link was opened later. */
    public static function orderNotPayable(): string
    {
        return __('پرداخت این سفارش فعلاً ممکن نیست، چون یکی از کالاهای آن برای فروش در دسترس نیست. با پشتیبانی تماس بگیرید.', 'tecteb-marketplace-core');
    }

    /**
     * The manager's version: the decision code itself, named.
     *
     * Shown only on screens behind a manager capability, next to the numbers
     * the shopper never sees.
     */
    public static function manager(string $decision): string
    {
        return match ($decision) {
            PurchasePolicy::STOREFRONT_STOPPED => __('فروش بازارگاه متوقف شده است (کلید توقف روشن است).', 'tecteb-marketplace-core'),
            PurchasePolicy::ORDERS_BLOCKED => __('دروازهٔ عملیات سفارش اجازه نمی‌دهد: نرخ کمیسیون یا تصمیم‌های مالی باز است.', 'tecteb-marketplace-core'),
            PurchasePolicy::VENDOR_STOPPED => __('فروشنده تعلیق است، پس هیچ‌کدام از کالاهایش فروخته نمی‌شوند.', 'tecteb-marketplace-core'),
            PurchasePolicy::VENDOR_CLOSED => __('فروشنده خودش فروشگاه را موقتاً تعطیل کرده است. این تعلیق نیست: سفارش‌های قبلی‌اش عادی ادامه دارند و فقط سفارش تازه گرفته نمی‌شود.', 'tecteb-marketplace-core'),
            PurchasePolicy::NOT_PUBLISHED => __('محصول در گردش کار بازارگاه منتشر نیست.', 'tecteb-marketplace-core'),
            PurchasePolicy::OUT_OF_STOCK => __('موجودی صفر است.', 'tecteb-marketplace-core'),
            PurchasePolicy::NOT_OURS => __('این محصول از آنِ بازارگاه نیست؛ بازارگاه دربارهٔ آن تصمیمی نمی‌گیرد.', 'tecteb-marketplace-core'),
            default => __('قابل خرید نیست.', 'tecteb-marketplace-core'),
        };
    }
}
