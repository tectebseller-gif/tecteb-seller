<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation;

use Tecteb\Marketplace\Modules\Order\Application\ReturnTerms;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;

/** Every Persian word the return screens use for a state, and the one warning. */
final class ReturnMessages
{
    public static function status(ReturnStatus $status): string
    {
        return match ($status) {
            ReturnStatus::Requested => __('درخواست‌شده', 'tecteb-marketplace-core'),
            ReturnStatus::Approved => __('تأییدشده', 'tecteb-marketplace-core'),
            ReturnStatus::Rejected => __('ردشده', 'tecteb-marketplace-core'),
            ReturnStatus::Received => __('کالا تحویل گرفته شد', 'tecteb-marketplace-core'),
            ReturnStatus::Refunded => __('بازپرداخت‌شده', 'tecteb-marketplace-core'),
            ReturnStatus::Cancelled => __('لغوشده', 'tecteb-marketplace-core'),
            ReturnStatus::ReconciliationRequired => __('نیازمند تطبیق', 'tecteb-marketplace-core'),
        };
    }

    /** success | warning | error | neutral */
    public static function statusTone(ReturnStatus $status): string
    {
        return match ($status) {
            ReturnStatus::Refunded => 'success',
            ReturnStatus::Requested, ReturnStatus::Approved, ReturnStatus::Received => 'warning',
            ReturnStatus::Rejected, ReturnStatus::Cancelled => 'error',
            ReturnStatus::ReconciliationRequired => 'error',
        };
    }

    /** The button that moves a return into this state. */
    public static function action(ReturnStatus $status): string
    {
        return match ($status) {
            ReturnStatus::Approved => __('تأیید مرجوعی', 'tecteb-marketplace-core'),
            ReturnStatus::Rejected => __('رد مرجوعی', 'tecteb-marketplace-core'),
            ReturnStatus::Received => __('ثبت تحویل کالا', 'tecteb-marketplace-core'),
            ReturnStatus::Refunded => __('ثبت بازگشت مالی', 'tecteb-marketplace-core'),
            ReturnStatus::Cancelled => __('لغو درخواست', 'tecteb-marketplace-core'),
            ReturnStatus::Requested => __('بازگشت به درخواست‌شده', 'tecteb-marketplace-core'),
            ReturnStatus::ReconciliationRequired => __('علامت‌زدن به‌عنوان نیازمند تطبیق', 'tecteb-marketplace-core'),
        };
    }

    /**
     * What «بازپرداخت» means here, in four parts — the sentence every refund
     * screen carries.
     *
     * Two of the four are performed by this plugin and two are not, and the
     * two that are not include the money. A screen that said «بازپرداخت انجام
     * شد» and stopped there would be telling a manager their customer has been
     * paid.
     */
    public static function refundScopeWarning(): string
    {
        return __('«بازپرداخت» در این بازارگاه یعنی دو کار: معکوس‌کردن خطوط دفترکل، و در صورت درخواست، بازگرداندن کالا به موجودی ووکامرس. دو کار دیگر انجام نمی‌شود: ساختن رکورد refund در ووکامرس، و انتقال واقعی وجه به مشتری. در این نسخه هیچ درگاه پرداخت واقعی وصل نیست، پس پول را باید خودتان از مسیر درگاه یا بانک برگردانید و بعد شمارهٔ refund ووکامرس را اینجا ثبت کنید.', 'tecteb-marketplace-core');
    }

    /**
     * The sentence that has to appear wherever a return is decided.
     *
     * It is not a disclaimer. It is the reason every request goes to a person:
     * the window, the return postage and any deduction are DEC-03 and unset,
     * so nothing here expires, auto-approves or quietly withholds an amount.
     */
    public static function openTermsWarning(): string
    {
        return sprintf(
            /* translators: %s: the decision reference, e.g. DEC-03 */
            __('شرایط تجاری مرجوعی (مهلت، کرایهٔ برگشت و کسورات) هنوز در %s تعیین نشده است. بازارگاه هیچ‌کدام را حدس نمی‌زند: هیچ درخواستی خودکار رد یا تأیید نمی‌شود و مبلغ بازگشتی فقط بهای همان کالا و مالیات همان تعداد است.', 'tecteb-marketplace-core'),
            ReturnTerms::DECISION
        );
    }
}
