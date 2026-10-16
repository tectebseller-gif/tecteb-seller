<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;

/**
 * The Persian sentence for each answer `RecordSupplierPayment` gives.
 *
 * Here and not in the service, because Application may not call WordPress —
 * the rule an architecture test has enforced since `alpha.9`, including for
 * `__()`.
 *
 * Every refusal names what to do next, and none of them names a table, a
 * class or an open development decision: «پیام‌های کاربر کوتاه باشد و وضعیت و
 * اقدام بعدی را بگوید» (§3). The one long sentence on this surface is the
 * conflict explanation, and it is long because it is the thing the owner has
 * to decide — it says what was recorded, what was not, and why.
 */
final class SupplierPaymentMessages
{
    /** @param array<string,mixed> $context */
    public static function notice(string $code, array $context = []): ?string
    {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        return match ($code) {
            RecordSupplierPayment::RECORDED =>
                __('پرداخت ثبت شد و در دفترکل هم ثبت شد، چون مبلغ پرداختی با سهم ثبت‌شدهٔ همان اقلام یکی بود.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::RECORDED_UNPOSTED => sprintf(
                /* translators: 1: the amount paid, 2: the recorded share */
                __('پرداخت ثبت شد، ولی در دفترکل ثبت نشد: مبلغ پرداختی %1$s است و سهم ثبت‌شدهٔ همان اقلام %2$s. این پرداخت در فهرست «پرداخت‌هایی که در دفترکل ثبت نشده‌اند» می‌آید و تا تعیین قاعده، هیچ عددی حدس زده نمی‌شود.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['share_minor'] ?? ''))
            ),
            RecordSupplierPayment::RECORDED_SHARE_UNKNOWN => sprintf(
                /* translators: 1: the order items whose share is unknown, 2: the amount paid */
                __('پرداخت ثبت شد، ولی در دفترکل ثبت نشد و «تسویه‌شده» هم اعلام نمی‌شود: سهم قلم سفارش %1$s ثبت نشده است، پس مقایسهٔ مبلغ %2$s با سهم این اقلام انجام‌دادنی نبود. این پرداخت در فهرست «پرداخت‌هایی که در دفترکل ثبت نشده‌اند» می‌آید و هیچ عددی به‌جای سهم نامعلوم گذاشته نمی‌شود.', 'tecteb-marketplace-core'),
                $fa(implode('، ', array_map('strval', (array) ($context['unknown_share_items'] ?? [])))),
                $fa((string) ($context['amount_minor'] ?? ''))
            ),
            RecordSupplierPayment::ITEM_HELD_BY_WITHDRAWAL => sprintf(
                /* translators: 1: the order item id, 2: the withdrawal request id */
                __('قلم سفارش %1$s در چرخهٔ برداشت فروشنده است (درخواست برداشت %2$s)، پس پرداخت دستی برای آن ثبت نمی‌شود — یک قلم دو بار تسویه نمی‌شود. هیچ‌چیز ثبت نشد و هیچ رزروی آزاد نشد؛ اگر آن درخواست را اشتباه می‌دانید، اول خودش را تعیین تکلیف کنید.', 'tecteb-marketplace-core'),
                $fa((string) ($context['order_item_id'] ?? '')),
                $fa((string) ($context['withdrawal_id'] ?? ''))
            ),
            RecordSupplierPayment::FORBIDDEN =>
                __('ثبت پرداخت به تأمین‌کننده نیازمند مجوز مدیریت مالی است.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::NO_ITEMS =>
                __('شناسهٔ تأمین‌کننده و دست‌کم یک قلم سفارش لازم است. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::AMOUNT_REQUIRED =>
                __('مبلغ پرداختی را بنویسید؛ عددی بزرگ‌تر از صفر و متناسب با واحد پول این تأمین‌کننده. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::REFERENCE_REQUIRED =>
                __('شمارهٔ پیگیری لازم است؛ بدون آن این ردیف با صورت‌حساب بانکی قابل تطبیق نیست. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::DATE_REQUIRED =>
                __('تاریخ پرداخت را به شکل YYYY-MM-DD بنویسید. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::UNIT_UNKNOWN =>
                __('واحد پول دفتر این تأمین‌کننده مشخص نیست یا بیش از یکی است، پس مبلغ با واحد حدسی ثبت نمی‌شود. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::ITEM_UNKNOWN => sprintf(
                /* translators: %s: the order item id */
                __('قلم سفارش %s پیدا نشد. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['order_item_id'] ?? ''))
            ),
            RecordSupplierPayment::WRONG_SUPPLIER => sprintf(
                /* translators: %s: the order item id */
                __('قلم سفارش %s مال تأمین‌کنندهٔ دیگری است. یک پرداخت نمی‌تواند اقلام دو تأمین‌کننده را بپوشاند. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['order_item_id'] ?? ''))
            ),
            RecordSupplierPayment::ITEM_ALREADY_PAID => sprintf(
                /* translators: 1: the order item id, 2: the existing payment id, 3: its date, 4: its reference */
                __('قلم سفارش %1$s قبلاً در پرداخت %2$s ثبت شده است (تاریخ %3$s، شمارهٔ پیگیری %4$s). هیچ‌چیز ثبت نشد و آن پرداخت دست نخورد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['order_item_id'] ?? '')),
                $fa((string) ($context['payment_id'] ?? '—')),
                $fa((string) ($context['paid_on'] ?? '—')),
                (string) ($context['reference'] ?? '—')
            ),
            RecordSupplierPayment::ALREADY_RECORDED =>
                __('همین پرداخت از قبل ثبت شده بود؛ پرداخت دومی ساخته نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::NOT_ATOMIC =>
                __('ثبت پرداخت و خط دفترکلش باید یک عملیات باشند و این نصب نمی‌تواند آن را تضمین کند. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::STORAGE_FAILED =>
                __('ثبت پرداخت انجام نشد. هیچ‌چیز نیمه‌کاره نماند؛ دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            default => null,
        };
    }

    /**
     * Why a recorded payment can be out of the ledger — the ONE thing on this
     * screen that needs more than a line.
     *
     * It is the conflict §6 asked to be identified instead of assumed, and it
     * is written for the person who can settle it.
     */
    public static function conflictExplanation(): string
    {
        return __('این‌ها پرداخت‌هایی هستند که انجام و ثبت شده‌اند، ولی مبلغشان با سهم ثبت‌شدهٔ همان اقلام فرق دارد. سهم، از فروش ثبت‌شده و کمیسیون همان لحظه حساب شده است؛ مبلغ پرداختی، قیمتی است که تک‌طب توافق کرده. تا وقتی تعیین نشده که اختلاف این دو چه معنایی دارد، بازارگاه خطی در دفترکل نمی‌نویسد — چون خط دفترکل می‌گوید «این، طلب تأمین‌کننده را تسویه کرد» و درباره مبلغی که با آن طلب فرق دارد، این جمله تصمیمی است که کسی نگرفته. و دستهٔ دوم اصلاً مقایسه‌شدنی نبوده: سهم دست‌کم یکی از اقلامشان ثبت نشده است، و سهمِ نامعلوم با سهمِ صفر یکی گرفته نمی‌شود — ستون «چرا ثبت نشده» می‌گوید هر ردیف کدام است.', 'tecteb-marketplace-core');
    }
}
