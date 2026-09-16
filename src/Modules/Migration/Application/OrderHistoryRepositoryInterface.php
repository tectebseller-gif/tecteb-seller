<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * Where a migrated shop's past sales are kept.
 *
 * **There is no update method, and that absence is the point.** A historical
 * record describes money that already moved under somebody else's rules; the
 * only honest operations are «write it down», «read it back» and «undo the
 * import that wrote it». An `update()` here would be an invitation to
 * recalculate, which is the one thing this table exists not to do.
 *
 * There is also no `total()` or `balance()`. Settlement and every report read
 * the ledger, and these rows are deliberately invisible to it — so a caller
 * that wanted to add them into a payable balance would have to write that sum
 * itself, in the open, rather than finding it ready-made here.
 */
interface OrderHistoryRepositoryInterface
{
    /**
     * Record one seller's share of one past order.
     *
     * Idempotent by the unique index rather than by a check: a resumed job
     * re-running its last page must produce one row, and «did we already
     * import this?» asked in PHP is a question two batches can both answer no.
     *
     * @param array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor:int, commission_minor:int, refunded:bool} $order
     * @return bool true when a row was written, false when it was already there
     */
    public function record(string $runId, array $order): bool;

    /** @return list<array<string,mixed>> newest order first */
    public function forVendor(int $vendorUserId, int $limit = 50, int $offset = 0): array;

    public function countForVendor(int $vendorUserId): int;

    /** @return array{orders:int, total_minor:int, net_minor:int} Dokan's own figures, summed */
    public function summaryForVendor(int $vendorUserId): array;

    /** Undo one import's history, and only that import's. */
    public function deleteRun(string $runId): int;

    public function countForRun(string $runId): int;
}
