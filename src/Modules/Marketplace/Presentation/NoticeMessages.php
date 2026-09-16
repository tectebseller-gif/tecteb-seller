<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Marketplace\Application\Notify;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;

/**
 * The Persian for every notification and every report figure.
 *
 * Here rather than in the services for the rule the architecture test enforces:
 * the Application layer may not call `__()`. A notice is stored as a key and a
 * few numbers, so the same row reads correctly on a dashboard today and in a
 * digest tomorrow, and a translation change never has to touch stored data.
 */
final class NoticeMessages
{
    /**
     * One notification, as a sentence.
     *
     * @param array<string,scalar> $context
     */
    public static function sentence(string $event, array $context = []): string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        return match ($event) {
            Notify::TICKET_OPENED => sprintf(
                /* translators: %s: the ticket's subject */
                __('گفتگوی تازه‌ای باز شد: %s', 'tecteb-marketplace-core'),
                (string) ($context['subject'] ?? '')
            ),
            Notify::TICKET_REPLIED => sprintf(
                /* translators: %s: the ticket's subject */
                __('به گفتگوی «%s» پاسخ داده شد.', 'tecteb-marketplace-core'),
                (string) ($context['subject'] ?? '')
            ),
            Notify::PRODUCT_APPROVED => sprintf(
                /* translators: %s: the product's title */
                __('محصول «%s» تأیید و منتشر شد.', 'tecteb-marketplace-core'),
                (string) ($context['title'] ?? '')
            ),
            Notify::PRODUCT_REJECTED => sprintf(
                /* translators: %s: the product's title */
                __('محصول «%s» نیاز به اصلاح دارد. دلیلش در صفحهٔ محصول نوشته شده.', 'tecteb-marketplace-core'),
                (string) ($context['title'] ?? '')
            ),
            Notify::VENDOR_APPROVED => __('فروشندگی شما تأیید شد.', 'tecteb-marketplace-core'),
            Notify::VENDOR_SUSPENDED => __('فروشگاه شما تعلیق شد. تا رفع تعلیق، محصولاتتان از فروش خارج است.', 'tecteb-marketplace-core'),
            Notify::RETURN_OPENED => sprintf(
                /* translators: %s: how many units */
                __('درخواست مرجوعی تازه‌ای ثبت شد (%s عدد).', 'tecteb-marketplace-core'),
                $fa((string) ($context['quantity'] ?? ''))
            ),
            Notify::RETURN_DECIDED => __('دربارهٔ یک مرجوعی تصمیم گرفته شد.', 'tecteb-marketplace-core'),
            Notify::WITHDRAWAL_DECIDED => __('دربارهٔ درخواست برداشت شما تصمیم گرفته شد.', 'tecteb-marketplace-core'),
            Notify::STOREFRONT_STOPPED => __('فروش بازارگاه متوقف شده است؛ تا از سرگیری، محصولات از فروشگاه بیرون‌اند.', 'tecteb-marketplace-core'),
            Notify::RATING_RECEIVED => sprintf(
                /* translators: %s: how many stars the buyer gave */
                __('یک خریدار به فروشگاه شما %s ستاره داد. تا تأیید مدیر در صفحهٔ عمومی دیده نمی‌شود.', 'tecteb-marketplace-core'),
                $fa((string) ($context['stars'] ?? ''))
            ),
            Notify::RATING_MODERATED => ((string) ($context['status'] ?? '') === 'approved'
                ? __('یکی از امتیازهای فروشگاه شما تأیید شد و حالا در صفحهٔ عمومی دیده می‌شود.', 'tecteb-marketplace-core')
                : __('یکی از امتیازهای فروشگاه شما رد شد. متن و دلیلش در صفحهٔ «نظرات» هست.', 'tecteb-marketplace-core')),
            // A key nobody has written a sentence for is shown as the key, not
            // as a blank — a notice with no text is a notice nobody can act on,
            // and the key at least names what happened.
            default => $event,
        };
    }

    /** The title of one report card. */
    public static function reportTitle(string $key): string
    {
        return match ($key) {
            Reports::SALES => __('فروش', 'tecteb-marketplace-core'),
            Reports::PRODUCTS => __('محصول', 'tecteb-marketplace-core'),
            Reports::STOCK => __('موجودی', 'tecteb-marketplace-core'),
            Reports::FINANCE => __('مالی', 'tecteb-marketplace-core'),
            Reports::OPERATIONS => __('عملیاتی', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /** The label of one figure inside a report. */
    public static function figure(string $key): string
    {
        return match ($key) {
            'lines' => __('کل اقلام فروخته‌شده', 'tecteb-marketplace-core'),
            'awaiting' => __('در انتظار آماده‌سازی', 'tecteb-marketplace-core'),
            'preparing' => __('در حال آماده‌سازی', 'tecteb-marketplace-core'),
            'partially_shipped' => __('ارسال جزئی', 'tecteb-marketplace-core'),
            'shipped' => __('ارسال‌شده', 'tecteb-marketplace-core'),
            'delivered' => __('تحویل‌شده', 'tecteb-marketplace-core'),
            'cancelled' => __('لغوشده', 'tecteb-marketplace-core'),
            'returned' => __('مرجوع‌شده', 'tecteb-marketplace-core'),
            'total' => __('کل محصولات', 'tecteb-marketplace-core'),
            'published' => __('منتشرشده', 'tecteb-marketplace-core'),
            'in_review' => __('در انتظار بررسی', 'tecteb-marketplace-core'),
            'needs_fix' => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
            'draft' => __('پیش‌نویس', 'tecteb-marketplace-core'),
            'suspended' => __('تعلیق‌شده', 'tecteb-marketplace-core'),
            'on_sale' => __('روی فروشگاه', 'tecteb-marketplace-core'),
            'out_of_stock' => __('ناموجود', 'tecteb-marketplace-core'),
            'low_stock' => __('رو به اتمام', 'tecteb-marketplace-core'),
            'threshold' => __('آستانهٔ هشدار', 'tecteb-marketplace-core'),
            'earned_minor' => __('سهم ثبت‌شده', 'tecteb-marketplace-core'),
            'pending_minor' => __('در انتظار', 'tecteb-marketplace-core'),
            'eligible_minor' => __('قابل درخواست', 'tecteb-marketplace-core'),
            'reserved_minor' => __('رزروشده در برداشت', 'tecteb-marketplace-core'),
            'paid_minor' => __('پرداخت‌شده', 'tecteb-marketplace-core'),
            'unrecorded_lines' => __('قلم با سهم ثبت‌نشده', 'tecteb-marketplace-core'),
            'vendors' => __('فروشگاه', 'tecteb-marketplace-core'),
            'vendor_applications_waiting' => __('درخواست فروشندگی در انتظار', 'tecteb-marketplace-core'),
            'awaiting_shipment' => __('قلم در انتظار ارسال', 'tecteb-marketplace-core'),
            'open_returns' => __('مرجوعی باز', 'tecteb-marketplace-core'),
            'open_tickets' => __('تیکت باز', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /**
     * Whether this figure is money, so a screen formats it as money.
     *
     * Asked by the suffix rather than by a second list: every minor-unit
     * figure in `Reports` ends in `_minor`, and a list that had to be kept in
     * step would eventually fall out of step.
     */
    public static function isMoney(string $key): bool
    {
        return str_ends_with($key, '_minor');
    }

    /**
     * Keys a report card should not print as a figure.
     *
     * `available` is how a report says its module is not loaded, and
     * `threshold` is a setting the stock card explains in its own sentence.
     *
     * @return list<string>
     */
    public static function hiddenFigures(): array
    {
        return ['available', 'threshold'];
    }

    /**
     * The figures of one report, as chart rows — or none, when it should not
     * be charted.
     *
     * Two exclusions, both deliberate:
     *
     *  - **Money is never charted.** A column chart rounds to fit a label
     *    above a bar, and a rounded balance is exactly the kind of number
     *    somebody acts on wrongly. The finance report keeps its table, where
     *    every figure is exact to the ریال.
     *  - **Totals are never charted beside their own parts.** «کل اقلام» next
     *    to the statuses that add up to it makes every other column a stub and
     *    says nothing the sum did not. So the whole-count keys are dropped and
     *    the breakdown is what gets drawn.
     *
     * @param array<string,mixed> $figures
     * @return list<array{label:string, value:int}>
     */
    public static function chartRows(string $reportKey, array $figures): array
    {
        $keys = match ($reportKey) {
            Reports::SALES => ['awaiting', 'preparing', 'partially_shipped', 'shipped', 'delivered', 'cancelled', 'returned'],
            Reports::PRODUCTS => ['published', 'in_review', 'needs_fix', 'draft', 'suspended'],
            Reports::STOCK => ['on_sale', 'low_stock', 'out_of_stock'],
            Reports::OPERATIONS => ['vendor_applications_waiting', 'awaiting_shipment', 'open_returns', 'open_tickets'],
            // Reports::FINANCE and anything unknown: no chart. See above.
            default => [],
        };
        $rows = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $figures)) {
                continue;
            }
            $rows[] = ['label' => self::figure($key), 'value' => (int) $figures[$key]];
        }
        return $rows;
    }

    /** What a chart of a report is measuring, said under it. */
    public static function chartCaption(string $reportKey): string
    {
        return match ($reportKey) {
            Reports::SALES => __('تعداد اقلام در هر وضعیت، همین لحظه.', 'tecteb-marketplace-core'),
            Reports::PRODUCTS => __('تعداد محصول در هر وضعیت.', 'tecteb-marketplace-core'),
            Reports::STOCK => __('وضعیت موجودی محصولات روی فروشگاه.', 'tecteb-marketplace-core'),
            Reports::OPERATIONS => __('کارهای باز بازارگاه، همین لحظه.', 'tecteb-marketplace-core'),
            default => '',
        };
    }

    /** The sentence that explains what «سهم ثبت‌نشده» means, and why not zero. */
    public static function unrecordedNote(): string
    {
        return __('«قلم با سهم ثبت‌نشده» یعنی سهم آن قلم هنوز محاسبه نشده — نه اینکه صفر است. تا تعیین نرخ کمیسیون، این دو یکی نیستند و صفر نوشتن دروغ می‌شد.', 'tecteb-marketplace-core');
    }
}
