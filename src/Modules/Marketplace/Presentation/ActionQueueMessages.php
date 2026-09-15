<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation;

/**
 * The Persian name of each action-queue bucket.
 *
 * Separate from ActionQueue because that class is Application-layer and must
 * not call WordPress — a rule the architecture test enforces, and a useful one:
 * the same counts feed a screen today and could feed a REST response or a
 * report tomorrow, none of which want a sentence baked in.
 */
final class ActionQueueMessages
{
    public static function label(string $key): string
    {
        return match ($key) {
            // the shop's own queue (UX §6)
            'new_orders' => __('سفارش تازه', 'tecteb-marketplace-core'),
            'partly_shipped' => __('ارسال ناتمام', 'tecteb-marketplace-core'),
            'products_need_work' => __('محصول نیازمند اصلاح', 'tecteb-marketplace-core'),
            'low_stock' => __('موجودی کم', 'tecteb-marketplace-core'),
            'open_returns' => __('مرجوعی باز', 'tecteb-marketplace-core'),
            'answered_tickets' => __('پاسخ تازه پشتیبانی', 'tecteb-marketplace-core'),
            'unrecorded_share' => __('قلم بدون سهم ثبت‌شده', 'tecteb-marketplace-core'),
            // the marketplace's own queue (UX §13)
            'vendor_applications' => __('فروشنده در انتظار بررسی', 'tecteb-marketplace-core'),
            'products_in_review' => __('محصول در انتظار تأیید', 'tecteb-marketplace-core'),
            'returns_waiting' => __('مرجوعی در انتظار تصمیم', 'tecteb-marketplace-core'),
            'tickets_waiting' => __('تیکت در انتظار پاسخ', 'tecteb-marketplace-core'),
            'wholesale_waiting' => __('خریدار عمده در انتظار تأیید', 'tecteb-marketplace-core'),
            default => $key,
        };
    }
}
