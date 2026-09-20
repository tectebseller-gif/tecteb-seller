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

    /**
     * The staff an import recorded for one shop — the RECORD, not a grant.
     *
     * A row here says «Dokan had this person in this role». It says nothing
     * about what they may do in this marketplace, and reading it must never
     * be mistaken for reading a permission: `StaffRepositoryInterface` is the
     * only place an actual permission lives.
     *
     * @return list<array{staff_user_id:int, vendor_user_id:int, display_name:string, user_email:string, dokan_role:string}>
     */
    public function staffForVendor(int $vendorUserId): array;

    /**
     * A shop's imported withdrawals, grouped by the status DOKAN gave them.
     *
     * Grouped rather than summed flat because the statuses mean different
     * things about money: an approved request is money that ALREADY LEFT, and
     * a pending one is a request nobody has paid. A single total would add
     * those together and produce a figure that is true of nothing.
     *
     * @return array<string, array{count:int, total:string}> dokan status => tally
     */
    public function withdrawalsByStatus(int $vendorUserId): array;

    /**
     * A shop's imported balance ledger, split by the transaction type DOKAN
     * gave each row.
     *
     * The split is what makes double counting visible instead of theoretical.
     * Dokan books an approved withdrawal twice by design — once as a row in
     * `dokan_withdraw` and once as a DEBIT in `dokan_vendor_balance` — so the
     * closing balance has already subtracted it. A report that showed
     * «closing balance» beside «total withdrawn» and let a reader add them
     * would overstate what is owed by exactly the amount already paid.
     *
     * @return array<string, array{count:int, debit:string, credit:string}>
     */
    public function balanceByType(int $vendorUserId): array;

    /**
     * Credit minus debit, summed in SQL over `DECIMAL(19,4)`.
     *
     * In SQL on purpose. PHP has no exact decimal type without an extension,
     * and the one thing this figure must never be is «about right»: it is
     * somebody's money as another system recorded it. MySQL subtracts two
     * exact decimals exactly, and the answer comes back as a string.
     *
     * **This is Dokan's own arithmetic, not a restatement.** No rate from
     * this marketplace touches it.
     */
    public function closingBalance(int $vendorUserId): string;

    /** Every shop that has any imported record at all. @return list<int> */
    public function vendorsWithRecords(): array;

    /** Undo one import's records, and only that import's. @return array{staff:int, balance:int, withdrawals:int} */
    public function deleteRun(string $runId): array;

    /** @return array{staff:int, balance:int, withdrawals:int} */
    public function countsForRun(string $runId): array;
}
