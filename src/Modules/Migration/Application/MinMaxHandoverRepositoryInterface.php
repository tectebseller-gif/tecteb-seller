<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The handover ledger: what «Min Max Control» held for a product, what this
 * marketplace held BEFORE the migration touched it, and what was decided.
 *
 * **One LIVE decision per product, and every decision ever made kept.** The
 * claim is taken on a nullable `live_key` (migration 28) BEFORE the
 * destination is written, so two runs on one product are arbitrated by the
 * database and the loser never reaches the three meta rows — «UNIQUE KEY پس از
 * نوشتن مقصد به‌تنهایی کافی نیست». A rollback or an explicit retry sets
 * `live_key` to NULL, which leaves the row in history and lets the product be
 * claimed again: «سوابق را کورکورانه حذف نکن» and «چرخهٔ انتقال، بازگشت و
 * انتقال دوباره» are the same column.
 *
 * So a decision is written in two calls that belong to ONE transaction:
 * `claim()` with everything knowable before the write, then `finish()` with
 * what the write actually landed. A transaction that rolls back takes the
 * claim with it, which is why there is no orphan-`claiming` sweeper: an
 * unfinished attempt leaves nothing at all.
 */
interface MinMaxHandoverRepositoryInterface
{
    /**
     * Claimed and not yet finished. Never a decision, never counted as one,
     * and never visible outside its own transaction — a row in this state is
     * either about to become a decision or about to disappear.
     */
    public const CLAIMING = 'claiming';

    /** Migrated: the three rows were written and this plugin owns them. */
    public const MIGRATED = 'migrated';

    /** Refused: the rule has no equivalent here. Nothing was written. */
    public const REFUSED = 'refused';

    /** A rule the manager already owns at the destination. Nothing written. */
    public const HELD = 'held_by_manager';

    /**
     * Written, but onto a product the marketplace does not own — so nothing
     * enforces it. Recorded as its own state rather than as a success.
     */
    public const UNENFORCED = 'written_unenforced';

    /**
     * Written onto a product the marketplace does not own, AND the manager
     * has explicitly accepted that this plugin enforces that product's
     * purchase-quantity rule.
     *
     * The grant is about ONE rule and nothing else — not the vendor, not the
     * product's ownership, not its approval cycle, which all stay exactly as
     * they were. It is a status rather than a column because `status` is
     * `VARCHAR(24)` and every «which rows are live» query names its statuses
     * (the `alpha.51` rule), so a new value needs no migration.
     */
    public const ENFORCED = 'rule_enforced';

    /** Rolled back: the destination was put back to what it held before. */
    public const ROLLED_BACK = 'rolled_back';

    /**
     * Take the claim for one product, recording everything knowable before
     * the destination is touched.
     *
     * Called INSIDE the caller's transaction and before the first write. The
     * unique key on `live_key` is what refuses a second claim, so this is the
     * race's only arbiter.
     *
     * @param array<string,string|null> $row
     * @return array{claimed:bool,id:int,taken_by:string} `taken_by` is the
     *         status of the live row that refused the claim, or `''`
     */
    public function claim(array $row): array;

    /**
     * Turn a claim into a decision, with what the write actually landed.
     *
     * @param array<string,string|null> $written `written_min|max|step`
     * @return bool false when the claim is no longer `claiming` — a lost race,
     *              not «already done» (`alpha.49`)
     */
    public function finish(int $claimId, string $status, string $problems, array $written): bool;

    /** The LIVE decision for this product, or null when it is claimable. */
    public function find(int $postId): ?array;

    /**
     * Every decision ever made about this product, newest first — including
     * rolled-back and released rows, which is the whole point of keeping them.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $postId, int $limit = 20): array;

    /** @return array<string,int> status => count */
    public function tally(): array;

    /**
     * @param list<string> $statuses
     * @return list<array<string,mixed>>
     */
    public function withStatus(array $statuses, int $limit = 200, int $afterId = 0): array;

    /**
     * Mark the live row rolled back and release the product, in one statement.
     *
     * The status and the live key are both in the `WHERE`, so a rollback that
     * another request already performed affects zero rows — a lost race, said
     * so, not a second restore over an already-restored destination.
     */
    public function markRolledBack(int $postId): bool;

    /**
     * Record the manager's grant: this marketplace enforces this product's
     * quantity rule from now on.
     *
     * Only from `written_unenforced`, and the state is in the `WHERE` so zero
     * rows is a lost race rather than «it was already granted». A `migrated`
     * product needs no grant — the marketplace owns it, so its own guard
     * already reads the rule — and granting one would suggest otherwise.
     */
    public function markEnforced(int $postId): bool;

    /**
     * Withdraw the grant, leaving the three rows exactly where they are.
     *
     * The product goes back to `written_unenforced`: the rule is still
     * written, and nothing reads it again. A grant that cannot be withdrawn
     * is a decision the manager cannot revisit, and this one is about what
     * shoppers may buy.
     */
    public function markUnenforced(int $postId): bool;

    /**
     * Release a `refused` or `held_by_manager` row so the product can be
     * decided about again, keeping the row as history.
     *
     * The explicit path the instruction asks for: «تلاش دوبارهٔ محصولات held و
     * refused پس از رفع علت مسیر مشخص داشته باشد». It is a manager's action
     * with its own click, because the cause — a step rule with no equivalent,
     * or a destination value the manager owns — is removed by hand and nothing
     * here can tell that it has been.
     */
    public function release(int $postId): bool;
}
