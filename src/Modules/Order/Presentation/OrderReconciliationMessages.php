<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation;

use Tecteb\Marketplace\Modules\Order\Domain\OrderInconsistency;

/**
 * What each reconciliation finding means, and what a manager does about it.
 *
 * Presentation, because `__()` belongs on this side of the line (`alpha.9`)
 * — and because every one of these sentences has to say TWO things: what was
 * found, and that this plugin will not fix it by itself.
 */
final class OrderReconciliationMessages
{
    public static function title(string $class): string
    {
        return match (OrderInconsistency::tryFrom($class)) {
            OrderInconsistency::OrderWithoutLine
                => __('سفارش ووکامرس دارد، قلم بازارگاه ندارد', 'tecteb-marketplace-core'),
            OrderInconsistency::LineWithoutAccrual
                => __('قلم ثبت شده، سند مالی ندارد', 'tecteb-marketplace-core'),
            OrderInconsistency::DuplicateAccrual
                => __('یک سند مالی روی چند قلم', 'tecteb-marketplace-core'),
            OrderInconsistency::CancelledWithoutReversal
                => __('قلم لغوشده، بدون سند معکوس', 'tecteb-marketplace-core'),
            OrderInconsistency::ReversalWithoutCancel
                => __('سند معکوس، روی قلمی که لغو نشده', 'tecteb-marketplace-core'),
            OrderInconsistency::UnbalancedEvent
                => __('سند مالی نامتوازن یا با دو واحد', 'tecteb-marketplace-core'),
            OrderInconsistency::VendorOrStatusMismatch
                => __('فروشنده یا وضعیت ناسازگار', 'tecteb-marketplace-core'),
            OrderInconsistency::Unchecked, null
                => __('بررسی نشد', 'tecteb-marketplace-core'),
        };
    }

    /** What it means, and the one safe next step — never an automatic repair. */
    public static function advice(string $class): string
    {
        return match (OrderInconsistency::tryFrom($class)) {
            OrderInconsistency::OrderWithoutLine => __(
                'این سفارش محصولی از بازارگاه دارد ولی هیچ قلمی برایش ثبت نشده — یعنی ثبت سفارش در لحظهٔ خرید شکست خورده و خطایش بی‌صدا گرفته شده است. سفارش را در ووکامرس باز کنید و اقلامش را با دست با دفترکل مقایسه کنید. این افزونه قلم جاافتاده را خودکار نمی‌سازد، چون ساختنش یعنی اختراع یک تعهد مالی.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::LineWithoutAccrual => __(
                'قلم ثبت شده و سهم فروشنده هم روی آن هست، ولی سندی در دفترکل برایش پیدا نشد. پس عددی وجود دارد که پشتش چیزی نیست. مبلغ را از خود سفارش ووکامرس بخوانید و تصمیم را دستی ثبت کنید.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::DuplicateAccrual => __(
                'دو قلم یک کلید سند مالی را ادعا می‌کنند، یعنی یک فروش ممکن است دو بار شمرده شده باشد. تا تعیین تکلیف، هیچ تسویه‌ای روی این فروشگاه انجام ندهید.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::CancelledWithoutReversal => __(
                'قلم لغو شده ولی تعهدش در دفترکل باطل نشده. اگر این قلم پیش از نسخهٔ alpha.99 لغو شده باشد همین حالت طبیعی است: آن نسخه سند معکوس نمی‌نوشت. برای برگرداندنش وضعیت سفارش را در ووکامرس یک بار از لغو خارج و دوباره لغو کنید تا قلاب چرخهٔ عمر اجرا شود.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::ReversalWithoutCancel => __(
                'سند معکوس نوشته شده ولی قلم لغو نیست. این حالت را کد خودِ افزونه نمی‌سازد (هیچ مسیری لغو را برنمی‌گرداند)، پس یا وضعیت قلم با دست عوض شده یا سند با دست نوشته شده. گزارش‌ها این سهم را معتبر می‌شمارند در حالی که دفترکل آن را پس داده است.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::UnbalancedEvent => __(
                'ردیف‌های این سند جمعشان صفر نمی‌شود، یا در دو واحد پول ثبت شده‌اند. جمع دو واحد عددی در هیچ واحدی نیست، پس این سند را نباید مبنای هیچ تسویه‌ای گرفت.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::VendorOrStatusMismatch => __(
                'شناسهٔ فروشندهٔ روی قلم با مالک ردیف محصول یکی نیست، یا وضعیت ذخیره‌شده نامی است که این نسخه نمی‌شناسد. تا تعیین تکلیف، سهم این قلم را به هیچ فروشگاهی پرداخت نکنید.',
                'tecteb-marketplace-core'
            ),
            OrderInconsistency::Unchecked, null => __(
                'این بررسی اجرا نشد. «اجرا نشد» با «چیزی پیدا نشد» یکی نیست و به‌عنوان تأیید سلامت خوانده نمی‌شود.',
                'tecteb-marketplace-core'
            ),
        };
    }

    /** Why a check could not run, in words rather than a code. */
    public static function scanReason(string $reason): string
    {
        return match ($reason) {
            'no_reader' => __('خوانندهٔ سفارش‌های ووکامرس در این نصب وصل نیست.', 'tecteb-marketplace-core'),
            'woocommerce_absent' => __('ووکامرس در این نصب پاسخ نداد، پس فهرست سفارش‌ها خوانده نشد.', 'tecteb-marketplace-core'),
            'order_unreadable' => __('یکی از سفارش‌ها خوانده نشد، پس کل این بررسی ناقص است و ناقص گزارش می‌شود.', 'tecteb-marketplace-core'),
            'lines_unreadable' => __('جدول اقلام خوانده نشد.', 'tecteb-marketplace-core'),
            'not_allowed' => __('مجوز لازم را ندارید.', 'tecteb-marketplace-core'),
            default => __('دلیل نامعلوم.', 'tecteb-marketplace-core'),
        };
    }
}
