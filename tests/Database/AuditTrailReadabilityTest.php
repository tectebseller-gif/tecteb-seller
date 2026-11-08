<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SiteTime;
use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;

/**
 * WHAT THIS PROVES: the audit trail can be filtered and read.
 *
 * ⛔ Both halves were measured by the owner on `alpha.98`, on the page itself.
 *
 *  - Selecting the event `order.captured` returned nothing, while the same
 *    event was plainly there under «همه» with `object_type=order` and
 *    `object_id=12299`. `sanitize_key()` strips the dot, so the filter
 *    arrived as `ordercaptured` — and since every event type in this plugin
 *    has a dot in it, the select had never worked for any of them.
 *  - The capture's time read about three and a half hours early, because the
 *    page printed the stored UTC value with no conversion. The quieter half
 *    was the date filter, which put the typed date into the query as UTC
 *    midnight and so dropped the first three and a half hours of the day
 *    asked for.
 *
 * Rendered through the real page rather than asserted against the repository,
 * because the defect was in the reading and a test on the query would have
 * been green on both of them (`alpha.13`'s lesson: registration is not
 * behaviour, and here neither is the layer underneath).
 */
final class AuditTrailReadabilityTest extends DatabaseTestCase
{
    /** The zone a real site is configured with — a FIXTURE, never a default. */
    private const TEHRAN = 'Asia/Tehran';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        Bootstrap::container()->get(MigrationRunner::class)->run();
        // ⛔ The REAL repository, over the real table. `bootPlugin()` binds the
        // in-memory recorder every contract test uses, and a page reading that
        // would be measuring a different store from the one a manager opens —
        // and would be green on both halves of this round's defect, because
        // neither the dot nor the zone exists in an array.
        $wpdb = $this->wpdb;
        $container = Bootstrap::container();
        $container->bind(
            AuditRepositoryInterface::class,
            static fn (ContainerInterface $c) => new WpAuditRepository($wpdb, $c->get(SiteTime::class))
        );
        $_GET = ['page' => 'tmc-audit'];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        update_option('timezone_string', '');
        parent::tearDown();
    }

    /**
     * The defect, on the page: an event type with a dot in it, selected.
     *
     * Two records with two event types, so «the filter worked» cannot be
     * satisfied by a page that ignores the filter and prints everything.
     */
    public function testSelectingAnEventTypeWithADotInItFindsIt(): void
    {
        $this->record(AuditEventCatalog::ORDER_CAPTURED, 'order', '12299', ['order_id' => 12299]);
        $this->record(AuditEventCatalog::ORDER_BLOCKED, 'product', '555', ['product_id' => 555]);

        $all = $this->renderAudit();
        self::assertStringContainsString('12299', $all, 'the row is there under «all»');
        self::assertStringContainsString('555', $all);

        $_GET['event'] = AuditEventCatalog::ORDER_CAPTURED;
        $filtered = $this->renderAudit();
        self::assertStringContainsString('12299', $filtered, 'the dotted event type must find its own rows');
        self::assertStringNotContainsString('555', $filtered, 'and only its own');
    }

    /**
     * WHAT THIS PROVES: the allowlist is the guard, and it is the catalog's.
     *
     * An event type that is not one of this plugin's own drops to «all»
     * rather than becoming a query — so nothing arbitrary reaches the
     * `WHERE`, and a typo does not read as «nothing happened».
     */
    public function testAnEventTypeThisPluginDoesNotHaveFallsBackToEverything(): void
    {
        $this->record(AuditEventCatalog::ORDER_CAPTURED, 'order', '12299', ['order_id' => 12299]);

        foreach (['not.an.event', 'order.captured; DROP TABLE x', '%', 'ordercaptured'] as $bogus) {
            $_GET['event'] = $bogus;
            $html = $this->renderAudit();
            self::assertStringContainsString('12299', $html, $bogus . ' silently emptied the table');
        }
    }

    /**
     * WHAT THIS PROVES: `Request::queryKey()` is untouched.
     *
     * «امنیت سایر کاربردهای `queryKey()` را تضعیف نکن» — so the fix is a
     * narrower read on ONE page rather than a wider sanitiser for nineteen
     * callers. This pins the sanitiser's own behaviour: a dot is still
     * stripped, which is correct for the slugs and ids every other caller
     * passes it.
     */
    public function testTheKeySanitiserStillStripsDotsForEverybodyElse(): void
    {
        self::assertSame('ordercaptured', sanitize_key('order.captured'));
    }

    /**
     * The timestamp, in the site's own zone.
     *
     * 21:30 UTC on the 8th is 01:00 on the 9th in Tehran. Printed raw it reads
     * as the 8th — the day the owner saw.
     */
    public function testTheTimeIsPrintedInTheSitesZoneAndNotAsStored(): void
    {
        update_option('timezone_string', self::TEHRAN);
        $this->record(
            AuditEventCatalog::ORDER_CAPTURED,
            'order',
            '12299',
            ['order_id' => 12299],
            '2026-11-08 21:30:00'
        );

        $html = $this->renderAudit();
        $digits = static fn (string $s): string => strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $plain = $digits($html);
        self::assertStringContainsString('2026-11-09 01:00', $plain, 'the local time must be on the page');
        self::assertStringNotContainsString('2026-11-08 21:30', $plain, 'the raw UTC value must not be');
    }

    /**
     * The date filter, converted through the same zone.
     *
     * The row is at 21:30 UTC on the 8th, which is the 9th in Tehran. «From
     * the 9th» must find it and «to the 8th» must not — and on `alpha.98`
     * both answers were the other way round.
     */
    public function testALocalDateFilterFindsTheLocalDay(): void
    {
        update_option('timezone_string', self::TEHRAN);
        $this->record(
            AuditEventCatalog::ORDER_CAPTURED,
            'order',
            '12299',
            ['order_id' => 12299],
            '2026-11-08 21:30:00'
        );

        $_GET['from'] = '2026-11-09';
        self::assertStringContainsString('12299', $this->renderAudit(), '«from the 9th» must find a row that is on the 9th locally');

        $_GET = ['page' => 'tmc-audit', 'to' => '2026-11-08'];
        self::assertStringNotContainsString('12299', $this->renderAudit(), '«to the 8th» must not');

        $_GET = ['page' => 'tmc-audit', 'from' => '2026-11-09', 'to' => '2026-11-09'];
        self::assertStringContainsString('12299', $this->renderAudit(), 'and one local day contains it');
    }

    /**
     * WHAT THIS PROVES: the repository and the screen use ONE zone.
     *
     * Measured at the layer the query runs in, because a page that printed
     * the right time against a query that filtered the wrong day would pass
     * every assertion above except this one.
     */
    public function testTheQueryBoundariesComeFromTheSameZoneAsTheScreen(): void
    {
        update_option('timezone_string', self::TEHRAN);
        $time = Bootstrap::container()->get(SiteTime::class);
        self::assertSame(self::TEHRAN, $time->zone()->getName());

        $repository = new WpAuditRepository($this->wpdb, $time);
        $this->record(
            AuditEventCatalog::ORDER_CAPTURED,
            'order',
            '12299',
            ['order_id' => 12299],
            '2026-11-08 21:30:00'
        );
        self::assertSame(1, $repository->count(['from' => '2026-11-09']));
        self::assertSame(0, $repository->count(['to' => '2026-11-08']));
        // And a build with no zone to read answers exactly as every release
        // before this one did, rather than inheriting a guess.
        $utc = new WpAuditRepository($this->wpdb);
        self::assertSame(0, $utc->count(['from' => '2026-11-09']));
        self::assertSame(1, $utc->count(['to' => '2026-11-08']));
    }

    /**
     * A date nobody can place leaves the filter OFF.
     *
     * Rather than becoming a window the person did not ask for — on an audit
     * trail the difference between those two is «nothing happened» and «I
     * looked in the wrong place».
     */
    public function testADateThatCannotBePlacedDoesNotNarrowAnything(): void
    {
        update_option('timezone_string', self::TEHRAN);
        $this->record(AuditEventCatalog::ORDER_CAPTURED, 'order', '12299', ['order_id' => 12299]);
        foreach (['yesterday', '09/11/2026', '2026-02-30', ''] as $bad) {
            $_GET = ['page' => 'tmc-audit', 'from' => $bad];
            self::assertStringContainsString('12299', $this->renderAudit(), $bad . ' narrowed the table to nothing');
        }
    }

    private function renderAudit(): string
    {
        /** @var list<array{slug:string, render:callable}> $pages */
        $pages = apply_filters(AdminExtensions::FILTER, []);
        foreach ($pages as $page) {
            if (($page['slug'] ?? '') === 'tmc-audit') {
                return $this->capture(static fn () => ($page['render'])());
            }
        }
        self::fail('the audit page must be registered for this test to mean anything');
    }

    /** @param array<string,mixed> $payload */
    private function record(
        string $eventType,
        string $objectType,
        string $objectId,
        array $payload,
        string $atUtc = '2026-11-08 09:00:00'
    ): void {
        $repository = new WpAuditRepository($this->wpdb);
        self::assertTrue($repository->insert(new AuditRecord(
            $eventType,
            1,
            $objectType,
            $objectId,
            $payload,
            null,
            new \DateTimeImmutable($atUtc, new \DateTimeZone('UTC'))
        )), 'the fixture row must be written: ' . $repository->lastError());
    }
}
