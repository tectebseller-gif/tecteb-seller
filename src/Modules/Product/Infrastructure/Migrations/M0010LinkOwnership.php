<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;

/**
 * One column: does this row OWN the WooCommerce product it points at, or does
 * it merely know about it?
 *
 * The default is `marketplace`, so every row that existed before this
 * migration keeps behaving exactly as it did — these are products this plugin
 * created and has always owned. Only the Dokan migration writes `observed`,
 * and only an explicit transfer changes it.
 *
 * NOT NULL with a default rather than nullable: "we never decided" is not a
 * state a purchase guard can act on, and a null here would have to be read as
 * one of the two anyway.
 */
final class M0010LinkOwnership implements MigrationInterface
{
    public const COLUMN = 'link_ownership';

    public function version(): int
    {
        return 10;
    }

    public function id(): string
    {
        return '0010_product_link_ownership';
    }

    public function up(DatabaseInterface $db): void
    {
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);
        if (!$this->columnExists($db, $products, self::COLUMN)) {
            $this->run($db, "ALTER TABLE `{$products}` ADD COLUMN `" . self::COLUMN . "`
                VARCHAR(16) NOT NULL DEFAULT '" . LinkOwnership::Marketplace->value . "'");
        }
        // Every purchase question filters on this column, so it is indexed
        // together with the link it qualifies — but `wc_product_id` arrives in
        // migration 6, and this one must not assume a partially built schema
        // has it. Without the pair the column still works; it is just slower.
        if ($this->columnExists($db, $products, 'wc_product_id')
            && !$this->indexExists($db, $products, 'tmc_product_ownership')) {
            $this->run($db, "ALTER TABLE `{$products}` ADD KEY `tmc_product_ownership` (`"
                . self::COLUMN . "`, `wc_product_id`)");
        }
    }

    public function verify(DatabaseInterface $db): bool
    {
        return $this->columnExists(
            $db,
            M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS),
            self::COLUMN
        );
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
            throw new MigrationException('link ownership migration failed: ' . $db->lastError());
        }
    }
}
