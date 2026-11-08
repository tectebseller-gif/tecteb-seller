<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Audit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Tests\Support\RecordingAuditRepository;

/** CORE-08: allowlist, sensitive input, insert failure surfaced. */
final class AuditTest extends TestCase
{
    private function logger(RecordingAuditRepository $repo): AuditLogger
    {
        return new AuditLogger($repo, new AuditEventSanitizer(), new FixedClock(new \DateTimeImmutable('2026-09-06T10:00:00+03:30')));
    }

    public function testUnknownEventTypeIsRejected(): void
    {
        self::assertNull((new AuditEventSanitizer())->sanitize('vendor.login', ['x' => 1]));
        $repo = new RecordingAuditRepository();
        $r = $this->logger($repo)->log('vendor.login', 1, null, null, []);
        self::assertFalse($r->ok);
        self::assertSame('unknown_event_type', $r->error);
        self::assertSame([], $repo->records);
    }

    public function testOnlyAllowlistedKeysSurviveAndNestedKeysAreRestricted(): void
    {
        $clean = (new AuditEventSanitizer())->sanitize(AuditEventCatalog::SETTINGS_UPDATED, [
            'changed' => ['max_staff', 'user_password', 'default_commission_rate_bp'],
            'old' => ['max_staff' => 10, 'user_password' => 'hunter2', 'nested' => ['deep' => 1]],
            'new' => ['max_staff' => 12, 'otp_code' => '123456'],
            'raw_request' => ['POST' => 'everything'],
            'email' => 'a@b.c',
        ]);
        self::assertSame([
            'changed' => ['max_staff', 'default_commission_rate_bp'],
            'old' => ['max_staff' => 10],
            'new' => ['max_staff' => 12],
        ], $clean);
    }

    public function testSensitiveLookingValuesAreRedactedAndBounded(): void
    {
        $clean = (new AuditEventSanitizer())->sanitize(AuditEventCatalog::MIGRATION_FAILED, [
            'step' => "0001\x00\x01",
            'message' => 'contact admin@example.com or 09121234567 ' . str_repeat('x', 600),
        ]);
        self::assertSame('0001', $clean['step']);
        self::assertStringNotContainsString('@', $clean['message']);
        self::assertStringNotContainsString('09121234567', $clean['message']);
        self::assertStringContainsString('[redacted]', $clean['message']);
        self::assertSame(AuditEventSanitizer::MAX_STRING, mb_strlen($clean['message']));
    }

    public function testSuccessfulLogHasUtcTimestampAndCorrelationId(): void
    {
        $repo = new RecordingAuditRepository();
        $r = $this->logger($repo)->log(AuditEventCatalog::PLUGIN_ACTIVATED, 7, 'plugin', 'tecteb-marketplace-core', ['plugin_version' => '0.1.0-alpha.1', 'schema_version' => 1]);
        self::assertTrue($r->ok);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $r->correlationId);
        $rec = $repo->records[0];
        self::assertSame('UTC', $rec->createdAtUtc->getTimezone()->getName());
        self::assertSame('2026-09-06 06:30:00', $rec->createdAtUtc->format('Y-m-d H:i:s'));
        self::assertSame(7, $rec->actorId);
        self::assertSame(['plugin_version' => '0.1.0-alpha.1', 'schema_version' => 1], $rec->payload);
    }

    public function testInsertFailureIsReturnedNotSwallowed(): void
    {
        $repo = new RecordingAuditRepository();
        $repo->fail = true;
        $r = $this->logger($repo)->log(AuditEventCatalog::PLUGIN_DEACTIVATED, null, null, null, ['plugin_version' => 'x']);
        self::assertFalse($r->ok);
        self::assertStringContainsString('simulated insert failure', (string) $r->error);
    }

    /**
     * WHAT THIS PROVES: a capture's own refusal reasons reach the audit row.
     *
     * ⛔ **Found by the falsification loop, not by reading.** `alpha.99`
     * widened `ORDER_CAPTURED`'s allowlist from five keys to ten, and a
     * mutation that narrowed it back **survived every suite**: the fix had no
     * test. The five that were being dropped are exactly the ones a person
     * needs when a capture went wrong — `failed`, `refused_unit`, `repaired`,
     * `incomplete` and `reasons` — so the trail recorded that a capture had
     * happened and silently discarded why part of it had not.
     *
     * The sanitiser copies ONLY allowlisted keys, so this is asked of the
     * sanitiser rather than of the page: a key that is not on the list cannot
     * be stored, and nothing downstream can put it back.
     */
    public function testACapturesRefusalReasonsAreNotDroppedFromItsRow(): void
    {
        $sanitizer = new AuditEventSanitizer(new AuditEventCatalog());
        $clean = $sanitizer->sanitize(AuditEventCatalog::ORDER_CAPTURED, [
            'order_id' => 12300,
            'vendors' => 1,
            'items' => 1,
            'recorded' => 0,
            'skipped' => 0,
            'failed' => 1,
            'refused_unit' => 1,
            'repaired' => 0,
            'incomplete' => 1,
            'reasons' => 'unit_unreadable,line_incomplete',
        ]);

        self::assertNotNull($clean);
        foreach (['failed', 'refused_unit', 'repaired', 'incomplete', 'reasons'] as $key) {
            self::assertArrayHasKey($key, $clean, $key . ' is why a capture did not finish');
        }
        self::assertSame('unit_unreadable,line_incomplete', $clean['reasons']);
    }

    /**
     * WHAT THIS PROVES: the lifecycle sync's own row carries what it did and
     * what it refused.
     *
     * Same shape, same reason. A pass that wrote nothing because every line
     * had already shipped is the most important one to find afterwards.
     */
    public function testTheLifecycleSyncsRowCarriesItsCountsAndItsReasons(): void
    {
        $sanitizer = new AuditEventSanitizer(new AuditEventCatalog());
        $clean = $sanitizer->sanitize(AuditEventCatalog::ORDER_LIFECYCLE_SYNCED, [
            'order_id' => 12300,
            'wc_status' => 'cancelled',
            'disposition' => 'void',
            'items' => 2,
            'cancelled' => 1,
            'voided' => 1,
            'untouched' => 1,
            'failed' => 0,
            'vendors' => 1,
            'reasons' => 'cancel_after_shipping',
        ]);

        self::assertNotNull($clean);
        foreach ([
            'order_id', 'wc_status', 'disposition', 'items',
            'cancelled', 'voided', 'untouched', 'failed', 'vendors', 'reasons',
        ] as $key) {
            self::assertArrayHasKey($key, $clean, $key . ' must survive the allowlist');
        }
        self::assertSame('cancel_after_shipping', $clean['reasons']);
    }
}
