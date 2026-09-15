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

    /** @return list<LedgerEntry> */
    public function forVendor(int $vendorUserId, int $limit = 100): array;

    /** @return list<LedgerEntry> */
    public function forEvent(string $eventKey): array;

    /** Sum per account, for one vendor. @return array<string,int> */
    public function balances(int $vendorUserId): array;
}
