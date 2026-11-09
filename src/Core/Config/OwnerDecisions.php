<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config;

/**
 * THE OWNER'S ANSWERS, in one place, so no screen and no gate can describe
 * them differently.
 *
 * Until this release DEC-02, DEC-04 and FIN-04 were absences: names in two
 * `OPEN_DECISIONS` lists, which is how a gate says «somebody has not told me
 * yet». The owner has now answered all three, so the lists are empty and the
 * answers live here instead — with the code that enforces each one named
 * beside it, because §1 asks for exactly that: «صرف حذف شناسه‌ها از
 * `OPEN_DECISIONS` کافی نیست. باید ثابت شود قواعد انتخاب‌شده در محاسبات،
 * وضعیت‌ها، آزمون‌ها و گزارش‌ها اعمال می‌شوند.»
 *
 * ### Why a record rather than three edited docblocks
 *
 * One list, three readers (`alpha.78`). The order gate stops naming DEC-02;
 * the settlement gate stops naming FIN-04; the storefront screen has to tell
 * a manager what was decided rather than printing an empty cell where the
 * open decisions used to be. Three places that each remembered the answer
 * separately would drift the first time one of them was edited, and the
 * drift would be invisible — every screen would still read as if it knew.
 *
 * ### Decided is not the same as open
 *
 * **This is the distinction the screens now have to carry.** A gate that is
 * shut because nobody has decided says «awaiting X» and has a remedy: decide
 * X. A gate that is shut because the owner decided to keep it shut says so,
 * and its remedy is a different decision, not the same one. Settlement and
 * withdrawals are the second kind from this release: FIN-04 is answered, and
 * its answer is «automatic settlement stays closed in v1».
 *
 * ⛔ **And nothing here opens anything by itself.** These are sentences and
 * names. The enforcement is in the classes named against each answer, and
 * `OwnerDecisionsAreEnforcedTest` runs those classes rather than reading this
 * file — a constant that claims a rule is applied is not a rule being
 * applied (`alpha.92`).
 */
final class OwnerDecisions
{
    public const DEC_02 = 'DEC-02';
    public const DEC_04 = 'DEC-04';
    public const FIN_04 = 'FIN-04';

    /**
     * Each answered decision => the owner's rule, and where it is enforced.
     *
     * The Persian is the owner's own wording, shortened to one sentence a
     * manager reads on a screen; the `where` is the class a reviewer opens to
     * check the claim.
     *
     * @var array<string, array{answer:string, where:list<string>}>
     */
    public const DECIDED = [
        self::DEC_02 => [
            'answer' => 'تکمیل مؤثر هر قلم سفارش با تأیید دستی مدیر ثبت می‌شود؛ در سفارش چندفروشنده‌ای هر قلم مستقل بررسی می‌شود، و تغییر وضعیت سفارش مادر به‌تنهایی هیچ قلمی را قابل تسویه نمی‌کند.',
            'where' => [
                'Modules\\Order\\Application\\ManageOrderItems::recordSettlementCompletion',
                'Modules\\Finance\\Application\\VendorBalance',
            ],
        ],
        self::DEC_04 => [
            'answer' => 'مبنای کمیسیون پس از تخفیف و پیش از مالیات است؛ مالیات سند جداگانهٔ خودش را در دفترکل دارد؛ کوپن سراسری ساخته و اعمال نمی‌شود؛ و هیچ گردکردن تازه‌ای اضافه نمی‌شود.',
            'where' => [
                'Modules\\Finance\\Domain\\TectebCommissionCalculator',
                'Modules\\Marketplace\\Application\\ManageCoupons',
                'Modules\\Finance\\Application\\VendorFundedDiscount',
            ],
        ],
        self::FIN_04 => [
            'answer' => 'ثبت سفارش واقعی باز می‌شود، ولی تسویهٔ خودکار و درخواست برداشت فروشنده در این نسخه بسته می‌مانند — و بستن‌شان یک تصمیم است، نه یک تصمیمِ گرفته‌نشده.',
            'where' => [
                'Modules\\Finance\\Application\\SettlementGate',
                'Modules\\Finance\\Application\\VendorWithdrawalPolicy',
            ],
        ],
    ];

    /**
     * What the owner decided to keep shut, as reason codes a screen prints.
     *
     * Named so that «بسته است» never reads as «منتظر تصمیم است» again.
     *
     * @var list<string>
     */
    public const CLOSED_BY_DECISION = [
        'settlement_closed_by_owner',
        'withdrawal_requests_closed',
    ];

    /** @return list<string> every decision the owner has answered */
    public static function answered(): array
    {
        return array_keys(self::DECIDED);
    }

    /** The owner's rule for one decision, or '' when this is not one of them. */
    public static function answerFor(string $decision): string
    {
        return (string) (self::DECIDED[$decision]['answer'] ?? '');
    }
}
