<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * WHAT WAS AGREED, WHAT WAS PAID OF IT, AND WHAT IS OWED BACK — four tables
 * and four columns, all additive, with NO backfill.
 *
 * The owner settled three things this step exists for, and each one is a fact
 * this schema had nowhere to put:
 *
 *  - **«مبلغ توافق‌شدهٔ خرید با تأمین‌کننده، سهم محاسبه‌شده نیست.»** Until now
 *    the only number a payment could be compared with was the share the
 *    commission model derived from the sale. The agreed purchase price is a
 *    different number, agreed by people, and it has to be recorded
 *    explicitly — and its changes have to have a history, which an
 *    overwritable column does not give.
 *  - **«پرداخت کمتر از تعهد، پرداخت جزئی با باقی‌ماندهٔ مانده است.»** One
 *    payment could settle an item and only one; there was no shape in which
 *    «۳ میلیون از ۵ میلیون» could be written down at all.
 *  - **«ثبت طلب، وصول واقعی و تهاتر سه رویداد متفاوت باشند.»** A return after
 *    the supplier has been paid produces a debt; collecting it and offsetting
 *    it against a future payment are two further, separate events, and one
 *    amount must not be collected twice or deducted twice.
 *
 * ### What is deliberately NOT changed
 *
 * **The guarantee «one claim per order item» stays, and it is not weakened
 * by one byte.** The owner's warning was explicit — «کلید یکتای «هر قلم فقط
 * یک پرداخت» را کورکورانه حذف نکن؛ حفاظت جایگزین برای مانده، تکرار و
 * هم‌زمانی باید همراه آن پیاده و آزموده شود» — so the guarantee MOVES rather
 * than going away, onto `live_item_id`: a nullable column carrying
 * `UNIQUE (live_item_id)`, which is the idiom `alpha.54` settled on for
 * exactly this problem (`tmc_jobs`, migration 13, and
 * `M0028MinMaxHandoverLiveKey`). One live claim per item, any number of
 * historical rows beside it.
 *
 * Why it had to move at all: `UNIQUE (order_item_id)` is held by a VOIDED
 * payment's claim row as firmly as by a live one, so with it in place §5's
 * «ثبت پرداخت اشتباه … و سپس ثبت صحیح» was impossible — the correct payment
 * collided with the record of the wrong one. The alternative was deleting the
 * claim rows of a voided payment, which «سابقه حذف نشود» forbids. The rows
 * stay, `live_item_id` becomes NULL when the payment is voided, and the item
 * is claimable again.
 *
 * What moves with it is the money: an amount now lives on an ALLOCATION
 * (`tmc_supplier_payment_allocations`), one row per payment per obligation,
 * so a second, third and fourth payment against the same obligation are
 * ordinary rows and the claim row is written once by whichever payment got
 * there first. The three protections the owner required are each a mechanism
 * rather than a sentence:
 *
 *  - **balance** — the obligation row is taken `FOR UPDATE` and the insert
 *    itself carries `paid_so_far + new <= agreed`, so an over-payment cannot
 *    be written even by a caller that skipped the check;
 *  - **repetition** — `UNIQUE (idempotency_key)` on the payment header, which
 *    is untouched, plus `UNIQUE (payment_id, obligation_id)` here so one
 *    payment cannot allocate to one obligation twice;
 *  - **concurrency** — the same obligation row lock, which two records of a
 *    partial payment must both pass through, in order;
 *  - **double settlement across the two paths** — `UNIQUE (live_item_id)`,
 *    which is what `DbWithdrawalRepository::reserve()` and the payment claim
 *    both arbitrate on, exactly as in `alpha.61`.
 *
 * ### Why an obligation is per ORDER ITEM
 *
 * Because every other thing in this module already is: the claim, the share,
 * the withdrawal reservation and the return all address an order item, and an
 * obligation addressed at anything else would need a mapping nobody has
 * defined. `agreed_minor` is NULLABLE and that is the point — «نامعلوم» and
 * «صفر» are different answers (`alpha.28`), and a payment against an
 * obligation whose amount was never agreed is recorded and left for review
 * rather than declared complete.
 *
 * ### No backfill, and what that costs
 *
 * Nothing is derived for the payments `alpha.57`–`alpha.61` wrote. Those rows
 * were recorded under the rule that a payment settles its items outright, and
 * inventing an obligation for them would be this build deciding what was
 * agreed — the one thing §3 forbids. They keep their meaning; obligations
 * start from the first one a manager records.
 *
 * ### Rolling back
 *
 * A build at 30 or earlier never reads the new tables or the new columns, so
 * the DATABASE needs no work to go back, and `M0029::verify()` accepts the
 * moved key in both spellings so the chain does not freeze. But the cost is
 * real and it is not nothing:
 *
 *  - **a partial payment reads as a full one.** The older build has no
 *    obligations and no allocations, so it reports an item as settled by the
 *    first payment that claimed it.
 *  - **a voided payment reads as live.** Nothing in that build asks
 *    `voided_at`, so a correction made here is invisible there.
 *  - **`reserve()` there tests `order_item_id`, not `live_item_id`**, so a
 *    claim row this build released by voiding still blocks a withdrawal.
 *    That is the conservative direction — it refuses rather than pays twice —
 *    and it is still a difference.
 *
 * Stated in `docs/upgrade-and-rollback.md` §42 rather than hidden.
 */
final class M0031SupplierObligations implements MigrationInterface
{
    /** The agreed purchase price for one order item. */
    public const OBLIGATIONS = 'tmc_supplier_obligations';
    /** Every change to an agreed amount, append-only. */
    public const OBLIGATION_HISTORY = 'tmc_supplier_obligation_history';
    /** How much of one payment went against one obligation. */
    public const ALLOCATIONS = 'tmc_supplier_payment_allocations';
    /** Money owed BACK by a supplier, after a return of something paid for. */
    public const RECEIVABLES = 'tmc_supplier_receivables';
    /** A collection or an offset against a receivable, append-only. */
    public const RECEIVABLE_SETTLEMENTS = 'tmc_supplier_receivable_settlements';

    /**
     * Recorded against an obligation, and the obligation is not yet covered.
     *
     * A fourth named posting state beside `posted`, `needs_definition` and
     * `share_unknown`, and a different fact from all three: the agreed amount
     * IS known, the payment is smaller than it, and that is not a problem to
     * be reviewed — it is a partial payment with a balance.
     */
    public const POSTING_PARTIAL = 'partially_paid';

    /**
     * Recorded, and there is no agreed amount to measure it against.
     *
     * Not `share_unknown`, which is about the COMPUTED share: this one says
     * the people have not written down what they agreed. «نامعلوم با صفرِ
     * معتبر یکی نیست» applies to both, separately.
     */
    public const POSTING_AGREED_UNKNOWN = 'agreed_unknown';

    /** A real collection of money from the supplier. */
    public const SETTLEMENT_COLLECTED = 'collected';
    /** An amount set against what Tecteb owes the supplier next. */
    public const SETTLEMENT_OFFSET = 'offset';

    public function version(): int
    {
        return 31;
    }

    public function id(): string
    {
        return '0031_supplier_obligations';
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();
        $payments = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENTS);
        if (!$this->exists($db, $payments)) {
            // Step 29 creates it, and inventing it here would put one table's
            // definition in two places (`alpha.10`).
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $payments . ' is missing');
        }

        $obligations = self::table($db, self::OBLIGATIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$obligations}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `supplier_user_id` BIGINT UNSIGNED NOT NULL,
            `agreed_minor` BIGINT NULL DEFAULT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `agreed_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_supplier_obligation_item` (`order_item_id`),
            KEY `tmc_supplier_obligation_of` (`supplier_user_id`)
        ) {$cc}");

        $history = self::table($db, self::OBLIGATION_HISTORY);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$history}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `obligation_id` BIGINT UNSIGNED NOT NULL,
            `previous_minor` BIGINT NULL DEFAULT NULL,
            `new_minor` BIGINT NULL DEFAULT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `reason` VARCHAR(255) NOT NULL DEFAULT '',
            `changed_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_supplier_obligation_story` (`obligation_id`, `id`)
        ) {$cc}");

        $allocations = self::table($db, self::ALLOCATIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$allocations}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payment_id` BIGINT UNSIGNED NOT NULL,
            `obligation_id` BIGINT UNSIGNED NOT NULL,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `supplier_user_id` BIGINT UNSIGNED NOT NULL,
            `amount_minor` BIGINT NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_supplier_allocation_once` (`payment_id`, `obligation_id`),
            KEY `tmc_supplier_allocation_of` (`obligation_id`)
        ) {$cc}");

        $receivables = self::table($db, self::RECEIVABLES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$receivables}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `supplier_user_id` BIGINT UNSIGNED NOT NULL,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `return_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `quantity` INT NOT NULL DEFAULT 0,
            `amount_minor` BIGINT NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `reason` VARCHAR(255) NOT NULL DEFAULT '',
            `recorded_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `event_key` VARCHAR(190) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_supplier_receivable_once` (`event_key`),
            KEY `tmc_supplier_receivable_of` (`supplier_user_id`, `order_item_id`)
        ) {$cc}");

        $settlements = self::table($db, self::RECEIVABLE_SETTLEMENTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$settlements}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `receivable_id` BIGINT UNSIGNED NOT NULL,
            `kind` VARCHAR(16) NOT NULL,
            `amount_minor` BIGINT NOT NULL,
            `payment_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `reference` VARCHAR(190) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `recorded_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `idempotency_key` VARCHAR(190) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_supplier_receivable_settled_once` (`idempotency_key`),
            KEY `tmc_supplier_receivable_settled_of` (`receivable_id`)
        ) {$cc}");

        // THE LIVE CLAIM KEY on the item table.
        //
        // Backfilled, and this one is derived rather than guessed: before this
        // build nothing could be voided, so every claim row that exists IS
        // live, and `live_item_id = order_item_id` is a restatement of the row
        // rather than an assumption about it. That is the difference between
        // this and the backfills this module refuses to do.
        $items = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS);
        if (!$this->exists($db, $items)) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $items . ' is missing');
        }
        if ($this->hasColumn($db, $items, 'live_item_id') !== true) {
            $this->run($db, "ALTER TABLE `{$items}` ADD COLUMN `live_item_id` BIGINT UNSIGNED NULL DEFAULT NULL");
            $this->run($db, "UPDATE `{$items}` SET `live_item_id` = `order_item_id` WHERE `live_item_id` IS NULL");
        }
        if (!$this->hasUniqueOver($db, $items, 'live_item_id')) {
            $this->run($db, "ALTER TABLE `{$items}` ADD UNIQUE KEY `tmc_supplier_payment_live` (`live_item_id`)");
        }
        // …and only THEN the old key goes, by the guarantee it carries rather
        // than by its name (`alpha.54`): a single-column unique over
        // `order_item_id`. Replaced by a plain index, because every lookup
        // that used it still happens.
        $stale = $this->uniqueNameOver($db, $items, 'order_item_id');
        if ($stale !== null) {
            $this->run($db, "ALTER TABLE `{$items}` DROP INDEX `{$stale}`");
            if (!$this->hasIndexOver($db, $items, 'order_item_id')) {
                $this->run($db, "ALTER TABLE `{$items}` ADD KEY `tmc_supplier_payment_item_of` (`order_item_id`)");
            }
        }

        // FOUR COLUMNS ON THE PAYMENT, for the void and the reconciliation.
        //
        // On the payment itself and not in a side table, because «ابطال ثبت»
        // is a state OF that document: a reader that has the payment row must
        // be able to see that it was voided without a join it might forget,
        // and `UPDATE … WHERE voided_at IS NULL` is then the whole of the
        // idempotency («درخواست تکراری ابطال نباید اثر مالی دوم بسازد»).
        //
        // `share_minor` is NOT overwritten by a reconciliation — the figure
        // recorded when the money changed hands stays where it is and the
        // later one is written beside it, so «snapshot اولیه پاک نشود» is a
        // property of the schema rather than a rule somebody has to remember.
        foreach ([
            'voided_at' => 'DATETIME NULL DEFAULT NULL',
            'voided_by' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'void_reason' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'void_event' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'share_minor_final' => 'BIGINT NULL DEFAULT NULL',
            'reconciled_at' => 'DATETIME NULL DEFAULT NULL',
            'reconciled_by' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        ] as $column => $definition) {
            if ($this->hasColumn($db, $payments, $column) === true) {
                continue;       // repeatable, like every other step here
            }
            $this->run($db, "ALTER TABLE `{$payments}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    /**
     * The GUARANTEES, not the column sets and not the key names.
     *
     * `alpha.51` froze the chain by matching an exact column set and
     * `alpha.54` by matching a unique key's name; both were paid for on a
     * real site, so this asks only for what the step promises: the tables
     * exist with the columns later code reads, the allocation table cannot
     * hold one payment against one obligation twice, and the payment table
     * has somewhere to record a void.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $payments = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENTS);
        if (!$this->hasColumns($db, $payments, [
            'voided_at', 'voided_by', 'void_reason', 'void_event',
            'share_minor_final', 'reconciled_at', 'reconciled_by',
        ])) {
            return false;
        }
        if (!$this->hasColumns($db, self::table($db, self::OBLIGATIONS), [
            'id', 'order_item_id', 'supplier_user_id', 'agreed_minor', 'currency', 'exponent',
        ])) {
            return false;
        }
        if (!$this->hasColumns($db, self::table($db, self::OBLIGATION_HISTORY), [
            'id', 'obligation_id', 'previous_minor', 'new_minor', 'changed_by', 'created_at',
        ])) {
            return false;
        }
        if (!$this->hasColumns($db, self::table($db, self::ALLOCATIONS), [
            'id', 'payment_id', 'obligation_id', 'order_item_id', 'amount_minor',
        ])) {
            return false;
        }
        if (!$this->hasColumns($db, self::table($db, self::RECEIVABLES), [
            'id', 'supplier_user_id', 'order_item_id', 'amount_minor', 'event_key',
        ])) {
            return false;
        }
        if (!$this->hasColumns($db, self::table($db, self::RECEIVABLE_SETTLEMENTS), [
            'id', 'receivable_id', 'kind', 'amount_minor', 'idempotency_key',
        ])) {
            return false;
        }
        $items = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS);
        if (!$this->hasUniqueOver($db, $items, 'live_item_id')) {
            return false;
        }
        return $this->hasUniquePair($db, self::table($db, self::ALLOCATIONS), 'payment_id', 'obligation_id')
            && $this->hasUniqueOver($db, self::table($db, self::OBLIGATIONS), 'order_item_id')
            && $this->hasUniqueOver($db, self::table($db, self::RECEIVABLES), 'event_key')
            && $this->hasUniqueOver($db, self::table($db, self::RECEIVABLE_SETTLEMENTS), 'idempotency_key');
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

    /** `true`, `false`, or `null` when the question could not be answered. */
    private function hasColumn(DatabaseInterface $db, string $table, string $column): ?bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        );
        return $found === null ? null : (int) $found > 0;
    }

    /** A single-column unique key over `$column`, whatever it is called. */
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

    /**
     * A two-column unique key over exactly these two, in this order.
     *
     * `COUNT(*)` on `STATISTICS` counts COLUMNS and not indexes (`alpha.39`),
     * so the width is asked separately rather than inferred.
     */
    private function hasUniquePair(DatabaseInterface $db, string $table, string $first, string $second): bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS s
             WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = %s
               AND s.NON_UNIQUE = 0 AND s.COLUMN_NAME = %s AND s.SEQ_IN_INDEX = 1
               AND EXISTS (SELECT 1 FROM information_schema.STATISTICS u
                     WHERE u.TABLE_SCHEMA = s.TABLE_SCHEMA AND u.TABLE_NAME = s.TABLE_NAME
                       AND u.INDEX_NAME = s.INDEX_NAME AND u.COLUMN_NAME = %s AND u.SEQ_IN_INDEX = 2)
               AND (SELECT COUNT(*) FROM information_schema.STATISTICS t
                     WHERE t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME
                       AND t.INDEX_NAME = s.INDEX_NAME) = 2',
            [$table, $first, $second]
        );
        return $found !== null && (int) $found > 0;
    }

    /** The NAME of a single-column unique key over `$column`, or null. */
    private function uniqueNameOver(DatabaseInterface $db, string $table, string $column): ?string
    {
        $name = $db->getVar(
            'SELECT s.INDEX_NAME FROM information_schema.STATISTICS s
             WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = %s
               AND s.NON_UNIQUE = 0 AND s.COLUMN_NAME = %s AND s.SEQ_IN_INDEX = 1
               AND s.INDEX_NAME <> %s
               AND (SELECT COUNT(*) FROM information_schema.STATISTICS t
                     WHERE t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME
                       AND t.INDEX_NAME = s.INDEX_NAME) = 1
             LIMIT 1',
            [$table, $column, 'PRIMARY']
        );
        $name = $name === null ? '' : (string) $name;
        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1 ? $name : null;
    }

    /** Any index at all whose first column is `$column`. */
    private function hasIndexOver(DatabaseInterface $db, string $table, string $column): bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND COLUMN_NAME = %s AND SEQ_IN_INDEX = 1',
            [$table, $column]
        );
        return $found !== null && (int) $found > 0;
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
