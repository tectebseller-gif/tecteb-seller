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
            OrderItemStatus::Placed, OrderItemStatus::Preparing => 'warning',
            OrderItemStatus::Cancelled => 'error',
            OrderItemStatus::Shipped => 'neutral',
        };
    }

    /** The button that moves an item into this status. */
    public static function action(OrderItemStatus $status): string
    {
        return match ($status) {
            OrderItemStatus::Preparing => __('شروع آماده‌سازی', 'tecteb-marketplace-core'),
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
            'order_item_shipped' => __('ارسال ثبت شد. کد رهگیری برای مشتری نمایش داده می‌شود.', 'tecteb-marketplace-core'),
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
        ];
    }

    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, self::successCodes(), true);
    }
}
