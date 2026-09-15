<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * The only table of phase 1 (CORE-08). Prefix-safe; UTC timestamps.
 * Single CREATE TABLE IF NOT EXISTS keeps the step idempotent and resumable.
 */
final class M0001CreateAuditTable implements MigrationInterface
{
    public const TABLE_SUFFIX = 'tmc_audit_events';

    /** @var list<string> */
    public const COLUMNS = [
        'id', 'event_type', 'actor_id', 'object_type', 'object_id', 'payload', 'correlation_id', 'created_at',
    ];

    public function version(): int
    {
        return 1;
    }

    public function id(): string
    {
        return '0001_create_audit_table';
    }

    public static function tableName(DatabaseInterface $db): string
    {
        return $db->prefix() . self::TABLE_SUFFIX;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::tableName($db);
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_type` VARCHAR(64) NOT NULL,
            `actor_id` BIGINT UNSIGNED NULL,
            `object_type` VARCHAR(64) NULL,
            `object_id` VARCHAR(64) NULL,
            `payload` LONGTEXT NOT NULL,
            `correlation_id` CHAR(36) NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_evt_created` (`event_type`, `created_at`),
            KEY `tmc_actor_created` (`actor_id`, `created_at`),
            KEY `tmc_object` (`object_type`, `object_id`)
        ) " . $db->charsetCollate();
        if ($db->execute($sql) === null) {
            throw new MigrationException('CREATE TABLE failed: ' . $db->lastError());
        }
    }

    public function verify(DatabaseInterface $db): bool
    {
        $count = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [self::tableName($db)]
        );
        return (int) $count === count(self::COLUMNS);
    }
}
