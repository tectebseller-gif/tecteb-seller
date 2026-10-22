<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\LedgerEntry;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;

interface LedgerRepositoryInterface
{
    /**
     * Writes every line of the transaction, or none of them.
     *
     * Returns false when the event key has already been recorded — that is
     * the idempotency FIN-03 asks for, and it is enforced by a unique index
     * rather than by checking first, so two concurrent callbacks cannot both
     * pass the check and then both write.
     */
    public function record(LedgerTransaction $transaction): bool;

    public function hasEvent(string $eventKey): bool;

    /**
     * Every distinct unit of money this vendor's ledger already holds.
     *
     * Exists because `balances()` sums `amount_minor` grouped by ACCOUNT and
     * nothing else, while `Money::assertSameUnit()` refuses to combine two
     * units one layer down. Before `alpha.39` the two could not disagree: the
     * capture forced `'IRR', 0` on every row, so the books were uniform by
     * accident. Now the order's real unit is recorded, and «uniform» has to be
     * something the capture CHECKS rather than something it causes.
     *
     * A marketplace records in one unit. A second one appearing is a named
     * refusal at capture, not an average of two kinds of money.
     *
     * @return list<array{currency:string, exponent:int}>
     */
    public function unitsFor(int $vendorUserId): array;

    /**
     * Vendors whose books already hold more than one unit of money, with the
     * units named.
     *
     * The reporting half of the unit contract: `alpha.39`'s capture accepted a
     * line in ANY unit the books already held, so a ledger that became mixed
     * stayed mixed and nothing said so. `alpha.40` refuses new accruals for
     * such a vendor — and refusing without reporting would leave a manager with
     * a blocked order and no idea why. Nothing here converts anything:
     * «دادهٔ ناسازگار موجود بدون تبدیل حدسی گزارش شود».
     *
     * @return list<array{vendor_user_id:int, units:string, entries:int}>
     */
    public function vendorsWithMixedUnits(int $limit = 200): array;

    /** @return list<LedgerEntry> */
    public function forVendor(int $vendorUserId, int $limit = 100): array;

    /** @return list<LedgerEntry> */
    public function forEvent(string $eventKey): array;

    /**
     * Whether this marketplace's ledger already accounts for a WooCommerce
     * order.
     *
     * FIN-02 says one financial engine per order. That is only a rule if
     * something can ask the question, so this exists: the Dokan import asks it
     * before writing a historical record, and refuses rather than leaving an
     * order with two sets of figures and no way to tell which is authoritative.
     *
     * Asked by the event-key prefix `order:<id>:item:` — the key
     * `CaptureOrder::eventKey()` builds — so it is a prefix scan on the same
     * unique index the ledger already keeps.
     */
    public function coversOrder(int $wcOrderId): bool;

    /**
     * Sum per account, for one vendor — in ONE unit, which the answer names.
     *
     * ⛔ **Not `array<string,int>` any more, and the reason is a measured
     * number.** Until `alpha.68` this summed `amount_minor` grouped by
     * account alone, while every row of the ledger has carried `currency` and
     * `exponent` since migration 4. That was harmless only while a vendor's
     * books held one unit by accident; from `alpha.67` an exact commission is
     * recorded four decimal places finer than the order's own unit, so one
     * vendor legitimately holds two exponents and the old answer added ۱۰۰
     * تومان to ۸۸٫۳۷۵ تومان and said `883850` — neither figure, in no unit.
     * The exact answer is ۱۸۸٫۳۷۵.
     *
     * So each account answers with `minor` and the `exponent` it is in,
     * combined by a whole power of ten (exact, no rounding), plus the
     * per-unit `groups` behind it. Two CURRENCIES are not combined at all:
     * `refused` is `mixed_currencies`, `minor` is `null`, and `currencies`
     * names them — because the honest answer to «what is the total» across
     * two kinds of money is that there is not one.
     *
     * **Three refusals, and `exact` is `false` for every one of them.** A
     * refused answer never carries a `minor` that could be read as a total:
     *
     *   * `mixed_currencies` — two kinds of money in one account;
     *   * `scale_exceeds_integer_range` — rescaling one group to the finest
     *     exponent would leave the integer range;
     *   * `total_exceeds_integer_range` — one group's own `SUM()` is wider
     *     than a PHP integer, or the combined total would be. Both halves
     *     matter and `alpha.68` had neither: it cast the SUM text to `int`
     *     (which SATURATES rather than fails) and guarded only the
     *     multiplication, not the addition of the products.
     *
     * @return array<string, array{
     *   minor:int|null, currency:string, exponent:int|null,
     *   exact:bool, refused:string, currencies:string,
     *   groups:list<array{currency:string, exponent:int, minor:int|null, total:string}>
     * }>
     */
    public function balances(int $vendorUserId): array;
}
