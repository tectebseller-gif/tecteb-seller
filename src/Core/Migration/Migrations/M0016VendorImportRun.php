<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * The half of migration 14 that migration 14 missed.
 *
 * `0014` stamped the run id on every imported PRODUCT, so a rollback could
 * find rows a crash had orphaned. The vendor profile an import creates got no
 * stamp — and the evidence run found the consequence immediately: with the
 * manifest gone, `rollback()` removed the products and left the shop behind,
 * reporting `vendors=0` while a vendor profile sat there that nothing could
 * name.
 *
 * Half a fix for «the record of what was created must be the thing that was
 * created» is not a fix. It is the same column, on the other table, for the
 * same reason.
 */
final class M0016VendorImportRun implements MigrationInterface
{
    public const PROFILES = 'tmc_vendor_profiles';

    public function version(): int
    {
        return 16;
    }

    public function id(): string
    {
        return '0016_vendor_import_run';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $profiles = self::table($db, self::PROFILES);

        if (!$this->columnExists($db, $profiles, 'import_run_id')) {
            $this->run($db, "ALTER TABLE `{$profiles}` ADD COLUMN `import_run_id` VARCHAR(64) NOT NULL DEFAULT ''");
        }
        if (!$this->indexExists($db, $profiles, 'tmc_profile_import_run')) {
            $this->run($db, "ALTER TABLE `{$profiles}` ADD KEY `tmc_profile_import_run` (`import_run_id`)");
        }
    }

    public function verify(DatabaseInterface $db): bool
    {
        $profiles = self::table($db, self::PROFILES);
        return $this->columnExists($db, $profiles, 'import_run_id')
            && $this->indexExists($db, $profiles, 'tmc_profile_import_run');
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
