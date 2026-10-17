<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierReceivables;
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
            // §3 — a partial payment is a success with a balance, not a
            // problem to be reviewed.
            RecordSupplierPayment::RECORDED_PARTIAL => sprintf(
                /* translators: 1: the amount paid, 2: the remaining balance */
                __('پرداخت جزئی ثبت شد: %1$s پرداخت شد و %2$s از مبلغ توافق‌شده باقی مانده است. این تسویهٔ کامل نیست و پرداخت‌های بعدی روی همین تعهد ثبت می‌شوند.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['balance_minor'] ?? ''))
            ),
            RecordSupplierPayment::RECORDED_AGREED_UNKNOWN =>
                __('پرداخت ثبت شد، ولی مبلغ توافق‌شدهٔ این قلم ثبت نشده است، پس «تسویه‌شده» یا «جزئی» اعلام نمی‌شود. مبلغ توافق را در بخش «مبلغ توافق‌شدهٔ خرید» بنویسید تا مانده محاسبه شود.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::ABOVE_BALANCE => sprintf(
                /* translators: 1: the amount paid, 2: the remaining balance */
                __('مبلغ %1$s بیشتر از ماندهٔ %2$s است و ثبت نشد. پرداخت بیش از مانده خودکار به بدهکاری یا پیش‌پرداخت تبدیل نمی‌شود؛ یا مبلغ را اصلاح کنید یا مبلغ توافق‌شده را.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['balance_minor'] ?? ''))
            ),
            RecordSupplierPayment::OBLIGATION_SETTLED => sprintf(
                /* translators: %s: the order item id */
                __('مبلغ توافق‌شدهٔ قلم %s کامل پرداخت شده است و ماندهٔ بازی ندارد. هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['order_item_id'] ?? ''))
            ),
            RecordSupplierPayment::ALLOCATION_AMBIGUOUS =>
                __('این پرداخت چند قلم دارد و دست‌کم یکی از آن‌ها مبلغ توافق‌شده دارد. تقسیم خودکار یک پرداخت بین چند تعهد انجام نمی‌شود؛ برای هر قلم یک پرداخت جدا ثبت کنید.', 'tecteb-marketplace-core'),
            // §5 — the void.
            RecordSupplierPayment::VOIDED => sprintf(
                /* translators: %s: the payment id */
                __('ثبت پرداخت #%s ابطال شد. سابقه حذف نشده و اگر این پرداخت در دفترکل ثبت شده بود، سند معکوس در همان واحد کار نوشته شد. ابطال به‌معنای بازگشت واقعی پول نیست؛ اگر پولی واقعاً برگشته، آن را به‌عنوان وصول طلب ثبت کنید.', 'tecteb-marketplace-core'),
                $fa((string) ($context['payment_id'] ?? ''))
            ),
            RecordSupplierPayment::VOID_REASON_REQUIRED =>
                __('برای ابطال، دلیل لازم است. هیچ‌چیز تغییر نکرد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::ALREADY_VOID =>
                __('این ثبت قبلاً ابطال شده است. دلیل، زمان و اقدام‌کنندهٔ همان ابطال اول سر جایش می‌مانَد و اثر مالی دومی ساخته نشد.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::NOT_FOUND =>
                __('پرداختی با این شناسه پیدا نشد.', 'tecteb-marketplace-core'),
            // §6 — reviewing the same payment.
            RecordSupplierPayment::RECONCILED => sprintf(
                /* translators: 1: the share recorded at the time, 2: the share now */
                __('همین پرداخت بررسی شد. سهم ثبت‌شده در زمان پرداخت %1$s بود و سهم محاسبه‌شدهٔ کنونی %2$s است؛ هر دو عدد نگه داشته شد و پرداخت دومی ساخته نشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['share_minor'] ?? '')),
                $fa((string) ($context['share_minor_final'] ?? ''))
            ),
            RecordSupplierPayment::STILL_UNKNOWN => sprintf(
                /* translators: %s: the order items whose share is still unknown */
                __('سهم قلم %s هنوز ثبت نشده است، پس بررسی انجام‌دادنی نبود و هیچ‌چیز تغییر نکرد. «نامعلوم» با صفر یکی نیست.', 'tecteb-marketplace-core'),
                $fa(implode('، ', array_map('strval', (array) ($context['unknown_share_items'] ?? []))))
            ),
            RecordSupplierPayment::ALREADY_RECONCILED =>
                __('این پرداخت قبلاً بررسی شده است و ارقام همان بررسی سر جایشان می‌مانند.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::NOTHING_TO_RECONCILE =>
                __('دربارهٔ این پرداخت چیزی در انتظار بررسی نیست.', 'tecteb-marketplace-core'),
            RecordSupplierPayment::IS_VOID =>
                __('این ثبت ابطال شده است و بررسی نمی‌شود؛ به‌جایش پرداخت صحیح را ثبت کنید.', 'tecteb-marketplace-core'),
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
            // The obligation and receivable services, whose codes land on
            // this same screen. One place answers for the whole page, so a
            // manager never sees a bare code.
            ManageSupplierObligations::AGREED => sprintf(
                /* translators: 1: the order item id, 2: the agreed amount */
                __('مبلغ توافق‌شدهٔ قلم %1$s روی %2$s ثبت شد و تغییر در سابقهٔ همان قلم نوشته شد. هیچ پرداخت یا سفارش ثبت‌شده‌ای بازنویسی نشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['order_item_id'] ?? '')),
                $fa((string) ($context['amount_minor'] ?? ''))
            ),
            ManageSupplierObligations::CLEARED =>
                __('مبلغ توافق‌شده برداشته شد و «نامعلوم» ثبت شد — که با صفر یکی نیست. تغییر در سابقه نوشته شد.', 'tecteb-marketplace-core'),
            ManageSupplierObligations::UNCHANGED =>
                __('همین مبلغ از قبل ثبت بود، پس چیزی نوشته نشد و سابقه‌ای اضافه نشد.', 'tecteb-marketplace-core'),
            ManageSupplierObligations::BELOW_PAID => sprintf(
                /* translators: 1: the new amount, 2: the amount already paid */
                __('مبلغ %1$s کمتر از مبلغ %2$s است که تا امروز پرداخت شده، پس ثبت نشد. این اصلاح نیست؛ یعنی اضافه پرداخت شده، و معنای آن تعیین نشده است.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['paid_minor'] ?? ''))
            ),
            ManageSupplierObligations::AMOUNT_INVALID =>
                __('مبلغ توافق‌شده را دقیق بنویسید؛ عددی غیرمنفی و متناسب با واحد پول این تأمین‌کننده. صفر پذیرفته می‌شود و یعنی «توافق بر صفر».', 'tecteb-marketplace-core'),
            ManageSupplierReceivables::RECORDED => sprintf(
                /* translators: 1: the receivable id, 2: the amount */
                __('طلب #%1$s به مبلغ %2$s ثبت شد. ثبت طلب به‌معنای وصول یا کسر نیست؛ وصول واقعی و تهاتر را جدا ثبت کنید.', 'tecteb-marketplace-core'),
                $fa((string) ($context['receivable_id'] ?? '')),
                $fa((string) ($context['amount_minor'] ?? ''))
            ),
            ManageSupplierReceivables::ALREADY_RECORDED =>
                __('برای همین مرجوعی و همین قلم، طلب قبلاً ثبت شده است و طلب دومی ساخته نشد.', 'tecteb-marketplace-core'),
            ManageSupplierReceivables::ITEM_NOT_PAID =>
                __('برای این قلم پرداختی به تأمین‌کننده ثبت نشده (یا ثبتش ابطال شده)، پس طلبی هم وجود ندارد. مرجوعی عادی از مسیر خودش انجام می‌شود.', 'tecteb-marketplace-core'),
            ManageSupplierReceivables::COLLECTED => sprintf(
                /* translators: 1: the amount collected, 2: the remaining balance */
                __('وصول واقعی %1$s ثبت شد و ماندهٔ طلب %2$s است. هیچ انتقال بانکی از اینجا انجام نشد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['balance_minor'] ?? ''))
            ),
            ManageSupplierReceivables::OFFSET => sprintf(
                /* translators: 1: the amount offset, 2: the remaining balance */
                __('تهاتر %1$s ثبت شد و ماندهٔ طلب %2$s است. این فقط یک ثبت است؛ هیچ مبلغی خودکار از پرداخت بعدی کسر نمی‌شود.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['balance_minor'] ?? ''))
            ),
            ManageSupplierReceivables::ABOVE_BALANCE => sprintf(
                /* translators: 1: the amount, 2: the remaining balance */
                __('مبلغ %1$s بیشتر از ماندهٔ %2$s این طلب است و ثبت نشد — یک مبلغ دو بار وصول یا کسر نمی‌شود.', 'tecteb-marketplace-core'),
                $fa((string) ($context['amount_minor'] ?? '')),
                $fa((string) ($context['balance_minor'] ?? ''))
            ),
            ManageSupplierReceivables::ALREADY_SETTLED =>
                __('همین ثبت قبلاً انجام شده است و ردیف دومی ساخته نشد.', 'tecteb-marketplace-core'),
            ManageSupplierReceivables::NOT_FOUND =>
                __('طلبی با این شناسه پیدا نشد.', 'tecteb-marketplace-core'),
            ManageSupplierReceivables::AMOUNT_INVALID =>
                __('مبلغ را دقیق بنویسید؛ عددی بزرگ‌تر از صفر و متناسب با واحد پول این تأمین‌کننده.', 'tecteb-marketplace-core'),
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
