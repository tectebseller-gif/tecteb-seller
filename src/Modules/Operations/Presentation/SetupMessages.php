<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation;

use Tecteb\Marketplace\Modules\Operations\Application\SetupChecklist;

/** The wizard's Persian wording, kept out of the checklist that computes it. */
final class SetupMessages
{
    public static function title(string $step): string
    {
        return match ($step) {
            SetupChecklist::STEP_WOOCOMMERCE => __('ووکامرس فعال باشد', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_COMMISSION => __('نرخ کمیسیون', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_DOCUMENTS => __('مدارک لازم برای فروشندگی', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_SETTLEMENT => __('مهلت تسویه', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_STAFF_LIMIT => __('سقف پرسنل هر فروشگاه', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_VENDOR_PAGE => __('نشانی ناحیهٔ فروشنده', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_QUEUE => __('اجرای کارهای زمان‌بر', 'tecteb-marketplace-core'),
            default => $step,
        };
    }

    public static function describe(string $step): string
    {
        return match ($step) {
            SetupChecklist::STEP_WOOCOMMERCE => __('بدون ووکامرس، ماژول‌های سفارش و محصول اصلاً بارگذاری نمی‌شوند و افزونه فقط صفحه‌های مدیریتی‌اش را نشان می‌دهد.', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_COMMISSION => __('تا وقتی نرخی — سراسری یا برای یک فروشنده — ثبت نشده باشد، دروازهٔ عملیات سفارش بسته می‌ماند. این افزونه هیچ نرخی حدس نمی‌زند.', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_DOCUMENTS => __('فهرست مدارکی که متقاضی فروشندگی باید بفرستد. اگر عمداً مدرکی نمی‌خواهید، همان را هم صریح ثبت کنید تا با «تنظیم‌نشده» اشتباه نشود.', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_SETTLEMENT => __('چند روز پس از تکمیل سفارش، سهم فروشنده قابل درخواست می‌شود.', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_STAFF_LIMIT => __('بیشترین تعداد کاربری که یک فروشنده می‌تواند به فروشگاهش دعوت کند.', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_VENDOR_PAGE => __('با پیوندهای یکتای «ساده»، مسیر /vendor/ کار نمی‌کند و ناحیهٔ فروشنده فقط با نشانی پرسش‌داری باز می‌شود؛ کار می‌کند ولی نشانی‌ای نیست که به فروشنده بدهید.', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_QUEUE => __('کارهای زمان‌بر مثل ورود داده از دکان در صف اجرا می‌شوند. ببینید چه چیزی این صف را پیش می‌برد و آیا روشن است.', 'tecteb-marketplace-core'),
            default => '',
        };
    }

    public static function status(string $status): string
    {
        return match ($status) {
            SetupChecklist::DONE => __('انجام شده', 'tecteb-marketplace-core'),
            SetupChecklist::TODO => __('باقی مانده', 'tecteb-marketplace-core'),
            SetupChecklist::BLOCKED => __('منتظر تصمیم مالک', 'tecteb-marketplace-core'),
            SetupChecklist::SKIPPED => __('فعلاً رد شده', 'tecteb-marketplace-core'),
            default => $status,
        };
    }

    public static function cta(string $step): string
    {
        return match ($step) {
            SetupChecklist::STEP_WOOCOMMERCE => __('رفتن به فهرست افزونه‌ها', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_VENDOR_PAGE => __('تنظیم پیوندهای یکتا', 'tecteb-marketplace-core'),
            SetupChecklist::STEP_QUEUE => __('دیدن صف و سلامت اجرا', 'tecteb-marketplace-core'),
            default => __('رفتن به تنظیم این گام', 'tecteb-marketplace-core'),
        };
    }

    public static function blocker(string $blocker): string
    {
        return match ($blocker) {
            'DEC-01' => __('نرخ کمیسیون تصمیم مالک است (DEC-01) و FIN-02 اجازهٔ حدس‌زدنش را نمی‌دهد. تا ثبت نرخ مصوب، این گام باز می‌ماند؛ می‌توانید نرخ را برای یک فروشندهٔ خاص هم ثبت کنید.', 'tecteb-marketplace-core'),
            'woocommerce_missing' => __('ووکامرس روی این سایت فعال نیست.', 'tecteb-marketplace-core'),
            'permalinks_plain' => __('ساختار پیوند یکتا روی «ساده» است.', 'tecteb-marketplace-core'),
            'wp_cron_disabled' => __('WP-Cron با ثابت DISABLE_WP_CRON خاموش است؛ تا cron سیستمی تنظیم نشود، صف فقط با اجرای دستی پیش می‌رود.', 'tecteb-marketplace-core'),
            default => $blocker,
        };
    }
}
