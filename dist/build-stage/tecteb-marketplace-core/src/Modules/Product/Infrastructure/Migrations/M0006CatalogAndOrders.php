<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * The link to WooCommerce, the manager's SEO fields, variations, and the
 * marketplace's own record of what was sold.
 *
 * Two shapes of change live here, and they behave differently:
 *
 *  - NEW TABLES use `CREATE TABLE IF NOT EXISTS`, which is idempotent by
 *    itself.
 *  - NEW COLUMNS on an existing table are checked against
 *    `information_schema` first. `ADD COLUMN IF NOT EXISTS` is MariaDB-only
 *    and this plugin has to run on MySQL 8 too, so the existence question is
 *    asked in a way both answer (ADR-003: a step may be re-run after a crash,
 *    so it must survive being re-run).
 *
 * `wc_product_id` is the ONE place the WooCommerce link is stored on this
 * side, and it is unique: two marketplace rows pointing at one product post
 * would be the duplicate this whole design exists to prevent (ADR-008).
 */
final class M0006CatalogAndOrders implements MigrationInterface
{
    public const ATTRIBUTES = 'tmc_product_attributes';
    public const VARIATIONS = 'tmc_product_variations';
    public const ORDER_ITEMS = 'tmc_order_items';

    /** @var list<string> */
    public const TABLES = [self::ATTRIBUTES, self::VARIATIONS, self::ORDER_ITEMS];

    /** column => definition, added to tmc_products when missing */
    private const PRODUCT_COLUMNS = [
        'wc_product_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'seo_slug' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'seo_title' => "VARCHAR(190) NOT NULL DEFAULT ''",
        'seo_description' => 'TEXT NULL',
        'synced_at' => 'DATETIME NULL',
    ];

    public function version(): int
    {
        return 6;
    }

    public function id(): string
    {
        return '0006_catalog_link_variations_and_orders';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);

        foreach (self::PRODUCT_COLUMNS as $column => $definition) {
            if ($this->columnExists($db, $products, $column)) {
                continue;
            }
            $this->run($db, "ALTER TABLE `{$products}` ADD COLUMN `{$column}` {$definition}");
        }
        // Unique, but only over the rows that HAVE a link: MySQL and MariaDB
        // both allow repeated NULLs in a unique index, and 0 is not NULL — so
        // the zero default would collide on the second unlinked product. The
        // index is therefore added over a column that stores NULL for "not
        // linked"; the default above keeps old rows readable and the
        // repository writes NULL from now on.
        if ($this->columnDefaultsToZero($db, $products)) {
            $this->run($db, "ALTER TABLE `{$products}` MODIFY `wc_product_id` BIGINT UNSIGNED NULL DEFAULT NULL");
            $this->run($db, "UPDATE `{$products}` SET wc_product_id = NULL WHERE wc_product_id = 0");
        }
        if (!$this->indexExists($db, $products, 'tmc_product_wc')) {
            $this->run($db, "ALTER TABLE `{$products}` ADD UNIQUE KEY `tmc_product_wc` (`wc_product_id`)");
        }

        $attributes = self::table($db, self::ATTRIBUTES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$attributes}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `attr_key` VARCHAR(64) NOT NULL,
            `label` VARCHAR(190) NOT NULL,
            `options` TEXT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_attr_key` (`product_id`, `attr_key`)
        ) {$cc}");

        $variations = self::table($db, self::VARIATIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$variations}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `combination` VARCHAR(255) NOT NULL,
            `attributes` TEXT NULL,
            `sku` VARCHAR(64) NOT NULL DEFAULT '',
            `price_minor` BIGINT NOT NULL DEFAULT 0,
            `sale_price_minor` BIGINT NULL,
            `stock` INT NOT NULL DEFAULT 0,
            `media_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `wc_variation_id` BIGINT UNSIGNED NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_variation_combo` (`product_id`, `combination`),
            KEY `tmc_variation_wc` (`wc_variation_id`)
        ) {$cc}");

        $orderItems = self::table($db, self::ORDER_ITEMS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$orderItems}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wc_order_id` BIGINT UNSIGNED NOT NULL,
            `wc_order_item_id` BIGINT UNSIGNED NOT NULL,
            `wc_product_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variation_id` BIGINT UNSIGNED NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL DEFAULT '',
            `sku` VARCHAR(64) NOT NULL DEFAULT '',
            `quantity` INT NOT NULL DEFAULT 0,
            `unit_price_minor` BIGINT NOT NULL DEFAULT 0,
            `base_minor` BIGINT NOT NULL DEFAULT 0,
            `tax_minor` BIGINT NOT NULL DEFAULT 0,
            `commission_minor` BIGINT NULL,
            `vendor_share_minor` BIGINT NULL,
            `rate_bp` INT UNSIGNED NULL,
            `rate_source` VARCHAR(16) NOT NULL DEFAULT '',
            `ledger_event` VARCHAR(190) NOT NULL DEFAULT '',
            `status` VARCHAR(24) NOT NULL DEFAULT 'placed',
            `carrier` VARCHAR(64) NOT NULL DEFAULT '',
            `tracking_code` VARCHAR(96) NOT NULL DEFAULT '',
            `shipped_at` DATETIME NULL,
            `note` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_order_item_once` (`wc_order_item_id`),
            KEY `tmc_order_vendor` (`vendor_user_id`, `status`),
            KEY `tmc_order_ref` (`wc_order_id`)
        ) {$cc}");
    }

    public function verify(DatabaseInterface $db): bool
    {
        foreach (self::TABLES as $suffix) {
            $table = self::table($db, $suffix);
            if ((string) $db->getVar('SHOW TABLES LIKE %s', [$table]) !== $table) {
                return false;
            }
        }
        $products = M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS);
        foreach (array_keys(self::PRODUCT_COLUMNS) as $column) {
            if (!$this->columnExists($db, $products, $column)) {
                return false;
            }
        }
        return true;
    }

    private function columnExists(DatabaseInterface $db, string $table, string $column): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        ) > 0;
    }

    private function columnDefaultsToZero(DatabaseInterface $db, string $table): bool
    {
        $nullable = (string) $db->getVar(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, 'wc_product_id']
        );
        return strtoupper($nullable) === 'NO';
    }

    private function indexExists(DatabaseInterface $db, string $table, string $index): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [$table, $index]
        ) > 0;
    }

    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('catalog migration failed: ' . $db->lastError());
        }
    }
}
