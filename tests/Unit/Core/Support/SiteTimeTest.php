<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Support;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Support\SiteTime;

/**
 * Stored in UTC, read in the site's zone, filtered through the same one.
 *
 * Iran's offset appears in these tests as a FIXTURE — the zone a real site is
 * configured with — and nowhere in the class under test. The test for that is
 * the last one here: the same code answers a different zone correctly.
 */
final class SiteTimeTest extends TestCase
{
    private const TEHRAN = 'Asia/Tehran';

    private function tehran(): SiteTime
    {
        return new SiteTime(new \DateTimeZone(self::TEHRAN));
    }

    /**
     * The defect, in one assertion.
     *
     * 21:30 UTC on the 8th is 01:00 on the 9th in Tehran. Printed raw it reads
     * as the 8th, which is the day the owner saw on the audit page.
     */
    public function testAnInstantIsPrintedInTheSitesZoneAndNotAsStored(): void
    {
        $utc = new \DateTimeImmutable('2026-11-08 21:30:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-11-09 01:00', $this->tehran()->render($utc));
        self::assertSame('2026-11-08 21:30', SiteTime::utc()->render($utc));
    }

    /**
     * The other half, and the one that silently lost rows.
     *
     * «From the 9th» in Tehran begins at 20:30 UTC on the 8th. The old query
     * used `2026-11-09 00:00:00` as a UTC bound, so it dropped the first three
     * and a half hours of the 9th local and swept in the last three and a half
     * of the 8th.
     */
    public function testALocalDateBecomesTheRightUtcBoundaries(): void
    {
        $time = $this->tehran();
        self::assertSame('2026-11-08 20:30:00', $time->dayStartUtc('2026-11-09'));
        self::assertSame('2026-11-09 20:29:59', $time->dayEndUtc('2026-11-09'));
    }

    /**
     * The boundary pair covers exactly one local day — no gap and no overlap
     * with the next one.
     */
    public function testConsecutiveDaysMeetWithoutAGapOrAnOverlap(): void
    {
        $time = $this->tehran();
        $end = $time->dayEndUtc('2026-11-09');
        $next = $time->dayStartUtc('2026-11-10');
        self::assertIsString($end);
        self::assertIsString($next);
        $endAt = new \DateTimeImmutable($end, new \DateTimeZone('UTC'));
        $nextAt = new \DateTimeImmutable($next, new \DateTimeZone('UTC'));
        self::assertSame(1, $nextAt->getTimestamp() - $endAt->getTimestamp());
    }

    /** A UTC site is unchanged — every release before this one. */
    public function testAUtcSiteGetsTheBoundariesItAlwaysGot(): void
    {
        $time = SiteTime::utc();
        self::assertSame('2026-11-09 00:00:00', $time->dayStartUtc('2026-11-09'));
        self::assertSame('2026-11-09 23:59:59', $time->dayEndUtc('2026-11-09'));
    }

    /**
     * An unparseable date is `null`, not «today» and not the epoch.
     *
     * A filter built from a fallback answers about a window nobody asked for,
     * and on an audit trail that is the difference between «nothing happened»
     * and «I looked in the wrong place».
     */
    public function testTextThatIsNotADateIsRefusedRatherThanGuessed(): void
    {
        $time = $this->tehran();
        foreach (['', '   ', 'yesterday', '2026-11', '09/11/2026', '۱۴۰۵-۰۸-۱۸', '2026-11-9'] as $bad) {
            self::assertNull($time->dayStartUtc($bad), $bad);
            self::assertNull($time->dayEndUtc($bad), $bad);
        }
    }

    /**
     * A date that is not on the calendar is refused rather than rolled over.
     *
     * PHP reads `2026-02-30` as 2 March. A filter that accepted it would quietly
     * answer about two days the person did not type.
     */
    public function testADateThatDoesNotExistIsRefusedRatherThanRolledOver(): void
    {
        self::assertNull($this->tehran()->dayStartUtc('2026-02-30'));
        self::assertNull($this->tehran()->dayStartUtc('2026-13-01'));
        // …and a real leap day is accepted, so the guard is about the calendar
        // rather than about February.
        self::assertIsString($this->tehran()->dayStartUtc('2028-02-29'));
    }

    /**
     * WHAT THIS PROVES: no zone is written into the class.
     *
     * The same code answers a zone on the other side of UTC, and one with a
     * daylight-saving transition, correctly. If Tehran's offset were hard
     * coded, both of these would be wrong.
     */
    public function testAnotherZoneIsRightForTheSameReason(): void
    {
        $newYork = new SiteTime(new \DateTimeZone('America/New_York'));
        // Eastern Standard Time in November: UTC−5.
        self::assertSame('2026-11-09 05:00:00', $newYork->dayStartUtc('2026-11-09'));
        $kolkata = new SiteTime(new \DateTimeZone('Asia/Kolkata'));
        self::assertSame('2026-11-08 18:30:00', $kolkata->dayStartUtc('2026-11-09'));
    }

    /**
     * A spring-forward day whose local midnight does not exist.
     *
     * Resolved by PHP's own transition table rather than by arithmetic here:
     * the answer stays inside the day asked for, which is all a boundary has
     * to promise.
     */
    public function testADayWhoseLocalMidnightDoesNotExistStaysInsideThatDay(): void
    {
        // Lord Howe skips 02:00→02:30; Chile's Santiago skips midnight itself
        // on the first Sunday of September.
        $santiago = new SiteTime(new \DateTimeZone('America/Santiago'));
        $start = $santiago->dayStartUtc('2026-09-06');
        self::assertIsString($start);
        $local = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('America/Santiago'));
        self::assertSame('2026-09-06', $local->format('Y-m-d'));
    }
}
