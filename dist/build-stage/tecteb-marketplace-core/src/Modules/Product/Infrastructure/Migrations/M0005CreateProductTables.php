<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Products, their medical answers, their images and their pending revisions.
 *
 * `vendor_user_id` is on the product row and indexed with the status, because
 * every single query in this module is "this vendor's products" — ownership
 * is not a filter added later, it is how the data is reached (AC-PRIV).
 *
 * Spec answers live in their own table keyed by the field's STABLE key rather
 * than in a JSON blob on the product, so a retired field keeps its answers and
 * a renamed label changes nothing (MED-01).
 */
final class M0005CreateProductTables implements MigrationInterface
{
    public const PRODUCTS = 'tmc_products';
    public const SPEC_TEMPLATES = 'tmc_spec_templates';
    public const SPEC_FIELDS = 'tmc_spec_fields';
    public const PRODUCT_SPECS = 'tmc_product_specs';
    public const PRODUCT_IMAGES = 'tmc_product_images';
    public const REVISIONS = 'tmc_product_revisions';

    /** @var list<string> */
    public const TABLES = [
        self::PRODUCTS, self::SPEC_TEMPLATES, self::SPEC_FIELDS,
        self::PRODUCT_SPECS, self::PRODUCT_IMAGES, self::REVISIONS,
    ];

    public function version(): int
    {
        return 5;
    }

    public function id(): string
    {
        return '0005_create_product_tables';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $products = self::table($db, self::PRODUCTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$products}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL DEFAULT '',
            `type` VARCHAR(16) NOT NULL DEFAULT 'simple',
            `category_key` VARCHAR(64) NOT NULL DEFAULT '',
            `brand` VARCHAR(120) NOT NULL DEFAULT '',
            `short_description` TEXT NULL,
            `price_minor` BIGINT NOT NULL DEFAULT 0,
            `sale_price_minor` BIGINT NULL,
            `sale_from` DATE NULL,
            `sale_to` DATE NULL,
            `sku` VARCHAR(64) NOT NULL DEFAULT '',
            `stock` INT NOT NULL DEFAULT 0,
            `min_purchase` INT NOT NULL DEFAULT 1,
            `max_purchase` INT NULL,
            `weight_grams` INT NOT NULL DEFAULT 0,
            `dimensions` VARCHAR(120) NOT NULL DEFAULT '',
            `tax_class` VARCHAR(64) NOT NULL DEFAULT '',
            `status` VARCHAR(24) NOT NULL,
            `review_note` TEXT NULL,
            `spec_schema_version` INT NOT NULL DEFAULT 0,
            `main_image_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `published_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_product_owner` (`vendor_user_id`, `status`),
            KEY `tmc_product_queue` (`status`, `updated_at`),
            KEY `tmc_product_sku` (`vendor_user_id`, `sku`)
        ) {$cc}");

        $templates = self::table($db, self::SPEC_TEMPLATES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$templates}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_key` VARCHAR(64) NOT NULL,
            `label` VARCHAR(190) NOT NULL,
            `schema_version` INT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_template_category` (`category_key`)
        ) {$cc}");

        $fields = self::table($db, self::SPEC_FIELDS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$fields}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `template_id` BIGINT UNSIGNED NOT NULL,
            `field_key` VARCHAR(64) NOT NULL,
            `label` VARCHAR(190) NOT NULL,
            `type` VARCHAR(16) NOT NULL,
            `required` TINYINT(1) NOT NULL DEFAULT 0,
            `unit` VARCHAR(32) NOT NULL DEFAULT '',
            `options` TEXT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `deprecated` TINYINT(1) NOT NULL DEFAULT 0,
            `schema_version` INT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_field_key` (`template_id`, `field_key`)
        ) {$cc}");

        $values = self::table($db, self::PRODUCT_SPECS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$values}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `field_key` VARCHAR(64) NOT NULL,
            `value` TEXT NULL,
            `schema_version` INT NOT NULL DEFAULT 1,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_spec_value` (`product_id`, `field_key`)
        ) {$cc}");

        $images = self::table($db, self::PRODUCT_IMAGES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$images}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `media_id` BIGINT UNSIGNED NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_image_once` (`product_id`, `media_id`)
        ) {$cc}");

        $revisions = self::table($db, self::REVISIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$revisions}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `payload` LONGTEXT NULL,
            `status` VARCHAR(16) NOT NULL,
            `note` TEXT NULL,
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_revision_queue` (`status`, `created_at`),
            KEY `tmc_revision_product` (`product_id`, `status`)
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
        return true;
    }

    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('product migration failed: ' . $db->lastError());
        }
    }
}
