<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation;

use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;

/**
 * Every sentence the settlement screens say — in one place, so the Domain
 * stays free of WordPress and the vendor and the manager can be told
 * different amounts of the same truth.
 */
final class WithdrawalMessages
{
    public static function status(WithdrawalStatus $status): string
    {
        return match ($status) {
            WithdrawalStatus::Requested => __('درخواست‌شده', 'tecteb-marketplace-core'),
            WithdrawalStatus::Reviewing => __('در حال بررسی', 'tecteb-marketplace-core'),
            WithdrawalStatus::Approved => __('تأییدشده', 'tecteb-marketplace-core'),
            WithdrawalStatus::PaymentInProgress => __('در حال پرداخت', 'tecteb-marketplace-core'),
            WithdrawalStatus::Paid => __('پرداخت‌شده', 'tecteb-marketplace-core'),
            WithdrawalStatus::Rejected => __('رد شده', 'tecteb-marketplace-core'),
            WithdrawalStatus::Cancelled => __('لغو شده', 'tecteb-marketplace-core'),
            WithdrawalStatus::ReconciliationRequired => __('نیازمند تطبیق دستی', 'tecteb-marketplace-core'),
        };
    }

    /** The button that moves a request INTO this state. */
    public static function action(WithdrawalStatus $target): string
    {
        return match ($target) {
            WithdrawalStatus::Reviewing => __('شروع بررسی', 'tecteb-marketplace-core'),
            WithdrawalStatus::Approved => __('تأیید', 'tecteb-marketplace-core'),
            WithdrawalStatus::PaymentInProgress => __('شروع پرداخت', 'tecteb-marketplace-core'),
            WithdrawalStatus::Paid => __('ثبت پرداخت انجام‌شده', 'tecteb-marketplace-core'),
            WithdrawalStatus::Rejected => __('رد', 'tecteb-marketplace-core'),
            WithdrawalStatus::Cancelled => __('لغو', 'tecteb-marketplace-core'),
            WithdrawalStatus::ReconciliationRequired => __('نتیجهٔ انتقال نامعلوم است', 'tecteb-marketplace-core'),
        };
    }

    /** What an operation result code means to whoever pressed the button. */
    public static function outcome(string $code): string
    {
        return match ($code) {
            'withdrawal_requested' => __('درخواست برداشت ثبت شد و مبلغ همان لحظه قفل شد.', 'tecteb-marketplace-core'),
            'withdrawal_cancelled' => __('درخواست لغو شد و مبلغ به مانده برگشت.', 'tecteb-marketplace-core'),
            'withdrawal_reviewed' => __('وضعیت درخواست تغییر کرد.', 'tecteb-marketplace-core'),
            'withdrawal_already_open' => __('یک درخواست باز دارید. تا تعیین تکلیف آن، درخواست تازه ثبت نمی‌شود.', 'tecteb-marketplace-core'),
            'nothing_eligible' => __('در این لحظه مبلغ قابل برداشتی ندارید.', 'tecteb-marketplace-core'),
            'settlement_blocked' => __('برداشت هنوز فعال نشده است؛ تصمیم‌های مالی باز است.', 'tecteb-marketplace-core'),
            'bank_account_missing' => __('اول شماره شبا و صاحب حساب را در تنظیمات فروشگاه ثبت کنید.', 'tecteb-marketplace-core'),
            'bank_account_on_hold' => __('حساب بانکی پس از تغییر شبا در انتظار تأیید مدیر است و تسویه موقتاً متوقف می‌ماند.', 'tecteb-marketplace-core'),
            'reservation_lost' => __('بخشی از مبلغ هم‌زمان در درخواست دیگری قفل شد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'reference_required' => __('برای ثبت پرداخت، شماره پیگیری انتقال لازم است.', 'tecteb-marketplace-core'),
            'note_required' => __('برای این اقدام نوشتن دلیل لازم است.', 'tecteb-marketplace-core'),
            'invalid_transition' => __('این تغییر وضعیت از حالت فعلی ممکن نیست.', 'tecteb-marketplace-core'),
            'ledger_unwritable' => __('دفترکل نوشتن را نپذیرفت؛ پرداخت ثبت نشد.', 'tecteb-marketplace-core'),
            'forbidden' => __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'),
            'not_found' => __('درخواست پیدا نشد.', 'tecteb-marketplace-core'),
            'storage_failed' => __('ذخیره نشد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            default => $code,
        };
    }

    /** Whether an outcome code is a refusal rather than a confirmation. */
    public static function isRefusal(string $code): bool
    {
        return !in_array($code, [
            'withdrawal_requested',
            'withdrawal_cancelled',
            'withdrawal_reviewed',
        ], true);
    }

    /**
     * Why a share is not withdrawable yet — for the vendor, in their words.
     *
     * The two reasons are genuinely different and the vendor needs to tell
     * them apart: one is waiting on the marketplace to confirm the sale, the
     * other is the agreed waiting period running.
     */
    public static function pendingReason(
        int $awaitingCompletion,
        int $awaitingDelay,
        int $delayDays,
        int $awaitingReturn = 0
    ): string {
        $parts = [];
        if ($awaitingCompletion > 0) {
            $parts[] = __('بعضی سفارش‌ها هنوز از سوی بازارگاه «تکمیل‌شده» ثبت نشده‌اند.', 'tecteb-marketplace-core');
        }
        if ($awaitingDelay > 0) {
            $parts[] = sprintf(
                /* translators: %s: number of days */
                __('بعضی مبالغ در مهلت %s روزهٔ پس از تکمیل هستند.', 'tecteb-marketplace-core'),
                \Tecteb\Marketplace\Core\Support\PersianDigits::toPersian((string) $delayDays)
            );
        }
        if ($awaitingReturn > 0) {
            $parts[] = __('سهم بعضی اقلام تا تعیین تکلیف مرجوعی‌شان قابل برداشت نیست؛ اگر مرجوعی رد شود، همان سهم برمی‌گردد.', 'tecteb-marketplace-core');
        }
        return implode(' ', $parts);
    }
}
