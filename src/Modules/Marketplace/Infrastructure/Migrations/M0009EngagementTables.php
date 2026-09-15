<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Coupons, wholesale buyers, tiered prices and tickets — the phase-7 items the
 * owner asked to be advanced.
 *
 * Every table here holds only facts somebody entered. None of them has a
 * column for a rule this project has not been given:
 *
 *  - **No global-coupon funding column.** Who pays for a marketplace-wide
 *    discount is DEC-04. A vendor's own coupon is their own cost and is
 *    recorded; a global one is refused at the service, not defaulted here.
 *  - **No wholesale tariff or minimum.** DEC-05 leaves «تعرفه/حداقل عمده» to
 *    be finalised, so a tier is a row a VENDOR wrote for their own product,
 *    never a marketplace-wide number this schema could imply.
 *  - **No retention column on tickets.** How long a thread is kept is also
 *    DEC-05, so nothing here expires and nothing is deleted on a timer.
 *
 * `tmc_coupon_uses` is UNIQUE on `(coupon_id, wc_order_id)`: one order can
 * consume a coupon once, whatever a retried callback does, and the count a
 * usage limit is checked against is a SUM over these rows rather than a
 * counter that a crash between two writes could leave wrong.
 */
final class M0009EngagementTables implements MigrationInterface
{
    public const COUPONS = 'tmc_coupons';
    public const COUPON_USES = 'tmc_coupon_uses';
    public const B2B_ACCOUNTS = 'tmc_b2b_accounts';
    public const PRICE_TIERS = 'tmc_price_tiers';
    public const TICKETS = 'tmc_tickets';
    public const TICKET_MESSAGES = 'tmc_ticket_messages';

    /** @var list<string> */
    public const TABLES = [
        self::COUPONS,
        self::COUPON_USES,
        self::B2B_ACCOUNTS,
        self::PRICE_TIERS,
        self::TICKETS,
        self::TICKET_MESSAGES,
    ];

    public function version(): int
    {
        return 9;
    }

    public function id(): string
    {
        return '0009_coupons_b2b_and_tickets';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $coupons = self::table($db, self::COUPONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$coupons}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(64) NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `kind` VARCHAR(16) NOT NULL DEFAULT 'percent',
            `value` BIGINT NOT NULL DEFAULT 0,
            `min_subtotal_minor` BIGINT NOT NULL DEFAULT 0,
            `max_discount_minor` BIGINT NULL,
            `usage_limit` INT UNSIGNED NOT NULL DEFAULT 0,
            `per_customer_limit` INT UNSIGNED NOT NULL DEFAULT 0,
            `starts_at` DATETIME NULL,
            `ends_at` DATETIME NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'active',
            `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_coupon_code` (`code`),
            KEY `tmc_coupon_vendor` (`vendor_user_id`, `status`)
        ) {$cc}");

        $uses = self::table($db, self::COUPON_USES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$uses}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `coupon_id` BIGINT UNSIGNED NOT NULL,
            `wc_order_id` BIGINT UNSIGNED NOT NULL,
            `customer_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `amount_minor` BIGINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_coupon_use_once` (`coupon_id`, `wc_order_id`),
            KEY `tmc_coupon_use_customer` (`coupon_id`, `customer_user_id`)
        ) {$cc}");

        $accounts = self::table($db, self::B2B_ACCOUNTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$accounts}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'requested',
            `company` VARCHAR(190) NOT NULL DEFAULT '',
            `registration_id` VARCHAR(64) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `decided_by` BIGINT UNSIGNED NULL,
            `decided_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_b2b_user` (`user_id`),
            KEY `tmc_b2b_status` (`status`)
        ) {$cc}");

        $tiers = self::table($db, self::PRICE_TIERS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$tiers}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `min_quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `unit_price_minor` BIGINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_tier_step` (`product_id`, `min_quantity`),
            KEY `tmc_tier_vendor` (`vendor_user_id`)
        ) {$cc}");

        $tickets = self::table($db, self::TICKETS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$tickets}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `subject` VARCHAR(190) NOT NULL DEFAULT '',
            `status` VARCHAR(16) NOT NULL DEFAULT 'open',
            `order_ref` VARCHAR(64) NOT NULL DEFAULT '',
            `locked` TINYINT(1) NOT NULL DEFAULT 0,
            `opened_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `last_reply_at` DATETIME NULL,
            `last_reply_role` VARCHAR(16) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_ticket_vendor` (`vendor_user_id`, `status`),
            KEY `tmc_ticket_queue` (`status`, `last_reply_at`)
        ) {$cc}");

        $messages = self::table($db, self::TICKET_MESSAGES);
        // No `updated_at`, and the repository has no update path: UX §10.1
        // says «پیام و فایل ویرایش عادی ندارند». Hiding is a separate,
        // audited act that keeps the row and its text.
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$messages}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id` BIGINT UNSIGNED NOT NULL,
            `author_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `author_role` VARCHAR(16) NOT NULL DEFAULT 'vendor',
            `body` TEXT NULL,
            `hidden` TINYINT(1) NOT NULL DEFAULT 0,
            `hidden_reason` VARCHAR(190) NOT NULL DEFAULT '',
            `hidden_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_ticket_thread` (`ticket_id`, `id`)
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

    /**
     * `execute()` answers NULL on failure and an affected-row count otherwise,
     * and DDL affects zero rows — so the check is against null.
     */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('engagement migration failed: ' . $db->lastError());
        }
    }
}
