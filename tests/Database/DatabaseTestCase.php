<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Tests\WordPressContract\ContractTestCase;
use TmcWpStubs\State;

/**
 * Real MariaDB/MySQL through the PDO-backed wpdb stub. Requires
 * TMC_TEST_DB_DSN / _USER / _PASS pointing at a DISPOSABLE database named
 * tmc_test. Fails loudly when they are missing — never skips.
 */
abstract class DatabaseTestCase extends ContractTestCase
{
    protected \wpdb $wpdb;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
            if (getenv($var) === false) {
                throw new \RuntimeException("Database suite refuses to run: {$var} is not set (disposable tmc_test database required).");
            }
        }
        if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
            throw new \RuntimeException('Database suite refuses to run: DSN must name the disposable tmc_test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new \wpdb();
        $this->wpdb = $GLOBALS['wpdb'];
        $this->wpdb->ensureOptionsTable();
        $this->wpdb->pdo()->exec("TRUNCATE TABLE `{$this->wpdb->options}`");
        $this->wpdb->dropTable($this->auditTable());
        // In WordPress the lock, the schema version and the guarded writes all
        // live in ONE options table. Point the option functions at the real
        // table so this suite exercises that single storage layer; an
        // in-process array would let a guarded write and a get_option()
        // disagree, which is exactly what these tests must be able to catch.
        State::$optionsBackedByWpdb = true;
    }

    protected function tearDown(): void
    {
        State::$optionsBackedByWpdb = false;
        parent::tearDown();
    }

    /** Raw option_value straight from the table, bypassing every cache. */
    protected function rawOption(string $name): ?string
    {
        $stmt = $this->wpdb->pdo()->prepare("SELECT option_value FROM `{$this->wpdb->options}` WHERE option_name = ?");
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /**
     * The stored schema version as a number. WordPress keeps option values in
     * a text column, so an integer written through ANY path — update_option()
     * or our guarded single statement — reads back as a numeric string. The
     * runner casts (currentVersion()); the tests assert the number.
     */
    protected function storedSchemaVersion(): ?int
    {
        $raw = $this->rawOption(SchemaVersion::OPTION);
        return $raw === null ? null : (int) $raw;
    }

    protected function auditTable(): string
    {
        return $this->wpdb->prefix . M0001CreateAuditTable::TABLE_SUFFIX;
    }

    protected function tableExists(string $table): bool
    {
        $stmt = $this->wpdb->pdo()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return list<string> */
    protected function indexNames(string $table): array
    {
        $stmt = $this->wpdb->pdo()->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME');
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    protected function lockRow(): ?string
    {
        $stmt = $this->wpdb->pdo()->prepare("SELECT option_value FROM `{$this->wpdb->options}` WHERE option_name = 'tmc_migration_lock'");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
