<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Two things the review flow never had: a BASE, and a memory.
 *
 * ### The base
 *
 * A three-way merge needs three values — base, theirs, ours — and until now
 * there were two. The projection stamp records what the marketplace last
 * WROTE; nothing recorded what the vendor's next edit was based on. So every
 * difference between the record and the shop read as a possible conflict,
 * including the ordinary case where the manager edited a field the vendor
 * never touched: one vendor edit produced a question about every field.
 *
 * `approved_baseline` is that third value — the fields as they stood the last
 * time both sides demonstrably agreed (an approval, or an explicit decision).
 *
 * **It is added NULL and nothing backfills it.** A guessed base is worse than
 * none: it would license a write over text nobody has looked at. While it is
 * NULL the rules fall back to exactly what `alpha.28` did, and the first
 * approval or decision after the upgrade records it — so the improvement
 * arrives product by product, on evidence.
 *
 * ### The memory
 *
 * `review_note` is ONE column, overwritten by every decision. A manager who
 * asked for a correction, then changed their mind, then rejected, left one
 * sentence behind and no trail — and the vendor never saw any of it on the
 * page where they were meant to act on it.
 *
 * `tmc_product_decisions` is append-only. Nothing in this plugin updates or
 * deletes a row in it; «رد» and «ارسال مجدد» add lines, they do not remove
 * any. The old column stays exactly where it is, still written, so a rollback
 * to `alpha.28` finds what it expects.
 *
 * The one row this migration writes per product is a COPY of that column, not
 * an invention: the text and its product are both in the row already. The one
 * thing the old shape cannot tell us is WHEN the decision was made, so the
 * row carries the product's `updated_at` and is labelled `imported` — a date
 * that is honest about being derived rather than recorded.
 */
final class M0019BaselineAndDecisions implements MigrationInterface
{
    public const DECISIONS = 'product_decisions';
    public const BASELINE_COLUMN = 'approved_baseline';

    /** The marker on a row this migration derived rather than recorded. */
    public const IMPORTED = 'imported';

    public function version(): int
    {
        return 19;
    }

    public function id(): string
    {
        return '0019_product_baseline_and_decisions';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . 'tmc_' . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);
        if (!$this->columnExists($db, $products, self::BASELINE_COLUMN)) {
            $this->run($db, "ALTER TABLE `{$products}` ADD COLUMN `" . self::BASELINE_COLUMN . "` LONGTEXT NULL");
        }

        $cc = $db->charsetCollate();
        $decisions = self::table($db, self::DECISIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$decisions}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `decision` VARCHAR(32) NOT NULL,
            `note` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_decision_product` (`product_id`, `id`),
            KEY `tmc_decision_vendor` (`vendor_user_id`, `id`)
        ) {$cc}");

        // Idempotent by construction: it only ever inserts for a product that
        // has no row yet, so a resumed migration does not duplicate history.
        $this->run($db, "INSERT INTO `{$decisions}`
                (`product_id`, `vendor_user_id`, `actor_id`, `decision`, `note`, `created_at`)
            SELECT p.`id`, p.`vendor_user_id`, 0, '" . self::IMPORTED . "', p.`review_note`, p.`updated_at`
              FROM `{$products}` p
             WHERE p.`review_note` IS NOT NULL AND p.`review_note` <> ''
               AND NOT EXISTS (SELECT 1 FROM `{$decisions}` d WHERE d.`product_id` = p.`id`)");
    }

    public function verify(DatabaseInterface $db): bool
    {
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);
        if (!$this->columnExists($db, $products, self::BASELINE_COLUMN)) {
            return false;
        }
        $decisions = self::table($db, self::DECISIONS);
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$decisions]
        ) > 0;
    }

    private function columnExists(DatabaseInterface $db, string $table, string $column): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        ) > 0;
    }

    /** `execute()` answers NULL on failure; DDL affects zero rows, which is a success. */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('baseline/decisions migration failed: ' . $db->lastError());
        }
    }
}
