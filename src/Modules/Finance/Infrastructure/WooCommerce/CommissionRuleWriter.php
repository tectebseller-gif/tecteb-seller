<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;

/**
 * THE COMMISSION'S TYPE AND ITS AMOUNT, WRITTEN AS ONE THING OR NOT AT ALL.
 *
 * A commission is a pair. «درصدی» without a number is not half a rule and
 * «۲۰٬۰۰۰» without a kind is not the other half — each alone is a rule nobody
 * asked for. Until this round the panel wrote the two keys with two
 * `update_post_meta()` calls and compared afterwards, which is exactly the
 * shape that produced the review's first finding. Measured on the delivered
 * `alpha.60` bytes, with a previous rule of `percent/12.50`, a request for
 * `fixed/20000` and the VALUE write refused:
 *
 *     db_type=fixed  db_value=12.50  rule=fixed/12.50
 *     notice=«کمیسیون تک‌طب ذخیره نشد. مقدار قبلی سر جایش است»
 *
 * The stored rule was «۱۲٫۵۰ به ازای هر عدد» — neither the old rule nor the
 * new one — and the sentence the manager read was false. The other direction
 * was worse: with the TYPE write refused the pair became `percent/20000`,
 * which `TectebCommission` reads as **no commission at all**, so a 12.5%
 * commission disappeared under a message saying it was still there.
 *
 * **What makes it one unit of work.** WordPress has no transaction of its own
 * for post meta, but `update_post_meta()` runs on `$wpdb` — the same
 * connection this gateway holds — so a real `START TRANSACTION` around the two
 * calls does cover them both, and a `ROLLBACK` really does put the rows back.
 * Three consequences, each of them a rule this repository has already paid
 * for:
 *
 *  1. **The writes still go through `update_post_meta()`**, so
 *     `TectebCommissionMetaGuard`'s five filters still run. A writer that
 *     wrote the rows itself would be the one door the guard is not on — the
 *     whole finding of `alpha.58`.
 *  2. **Verification reads the ROWS, never the cache.** `get_post_meta()`
 *     answers from the object cache (`alpha.55`), and after a rollback that
 *     cache still holds the value the rollback threw away — so reading it
 *     would confirm a write that no longer exists. The cache is therefore
 *     purged after any rollback and every check here is a statement on the
 *     locked connection.
 *  3. **The rows are locked before they are read.** Two managers saving the
 *     same product at the same moment would otherwise be able to interleave
 *     one request's type with another's amount; with the pair locked, the
 *     second save waits and then writes its own pair whole.
 *
 * **It never says more than it measured.** `RESTORED` is returned only after
 * re-reading the rows and finding the previous pair back; when they are
 * neither the old pair nor the new one the answer is `UNRESTORED` and carries
 * what is actually stored, because «قبلی حفظ شد» is a sentence that has to be
 * earned.
 *
 * And «no row» is kept apart from «a row saying no commission»: the first is a
 * product nobody has decided about, the second is a decision. Both read as
 * «بدون کمیسیون» when the money is computed — `TectebCommission` says so — but
 * they are different things to tell a manager, and a restore that turned the
 * first into the second would invent a decision.
 */
final class CommissionRuleWriter
{
    /** Both rows carry the requested pair and the transaction committed. */
    public const SAVED = 'saved';
    /**
     * Nothing was written because the write could not be made atomic.
     *
     * Either there is no gateway, or one is already inside somebody else's
     * transaction — and in the second case a commit here is a no-op whose
     * outer unit of work can still roll back, so «saved» would be a guess.
     * The same posture as `supplier_payment_not_atomic`: a build that cannot
     * make this one operation does not get to do half of it.
     */
    public const NOT_ATOMIC = 'not_atomic';
    /** A statement failed. Nothing is claimed about what is stored. */
    public const STORAGE_FAILED = 'storage_failed';
    /**
     * One of the keys has more than one row.
     *
     * `wp_postmeta` has NO unique index on `(post_id, meta_key)` — measured on
     * WordPress 7.1 in `alpha.55`, where our own comment claimed otherwise —
     * and `update_post_meta()` overwrites every row of a key. A pair that is
     * already duplicated cannot be compared or restored, so it is named.
     */
    public const ROWS_DUPLICATED = 'rows_duplicated';
    /** The write did not land and the previous pair is back — re-read, not assumed. */
    public const RESTORED = 'not_stored_restored';
    /** The write did not land and what is stored is neither pair. Never claimed restored. */
    public const UNRESTORED = 'not_stored_unrestored';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return array{
     *   outcome:string, previous_type:string, previous_value:string,
     *   stored_type:string, stored_value:string, rolled_back:bool
     * } `__absent__` where a key has no row at all.
     */
    public function write(int $postId, TectebCommission $rule): array
    {
        $answer = static fn (string $outcome, array $before = [], array $now = [], bool $rolledBack = false): array => [
            'outcome' => $outcome,
            'previous_type' => $before['type'] ?? '__unknown__',
            'previous_value' => $before['value'] ?? '__unknown__',
            'stored_type' => $now['type'] ?? '__unknown__',
            'stored_value' => $now['value'] ?? '__unknown__',
            'rolled_back' => $rolledBack,
        ];

        if ($postId <= 0) {
            return $answer(self::NOT_ATOMIC);
        }
        if ($this->db->inTransaction()) {
            return $answer(self::NOT_ATOMIC);
        }
        if (!$this->db->begin()) {
            return $answer(self::STORAGE_FAILED);
        }

        // THE LOCK FIRST, and with `execute()` rather than a read: a read
        // answers `null` both for «no row» and for «the statement failed», and
        // a lock that was never taken but read as taken is the `alpha.54`
        // defect exactly. `execute()` separates them — `null` is a failure and
        // a number is a row count, zero included.
        $locked = $this->db->execute(
            'SELECT meta_id FROM `' . $this->table() . '`
              WHERE post_id = %d AND meta_key IN (%s, %s) FOR UPDATE',
            [$postId, WpTectebCommissionSource::TYPE_META, WpTectebCommissionSource::VALUE_META]
        );
        if ($locked === null) {
            $this->db->rollback();
            return $answer(self::STORAGE_FAILED);
        }

        $before = $this->pair($postId);
        if ($before === null) {
            $this->db->rollback();
            return $answer(self::STORAGE_FAILED);
        }
        if ($before['type_rows'] > 1 || $before['value_rows'] > 1) {
            $this->db->rollback();
            return $answer(self::ROWS_DUPLICATED, $before, $before, true);
        }

        // Through WordPress, so every guard on the meta write still speaks.
        update_post_meta($postId, WpTectebCommissionSource::TYPE_META, $rule->type);
        update_post_meta($postId, WpTectebCommissionSource::VALUE_META, $rule->displayValue());

        $now = $this->pair($postId);
        if ($now === null) {
            $this->db->rollback();
            $this->purge($postId);
            return $answer(self::STORAGE_FAILED, $before);
        }
        if ($now['type'] === $rule->type && $now['value'] === $rule->displayValue()) {
            if ($this->db->commit()) {
                return $answer(self::SAVED, $before, $now);
            }
            // A commit can fail, and a caller told «saved» after it did would
            // be describing nothing (`alpha.39`). What is on disk is then
            // whatever the rollback left, so it is re-read rather than named.
            $this->db->rollback();
            $this->purge($postId);
            return $this->afterRollback($postId, $before, $rule, true);
        }

        $rolledBack = $this->db->rollback();
        $this->purge($postId);
        return $this->afterRollback($postId, $before, $rule, $rolledBack);
    }

    /**
     * What is stored once the unit of work has been abandoned — read, not
     * assumed, because `rollback()` itself can fail and a failed rollback
     * leaves the half-write exactly where it was.
     *
     * @param array{type:string,value:string,type_rows:int,value_rows:int} $before
     */
    private function afterRollback(int $postId, array $before, TectebCommission $rule, bool $rolledBack): array
    {
        $now = $this->pair($postId);
        if ($now === null) {
            return [
                'outcome' => self::STORAGE_FAILED,
                'previous_type' => $before['type'],
                'previous_value' => $before['value'],
                'stored_type' => '__unknown__',
                'stored_value' => '__unknown__',
                'rolled_back' => $rolledBack,
            ];
        }
        $same = $now['type'] === $before['type'] && $now['value'] === $before['value'];
        return [
            'outcome' => $same ? self::RESTORED : self::UNRESTORED,
            'previous_type' => $before['type'],
            'previous_value' => $before['value'],
            'stored_type' => $now['type'],
            'stored_value' => $now['value'],
            'rolled_back' => $rolledBack,
        ];
    }

    /**
     * The two rows as they are on disk: value and row count for each key.
     *
     * One aggregate statement per key, because an aggregate with no `GROUP BY`
     * always returns a row — so `null` here can only mean the statement
     * failed, and «no such row» arrives as a count of nought. That is the
     * distinction `alpha.49` had to buy twice.
     *
     * @return array{type:string,value:string,type_rows:int,value_rows:int}|null
     */
    private function pair(int $postId): ?array
    {
        $type = $this->one($postId, WpTectebCommissionSource::TYPE_META);
        $value = $this->one($postId, WpTectebCommissionSource::VALUE_META);
        if ($type === null || $value === null) {
            return null;
        }
        return [
            'type' => $type['value'],
            'value' => $value['value'],
            'type_rows' => $type['rows'],
            'value_rows' => $value['rows'],
        ];
    }

    /** @return array{value:string,rows:int}|null */
    private function one(int $postId, string $metaKey): ?array
    {
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS n, MIN(meta_value) AS v FROM `' . $this->table() . '`
              WHERE post_id = %d AND meta_key = %s',
            [$postId, $metaKey]
        );
        if ($row === null) {
            return null;
        }
        $rows = (int) ($row['n'] ?? 0);
        return [
            // ABSENT is not EMPTY: a product nobody has decided about is not a
            // product somebody set to «بدون کمیسیون» (`alpha.28`).
            'value' => $rows === 0 ? '__absent__' : (string) ($row['v'] ?? ''),
            'rows' => $rows,
        ];
    }

    /**
     * The object cache for this post's meta, dropped after a rollback.
     *
     * `update_post_meta()` updated it before the transaction was abandoned, so
     * leaving it would make the next `get_post_meta()` — the panel's own
     * render, and every reader of the rule — answer with a value the database
     * no longer has.
     */
    private function purge(int $postId): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($postId, 'post_meta');
        }
    }

    private function table(): string
    {
        return $this->db->prefix() . 'postmeta';
    }
}
