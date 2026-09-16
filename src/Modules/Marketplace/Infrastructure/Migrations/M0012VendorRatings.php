<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One table, and one table only, because half of «نظرات و امتیاز» already has
 * a home.
 *
 * **The product review is WooCommerce's own.** A review of a product is a
 * WordPress comment with a `rating` meta; the storefront renders it, the
 * moderation queue holds it, the star average on the product page is computed
 * from it. Writing a second product-review table would have meant a shopper
 * seeing one set of reviews and the vendor's panel showing another — the
 * mistake the coupon made in `alpha.9` and `alpha.10` undid, where a «coupon»
 * that was really a negative fee did not behave like a coupon anywhere it
 * mattered. So nothing here stores a product review.
 *
 * **The vendor rating is ours, because WooCommerce has no such thing.** There
 * is no post to hang a comment on: a shop is a user, not a product. Hence this
 * table — and only this one.
 *
 * Three rules are in the schema rather than in a caller:
 *
 *  - **`UNIQUE (order_item_id)`.** «نظر فقط برای خریدار واقعی است» (Master A.5),
 *    and the thing that makes a buyer real is a recorded order line. Hanging
 *    the rating off that line means the proof of purchase IS the identity of
 *    the rating, and a second rating for the same purchase is refused by the
 *    index rather than by a check two requests can both pass.
 *  - **`reply` is a column, not a row.** «فروشنده فقط پاسخ می‌دهد» (Master A.5):
 *    one answer from the shop, no thread, and no way to write anything else.
 *    A replies TABLE would have been a conversation, which is what the ticket
 *    module is for.
 *  - **`status` starts pending and only a manager moves it.** Moderation is
 *    the manager's («moderation و گزارش», UX §12), so an unmoderated rating is
 *    invisible on the storefront and still visible to the shop it is about —
 *    which is why the status is a column and not a deletion.
 *
 * Nothing here is ever deleted. A rejected rating keeps its text, its actor and
 * its reason, for the same reason a hidden ticket message does.
 */
final class M0012VendorRatings implements MigrationInterface
{
    public const VENDOR_RATINGS = 'tmc_vendor_ratings';

    /** @var list<string> */
    public const TABLES = [self::VENDOR_RATINGS];

    public function version(): int
    {
        return 12;
    }

    public function id(): string
    {
        return '0012_vendor_ratings';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $charset = $db->charsetCollate();
        $ratings = self::table($db, self::VENDOR_RATINGS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$ratings}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `buyer_user_id` BIGINT UNSIGNED NOT NULL,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `stars` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `body` TEXT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
            `reply` TEXT NULL,
            `replied_at` DATETIME NULL DEFAULT NULL,
            `moderated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
            `moderated_at` DATETIME NULL DEFAULT NULL,
            `moderation_reason` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_rating_once` (`order_item_id`),
            KEY `tmc_rating_vendor` (`vendor_user_id`, `status`, `id`),
            KEY `tmc_rating_buyer` (`buyer_user_id`),
            KEY `tmc_rating_queue` (`status`, `id`)
        ) {$charset}");
    }

    public function verify(DatabaseInterface $db): bool
    {
        foreach (self::TABLES as $suffix) {
            if (!$this->tableExists($db, self::table($db, $suffix))) {
                return false;
            }
        }
        return true;
    }

    private function tableExists(DatabaseInterface $db, string $table): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
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
