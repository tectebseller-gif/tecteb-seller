<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation;

/**
 * Every Persian string these pages show.
 *
 * It is a separate class for the reason the action queue learned in `alpha.9`:
 * the Application layer may not call WordPress, `__()` included, so a service
 * returns a key and the sentence is chosen here. The architecture test measures
 * that, and it is the only reason a translation never ends up inside a job
 * handler where no translator would ever find it.
 */
final class OperationsMessages
{
    public static function jobStatus(string $status): string
    {
        return match ($status) {
            'pending' => __('در صف', 'tecteb-marketplace-core'),
            'running' => __('در حال اجرا', 'tecteb-marketplace-core'),
            'done' => __('انجام شد', 'tecteb-marketplace-core'),
            'failed' => __('شکست خورد', 'tecteb-marketplace-core'),
            'cancelled' => __('لغو شد', 'tecteb-marketplace-core'),
            'stalled' => __('بی‌صاحب مانده', 'tecteb-marketplace-core'),
            default => $status,
        };
    }

    public static function jobType(string $type): string
    {
        return match ($type) {
            'dokan_import' => __('ورود داده از دکان', 'tecteb-marketplace-core'),
            'event_delivery' => __('ارسال رویداد', 'tecteb-marketplace-core'),
            default => $type,
        };
    }

    /**
     * What the site is actually using to wake the queue up — and what that
     * costs. The last line is the one that matters: WP-Cron needs a visitor,
     * so a queue on a quiet shop does not drain by itself.
     */
    public static function binding(string $binding): string
    {
        return match ($binding) {
            'action_scheduler' => __('Action Scheduler (همراه ووکامرس). صف با بازدید سایت پیش می‌رود و تلاش دوبارهٔ خودش را هم دارد.', 'tecteb-marketplace-core'),
            'wp_cron' => __('WP-Cron خود وردپرس. این «ساعت» نیست: با بازدید کاربر اجرا می‌شود، پس روی سایت کم‌بازدید ممکن است دیر پیش برود.', 'tecteb-marketplace-core'),
            'wp_cron_disabled' => __('WP-Cron با ثابت DISABLE_WP_CRON خاموش است. تا وقتی cron سیستمی روی wp-cron.php تنظیم نشده، صف خودبه‌خود پیش نمی‌رود و باید دستی اجرا شود.', 'tecteb-marketplace-core'),
            default => $binding,
        };
    }

    public static function jobOutcome(string $outcome): string
    {
        return match ($outcome) {
            'done' => __('تمام شد', 'tecteb-marketplace-core'),
            'more' => __('یک دسته انجام شد؛ ادامه دارد', 'tecteb-marketplace-core'),
            'failed' => __('شکست خورد', 'tecteb-marketplace-core'),
            'lease_lost' => __('مهلت قفل تمام شده بود و کارگر دیگری آن را گرفته', 'tecteb-marketplace-core'),
            'no_handler' => __('این نوع کار در این نسخه اجرا نمی‌شود', 'tecteb-marketplace-core'),
            default => $outcome,
        };
    }

    /**
     * Audit event types, in the manager's words.
     *
     * An unknown key falls through as itself rather than as «نامشخص»: a new
     * build's event is better shown raw than hidden behind a word that means
     * nothing. That is also how a manager notices the catalogue needs a line.
     */
    public static function auditEvent(string $event): string
    {
        return match ($event) {
            'vendor.application.submitted' => __('ثبت درخواست فروشندگی', 'tecteb-marketplace-core'),
            'vendor.application.approved' => __('تأیید فروشنده', 'tecteb-marketplace-core'),
            'vendor.application.rejected' => __('رد درخواست فروشندگی', 'tecteb-marketplace-core'),
            'vendor.suspended' => __('تعلیق فروشنده', 'tecteb-marketplace-core'),
            'vendor.reinstated' => __('بازگرداندن فروشنده', 'tecteb-marketplace-core'),
            'product.submitted' => __('ارسال محصول برای بررسی', 'tecteb-marketplace-core'),
            'product.approved' => __('تأیید محصول', 'tecteb-marketplace-core'),
            'product.rejected' => __('رد محصول', 'tecteb-marketplace-core'),
            'dokan.dry_run' => __('اجرای آزمایشی مهاجرت دکان', 'tecteb-marketplace-core'),
            'dokan.imported' => __('ورود داده از دکان', 'tecteb-marketplace-core'),
            'plugin.deactivated' => __('غیرفعال‌سازی افزونه', 'tecteb-marketplace-core'),
            default => $event,
        };
    }

    public static function deliveryState(string $state): string
    {
        return match ($state) {
            'recorded' => __('ثبت شد', 'tecteb-marketplace-core'),
            'blocked' => __('ارسال بسته است', 'tecteb-marketplace-core'),
            'sent' => __('ارسال شد', 'tecteb-marketplace-core'),
            'failed' => __('ارسال شکست خورد', 'tecteb-marketplace-core'),
            default => $state,
        };
    }

    /**
     * Why a recorded event was not sent.
     *
     * `outbound_blocked` is not an error and the sentence says so. The Alpha's
     * outbound lock is unconditional — no option, constant or filter opens it —
     * so a row reaching «بسته» is the system working exactly as specified, and
     * a manager who reads «شکست» there would go looking for a fault that is not
     * one.
     */
    public static function deliveryReason(string $reason): string
    {
        return match ($reason) {
            'outbound_blocked' => __('قفل ارسال بیرونی این نسخه (CORE-04). رویداد ثبت و امضا شده و آمادهٔ ارسال است، ولی تا باز شدن این قفل چیزی از این سایت بیرون نمی‌رود.', 'tecteb-marketplace-core'),
            'no_endpoint' => __('نشانی مقصدی ثبت نشده است.', 'tecteb-marketplace-core'),
            'delivery_disabled' => __('ارسال رویداد خاموش است؛ در تنظیمات همین صفحه روشن می‌شود.', 'tecteb-marketplace-core'),
            default => $reason,
        };
    }
}
