<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * A RECORD OF MONEY TECTEB HAS ALREADY PAID A SUPPLIER — and nothing else.
 *
 * **Why a table was unavoidable, which is the only reason this round changes
 * the data structure.** The obvious place to put this was
 * `tmc_withdrawals`: it already holds an amount, a date, a reviewer, a
 * reference, a bank account and a per-order-item claim, and reusing it would
 * have cost nothing. The owner's §4 forbids exactly that — «نام‌های مشابه
 * نباید باعث شود چرخهٔ برداشت فروشنده برای خرید نقدی از تأمین‌کننده استفاده
 * شود» — and the forbidding is correct, because the two are different acts:
 *
 *  * a **withdrawal** is a vendor asking for money the marketplace owes them
 *    out of recorded sales. The amount is derived from the ledger, the vendor
 *    starts it, and the manager decides;
 *  * a **supplier payment** is Tecteb buying stock. The amount is whatever
 *    Tecteb agreed to pay, the manager states it, nobody requests it, and it
 *    may be nothing like the vendor share the commission model computed.
 *
 * Putting the second in the first table would make every balance, every
 * «قابل درخواست» figure and every stranded-reservation report answer a
 * question about a mixture of the two. The alternatives were weighed:
 *
 *  - **a status on `tmc_withdrawals`** — the open-request unique key is per
 *    vendor, so one recorded purchase would block the vendor's own request;
 *  - **ledger lines only, with no record** — a payment with no date, no
 *    reference, no recorder and no receipt is unauditable, and §6 names all
 *    four as required;
 *  - **a second general ledger** — forbidden outright («دفتر موازی نساز»),
 *    and not needed: the money line, when there is one, goes into
 *    `tmc_ledger` exactly like every other.
 *
 * **It records; it never transfers.** There is no gateway, no transfer call
 * and no place to put one: the row says a payment happened, and a person is
 * the one who made it happen.
 *
 * **One payment per order item, enforced by a unique key.** That is what makes
 * a refreshed page or a resubmitted form unable to create a second payment —
 * «به‌روزرسانی صفحه یا ارسال دوبارهٔ درخواست نباید پرداخت دوم بسازد» — and it
 * is deliberately stricter than it has to be: partial payments against one
 * item are not supported, a second payment naming a paid item is refused by
 * name, and whether they are needed is the owner's to say.
 *
 * **Rolling back to `alpha.56` or earlier needs no database work.** Both
 * tables are additive and nothing older reads them; they simply sit there. The
 * cost is stated in `docs/upgrade-and-rollback.md` §40 and it is the loss of
 * the screen, not of the rows.
 */
final class M0029SupplierPayments implements MigrationInterface
{
    public const PAYMENTS = 'tmc_supplier_payments';
    public const PAYMENT_ITEMS = 'tmc_supplier_payment_items';

    /** The money line was written into `tmc_ledger` and the two agree. */
    public const POSTING_POSTED = 'posted';
    /**
     * The payment is recorded and NOT in the ledger, because posting it would
     * state something nobody has defined.
     *
     * This is the conflict §6 asks to be identified rather than assumed. A
     * payout line against a supplier's earnings says «this settles what they
     * were owed»; a cash purchase price that differs from the computed share
     * says something else, and which of the two the money means is a business
     * definition the owner has not given. So the row waits, visibly, with both
     * numbers on it.
     */
    public const POSTING_NEEDS_DEFINITION = 'needs_definition';

    public function version(): int
    {
        return 29;
    }

    public function id(): string
    {
        return '0029_supplier_payments';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();
        $payments = self::table($db, self::PAYMENTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$payments}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `supplier_user_id` BIGINT UNSIGNED NOT NULL,
            `amount_minor` BIGINT NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `share_minor` BIGINT NOT NULL DEFAULT 0,
            `paid_on` DATE NOT NULL,
            `reference` VARCHAR(190) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `receipt_path` VARCHAR(255) NOT NULL DEFAULT '',
            `recorded_by` BIGINT UNSIGNED NOT NULL,
            `idempotency_key` VARCHAR(190) NOT NULL,
            `ledger_event` VARCHAR(190) NOT NULL DEFAULT '',
            `posting` VARCHAR(32) NOT NULL DEFAULT 'needs_definition',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_supplier_payment_once` (`idempotency_key`),
            KEY `tmc_supplier_payment_of` (`supplier_user_id`, `paid_on`)
        ) {$cc}");

        $items = self::table($db, self::PAYMENT_ITEMS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$items}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payment_id` BIGINT UNSIGNED NOT NULL,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `supplier_user_id` BIGINT UNSIGNED NOT NULL,
            `share_minor` BIGINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_supplier_payment_item` (`order_item_id`),
            KEY `tmc_supplier_payment_lines` (`payment_id`)
        ) {$cc}");
    }

    /**
     * The tables, their columns and the two unique keys — by SHAPE, and by
     * GUARANTEE rather than by name where a name would freeze the chain.
     *
     * `alpha.51` paid for an exact column-set match (step 21 locked the chain
     * on 20 the moment a column was added) and `alpha.54` paid for matching a
     * unique key by name. So: the needed columns must be PRESENT, extras are
     * fine, and each table must carry a unique key over the one column that
     * makes its promise — `idempotency_key` here, `order_item_id` there —
     * whatever that key is called.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $payments = self::table($db, self::PAYMENTS);
        $items = self::table($db, self::PAYMENT_ITEMS);

        if (!$this->hasColumns($db, $payments, [
            'id', 'supplier_user_id', 'amount_minor', 'currency', 'exponent', 'share_minor',
            'paid_on', 'reference', 'note', 'receipt_path', 'recorded_by',
            'idempotency_key', 'ledger_event', 'posting', 'created_at',
        ])) {
            return false;
        }
        if (!$this->hasColumns($db, $items, [
            'id', 'payment_id', 'order_item_id', 'supplier_user_id', 'share_minor', 'created_at',
        ])) {
            return false;
        }
        return $this->hasUniqueOver($db, $payments, 'idempotency_key')
            && $this->hasUniqueOver($db, $items, 'order_item_id');
    }

    /** @param list<string> $needed */
    private function hasColumns(DatabaseInterface $db, string $table, array $needed): bool
    {
        $columns = $db->getVar(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($columns === null || (string) $columns === '') {
            return false;
        }
        $present = array_filter(explode(',', (string) $columns), static fn (string $c): bool => $c !== '');
        return array_diff($needed, $present) === [];
    }

    /**
     * A single-column unique key over `$column`, whatever it is called and
     * however many other unique keys the table has.
     *
     * `SEQ_IN_INDEX = 1` with `COUNT(*) = 1` per index is how «one column»
     * is asked: `COUNT(*)` on `STATISTICS` counts COLUMNS, not indexes
     * (`alpha.39`), so a two-column key containing this one would otherwise
     * pass and promise something it does not give.
     */
    private function hasUniqueOver(DatabaseInterface $db, string $table, string $column): bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS s
             WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = %s
               AND s.NON_UNIQUE = 0 AND s.COLUMN_NAME = %s AND s.SEQ_IN_INDEX = 1
               AND (SELECT COUNT(*) FROM information_schema.STATISTICS t
                     WHERE t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME
                       AND t.INDEX_NAME = s.INDEX_NAME) = 1',
            [$table, $column]
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
