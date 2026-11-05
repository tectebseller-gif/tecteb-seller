<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation;

use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist as Plan;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;

/**
 * The Persian for the twelve steps — and the one place that says where each
 * one is done.
 *
 * Presentation, because `MigrationPlanChecklist` is pure and must not call
 * `__()`: the Application layer has no right to WordPress, including the
 * translator (`alpha.9`). So the checklist answers with keys and this file
 * turns them into sentences.
 *
 * ⛔ **And the targets are built from the pages' own `SLUG` constants**, not
 * typed. A page whose slug changes would otherwise leave a «رفتن» link
 * pointing at a screen that no longer exists, and a dead link on the one
 * screen a manager follows during a migration is worse than no link.
 */
final class MigrationWizardText
{
    /** The name of one step. */
    public static function step(string $key): string
    {
        return match ($key) {
            Plan::STEP_ENVIRONMENT => __('بررسی محیط و نسخه‌ها', 'tecteb-marketplace-core'),
            Plan::STEP_PLUGINS => __('وضعیت ووکامرس و دکان', 'tecteb-marketplace-core'),
            Plan::STEP_BACKUP => __('پشتیبان و امکان بازگشت', 'tecteb-marketplace-core'),
            Plan::STEP_INVENTORY => __('شناسایی فروشندگان و محصولات', 'tecteb-marketplace-core'),
            Plan::STEP_DRY_RUN => __('اجرای آزمایشی، بدون تغییر داده', 'tecteb-marketplace-core'),
            Plan::STEP_DIFFERENCES => __('نمایش اختلاف‌ها و ریسک‌های واقعی', 'tecteb-marketplace-core'),
            Plan::STEP_SYNC => __('همگام‌سازی ایمن دادهٔ قابل اصلاح', 'tecteb-marketplace-core'),
            Plan::STEP_PLAN => __('برنامهٔ انتقال و اثر آن', 'tecteb-marketplace-core'),
            Plan::STEP_CONSENT => __('تأیید صریح مدیر', 'tecteb-marketplace-core'),
            Plan::STEP_TRANSFER => __('اجرای مرحله‌ای با امکان ادامه', 'tecteb-marketplace-core'),
            Plan::STEP_VALIDATE => __('اعتبارسنجی نتیجه', 'tecteb-marketplace-core'),
            Plan::STEP_EXIT_READY => __('گزارش آمادگی خروج از دکان', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /** The four answers. */
    public static function status(string $status): string
    {
        return match ($status) {
            Plan::DONE => __('انجام شده', 'tecteb-marketplace-core'),
            Plan::TODO => __('در انتظار', 'tecteb-marketplace-core'),
            Plan::BLOCKED => __('مسدود', 'tecteb-marketplace-core'),
            // ⛔ NOT «نامشخص», which reads as a shrug. «اندازه‌گیری‌نشده» says
            // what actually happened: nobody could read it, and that is a
            // fact about this install rather than about the step.
            Plan::UNKNOWN => __('اندازه‌گیری‌نشده', 'tecteb-marketplace-core'),
            default => $status,
        };
    }

    /**
     * Why a step is where it is, with its own figure in it.
     *
     * @param callable(string):string $fa
     */
    public static function explain(string $status, string $blocker, string $detail, callable $fa): string
    {
        if ($status === Plan::DONE) {
            return $detail === '' ? '' : $fa($detail);
        }
        $sentence = match ($blocker) {
            'version_unreadable' => __('نسخهٔ PHP یا وردپرس خوانده نشد.', 'tecteb-marketplace-core'),
            'php_too_old' => sprintf(
                /* translators: %s: the PHP version this site runs */
                __('این بسته روی PHP ۸٫۱ و بالاتر اجرا می‌شود؛ این سایت %s است.', 'tecteb-marketplace-core'),
                $fa($detail)
            ),
            'schema_unreadable' => __('شمارهٔ ساختار دادهٔ هدف خوانده نشد.', 'tecteb-marketplace-core'),
            'schema_behind' => sprintf(
                /* translators: %s: stored/target structure numbers */
                __('ساختار داده با این بسته یکی نیست (%s). تا هم‌تراز نشود، برنامهٔ مهاجرت ساخته نمی‌شود.', 'tecteb-marketplace-core'),
                $fa($detail)
            ),
            'woocommerce_missing' => __('ووکامرس فعال نیست.', 'tecteb-marketplace-core'),
            'woocommerce_version_unreadable' => __('نسخهٔ ووکامرس خوانده نشد.', 'tecteb-marketplace-core'),
            'dokan_state_unreadable' => __('وضعیت دکان خوانده نشد.', 'tecteb-marketplace-core'),
            'dokan_not_running' => __('دکان اجرا نمی‌شود. برنامهٔ مهاجرت فقط وقتی ساخته می‌شود که مبدأ قابل خواندن باشد — پس این گام را پیش از خاموش‌کردن دکان انجام بدهید.', 'tecteb-marketplace-core'),
            'rollback_unknown' => __('امکان بازگشت اندازه‌گیری نشد. صفحهٔ سلامت سیستم جواب این یکی را چاپ می‌کند.', 'tecteb-marketplace-core'),
            'rollback_would_lose_data' => __('بازگشت به نسخهٔ قبلی با دادهٔ فعلی بهای تفسیر دارد. تا این باز است، مهاجرت را شروع نکنید.', 'tecteb-marketplace-core'),
            'inventory_unreadable' => __('فهرست فروشگاه‌ها خوانده نشد.', 'tecteb-marketplace-core'),
            'no_shop_identified' => __('هیچ فروشگاهی وارد نشده است.', 'tecteb-marketplace-core'),
            'runs_unreadable' => __('دفتر اجراها خوانده نشد.', 'tecteb-marketplace-core'),
            'no_run_recorded' => __('هیچ اجرایی ثبت نشده است. اول یک اجرای آزمایشی بگیرید.', 'tecteb-marketplace-core'),
            'scan_did_not_finish' => __('اسکن این فروشگاه به آخر نرسید، پس عددهایش دربارهٔ کل فروشگاه نیستند.', 'tecteb-marketplace-core'),
            'figures_unreadable', 'sync_figures_unreadable' => __('عددهای این گام خوانده نشدند.', 'tecteb-marketplace-core'),
            'fixable_differences_left' => sprintf(
                /* translators: %s: how many rows a batch would close */
                __('%s ردیف با بازخوانی دسته‌ای یا اصلاح وضعیت بسته می‌شود.', 'tecteb-marketplace-core'),
                $fa($detail)
            ),
            'real_risks_left' => sprintf(
                /* translators: %s: how many rows carry a real risk */
                __('%s ردیف ریسک واقعی دارد و برنامهٔ انتقال برایشان ردیفی نمی‌کشد.', 'tecteb-marketplace-core'),
                $fa($detail)
            ),
            'ownership_unreadable' => __('شمارش مالکیت خوانده نشد.', 'tecteb-marketplace-core'),
            'consent_is_a_press' => sprintf(
                /* translators: %s: rows still waiting */
                __('%s ردیف هنوز منتقل نشده. تأیید یک فشار روی صفحهٔ انتقال است و هیچ ردیفی جایش را نمی‌گیرد.', 'tecteb-marketplace-core'),
                $fa($detail)
            ),
            'rows_still_observed' => sprintf(
                /* translators: %s: rows still observed */
                __('%s ردیف هنوز «مشاهده‌شده» است.', 'tecteb-marketplace-core'),
                $fa($detail)
            ),
            'completeness_unreadable' => __('شروط تکمیل خوانده نشدند.', 'tecteb-marketplace-core'),
            'gates_open' => sprintf(
                /* translators: %s: the names of the open gates */
                __('این شروط باز است: %s', 'tecteb-marketplace-core'),
                $detail
            ),
            default => $blocker,
        };
        return $sentence;
    }

    /**
     * Where the step is done, as an admin path — or `''` when it is this very
     * page or a decision outside the plugin.
     */
    public static function target(string $key): string
    {
        return match ($key) {
            // Health prints the PHP, WordPress and structure figures, and the
            // rollback verdict beside them.
            Plan::STEP_ENVIRONMENT, Plan::STEP_BACKUP => 'admin.php?page=' . HealthPage::SLUG,
            Plan::STEP_PLUGINS => 'plugins.php',
            Plan::STEP_INVENTORY, Plan::STEP_DRY_RUN => 'admin.php?page=' . MigrationPage::SLUG,
            Plan::STEP_DIFFERENCES,
            Plan::STEP_SYNC,
            Plan::STEP_PLAN,
            Plan::STEP_CONSENT,
            Plan::STEP_TRANSFER => 'admin.php?page=' . MigrationPage::SLUG,
            // The gates are on this page, below this card.
            Plan::STEP_VALIDATE, Plan::STEP_EXIT_READY => '',
            default => '',
        };
    }
}
