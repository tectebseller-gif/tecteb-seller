<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;

/** Every Persian sentence the order screens say. Null for a code it does not own. */
final class OrderMessages
{
    public static function status(OrderItemStatus $status): string
    {
        return match ($status) {
            OrderItemStatus::Placed => __('ثبت‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Preparing => __('در حال آماده‌سازی', 'tecteb-marketplace-core'),
            OrderItemStatus::PartiallyShipped => __('بخشی ارسال‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Shipped => __('ارسال‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Delivered => __('تحویل‌شده', 'tecteb-marketplace-core'),
            OrderItemStatus::Cancelled => __('لغوشده', 'tecteb-marketplace-core'),
        };
    }

    /** success | warning | error | neutral */
    public static function statusTone(OrderItemStatus $status): string
    {
        return match ($status) {
            OrderItemStatus::Delivered => 'success',
            OrderItemStatus::Placed, OrderItemStatus::Preparing,
            OrderItemStatus::PartiallyShipped => 'warning',
            OrderItemStatus::Cancelled => 'error',
            OrderItemStatus::Shipped => 'neutral',
        };
    }

    /** The button that moves an item into this status. */
    public static function action(OrderItemStatus $status): string
    {
        return match ($status) {
            OrderItemStatus::Preparing => __('شروع آماده‌سازی', 'tecteb-marketplace-core'),
            OrderItemStatus::PartiallyShipped,
            OrderItemStatus::Shipped => __('ثبت ارسال', 'tecteb-marketplace-core'),
            OrderItemStatus::Delivered => __('تحویل شد', 'tecteb-marketplace-core'),
            OrderItemStatus::Cancelled => __('لغو این قلم', 'tecteb-marketplace-core'),
            OrderItemStatus::Placed => __('بازگشت به ثبت‌شده', 'tecteb-marketplace-core'),
        };
    }

    /**
     * @param array<string,scalar|null> $context
     * @return string|null null when this code belongs to another module
     */
    public static function notice(string $code, array $context = []): ?string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        return match ($code) {
            'order_item_preparing' => __('این قلم «در حال آماده‌سازی» شد.', 'tecteb-marketplace-core'),
            'order_item_shipped' => ((int) ($context['remaining'] ?? 0)) > 0
                ? sprintf(
                    /* translators: 1: units in this parcel, 2: units still to send */
                    __('ارسال %1$s عدد ثبت شد. %2$s عدد از این قلم هنوز ارسال نشده و می‌توانید بعداً با کد رهگیری دیگری بفرستید.', 'tecteb-marketplace-core'),
                    $fa($context['quantity'] ?? 0),
                    $fa($context['remaining'] ?? 0)
                )
                : __('ارسال ثبت شد و کل این قلم فرستاده شد. کد رهگیری برای مشتری نمایش داده می‌شود.', 'tecteb-marketplace-core'),
            'quantity_required' => __('تعداد را وارد کنید؛ دست‌کم یک عدد.', 'tecteb-marketplace-core'),
            'quantity_exceeds_remaining' => sprintf(
                /* translators: 1: requested, 2: what is left */
                __('%1$s عدد خواسته شده، ولی فقط %2$s عدد از این قلم باقی مانده است.', 'tecteb-marketplace-core'),
                $fa($context['requested'] ?? 0),
                $fa($context['remaining'] ?? 0)
            ),
            'quantity_exceeds_returnable' => sprintf(
                /* translators: 1: requested, 2: what may still be returned */
                __('%1$s عدد خواسته شده، ولی حداکثر %2$s عدد از این قلم قابل مرجوعی است.', 'tecteb-marketplace-core'),
                $fa($context['requested'] ?? 0),
                $fa($context['returnable'] ?? 0)
            ),
            'return_opened' => sprintf(
                /* translators: %s: quantity */
                __('درخواست مرجوعی برای %s عدد ثبت شد. تصمیم با مدیر بازارگاه است؛ مهلت و شرایط تجاری مرجوعی هنوز تعیین نشده و این درخواست خودکار رد یا تأیید نمی‌شود.', 'tecteb-marketplace-core'),
                $fa($context['quantity'] ?? 0)
            ),
            'return_approved' => __('مرجوعی تأیید شد. هنوز هیچ پولی برنگشته است.', 'tecteb-marketplace-core'),
            'return_rejected' => __('مرجوعی رد شد.', 'tecteb-marketplace-core'),
            'return_received' => ((int) ($context['restocked'] ?? 0)) > 0
                ? sprintf(
                    /* translators: %s: units returned to stock */
                    __('کالا تحویل گرفته شد و %s عدد به موجودی ووکامرس برگشت.', 'tecteb-marketplace-core'),
                    $fa($context['restocked'] ?? 0)
                )
                : __('کالا تحویل گرفته شد. موجودی تغییر نکرد.', 'tecteb-marketplace-core'),
            'return_cancelled' => __('درخواست مرجوعی لغو شد.', 'tecteb-marketplace-core'),
            'return_refunded' => sprintf(
                /* translators: %s: amount in minor units, already formatted */
                __('بازگشت مالی ثبت شد: %s. خطوط دفترکل معکوس شدند و هیچ خط قبلی پاک نشد. کرایهٔ ارسال و کسورات احتمالی تعیین‌نشده‌اند و در این مبلغ نیستند.', 'tecteb-marketplace-core'),
                $fa(number_format((int) ($context['refund_minor'] ?? 0)))
            ),
            'already_refunded' => __('این مرجوعی قبلاً بازپرداخت شده است. بازپرداخت دوم ثبت نمی‌شود.', 'tecteb-marketplace-core'),
            'use_refund' => __('برای ثبت بازگشت مالی از دکمهٔ بازپرداخت استفاده کنید؛ این مسیر پول جابه‌جا نمی‌کند.', 'tecteb-marketplace-core'),
            'nothing_recorded' => __('برای این قلم هیچ سهم مالی ثبت نشده بود (نرخ کمیسیون تعیین‌نشده)، پس چیزی برای معکوس‌کردن وجود ندارد.', 'tecteb-marketplace-core'),
            'ledger_already_recorded' => __('ردیف مرجوعی ثبت شد ولی دفترکل این رویداد را از قبل داشت. این دو باید بررسی شوند.', 'tecteb-marketplace-core'),
            'unbalanced_reversal' => __('خطوط معکوس متوازن نشدند؛ چیزی در دفترکل ثبت نشد.', 'tecteb-marketplace-core'),
            'order_item_cancelled_return' => __('روی قلمی که لغو شده، ارسال ثبت نمی‌شود.', 'tecteb-marketplace-core'),
            'order_item_delivered' => __('تحویل ثبت شد.', 'tecteb-marketplace-core'),
            'order_item_cancelled' => __('این قلم لغو شد. سهم مالی ثبت‌شده در دفترکل پاک نمی‌شود و تسویه‌اش جداگانه تعیین تکلیف می‌شود.', 'tecteb-marketplace-core'),
            'carrier_required' => __('برای ثبت ارسال، انتخاب شرکت حمل لازم است.', 'tecteb-marketplace-core'),
            'invalid_transition' => __('این تغییر وضعیت از وضعیت فعلی ممکن نیست.', 'tecteb-marketplace-core'),
            'orders_blocked' => __('ماژول سفارش بازارگاه هنوز عملیاتی نیست. تا تعیین نرخ کمیسیون و بسته‌شدن تصمیم‌های مالی، محصولات بازارگاه فروخته نمی‌شوند.', 'tecteb-marketplace-core'),
            'order_trial_mode' => sprintf(
                __('حالت آزمایشی سفارش روشن است (محیط: %s). قواعد مالی نمونه‌اند و این حالت روی سایت اصلی پذیرفته نمی‌شود.', 'tecteb-marketplace-core'),
                (string) ($context['environment'] ?? '')
            ),
            'unrecorded_share' => sprintf(
                __('%s قلم بدون ثبت سهم مالی مانده است؛ تا تعیین نرخ، تسویه‌شان ممکن نیست.', 'tecteb-marketplace-core'),
                $fa($context['count'] ?? 0)
            ),
            default => null,
        };
    }

    /** @return list<string> codes this module considers a success */
    public static function successCodes(): array
    {
        return [
            'order_item_preparing', 'order_item_shipped', 'order_item_delivered',
            'return_opened', 'return_approved', 'return_received',
            'return_refunded', 'return_cancelled',
        ];
    }

    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, self::successCodes(), true);
    }
}
