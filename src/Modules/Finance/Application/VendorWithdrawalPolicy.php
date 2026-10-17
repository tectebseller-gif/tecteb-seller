<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

/**
 * WHETHER A VENDOR MAY ASK FOR MONEY AT ALL — the owner's answer, not a gate
 * on an open decision.
 *
 * §7: «فعلاً درخواست برداشت جدید برای فروشنده بسته شود. فقط مخفی‌کردن دکمه
 * کافی نیست و مسیرهای POST/API نیز باید همین سیاست را اعمال کنند. گزارش‌های
 * مالی، سوابق و پرداخت‌های فروشنده قابل مشاهده بماند. درخواست‌های قبلی حذف،
 * لغو یا آزاد نشوند؛ مدیر بررسی کند.»
 *
 * ### Why this is NOT `SettlementGate`
 *
 * `SettlementGate` answers «are the open decisions closed yet?» and it is
 * WAIVABLE: a disposable site turns the trial switch on and the gate steps
 * aside, which is exactly right for DEC-02 and FIN-04 — they are missing
 * definitions, and a test site may proceed without them.
 *
 * This is a different kind of thing. It is the owner's current operating
 * decision, and a trial switch on a staging site is not a reason to overrule
 * it. So it is a separate question, asked separately, and the trial mode
 * cannot answer it. Measured consequence, and the reason this class exists at
 * all: with the policy folded into `SettlementGate`, every evidence run on
 * the disposable environment — all of which enable the trial key — would have
 * created withdrawal requests while the owner's instruction says none may be
 * created.
 *
 * ### What is closed, and what is deliberately not
 *
 *  - **creating a request** is closed. Enforced in `RequestWithdrawal`, which
 *    is the one path every POST, every API route and every CLI call goes
 *    through — «فقط مخفی‌کردن دکمه کافی نیست».
 *  - **a vendor cancelling an open request** is closed too, and that is a
 *    reading rather than a quotation, so it is written down: cancelling
 *    RELEASES the reserved lines, and «درخواست‌های قبلی … آزاد نشوند؛ مدیر
 *    بررسی کند» says releasing them is the manager's call. A vendor who wants
 *    one withdrawn asks, and the manager's own path is untouched.
 *  - **everything readable stays readable.** The balance, the history, the
 *    payments, the reports: nothing here hides a number. The vendor sees what
 *    they are owed and what has been paid, and the page says plainly why
 *    there is no button.
 *  - **the manager's review is untouched.** `ReviewWithdrawals` does not
 *    consult this class. An existing request can still be approved, paid or
 *    rejected by a person, which is the whole of «مدیر بررسی کند».
 *  - **`alpha.61`'s coordination is untouched.** The claim inside `reserve()`
 *    and the `NOT EXISTS` in the supplier payment path are not weakened by
 *    this: a policy that says «no new requests» must not become the reason
 *    nobody notices if one is ever created again.
 */
final class VendorWithdrawalPolicy
{
    /** The vendor's own request path is closed. */
    public const REQUESTS_CLOSED = 'withdrawal_requests_closed';
    /** A vendor may not release an open request's reservation either. */
    public const CANCEL_CLOSED = 'withdrawal_cancel_closed';

    /**
     * The owner's decision, as a constant rather than a setting.
     *
     * A setting would be a second place the answer lives and a thing somebody
     * could flip without the decision changing. When the owner reopens
     * requests this constant changes in a build, with a delivery note, and
     * `VendorWithdrawalPolicyTest` is what notices if it changes by accident.
     */
    private const OPEN = false;

    public function requestsAreOpen(): bool
    {
        return self::OPEN;
    }

    /** Whether a vendor may cancel — the same decision, for the reason above. */
    public function cancelIsOpen(): bool
    {
        return self::OPEN;
    }
}
