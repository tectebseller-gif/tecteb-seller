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

    /** Sum per account, for one vendor. @return array<string,int> */
    public function balances(int $vendorUserId): array;
}
