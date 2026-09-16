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
    public const RECORDED = 'recorded';
    public const ALREADY = 'already';
    public const FAILED = 'failed';

    /**
     * Record one seller's share of one past order.
     *
     * Idempotent by the unique index rather than by a check: a resumed job
     * re-running its last page must produce one row, and «did we already
     * import this?» asked in PHP is a question two batches can both answer no.
     *
     * **Three answers, because there are three outcomes.** This returned a
     * bool until an evidence run reported «already imported» about six orders
     * the table did not contain: `INSERT IGNORE` counts zero rows for a
     * duplicate, `execute()` returns null for a failure, and one boolean made
     * those the same event. A migration that cannot tell «already done» from
     * «did not work» will report a shop as migrated that is not.
     *
     * @param array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor:int, commission_minor:int, refunded:bool} $order
     * @return self::RECORDED|self::ALREADY|self::FAILED
     */
    public function record(string $runId, array $order): string;

    /** @return list<array<string,mixed>> newest order first */
    public function forVendor(int $vendorUserId, int $limit = 50, int $offset = 0): array;

    public function countForVendor(int $vendorUserId): int;

    /** @return array{orders:int, total_minor:int, net_minor:int} Dokan's own figures, summed */
    public function summaryForVendor(int $vendorUserId): array;

    /** Undo one import's history, and only that import's. */
    public function deleteRun(string $runId): int;

    public function countForRun(string $runId): int;
}
