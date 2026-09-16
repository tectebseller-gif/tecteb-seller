<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;

/**
 * The Persian for reviews and ratings, and the one place stars become text.
 *
 * Here rather than in the services, for the rule the architecture test
 * enforces: the Application layer may not call `__()`.
 *
 * The star average is carried everywhere as HUNDREDTHS of a star, and turned
 * into «۴٫۳» in exactly one function. A number formatted in three screens is a
 * number that rounds three ways.
 */
final class ReviewMessages
{
    /** One star average, from hundredths, in Persian digits. */
    public static function stars(int $hundredths): string
    {
        if ($hundredths <= 0) {
            return '—';
        }
        return PersianDigits::toPersian(number_format($hundredths / 100, 1, '.', ''));
    }

    /** How many, as a count with its unit. */
    public static function count(int $count): string
    {
        return sprintf(
            /* translators: %s: how many reviews or ratings */
            _n('%s نظر', '%s نظر', $count, 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $count)
        );
    }

    public static function statusLabel(RatingStatus $status): string
    {
        return match ($status) {
            RatingStatus::Pending => __('در انتظار بررسی', 'tecteb-marketplace-core'),
            RatingStatus::Approved => __('تأییدشده', 'tecteb-marketplace-core'),
            RatingStatus::Rejected => __('ردشده', 'tecteb-marketplace-core'),
        };
    }

    public static function statusTone(RatingStatus $status): string
    {
        return match ($status) {
            RatingStatus::Pending => 'warning',
            RatingStatus::Approved => 'success',
            RatingStatus::Rejected => 'danger',
        };
    }

    /** The sentence that says what a shop may and may not do here. */
    public static function vendorScopeNote(): string
    {
        return __('در این بخش فقط می‌توانید پاسخ بدهید. امتیاز و متن نظر مال خریدار است و تأیید یا رد آن با مدیر تک‌طب؛ هیچ‌کدام از فروشگاه تغییر نمی‌کند.', 'tecteb-marketplace-core');
    }

    /** Why a pending rating is visible to the shop but not to shoppers. */
    public static function pendingVisibilityNote(): string
    {
        return __('نظر تازه تا تأیید مدیر در صفحهٔ عمومی دیده نمی‌شود، ولی از همین حالا برای خودتان پیداست — تا پیش از مشتری‌ها در جریان باشید.', 'tecteb-marketplace-core');
    }

    /** The two averages, side by side, and why they are not added together. */
    public static function separateAveragesNote(): string
    {
        return __('امتیاز محصول و امتیاز فروشگاه دو عدد جدا هستند و با هم جمع نمی‌شوند: یکی دربارهٔ کالاست و در ووکامرس ثبت می‌شود، دیگری دربارهٔ خودِ فروشگاه.', 'tecteb-marketplace-core');
    }

    /** Shown where product reviews cannot be read at all. */
    public static function reviewsUnavailableNote(): string
    {
        return __('نظرهای محصول از ووکامرس خوانده می‌شوند و ووکامرس روی این سایت اجرا نیست. این یعنی «خوانده نشد»، نه «نظری وجود ندارد».', 'tecteb-marketplace-core');
    }

    /**
     * One message for the review screens.
     *
     * @param array<string,scalar> $context
     */
    public static function notice(string $code, array $context = []): ?string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        return match ($code) {
            'rating_recorded' => __('امتیاز شما ثبت شد و پس از بررسی مدیر روی صفحهٔ فروشگاه نشان داده می‌شود.', 'tecteb-marketplace-core'),
            'rating_already_given' => __('برای این خرید یک‌بار امتیاز ثبت شده است.', 'tecteb-marketplace-core'),
            'rating_not_your_purchase' => __('این خرید به حساب شما ثبت نشده است، پس امتیازی برایش ثبت نمی‌شود.', 'tecteb-marketplace-core'),
            'rating_purchase_unverifiable' => __('خرید شما قابل بررسی نیست، چون ووکامرس روی این سایت اجرا نمی‌شود. این یعنی نتوانستیم بررسی کنیم، نه اینکه خریدی نبوده.', 'tecteb-marketplace-core'),
            'rating_stars_out_of_range' => sprintf(
                /* translators: 1: lowest allowed star, 2: highest allowed star */
                __('امتیاز باید بین %1$s و %2$s باشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['min'] ?? '')),
                $fa((string) ($context['max'] ?? ''))
            ),
            'rating_moderated' => __('تصمیم شما ثبت شد.', 'tecteb-marketplace-core'),
            'review_moderated' => __('وضعیت این نظر در ووکامرس تغییر کرد.', 'tecteb-marketplace-core'),
            'reply_recorded' => __('پاسخ شما ثبت شد و زیر همان نظر روی صفحهٔ محصول دیده می‌شود.', 'tecteb-marketplace-core'),
            'reply_already_given' => __('برای این نظر یک پاسخ ثبت شده است. پاسخ فروشگاه یکی است و ویرایش نمی‌شود.', 'tecteb-marketplace-core'),
            'reply_body_required' => __('متن پاسخ خالی است.', 'tecteb-marketplace-core'),
            'reply_failed' => __('پاسخ ثبت نشد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'moderation_reason_required' => __('برای رد کردن یک نظر، دلیل لازم است. دلیل برای خود فروشگاه هم نوشته می‌شود.', 'tecteb-marketplace-core'),
            'moderation_cannot_undecide' => __('نظر بررسی‌شده را نمی‌توان دوباره «بررسی‌نشده» کرد؛ تصمیم تازه بگیرید.', 'tecteb-marketplace-core'),
            'moderation_failed' => __('تغییر وضعیت انجام نشد.', 'tecteb-marketplace-core'),
            'reviews_woocommerce_missing' => self::reviewsUnavailableNote(),
            'not_ours' => __('این نظر مربوط به محصول فروشگاه شما نیست.', 'tecteb-marketplace-core'),
            default => null,
        };
    }

    /** @return list<string> codes a screen must show as a warning, not a success */
    public static function errorCodes(): array
    {
        return [
            'rating_already_given',
            'rating_not_your_purchase',
            'rating_purchase_unverifiable',
            'rating_stars_out_of_range',
            'reply_already_given',
            'reply_body_required',
            'reply_failed',
            'moderation_reason_required',
            'moderation_cannot_undecide',
            'moderation_failed',
            'reviews_woocommerce_missing',
            'not_ours',
        ];
    }
}
