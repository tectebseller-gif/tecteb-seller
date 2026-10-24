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

    /** What started a batch — the tick, or somebody pressing the button. */
    public static function runStarter(string $how): string
    {
        return match ($how) {
            'cron' => __('زمان‌بند', 'tecteb-marketplace-core'),
            'manual' => __('اجرای دستی', 'tecteb-marketplace-core'),
            default => __('نامعلوم', 'tecteb-marketplace-core'),
        };
    }

    /** The import's three phases, for «جای توقف» on the queue page. */
    public static function importPhase(string $phase): string
    {
        return match ($phase) {
            'vendors' => __('فروشندگان', 'tecteb-marketplace-core'),
            'products' => __('محصولات', 'tecteb-marketplace-core'),
            'orders' => __('سابقهٔ سفارش', 'tecteb-marketplace-core'),
            // ⛔ The record import's three phases. Without them the queue
            // page printed «مرحلهٔ نامعلوم» for every batch of a job whose
            // cursor named its phase perfectly well — a display that invited
            // a reader to think the job had lost its place. The keys carry
            // the `_records` suffix precisely so a cursor can never be read
            // as the product import's phase of the same word, and that is
            // only worth having if BOTH lists know the suffixed names.
            'staff_records' => __('پرسنل فروشگاه', 'tecteb-marketplace-core'),
            'balance_records' => __('دفتر مانده', 'tecteb-marketplace-core'),
            'withdrawal_records' => __('درخواست‌های برداشت', 'tecteb-marketplace-core'),
            default => __('نامعلوم', 'tecteb-marketplace-core'),
        };
    }

    /**
     * A step's machine key, in Persian, for «زمان مرحله‌ها».
     *
     * One case per name in `StepTiming::STEPS`, plus the one name that is not
     * a step: `StepTiming::UNLISTED`, which is what a key that was never on
     * the list becomes before it is stored. It gets a sentence of its own
     * rather than being printed raw — a reader seeing `step_not_listed` would
     * reasonably think a step is called that.
     *
     * ⛔ Anything else still falls through unchanged, which is the opposite
     * choice from `importPhase()` on purpose: a row written by an older build
     * may name a step this list has since dropped, and the raw key is the most
     * useful thing on that row. It cannot carry anything but a key — see the
     * closed list in `StepTiming`.
     */
    public static function importStep(string $step): string
    {
        return match ($step) {
            'page_read' => __('خواندن صفحه از دکان', 'tecteb-marketplace-core'),
            'judge' => __('بررسی شرایط ورود', 'tecteb-marketplace-core'),
            'row_and_manifest' => __('نوشتن ردیف و دفتر اجرا', 'tecteb-marketplace-core'),
            'image_file_copy' => __('کپی فایل تصویر', 'tecteb-marketplace-core'),
            'image_metadata' => __('ساخت اندازه‌های تصویر', 'tecteb-marketplace-core'),
            'attach' => __('چسباندن تصویر به محصول', 'tecteb-marketplace-core'),
            'step_not_listed' => __('مرحله‌ای بیرون از فهرست مجاز', 'tecteb-marketplace-core'),
            default => $step,
        };
    }

    /**
     * Why a batch ended ITSELF, for «جای توقف» on the queue page.
     *
     * ⛔ Every sentence here is about OUR declared budget and says so. The
     * middle key used to be `time_budget_one_unit_longer_than_the_request`
     * and its sentence said a product takes longer than a whole web request —
     * which is not what was measured and cannot be measured from inside PHP:
     * the request's real deadline belongs to FPM, the web server or a proxy.
     * A unit over our fifteen seconds may be perfectly survivable on a host
     * that allows sixty. What is measured is a unit against the budget, so
     * that is what the key and the sentence say.
     */
    public static function stopReason(string $why): string
    {
        return match ($why) {
            'time_budget_spent' => __(
                'این دسته خودش تمام شد چون بودجهٔ زمانیِ اعلام‌شده رو به پایان بود؛ ادامه از همین‌جا.',
                'tecteb-marketplace-core'
            ),
            'time_budget_spent_before_first_unit' => __(
                'بودجهٔ زمانی پیش از رسیدن به اولین محصول تمام شده بود — یعنی خودِ خواندنِ صفحه یا انتظارِ قفل آن را خرج کرد. ادامه از همین‌جا؛ عددِ بودجه و مصرف در همین ردیف است.',
                'tecteb-marketplace-core'
            ),
            'time_budget_one_unit_over_budget' => __(
                'یک محصول به‌تنهایی از بودجهٔ زمانیِ اعلام‌شدهٔ ما بیشتر برد. این دربارهٔ بودجهٔ ما است، نه مهلت واقعی میزبان — که از داخل PHP خوانده نمی‌شود. زمان مرحله‌ها می‌گوید کدام مرحله گران است.',
                'tecteb-marketplace-core'
            ),
            // ⛔ Kept, and only as a label: rows written by `alpha.75` carry
            // the old key, and a cursor from an installed package is read by
            // the package that replaces it. The sentence is the corrected one.
            'time_budget_one_unit_longer_than_the_request' => __(
                'یک محصول به‌تنهایی از بودجهٔ زمانیِ اعلام‌شدهٔ ما بیشتر برد. (این ردیف با نسخهٔ پیشین نوشته شده؛ آن نسخه این را «بیشتر از کلِ وقتِ یک درخواست» می‌نامید، که اندازه‌گیری نشده بود.)',
                'tecteb-marketplace-core'
            ),
            default => $why,
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
     * Why a hand-over did not happen, in the manager's words.
     *
     * Each refusal names a different situation and asks for a different thing
     * back, which is the whole reason `handOver()` answers with a name rather
     * than `false`: «مهلت قفل پایان نیافت» was printed for all of them and told
     * a manager nothing about what to do next.
     */
    public static function handOverRefusal(string $why): string
    {
        return match ($why) {
            'not_running' => __('این کار دیگر در حال اجرا نیست؛ شاید همین حالا تمام شده باشد. صفحه را تازه کنید.', 'tecteb-marketplace-core'),
            'lock_changed' => __('کارگر دیگری از لحظهٔ باز شدن این صفحه این کار را گرفته است. صفحه را تازه کنید و دوباره ببینید.', 'tecteb-marketplace-core'),
            'worker_still_writing' => __('این کار همین چند لحظه پیش چیزی نوشته، پس کارگرش شاید زنده باشد. اگر مطمئنید، کادر «با این حال ادامه بده» را علامت بزنید.', 'tecteb-marketplace-core'),
            'unreadable' => __('وضعیت این کار خوانده نشد، پس مهلت قفلش پایان داده نشد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            default => __('مهلت قفل این کار پایان نیافت. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core'),
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
