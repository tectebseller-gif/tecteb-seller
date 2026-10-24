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

    /** Below the floor there is no point starting a batch at all. */
    public function testAVeryShortLimitStillLeavesTheFloor(): void
    {
        self::assertSame(
            (float) JobBudget::FLOOR_SECONDS,
            JobBudget::forRequest(2)->totalSeconds()
        );
        self::assertSame(
            (float) JobBudget::FLOOR_SECONDS,
            JobBudget::forRequest(0, 1)->totalSeconds()
        );
    }
}
