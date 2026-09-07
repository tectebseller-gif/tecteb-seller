<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;

/** CORE-08 on MariaDB: prepared insert, NULL actor, UTC, sanitised payload, failure surfaced. */
final class WpAuditRepositoryTest extends DatabaseTestCase
{
    private function logger(): AuditLogger
    {
        return new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new FixedClock(new \DateTimeImmutable('2026-09-06T10:00:00+03:30')));
    }

    public function testRowsAreInsertedWithNullActorUtcAndCleanPayload(): void
    {
        (new M0001CreateAuditTable())->up(new WpDatabase($this->wpdb));
        $r = $this->logger()->log(AuditEventCatalog::SETTINGS_UPDATED, null, 'settings', "tmc_settings'; DROP TABLE x; --", [
            'changed' => ['max_staff'],
            'old' => ['max_staff' => 10, 'email' => 'x@y.z'],
            'new' => ['max_staff' => 12],
        ]);
        self::assertTrue($r->ok, (string) $r->error);
        $rows = $this->wpdb->get_results("SELECT * FROM `{$this->auditTable()}`", ARRAY_A);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('settings.updated', $row['event_type']);
        self::assertNull($row['actor_id']);
        self::assertSame("tmc_settings'; DROP TABLE x; --", $row['object_id'], 'prepared statement stores the literal safely');
        self::assertSame('2026-09-06 06:30:00', $row['created_at']);
        self::assertSame($r->correlationId, $row['correlation_id']);
        $payload = json_decode($row['payload'], true);
        self::assertSame(['changed' => ['max_staff'], 'old' => ['max_staff' => 10], 'new' => ['max_staff' => 12]], $payload);
        self::assertStringNotContainsString('@', $row['payload']);
        self::assertTrue($this->tableExists($this->wpdb->prefix . 'options'));
    }

    public function testInsertFailureIsReturnedWithMessage(): void
    {
        // Table intentionally absent: the repository must report, not throw or pretend.
        $r = $this->logger()->log(AuditEventCatalog::PLUGIN_ACTIVATED, 1, 'plugin', 'tecteb-marketplace-core', ['plugin_version' => 'x']);
        self::assertFalse($r->ok);
        self::assertStringContainsString("doesn't exist", (string) $r->error);
    }
}
