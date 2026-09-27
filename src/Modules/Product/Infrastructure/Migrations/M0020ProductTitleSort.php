<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;

/**
 * A column so «عنوان (الفبایی فارسی)» can be a real ORDER BY.
 *
 * **Why the structure had to change.** The owner asked for four things at
 * once: Persian letters in Persian order — پ چ ژ گ included — ی/ي and ک/ك
 * treated as one letter, numbers read as numbers (۲ before ۱۰), and all of it
 * applied to the WHOLE result inside the query rather than to the twenty rows
 * already on the screen. No expression this plugin can write over the title
 * column does all four:
 *
 *  - ordering by the column is the codepoint order of the Arabic block, which
 *    is the Arabic alphabet: پ, چ, ژ and گ were added later and land after ی;
 *  - a collation that knows Persian is not something a plugin may assume is
 *    installed, and none of them make «۲» sort before «۱۰» anyway;
 *  - the REPLACE chain that would fold the letters cannot pad a digit run, so
 *    numbers stay wrong;
 *  - and doing it in PHP means reading every product of every shop on every
 *    page view, which is the thing the paging of `alpha.32` exists to avoid.
 *
 * So the reading of the title is computed once, when the title is written, and
 * stored next to it. `DbProductRepository::detailColumns()` maintains it for
 * every write — the form, the CSV import, a manager's correction, an approved
 * revision — and the index on `(title_sort, id)` makes the sort an index scan.
 *
 * **The backfill is the whole point of this migration**, and it is batched and
 * idempotent: it only touches rows whose key is still empty, so a run that is
 * interrupted resumes where it stopped, and a second run does nothing.
 *
 * **Rolling back to `alpha.32` needs no database work.** The column is
 * additive and nothing older reads it; the older code orders by `title` and
 * gets its old, wrong-for-Persian order back. Nothing else changes.
 */
final class M0020ProductTitleSort implements MigrationInterface
{
    public const COLUMN = 'title_sort';

    public const INDEX = 'tmc_product_title_sort';

    /** Rows per pass: enough to be quick, small enough to be resumable. */
    private const BATCH = 200;

    public function version(): int
    {
        return 20;
    }

    public function id(): string
    {
        return '0020_product_title_sort';
    }

    public function up(DatabaseInterface $db): void
    {
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);

        if (!$this->columnExists($db, $products, self::COLUMN)) {
            // `utf8mb4_bin` on purpose: the key encodes the order in ASCII, so
            // the comparison must be the bytes and nothing cleverer. A
            // case-insensitive collation would be harmless today and a trap the
            // day a token needed an uppercase letter.
            $this->run($db, "ALTER TABLE `{$products}`
                ADD COLUMN `" . self::COLUMN . "` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");
        }
        if (!$this->indexExists($db, $products, self::INDEX)) {
            $this->run($db, "ALTER TABLE `{$products}`
                ADD KEY `" . self::INDEX . "` (`" . self::COLUMN . "`, `id`)");
        }

        // Existing rows, in batches, by the same rule the repository will use
        // from now on. A row whose title is genuinely empty gets the key for
        // «no title» — which is a real key, so it is written once and not
        // looked at again.
        while (true) {
            $rows = $db->getResults(
                'SELECT id, title FROM `' . $products . '` WHERE `' . self::COLUMN . "` = '' ORDER BY id ASC LIMIT %d",
                [self::BATCH]
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $written = $db->execute(
                    'UPDATE `' . $products . '` SET `' . self::COLUMN . '` = %s WHERE id = %d',
                    [PersianCollation::sortKey((string) ($row['title'] ?? '')), (int) $row['id']]
                );
                if ($written === null) {
                    throw new MigrationException('title sort backfill failed: ' . $db->lastError());
                }
            }
        }
    }

    public function verify(DatabaseInterface $db): bool
    {
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);
        if (!$this->columnExists($db, $products, self::COLUMN) || !$this->indexExists($db, $products, self::INDEX)) {
            return false;
        }
        // Not «the column exists» but «every row has a key»: a backfill that
        // stopped half way leaves a column that passes a structural check and
        // a list that puts the rows it missed first.
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM `' . $products . '` WHERE `' . self::COLUMN . "` = ''"
        ) === 0;
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

    /** `execute()` answers NULL on failure; DDL affects zero rows, which is a success. */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('title sort migration failed: ' . $db->lastError());
        }
    }
}
