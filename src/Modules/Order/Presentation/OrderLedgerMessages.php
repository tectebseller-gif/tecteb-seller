<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation;

use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderDisposition;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderLifecycle;

/**
 * The Persian words the per-line order report prints.
 *
 * Here and not in the Application layer, which may not call `__()` — a key is
 * not a sentence, and the architecture test enforces it. One function per
 * question, so the table and the CSV cannot name the same state differently.
 *
 * **Every «نامعلوم» below is a real answer.** A state this plugin could not
 * read is not the commonest state and is never printed as one: that is the
 * `alpha.49` rule, and on a financial report it is the difference between
 * «this was not paid» and «I do not know whether this was paid».
 */
final class OrderLedgerMessages
{
    /** What WooCommerce says about the order this line is on. */
    public static function payment(?string $wcStatus, bool $readable): string
    {
        // ⛔ A WORD, NOT A SENTENCE — and the sentence is not lost, it moved.
        // Rendered at 1440 these two branches printed a full explanation
        // inside a narrow column, wrapping to ONE WORD PER LINE and making
        // every such row four hundred pixels tall; twenty-five of them are
        // ten thousand. The explanation belongs in the row's own accounting
        // detail, which is what `paymentNote()` is for, and a reader who only
        // needs «کدام‌ها نامعلوم‌اند» can now see twenty-five of them at once.
        if (!$readable || $wcStatus === null || trim($wcStatus) === '') {
            return __('نامعلوم', 'tecteb-marketplace-core');
        }
        $disposition = WcOrderLifecycle::of($wcStatus);
        return match ($disposition) {
            WcOrderDisposition::Paid => __('پرداخت‌شده', 'tecteb-marketplace-core'),
            WcOrderDisposition::AwaitingPayment => __('در انتظار پرداخت', 'tecteb-marketplace-core'),
            WcOrderDisposition::PaymentFailed => __('پرداخت ناموفق', 'tecteb-marketplace-core'),
            WcOrderDisposition::Void => __('لغوشده', 'tecteb-marketplace-core'),
            WcOrderDisposition::Refunded => __('بازپرداخت‌شده در ووکامرس', 'tecteb-marketplace-core'),
            // The slug itself, because when this plugin does not know a status
            // the only useful thing it can say is what the status was called.
            WcOrderDisposition::Unknown => sprintf(
                /* translators: %s: the WooCommerce order status slug */
                __('نامعلوم (%s)', 'tecteb-marketplace-core'),
                $wcStatus
            ),
        };
    }

    /**
     * WHY the payment state is unknown — the half that does not fit a column.
     *
     * Two different facts, and keeping them apart matters: «this installation
     * has no mirror column» is about the SOFTWARE and is the same for every
     * row, while «nobody has told us this order's status» is about THIS
     * order and migration 39 deliberately back-filled nothing, so `NULL`
     * means exactly that. Writing `pending` into those rows would have been
     * inventing a fact about a hundred and eighty-four orders.
     *
     * Empty when the state speaks for itself.
     */
    public static function paymentNote(?string $wcStatus, bool $readable): string
    {
        if (!$readable) {
            return __('وضعیت پرداخت نامعلوم است: ساختار دادهٔ این نصب هنوز ستون آینهٔ وضعیت را ندارد.', 'tecteb-marketplace-core');
        }
        if ($wcStatus === null || trim($wcStatus) === '') {
            return __('وضعیت پرداخت نامعلوم است: از زمان نصب، وضعیتی برای این سفارش اعلام نشده است.', 'tecteb-marketplace-core');
        }
        return '';
    }

    /** Where the vendor's own part of the order has got to. */
    public static function shipping(string $status): string
    {
        return match (OrderItemStatus::tryFrom($status)) {
            OrderItemStatus::Placed => __('ثبت‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Preparing => __('در حال آماده‌سازی', 'tecteb-marketplace-core'),
            OrderItemStatus::PartiallyShipped => __('ارسال جزئی', 'tecteb-marketplace-core'),
            OrderItemStatus::Shipped => __('ارسال‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Delivered => __('تحویل‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Cancelled => __('لغوشده', 'tecteb-marketplace-core'),
            default => __('وضعیت ناشناخته', 'tecteb-marketplace-core'),
        };
    }

    /** What the ledger holds for this line. */
    public static function financial(string $state): string
    {
        return match ($state) {
            'recorded' => __('ثبت‌شده', 'tecteb-marketplace-core'),
            'reversed' => __('ثبت‌شده، با سند معکوس', 'tecteb-marketplace-core'),
            'unrecorded' => __('ثبت‌نشده — نرخی قابل تعیین نبود', 'tecteb-marketplace-core'),
            default => __('نامعلوم', 'tecteb-marketplace-core'),
        };
    }

    /** Whether this line's share is money anybody may ask for. */
    public static function settlement(string $state): string
    {
        return match ($state) {
            'complete' => __('تکمیل‌شده برای تسویه', 'tecteb-marketplace-core'),
            'awaiting_completion' => __('در انتظار ثبت تکمیل توسط مدیر', 'tecteb-marketplace-core'),
            'reserved' => __('رزرو‌شده در یک درخواست برداشت', 'tecteb-marketplace-core'),
            'void' => __('بی‌اعتبار — قلم لغو شده', 'tecteb-marketplace-core'),
            'unrecorded' => __('سهمی ثبت نشده', 'tecteb-marketplace-core'),
            default => __('نامعلوم', 'tecteb-marketplace-core'),
        };
    }

    /** Which kind of commission rule produced this line's figure. */
    public static function commissionKind(string $kind, ?int $rateBp): string
    {
        return match ($kind) {
            'percent' => sprintf(
                /* translators: %s: the rate, as a percentage */
                __('درصدی (%s٪)', 'tecteb-marketplace-core'),
                self::percent($rateBp)
            ),
            'fixed' => __('مبلغ ثابت به ازای هر عدد', 'tecteb-marketplace-core'),
            'none' => __('بدون کمیسیون', 'tecteb-marketplace-core'),
            'unrecorded' => __('ثبت‌نشده', 'tecteb-marketplace-core'),
            default => __('نامعلوم', 'tecteb-marketplace-core'),
        };
    }

    /**
     * Basis points as a percentage, without a float.
     *
     * `1250` is ۱۲٫۵ and `1000` is ۱۰ — the trailing zeros come off because
     * «۱۰٫۰۰٪» and «۱۰٪» are the same rate, and the decimal separator is the
     * Persian one. Nothing here rounds: four digits of basis points are at
     * most two decimal places.
     */
    public static function percent(?int $rateBp): string
    {
        if ($rateBp === null) {
            return '—';
        }
        $whole = intdiv($rateBp, 100);
        $fraction = $rateBp % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }
        return $whole . '٫' . rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }

    /** The three totals, each with the sentence that says what it is not. */
    public static function totalLabel(string $which): string
    {
        return match ($which) {
            'historic' => __('جمع تاریخی فروش (شامل اقلام لغوشده)', 'tecteb-marketplace-core'),
            'valid' => __('جمع معتبر فعلی (تعهد باقی‌مانده)', 'tecteb-marketplace-core'),
            'pending' => __('در انتظار تکمیل مدیر', 'tecteb-marketplace-core'),
            default => '',
        };
    }
}
