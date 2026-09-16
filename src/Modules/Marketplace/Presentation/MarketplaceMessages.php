<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;

/** Every Persian sentence the coupon, wholesale and ticket screens say. */
final class MarketplaceMessages
{
    /**
     * @param array<string,scalar|null> $context
     * @return string|null null when this code belongs to another module
     */
    public static function notice(string $code, array $context = []): ?string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        return match ($code) {
            'coupon_created' => sprintf(
                /* translators: %s: the code */
                __('کد تخفیف %s ساخته شد و فقط روی محصولات همین فروشگاه کار می‌کند.', 'tecteb-marketplace-core'),
                (string) ($context['code'] ?? '')
            ),
            'coupon_disabled' => __('کد تخفیف غیرفعال شد. سابقهٔ استفاده‌هایش پاک نمی‌شود.', 'tecteb-marketplace-core'),
            'coupon_code_taken' => __('این کد قبلاً ساخته شده است؛ کد دیگری انتخاب کنید.', 'tecteb-marketplace-core'),
            'coupon_code_invalid' => __('کد فقط حروف انگلیسی بزرگ، عدد، خط تیره و زیرخط، بین ۳ تا ۶۴ نویسه.', 'tecteb-marketplace-core'),
            'coupon_kind_invalid' => __('نوع تخفیف باید درصدی یا مبلغ ثابت باشد.', 'tecteb-marketplace-core'),
            'coupon_value_invalid' => __('مقدار تخفیف باید بزرگ‌تر از صفر و حداکثر صد درصد باشد.', 'tecteb-marketplace-core'),
            'coupon_window_invalid' => __('تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.', 'tecteb-marketplace-core'),
            ManageCoupons::GLOBAL_UNDECIDED => sprintf(
                /* translators: %s: the decision reference */
                __('کد تخفیف سراسری ساخته نمی‌شود: تأمین هزینهٔ آن در %s تعیین نشده است. تا آن زمان هر فروشگاه فقط برای محصولات خودش کد می‌سازد.', 'tecteb-marketplace-core'),
                ManageCoupons::GLOBAL_DECISION
            ),
            'coupon_other_vendor' => __('این کد متعلق به فروشگاه دیگری است و روی این اقلام اعمال نمی‌شود.', 'tecteb-marketplace-core'),
            'coupon_out_of_window' => __('این کد در بازهٔ زمانی خودش نیست.', 'tecteb-marketplace-core'),
            'coupon_exhausted' => __('ظرفیت استفاده از این کد پر شده است.', 'tecteb-marketplace-core'),
            'coupon_customer_limit' => __('این کد را پیش‌تر به سقف مجاز استفاده کرده‌اید.', 'tecteb-marketplace-core'),
            'coupon_below_minimum' => __('مبلغ سبد به حداقل لازم این کد نرسیده است.', 'tecteb-marketplace-core'),
            'coupon_not_found' => __('چنین کدی وجود ندارد.', 'tecteb-marketplace-core'),

            // --- ticket attachments ---
            'attachment_added' => sprintf(
                /* translators: %s: the file's name as the sender typed it */
                __('فایل «%s» به این گفتگو پیوست شد. فایل بیرون از دسترس وب ذخیره می‌شود و فقط طرف‌های همین گفتگو می‌توانند بازش کنند.', 'tecteb-marketplace-core'),
                (string) ($context['name'] ?? '')
            ),
            'attachment_no_file' => __('فایلی انتخاب نشده بود، یا بارگذاری‌اش کامل نشد.', 'tecteb-marketplace-core'),
            'attachment_too_large' => sprintf(
                /* translators: %s: the limit in megabytes */
                __('فایل بزرگ‌تر از حد مجاز است. حداکثر %s مگابایت.', 'tecteb-marketplace-core'),
                $fa((int) (((int) ($context['max_bytes'] ?? 0)) / 1024 / 1024))
            ),
            'attachment_type_not_allowed' => __('این نوع فایل پذیرفته نمی‌شود. فقط تصویر (JPEG، PNG، WebP)، PDF و متن ساده.', 'tecteb-marketplace-core'),
            'attachment_too_many' => sprintf(
                /* translators: %s: how many files one message may carry */
                __('هر پیام حداکثر %s فایل می‌گیرد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['max'] ?? ''))
            ),
            'attachment_storage_unavailable' => __('جای امنی برای نگه‌داشتن فایل پیدا نشد، پس فایل ذخیره نشد. تا رفع این مشکل، پیوست ممکن نیست — فایل هرگز جایی که از وب خوانده شود گذاشته نمی‌شود.', 'tecteb-marketplace-core'),
            'attachment_storage_failed' => __('فایل ذخیره نشد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'attachment_hidden' => __('فایل پنهان شد. حذف نشد و سابقه‌اش با دلیل ثبت است.', 'tecteb-marketplace-core'),
            'hide_reason_required' => __('برای پنهان‌کردن، دلیل لازم است.', 'tecteb-marketplace-core'),

            // --- the WooCommerce refund record, said apart from the money ---
            'refund_recorded' => __('رکورد بازپرداخت در ووکامرس ساخته شد. این فقط یک ثبت است: هیچ پولی جابه‌جا نشد و درگاهی در کار نبوده.', 'tecteb-marketplace-core'),
            'refund_recovered' => __('همین مرجوعی از قبل یک رکورد بازپرداخت در ووکامرس داشت — از تلاش قبلی که نیمه‌کاره مانده بود. همان رکورد وصل شد؛ رکورد دومی ساخته نشد، موجودی دست نخورد و خط مالی دومی نوشته نشد.', 'tecteb-marketplace-core'),
            'refund_reconcile_required' => sprintf(
                /* translators: %s: the WooCommerce refund ids that need a decision */
                __('یک تلاش قبلی برای ثبت بازپرداخت این مرجوعی نیمه‌کاره مانده و روی این سفارش بازپرداختی هست که نمی‌دانیم مالِ ماست یا نه (شماره: %s). تا تصمیم شما هیچ بازپرداخت تازه‌ای ساخته نمی‌شود. همان شماره را در ووکامرس ببینید: اگر مالِ همین مرجوعی است آن را دستی وصل کنید، وگرنه این مرجوعی را دوباره ثبت کنید.', 'tecteb-marketplace-core'),
                $fa((string) ($context['candidates'] ?? ''))
            ),
            'refund_ledger_first' => __('اول باید بازگشت مالی در دفترکل ثبت شود؛ رکورد ووکامرس پس از آن ساخته می‌شود.', 'tecteb-marketplace-core'),
            'refund_already_recorded' => __('برای این مرجوعی قبلاً یک رکورد بازپرداخت ووکامرس ثبت شده است.', 'tecteb-marketplace-core'),
            'refund_link_failed' => sprintf(
                /* translators: %s: the WooCommerce refund id */
                __('رکورد بازپرداخت در ووکامرس با شمارهٔ %s ساخته شد، ولی به این مرجوعی وصل نشد. این شماره را نگه دارید و با پشتیبانی فنی تماس بگیرید.', 'tecteb-marketplace-core'),
                $fa((string) ($context['wc_refund_id'] ?? ''))
            ),
            'refund_amount_exceeds_remaining' => __('مبلغ از باقی‌ماندهٔ قابل بازپرداخت این سفارش بیشتر است.', 'tecteb-marketplace-core'),
            'refund_woocommerce_missing' => __('ووکامرس در دسترس نیست، پس رکورد بازپرداختی ساخته نمی‌شود.', 'tecteb-marketplace-core'),
            'refund_order_missing' => __('سفارش این قلم پیدا نشد.', 'tecteb-marketplace-core'),
            'refund_record_failed' => __('ووکامرس ساختن رکورد بازپرداخت را نپذیرفت.', 'tecteb-marketplace-core'),
            'refund_no_gateway_adapter' => __('هیچ درگاه پرداخت واقعی در این نسخه وصل نیست.', 'tecteb-marketplace-core'),
            'refund_no_transaction_id' => __('این سفارش شمارهٔ تراکنش ندارد، پس چیزی برای بازگرداندن از طریق درگاه وجود ندارد.', 'tecteb-marketplace-core'),
            'refund_gateway_not_installed' => __('درگاهی که این سفارش با آن پرداخت شده روی سایت نصب نیست.', 'tecteb-marketplace-core'),
            'refund_gateway_no_refund_support' => __('درگاه این سفارش بازپرداخت خودکار را پشتیبانی نمی‌کند.', 'tecteb-marketplace-core'),

            // --- notifications ---
            'notices_all_read' => __('همهٔ اعلان‌ها خوانده‌شده شدند.', 'tecteb-marketplace-core'),
            'notice_read' => __('اعلان خوانده‌شده شد.', 'tecteb-marketplace-core'),

            'wholesale_applied' => __('درخواست خرید عمده ثبت شد. تا تأیید مدیر، قیمت پلکانی نمایش داده نمی‌شود.', 'tecteb-marketplace-core'),
            'wholesale_already_applied' => __('درخواست شما از قبل ثبت شده است.', 'tecteb-marketplace-core'),
            'wholesale_approved' => __('خریدار عمده تأیید شد و از این پس قیمت پلکانی را می‌بیند.', 'tecteb-marketplace-core'),
            'wholesale_rejected' => __('درخواست خرید عمده رد شد.', 'tecteb-marketplace-core'),
            'wholesale_suspended' => __('دسترسی خرید عمده معلق شد.', 'tecteb-marketplace-core'),
            'wholesale_tiers_saved' => sprintf(
                /* translators: %s: number of steps */
                __('قیمت پلکانی با %s پله ذخیره شد.', 'tecteb-marketplace-core'),
                $fa($context['steps'] ?? 0)
            ),
            'tier_quantity_invalid' => __('هر پله باید از تعداد ۲ به بالا شروع شود؛ پلهٔ یک همان قیمت عادی است.', 'tecteb-marketplace-core'),
            'tier_price_invalid' => __('قیمت هر پله باید بزرگ‌تر از صفر و کمتر از قیمت عادی محصول باشد.', 'tecteb-marketplace-core'),
            'tier_not_descending' => __('با افزایش تعداد، قیمت هر واحد باید کمتر شود.', 'tecteb-marketplace-core'),

            'ticket_opened' => __('تیکت ثبت شد. پاسخ در همین صفحه نمایش داده می‌شود.', 'tecteb-marketplace-core'),
            'ticket_replied' => __('پاسخ ثبت شد.', 'tecteb-marketplace-core'),
            'ticket_empty' => __('موضوع و متن پیام نمی‌توانند خالی باشند.', 'tecteb-marketplace-core'),
            'ticket_locked' => __('این گفتگو توسط مدیر قفل شده و پیام تازه نمی‌پذیرد.', 'tecteb-marketplace-core'),
            'ticket_closed' => __('این گفتگو بسته شده است.', 'tecteb-marketplace-core'),
            'ticket_open' => __('گفتگو باز شد.', 'tecteb-marketplace-core'),
            'ticket_answered' => __('گفتگو «پاسخ‌داده‌شده» علامت خورد.', 'tecteb-marketplace-core'),
            'ticket_closed_state' => __('گفتگو بسته شد.', 'tecteb-marketplace-core'),
            'ticket_message_hidden' => __('پیام پنهان شد. متن آن پاک نشده و دلیل و مسئولش ثبت شده است.', 'tecteb-marketplace-core'),
            'hide_reason_required' => __('برای پنهان‌کردن یک پیام، ذکر دلیل الزامی است.', 'tecteb-marketplace-core'),
            'ticket_status_invalid' => __('وضعیت گفتگو معتبر نیست.', 'tecteb-marketplace-core'),
            default => null,
        };
    }

    public static function ticketStatus(string $status): string
    {
        return match ($status) {
            Ticket::ANSWERED => __('پاسخ داده شد', 'tecteb-marketplace-core'),
            Ticket::CLOSED => __('بسته', 'tecteb-marketplace-core'),
            default => __('در انتظار پاسخ', 'tecteb-marketplace-core'),
        };
    }

    public static function wholesaleStatus(WholesaleStatus $status): string
    {
        return match ($status) {
            WholesaleStatus::Requested => __('در انتظار تأیید', 'tecteb-marketplace-core'),
            WholesaleStatus::Approved => __('تأییدشده', 'tecteb-marketplace-core'),
            WholesaleStatus::Rejected => __('ردشده', 'tecteb-marketplace-core'),
            WholesaleStatus::Suspended => __('معلق', 'tecteb-marketplace-core'),
        };
    }

    /** The two sentences these screens must carry about what is NOT decided. */
    public static function openTermsWarning(): string
    {
        return sprintf(
            /* translators: 1: coupon decision, 2: wholesale/ticket decision */
            __('دو چیز هنوز تعیین نشده و بازارگاه حدسشان نمی‌زند: تأمین هزینهٔ کد تخفیف سراسری (%1$s) و تعرفه و حداقل خرید عمده و مدت نگهداری تیکت (%2$s). تا آن زمان کد سراسری ساخته نمی‌شود، هر پله را خود فروشنده می‌نویسد و هیچ گفتگویی خودکار حذف نمی‌شود.', 'tecteb-marketplace-core'),
            ManageCoupons::GLOBAL_DECISION,
            ManageWholesale::DECISION
        );
    }

    /** @return list<string> */
    public static function successCodes(): array
    {
        return [
            'coupon_created', 'coupon_disabled', 'wholesale_applied', 'wholesale_approved',
            'wholesale_tiers_saved', 'ticket_opened', 'ticket_replied', 'ticket_message_hidden',
            'ticket_open', 'ticket_answered', 'ticket_closed_state',
        ];
    }

    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, self::successCodes(), true);
    }
}
