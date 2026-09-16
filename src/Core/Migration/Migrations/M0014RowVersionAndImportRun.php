<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Two columns on `tmc_products`, each closing a hole that a comparison in PHP
 * could not.
 *
 * **`row_version` — because a check between a read and a write is a check two
 * requests can both pass.** `alpha.13` added a conflict check that compared the
 * form's `updated_at` against the stored one IN PHP, then wrote. Two members of
 * a shop editing the same product both read the same stamp, both found it
 * equal, and both wrote — the exact race the check existed to stop, moved one
 * step earlier. It is the same mistake `INSERT IGNORE` fixed for ratings and an
 * atomic `UPDATE … WHERE` fixed for job claims, made a third time.
 *
 * It also could not see a conflict INSIDE one second. `updated_at` is a
 * `DATETIME`, so two saves in the same second carry the same stamp and compare
 * equal — measurable on any shop where somebody double-submits a form.
 *
 * A counter has neither problem. It changes on every write whatever the clock
 * says, and the comparison moves into the WHERE clause where exactly one of two
 * concurrent writers can win. It is named `row_version` and not `revision`
 * because this plugin already has a «نسخهٔ پیشنهادی» — a proposed revision of a
 * published product — and two things called revision in one table is how a
 * later reader picks the wrong one.
 *
 * **`import_run_id` — because a manifest kept beside the data drifts from it.**
 * A Dokan import recorded what it created in one option, written after each
 * page. A process killed part-way through a page had made real rows that no run
 * claimed, so `rollback()` could not find them and a re-run did not know they
 * were there. Moving the write from «after both loops» to «after each page»
 * made the window smaller; it did not close it.
 *
 * Stamping the row is what closes it: the row that was created IS the record
 * that it was created, so there is no second write to lose. The option manifest
 * stays for the runs that predate this column — an upgrade must not orphan what
 * an earlier build imported — and `rollback()` now takes the union of both.
 */
final class M0014RowVersionAndImportRun implements MigrationInterface
{
    public const PRODUCTS = 'tmc_products';

    public function version(): int
    {
        return 14;
    }

    public function id(): string
    {
        return '0014_row_version_and_import_run';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $products = self::table($db, self::PRODUCTS);

        // Guarded individually: `ADD COLUMN` on a column that already exists is
        // an error, not a no-op, so a migration that is re-run — which this one
        // must survive, because the runner resumes — has to ask first.
        if (!$this->columnExists($db, $products, 'row_version')) {
            $this->run($db, "ALTER TABLE `{$products}` ADD COLUMN `row_version` INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if (!$this->columnExists($db, $products, 'import_run_id')) {
            $this->run($db, "ALTER TABLE `{$products}` ADD COLUMN `import_run_id` VARCHAR(64) NOT NULL DEFAULT ''");
        }
        if (!$this->indexExists($db, $products, 'tmc_product_import_run')) {
            // Rollback reads by run id and nothing else, so the index is the
            // difference between undoing an import and scanning the catalogue.
            $this->run($db, "ALTER TABLE `{$products}` ADD KEY `tmc_product_import_run` (`import_run_id`)");
        }
    }

    public function verify(DatabaseInterface $db): bool
    {
        $products = self::table($db, self::PRODUCTS);
        return $this->columnExists($db, $products, 'row_version')
            && $this->columnExists($db, $products, 'import_run_id')
            && $this->indexExists($db, $products, 'tmc_product_import_run');
    }

    private function columnExists(DatabaseInterface $db, string $table, string $column): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        ) > 0;
    }

    private function indexExists(DatabaseInterface $db, string $table, string $index): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [$table, $index]
        ) > 0;
    }

    /** `execute()` answers with a row count, and a successful DDL affects none. */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }
}
