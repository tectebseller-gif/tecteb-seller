<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Jobs;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Jobs\JobBudget;

/**
 * WHAT THIS PROVES: a batch can tell when to stop, and the reserve it keeps
 * back is measured rather than chosen.
 *
 * The clock is injected as a starting point in milliseconds, so «ten seconds
 * have passed» is a fact in the test rather than something it waits for. A
 * timing test that sleeps is a timing test that is flaky on a loaded machine.
 */
final class JobBudgetTest extends TestCase
{
    private static function startedSecondsAgo(float $seconds, float $budget): JobBudget
    {
        return JobBudget::of($budget, (hrtime(true) / 1e6) - ($seconds * 1000.0));
    }

    public function testAFreshBudgetIsNotExhausted(): void
    {
        $budget = JobBudget::of(15.0);
        self::assertFalse($budget->exhausted());
        self::assertGreaterThan(14_000.0, $budget->remainingMs());
    }

    public function testABudgetWhoseTimeHasPassedIsExhausted(): void
    {
        self::assertTrue(self::startedSecondsAgo(16.0, 15.0)->exhausted());
    }

    /**
     * The first unit of a batch always gets its attempt.
     *
     * Nothing has been measured, so there is no reserve to hold back — and a
     * batch that refuses its first unit would claim a job, do nothing, and
     * hold the lease for the rest of its three hundred seconds.
     */
    public function testWithNothingMeasuredThereIsRoomWhileTimeRemains(): void
    {
        self::assertTrue(JobBudget::of(15.0)->roomForAnotherUnit());
        self::assertSame(0.0, JobBudget::of(15.0)->slowestUnitMs());
        self::assertSame(0, JobBudget::of(15.0)->unitsMeasured());
    }

    /**
     * The reserve is the slowest unit SEEN, which is why no constant is
     * needed: cheap units let the batch run to the end of its budget and
     * expensive ones stop it early, from the same code.
     */
    public function testTheReserveIsTheSlowestUnitMeasuredSoFar(): void
    {
        $budget = self::startedSecondsAgo(10.0, 15.0);
        $budget->noteUnit(200.0);
        // Five seconds left, two hundred milliseconds reserved.
        self::assertTrue($budget->roomForAnotherUnit());

        $budget->noteUnit(6_000.0);
        // Still five seconds left, but now six are reserved.
        self::assertFalse($budget->roomForAnotherUnit());
        self::assertSame(6_000.0, $budget->slowestUnitMs());
        self::assertSame(2, $budget->unitsMeasured());
    }

    public function testASingleUnitCanOutgrowTheWholeBudgetAndSaysSo(): void
    {
        $budget = JobBudget::of(15.0);
        $budget->noteUnit(4_000.0);
        self::assertFalse($budget->aUnitOutgrewTheBudget());

        $budget->noteUnit(20_000.0);
        self::assertTrue(
            $budget->aUnitOutgrewTheBudget(),
            'a product that needs more than a whole request has to be nameable'
        );
    }

    /** A negative duration is a broken clock, not a credit. */
    public function testANegativeUnitDurationIsReadAsZero(): void
    {
        $budget = JobBudget::of(15.0);
        $budget->noteUnit(-500.0);
        self::assertSame(0.0, $budget->slowestUnitMs());
    }

    public function testWithNoPhpLimitTheDefaultStands(): void
    {
        self::assertSame(
            (float) JobBudget::DEFAULT_SECONDS,
            JobBudget::forRequest(0)->totalSeconds(),
            'zero is what CLI reports, and it is not a reason to run unbounded'
        );
    }

    /**
     * PHP's own limit narrows the budget, and only part of it is ours.
     *
     * The rest of the request — bootstrap, the page, the redirect — has to
     * fit too; a batch that spends the whole limit hands the fatal to
     * somebody else's code.
     */
    public function testPhpsOwnLimitOnlyEverNarrowsTheBudget(): void
    {
        // Thirty seconds would allow eighteen, but the default is fifteen and
        // PHP's limit is not a reason to spend MORE.
        self::assertSame(15.0, JobBudget::forRequest(30)->totalSeconds());
        // Twenty allows twelve, which is below the default, so twelve it is.
        self::assertSame(12.0, JobBudget::forRequest(20)->totalSeconds());
    }

    public function testADeclaredFigureIsUsedButNeverAboveWhatPhpAllows(): void
    {
        self::assertSame(60.0, JobBudget::forRequest(0, 60)->totalSeconds());
        self::assertSame(
            18.0,
            JobBudget::forRequest(30, 60)->totalSeconds(),
            'the declaration is about the host wall; the php limit is one we can read'
        );
    }

    /**
     * THE BUDGET NEVER EXCEEDS A CEILING, including the floor's own doing.
     *
     * ⛔ This test used to assert the opposite, and that is why the defect
     * shipped: it was called `testAVeryShortLimitStillLeavesTheFloor` and it
     * demanded `forRequest(2) === 5.0` — a budget of five seconds on a host
     * that says two. The floor was applied with `max()` AFTER the narrowing,
     * so it was not a floor at all but a licence to claim more than PHP
     * allows, asserted by the very suite that was supposed to catch it.
     *
     * The invariant is the one the instruction states: «بودجه هرگز نباید از
     * سقف اعلام‌شده یا سهم PHP بیشتر شود». Not enough time is a FACT, reported
     * by `belowFloor()`, not a number to be corrected upwards.
     */
    public function testTheBudgetNeverExceedsTheDeclaredFigureOrPhpsShare(): void
    {
        // Every limit the instruction names, and the share is exact — not
        // rounded up to a whole second, which is how a limit of 1 became a
        // budget of 1 instead of 0.6.
        $cases = [
            // php limit => the share, or the default when there is no limit
            0 => 15.0,
            1 => 0.6,
            3 => 1.8,
            5 => 3.0,
            10 => 6.0,
            600 => 15.0,
        ];
        foreach ($cases as $limit => $expected) {
            $budget = JobBudget::forRequest($limit);
            // A delta, because `3 * 0.6` is 1.7999999999999998 in binary
            // floating point and the point of the assertion is the seconds,
            // not the last bit of the mantissa.
            self::assertEqualsWithDelta(
                $expected,
                $budget->totalSeconds(),
                0.000_001,
                'php limit ' . $limit . ' must give the share, never more'
            );
            if ($limit > 0) {
                self::assertLessThanOrEqual(
                    (float) $limit * JobBudget::SHARE_OF_PHP_LIMIT,
                    $budget->totalSeconds(),
                    'php limit ' . $limit . ': above our share of what php allows'
                );
            }
        }
    }

    /**
     * A declaration SMALLER than the floor is honoured, not overruled.
     *
     * It is a statement about the host made by somebody who knows it, and
     * raising it would be the old mistake in the other direction.
     */
    public function testADeclarationBelowTheFloorIsHonouredAndReported(): void
    {
        $budget = JobBudget::forRequest(0, 3);
        self::assertSame(3.0, $budget->totalSeconds());
        self::assertTrue($budget->belowFloor());

        $tiny = JobBudget::forRequest(0, 1);
        self::assertSame(1.0, $tiny->totalSeconds());
        self::assertTrue($tiny->belowFloor());
    }

    /**
     * «Below the floor» is a report, and only below it.
     *
     * The flag is what the queue page prints so that a batch that stopped at
     * once has a visible cause on the host that caused it. At the floor it is
     * false: five seconds is the floor, not under it.
     */
    public function testBelowFloorIsTrueOnlyWhenThereReallyIsLessThanTheFloor(): void
    {
        self::assertTrue(JobBudget::forRequest(3)->belowFloor(), '1.8s is under the floor');
        self::assertFalse(JobBudget::forRequest(0)->belowFloor(), 'the default is well over it');
        self::assertFalse(
            JobBudget::of((float) JobBudget::FLOOR_SECONDS)->belowFloor(),
            'exactly the floor is not below it'
        );
        self::assertTrue(JobBudget::of(4.999)->belowFloor());
    }

    /**
     * The host's wall started at the REQUEST, so what the request already
     * spent counts against the budget.
     *
     * ⛔ Without this the budget believes it has fifteen seconds of a request
     * that has already used four on bootstrap, the admin page and a POST —
     * which is the owner's own question: «زمان صرف‌شده پیش از شروع JobBudget
     * چگونه در بودجهٔ درخواست حساب می‌شود».
     */
    public function testTimeTheRequestSpentBeforeTheBudgetExistedIsCountedIn(): void
    {
        $fresh = JobBudget::of(10.0);
        self::assertGreaterThan(9_900.0, $fresh->remainingMs());

        $late = JobBudget::of(10.0, null, 4_000.0);
        self::assertSame(4_000.0, $late->spentBeforeMs());
        self::assertLessThanOrEqual(6_000.0, $late->remainingMs());
        self::assertGreaterThan(5_900.0, $late->remainingMs());

        // And a request that has already used more than the whole budget is
        // exhausted before it starts — the honest answer, and the one that
        // stops a batch taking a three-hundred-second lease for nothing.
        $spent = JobBudget::of(10.0, null, 11_000.0);
        self::assertTrue($spent->exhausted());
        self::assertFalse($spent->roomForAnotherUnit());
    }

    /**
     * «One unit outgrew the budget» is about OUR figure and nothing else.
     *
     * The host's real deadline cannot be read from inside PHP, so the only
     * comparison this class can make is against the budget it was given.
     */
    public function testAUnitOutgrowingTheBudgetIsMeasuredAgainstOurOwnFigure(): void
    {
        $budget = JobBudget::of(2.0);
        self::assertFalse($budget->aUnitOutgrewTheBudget());
        $budget->noteUnit(1_999.0);
        self::assertFalse($budget->aUnitOutgrewTheBudget());
        $budget->noteUnit(2_001.0);
        self::assertTrue($budget->aUnitOutgrewTheBudget());

        // Same unit, a host that allows sixty: not over the budget at all.
        $generous = JobBudget::of(60.0);
        $generous->noteUnit(2_001.0);
        self::assertFalse($generous->aUnitOutgrewTheBudget());
    }
}
