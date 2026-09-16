<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * Where the rest of a migrated shop's past is kept: its staff, its balance
 * ledger and its withdrawal requests.
 *
 * **No update method, again, and for the same reason as the order history.**
 * These rows describe things that already happened under somebody else's
 * rules. The only honest verbs are «write it down», «read it back» and «undo
 * the import that wrote it». An `update()` would be an invitation to restate
 * a balance, which is the single thing this must never do.
 *
 * **Amounts are strings, in Dokan's own `DECIMAL(19,4)` form.** Not floats,
 * which round; not minor units, because converting is one step from
 * recomputing. A shop that earned under a 5% rate and now sits on 8% must
 * still see the figure Dokan recorded.
 */
interface ShopRecordRepositoryInterface
{
    public const RECORDED = 'recorded';
    public const ALREADY = 'already';
    public const FAILED = 'failed';

    /**
     * @param array{staff_user_id:int, vendor_user_id:int, display_name:string, user_email:string, dokan_role:string} $staff
     * @return self::RECORDED|self::ALREADY|self::FAILED
     */
    public function recordStaff(string $runId, array $staff): string;

    /**
     * @param array{trn_id:int, vendor_user_id:int, trn_type:string, particulars:string, debit:string, credit:string, status:string, trn_date:string} $row
     * @return self::RECORDED|self::ALREADY|self::FAILED
     */
    public function recordBalance(string $runId, array $row): string;

    /**
     * @param array{withdraw_id:int, vendor_user_id:int, amount:string, status:string, method:string, note:string, requested_at:string} $row
     * @return self::RECORDED|self::ALREADY|self::FAILED
     */
    public function recordWithdrawal(string $runId, array $row): string;

    /**
     * What a shop's imported past adds up to — summed in SQL over Dokan's own
     * decimals, so nothing is rounded on the way through PHP.
     *
     * @return array{staff:int, balance_rows:int, debit:string, credit:string, withdrawals:int, withdrawn:string}
     */
    public function summaryForVendor(int $vendorUserId): array;

    /** Undo one import's records, and only that import's. @return array{staff:int, balance:int, withdrawals:int} */
    public function deleteRun(string $runId): array;

    /** @return array{staff:int, balance:int, withdrawals:int} */
    public function countsForRun(string $runId): array;
}
