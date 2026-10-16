<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * AN UNKNOWN SHARE IS NOT A SHARE OF NOUGHT — one column, made nullable.
 *
 * `alpha.60` summed the vendor shares of a payment's items with
 * `(int) ($item->vendorShareMinor ?? 0)`, and `null` there is not «nothing to
 * add»: it is «this sale recorded no money», which happens for a real reason
 * (an unreadable money unit, a refused ledger write, a commission bigger than
 * the line — `incompleteCaptures()` lists them). Measured on the delivered
 * `alpha.60` bytes, with one item whose share was 900,000 and a second whose
 * share was `NULL`, and a payment of exactly 900,000:
 *
 *     code=supplier_payment_recorded  db_posting=posted  ledger_lines=2
 *     item_shares_stored=18:0|19:0
 *
 * The payment was declared to settle both items and a payout line went into
 * `tmc_ledger`, on the strength of a sum that silently left one item out.
 *
 * **Why a column had to move.** `share_minor` on the item table was written as
 * the literal `0` for every row, so it carried no information at all; from
 * this build it carries the share each item contributed, and `NULL` means «at
 * the moment this payment was written down, that item's share was not
 * recorded». That distinction has to be SNAPSHOTTED rather than re-derived:
 * a later repair can fill the share in, and a record that then stopped saying
 * «this was indeterminate when the money was handed over» would be rewriting
 * what a person actually decided — the same argument `SupplierPayment` already
 * makes for carrying the share beside the amount.
 *
 * Three alternatives were weighed and written down rather than left implied:
 *
 *  - **a sentinel in the existing NOT NULL column** — `-1` for unknown. The
 *    `alpha.28` rule forbids it: «ردیفی نبود» and «خالی بود» must not share a
 *    spelling, and a sentinel is a spelling nobody can see from the schema.
 *  - **recompute on read** — `awaitingDefinition()` could ask the order items
 *    again. That answers about today's rows, not about the payment, and is the
 *    `alpha.56` rule about deriving a number from the live tree.
 *  - **a new posting value alone**, with no per-item record. `posting` is
 *    `VARCHAR(32)` and the new value needs no migration — and it IS used —
 *    but it says «one of these items» without saying which, and
 *    «۱۲ قلم، یکی‌شان نامعلوم» is not something a person can act on.
 *
 * **No backfill, on purpose.** Rows written by `alpha.57`–`alpha.60` keep
 * their zeros, and this build does not guess what those zeros meant: that
 * build stored the same literal for «no share» and for «a share of nought»,
 * so the information is not there to recover. The improvement is per row from
 * here (the `alpha.29` posture about baselines), and the limitation is in the
 * delivery note rather than hidden behind a rewrite.
 *
 * **Rolling back to `alpha.60` or earlier needs no database work.** A nullable
 * column is read by the older build as `(int) null === 0`, which is what that
 * build wrote everywhere anyway; `M0029::verify()` asks for the column by
 * name and still finds it; and `MigrationRunner` never touches a structure
 * that is AHEAD of the build's own target, so a site left at 30 is not pulled
 * back to 29. The cost is stated in `docs/upgrade-and-rollback.md` §41.
 */
final class M0030SupplierPaymentShareUnknown implements MigrationInterface
{
    /**
     * The payment is recorded and NOT in the ledger because at least one
     * item's share was not known, so there was nothing to compare the amount
     * with.
     *
     * Distinct from `POSTING_NEEDS_DEFINITION`, which means both numbers were
     * known and DIFFERENT — a question for the owner («معنای اختلاف مبلغ و
     * سهم»). This one means the question could not even be asked, and the two
     * need different sentences on the screen and in any later report.
     *
     * `posting` is `VARCHAR(32)`, so this value itself needs no schema change.
     */
    public const POSTING_SHARE_UNKNOWN = 'share_unknown';

    public function version(): int
    {
        return 30;
    }

    public function id(): string
    {
        return '0030_supplier_payment_share_unknown';
    }

    public function up(DatabaseInterface $db): void
    {
        $items = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS);
        if (!$this->exists($db, $items)) {
            // Step 29 creates it. A chain that reaches here without it has a
            // bigger problem than a column type, and inventing the table from
            // this step would put its definition in two places.
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $items . ' is missing');
        }
        if ($this->isNullable($db, $items, 'share_minor') === true) {
            return;             // already done; the step is repeatable
        }
        $this->run($db, "ALTER TABLE `{$items}` MODIFY `share_minor` BIGINT NULL DEFAULT NULL");
    }

    /**
     * The GUARANTEE this step exists for: the column takes `NULL`.
     *
     * Not the exact column set and not the key names — `alpha.51` froze the
     * chain by matching a column set and `alpha.54` by matching a key name,
     * and both were paid for on a real site.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $items = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS);
        return $this->isNullable($db, $items, 'share_minor') === true;
    }

    /** `true`, `false`, or `null` when the question could not be answered. */
    private function isNullable(DatabaseInterface $db, string $table, string $column): ?bool
    {
        $answer = $db->getVar(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        );
        if ($answer === null) {
            return null;
        }
        return strtoupper((string) $answer) === 'YES';
    }

    private function exists(DatabaseInterface $db, string $table): bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        return $found !== null && (int) $found > 0;
    }

    /** `execute()` answers with a row count, and a successful DDL affects none (`alpha.8`). */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed: ' . $db->lastError() . ' — ' . $sql
            );
        }
    }
}
