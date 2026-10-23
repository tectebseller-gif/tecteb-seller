<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\CursorProgress;
use Tecteb\Marketplace\Modules\Migration\Domain\UnitClaim;

/**
 * The import's attempt counter, on its own.
 *
 * ⛔ THIS FILE EXISTS BECAUSE A FALSIFICATION FOUND A HOLE IN MY OWN TESTS.
 * Reverting the per-unit key — `$this->tries + 1` instead of
 * `($unitId === $this->at ? $this->tries : 0) + 1` — left the whole database
 * suite green and only the architecture byte-check noticed. The case the
 * database tests could not reach is the one that matters most: a count left
 * over from one unit condemning the NEXT one, which would refuse a product
 * that had never been attempted at all.
 *
 * No database and no WordPress: the counter is a closure and four integers,
 * and a test that needs a site to ask about arithmetic is a test nobody runs.
 */
final class CursorProgressTest extends TestCase
{
    /** @var list<array{cursor:array<string,mixed>, done:int, failed:int}> */
    private array $written = [];

    private function progress(
        int $after = 0,
        int $at = 0,
        int $tries = 0,
        array $refused = [],
        int $pageTries = 0,
        bool $leaseHolds = true
    ): CursorProgress {
        $this->written = [];
        return new CursorProgress(
            function (array $cursor, int $done, int $failed) use ($leaseHolds): bool {
                $this->written[] = ['cursor' => $cursor, 'done' => $done, 'failed' => $failed];
                return $leaseHolds;
            },
            'products',
            $after,
            $at,
            $tries,
            $refused,
            $pageTries,
            3,
            20
        );
    }

    private function lastCursor(): array
    {
        return $this->written[count($this->written) - 1]['cursor'] ?? [];
    }

    public function testAnAttemptIsWrittenBeforeTheUnitIsWorkedOn(): void
    {
        $p = $this->progress();
        self::assertSame(UnitClaim::Ok, $p->claim(1001));
        self::assertCount(1, $this->written, 'the claim IS a write');
        self::assertSame(1001, $this->lastCursor()['at']);
        self::assertSame(1, $this->lastCursor()['tries']);
        self::assertSame(0, $this->lastCursor()['after'], 'and the position has not moved');
    }

    public function testTheSameUnitCarriesOnCounting(): void
    {
        $p = $this->progress(at: 1001, tries: 2);
        self::assertSame(UnitClaim::Ok, $p->claim(1001));
        self::assertSame(3, $this->lastCursor()['tries']);
        self::assertSame(3, $p->attempts(1001));
    }

    /**
     * THE ONE THE DATABASE SUITE COULD NOT REACH. A count belonging to one
     * unit must not be spent on the next.
     */
    public function testADifferentUnitStartsItsOwnCountAtOne(): void
    {
        $p = $this->progress(after: 1001, at: 1001, tries: 2);
        self::assertSame(UnitClaim::Ok, $p->claim(1002), 'never attempted, so never given up on');
        self::assertSame(1002, $this->lastCursor()['at']);
        self::assertSame(1, $this->lastCursor()['tries'], 'ITS first attempt');
        self::assertSame(0, $p->attempts(1001), 'and the old unit is no longer the one being counted');
    }

    /** At the limit, the same unit is given up on — and not counted again. */
    public function testTheUnitAtTheLimitIsGivenUpOnWithoutAnotherAttempt(): void
    {
        $p = $this->progress(at: 1001, tries: 3);
        self::assertSame(UnitClaim::GiveUp, $p->claim(1001));
        self::assertSame([], $this->written, 'a position nobody can pass is not counted higher');
        self::assertSame(3, $p->attempts(1001), 'the count is still there to be named');
    }

    /** …and a unit at the limit does not make its neighbour unapproachable. */
    public function testTheLimitBelongsToOneUnitOnly(): void
    {
        $p = $this->progress(at: 1001, tries: 3);
        self::assertSame(UnitClaim::GiveUp, $p->claim(1001));
        self::assertSame(UnitClaim::Ok, $p->claim(1002), 'the next unit is untouched by it');
        self::assertSame(1, $this->lastCursor()['tries']);
    }

    /**
     * An `alpha.72` cursor's count is adopted by the first unit of the page.
     *
     * That package raised one count per batch and its batch always started at
     * `after`, so the count was always about the unit this page is about to
     * attempt. Reading it as «belongs to nobody» would throw away the three
     * attempts a stalled job has already paid for, and would mean a job
     * upgraded mid-stall starts its counting again — which is not a
     * correctness problem but is a loss of evidence the owner asked not to
     * have to repair by hand.
     */
    public function testACountFromTheOlderCursorShapeIsAdoptedByTheFirstUnit(): void
    {
        $p = $this->progress(at: 0, tries: 3);
        self::assertSame(UnitClaim::GiveUp, $p->claim(1001), 'three attempts already paid for');
        self::assertSame(3, $p->attempts(1001));
    }

    /** …and a count of nought is adopted by nobody, which is the ordinary case. */
    public function testAFreshCursorGivesTheFirstUnitItsOwnFirstAttempt(): void
    {
        $p = $this->progress(at: 0, tries: 0);
        self::assertSame(UnitClaim::Ok, $p->claim(1001));
        self::assertSame(1, $this->lastCursor()['tries']);
    }

    public function testALandingClearsTheCountAndMovesThePosition(): void
    {
        $p = $this->progress(at: 1001, tries: 2);
        self::assertTrue($p->landed(1001, 1, 0));
        self::assertSame(1001, $this->lastCursor()['after']);
        self::assertSame(0, $this->lastCursor()['at']);
        self::assertSame(0, $this->lastCursor()['tries']);
        self::assertSame(1, $p->done());
        self::assertSame(1, $p->recorded());
    }

    /**
     * A refusal is written WHEN IT HAPPENS, so a batch that never returns
     * does not take the name with it.
     */
    public function testARefusalIsOnTheRowAsSoonAsItIsRecorded(): void
    {
        $p = $this->progress();
        $p->claim(1001);
        self::assertTrue($p->landed(1001, 0, 1, ['1001:sku_already_used_in_this_shop']));
        self::assertSame(['1001:sku_already_used_in_this_shop'], $this->lastCursor()['refused']);
        self::assertSame(1, $p->failed());
    }

    /** Bounded, because a cursor is a position and not a log. */
    public function testTheNamesAreBoundedAndNewestLast(): void
    {
        $p = $this->progress(refused: array_map(static fn (int $n): string => 'old' . $n, range(1, 20)));
        $p->landed(1001, 0, 1, ['1001:why']);
        $kept = $this->lastCursor()['refused'];
        self::assertCount(20, $kept);
        self::assertSame('1001:why', $kept[19]);
        self::assertSame('old2', $kept[0], 'the oldest went');
    }

    /**
     * Asking whether the lease still holds is a QUESTION, not an attempt.
     *
     * The first version of this round reused `claim()` for it and the count
     * rose with every picture — three asks inside one product read as three
     * attempts on it.
     */
    public function testAskingWhetherTheLeaseHoldsDoesNotCountAsAnAttempt(): void
    {
        $p = $this->progress();
        $p->claim(1001);
        self::assertTrue($p->stillOurs());
        self::assertTrue($p->stillOurs());
        self::assertSame(1, $this->lastCursor()['tries'], 'still the first attempt');
        self::assertSame(1001, $this->lastCursor()['at']);
    }

    /** A lease that has gone refuses everything afterwards, and writes nothing. */
    public function testOnceTheLeaseIsGoneNothingElseIsWritten(): void
    {
        $p = $this->progress(leaseHolds: false);
        self::assertSame(UnitClaim::LeaseLost, $p->claim(1001));
        self::assertTrue($p->leaseLost());
        $before = count($this->written);
        self::assertSame(UnitClaim::LeaseLost, $p->claim(1002));
        self::assertFalse($p->landed(1002, 1, 0));
        self::assertFalse($p->stillOurs());
        self::assertSame($before, count($this->written), 'not one write after the lease went');
        self::assertSame(0, $p->recorded());
    }

    /** A unit to claim proves the page came back, so that count clears too. */
    public function testClaimingAUnitDisprovesAnUnreadablePage(): void
    {
        $p = $this->progress(pageTries: 2);
        $p->claim(1001);
        self::assertSame(0, $this->lastCursor()['page_tries']);
    }

    /** And the start of a phase is a clean position with the names kept. */
    public function testTheStartOfAPhaseKeepsTheNamesAndNothingElse(): void
    {
        $p = $this->progress(after: 1009, at: 1010, tries: 2, refused: ['1005:why']);
        $cursor = $p->cursorAtStartOf('orders');
        self::assertSame('orders', $cursor['phase']);
        self::assertSame(0, $cursor['after']);
        self::assertSame(0, $cursor['at']);
        self::assertSame(0, $cursor['tries']);
        self::assertSame(0, $cursor['page_tries']);
        self::assertSame(['1005:why'], $cursor['refused']);
    }
}
