<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

/**
 * How much of this request's time the queue may spend, and when to stop.
 *
 * ### Why a batch has to be able to stop itself
 *
 * Until this class there was no time budget anywhere in the plugin —
 * `set_time_limit`, `max_execution_time`, `microtime` and `hrtime` appeared
 * nowhere in `src/` — so **the only thing that ended a long batch was the
 * death of the request running it.** One batch of the Dokan import attempts
 * fifty products, and measured on the disposable environment a single product
 * with a 3000-pixel source costs between 3.4 and 4.1 seconds of
 * `wp_generate_attachment_metadata()` with nothing external involved at all.
 * Fifty of those is about two hundred seconds. No host allows that in one
 * web request.
 *
 * Being killed costs more than the unfinished work. The lease is three hundred
 * seconds and every checkpoint renews it (`JobRunner::DEFAULT_LEASE`), and a
 * killed request never calls `release()` — so after the last product that
 * landed, the job is **locked for about five minutes** during which neither a
 * cron tick nor a manual run finds anything to do. That is the «قفل منقضی
 * می‌شود» the owner sees, and it is arithmetic rather than a second fault: at
 * two products per attempt, a hundred and seventy-six products is about
 * seven and a half hours of waiting.
 *
 * ⛔ **And this is why raising the time limit is not the fix.** Whatever limit
 * is chosen, a batch that cannot stop itself will find a batch that exceeds
 * it; the import gets no further and every attempt still costs the lease.
 * «صرفاً افزایش timeout یا افزودن retry کافی نیست» — so the batch is given a
 * budget it can see, and it returns of its own accord with the lease released
 * cleanly and the progress already written down.
 *
 * ### Two questions, deliberately not one
 *
 * * **`exhausted()`** — the time is up. The runner asks it before starting
 *   another batch.
 * * **`roomForAnotherUnit()`** — there is not enough left for a unit of the
 *   size this batch has already measured. A handler asks it between units.
 *
 * They are different facts and are kept apart for the usual reason: a single
 * answer serving both would be wrong about one of them. A batch can be well
 * inside its budget and still have no room for another four-second product.
 *
 * ### Where the reserve comes from, and why it is not a number we chose
 *
 * `roomForAnotherUnit()` reserves **the slowest unit this batch has already
 * measured**. No constant, nothing to pick: on a catalogue of small pictures
 * the reserve is milliseconds and the batch runs to the end of its budget; on
 * one of large pictures it is seconds and the batch stops early. The first
 * unit of a batch always runs, because nothing has been measured yet — so a
 * single unit larger than the whole budget still gets its attempt, and when it
 * kills the request anyway the per-unit attempt count and `GIVE_UP_AFTER` name
 * it and step over it rather than letting it stall the import for ever.
 *
 * It is an estimate and is called one: a unit slower than every unit before it
 * can still overrun. What it rules out is the systematic case — fifty units of
 * known cost being started when there is room for three.
 *
 * ### The number we cannot read
 *
 * ⛔ The honest limit of this class: **PHP does not know when the request will
 * be cut off.** `max_execution_time` is PHP's own limit, and under FPM the
 * killer is more often `request_terminate_timeout`, or a web server's read
 * timeout, or a proxy — none of which PHP can see, and under CLI the value is
 * zero. So the budget is a declared figure with a conservative default,
 * narrowed by `max_execution_time` when PHP does report one, and settable by
 * whoever knows the host. It is not derived from a limit we have measured,
 * and saying otherwise would be the «برآورد» this project has corrected
 * before.
 */
final class JobBudget
{
    /**
     * Seconds, when nothing else says otherwise.
     *
     * Derived from the owner's own figures rather than from taste: on staging
     * a request currently fits one to three products before being cut off,
     * and a product there costs on the order of seconds. A budget of fifteen
     * seconds therefore stops a batch at about the point the host already
     * tolerates, while leaving room for two or three units to land. Raise it
     * with `TMC_JOB_TIME_BUDGET` on a host known to allow more.
     */
    public const DEFAULT_SECONDS = 15;

    /**
     * Below this, a batch is starting on borrowed time — and that is REPORTED
     * rather than corrected.
     *
     * ⛔ It used to be applied as `max(FLOOR_SECONDS, $seconds)` AFTER the
     * narrowing below, which made it the opposite of a floor: with
     * `max_execution_time = 3` the share is 1 second and the budget came out
     * at FIVE — more than PHP itself allows, claimed by the very method whose
     * job is to stay inside the host's walls. A host limit of 5 gave a budget
     * of 5 where the share is 3.
     *
     * A budget may never exceed the figure that was declared or the share of
     * a limit we can read. So when there is less than this left, the number
     * stays honest and `belowFloor()` says so; the batch still attempts its
     * first unit, because progress is recorded per unit and refusing all work
     * would stall the import for ever on a host we have merely guessed about.
     */
    public const FLOOR_SECONDS = 5;

    /**
     * The share of `max_execution_time` the queue may use when PHP reports one.
     *
     * Not all of it: the rest of the request — bootstrap, the admin page, the
     * redirect — has to fit too, and a batch that uses the whole limit hands
     * the fatal to somebody else's code.
     */
    public const SHARE_OF_PHP_LIMIT = 0.6;

    private float $slowestUnitMs = 0.0;

    private int $units = 0;

    private function __construct(
        private readonly float $startedAtMs,
        private readonly float $seconds,
        private readonly float $spentBeforeMs = 0.0
    ) {
    }

    /**
     * @param float $spentBeforeMs what this REQUEST had already used before the
     *        budget existed — bootstrap, the admin page, the POST handler. See
     *        `spentMs()`: the host's wall started at the request, not here.
     */
    public static function of(float $seconds, ?float $startedAtMs = null, float $spentBeforeMs = 0.0): self
    {
        return new self(
            $startedAtMs ?? hrtime(true) / 1e6,
            max(0.0, $seconds),
            max(0.0, $spentBeforeMs)
        );
    }

    /**
     * The budget this host allows, as far as it can be worked out.
     *
     * Two ceilings and never anything above either of them: the figure
     * somebody declared, and the share of a limit PHP reports. Nothing in
     * here raises the result — see `FLOOR_SECONDS` for the version that did.
     *
     * @param int   $phpLimit `max_execution_time`, where zero means «no limit»
     *                        — which is what CLI reports and is NOT a reason to
     *                        run without a budget, because the web server, FPM
     *                        and any proxy have walls of their own that PHP
     *                        cannot see
     * @param ?int  $declared an explicit figure from whoever knows the host.
     *                        A declaration SMALLER than the floor is honoured:
     *                        it is a statement about the host, and overruling
     *                        it would be this method's old mistake in the other
     *                        direction.
     * @param float $spentBeforeMs what the request used before this point
     */
    public static function forRequest(int $phpLimit, ?int $declared = null, float $spentBeforeMs = 0.0): self
    {
        $seconds = (float) ($declared !== null && $declared > 0 ? $declared : self::DEFAULT_SECONDS);
        if ($phpLimit > 0) {
            // The share, as a share — not rounded up to a whole second, which
            // is how a limit of 1 used to become a budget of 1 instead of 0.6.
            $seconds = min($seconds, $phpLimit * self::SHARE_OF_PHP_LIMIT);
        }
        return self::of($seconds, null, $spentBeforeMs);
    }

    /**
     * Less time than `FLOOR_SECONDS` to work in — a fact, not a correction.
     *
     * Printed beside the figures so that «the batch stopped at once» has a
     * visible cause on the host that caused it, rather than looking like a
     * defect in the queue.
     */
    public function belowFloor(): bool
    {
        return $this->seconds < (float) self::FLOOR_SECONDS;
    }

    /** What the request had already spent when the budget was created. */
    public function spentBeforeMs(): float
    {
        return $this->spentBeforeMs;
    }

    public function totalSeconds(): float
    {
        return $this->seconds;
    }

    /**
     * How much of this REQUEST has gone, not how much of this object's life.
     *
     * ⛔ The two are not the same and the difference is the whole question the
     * owner asked: the host's deadline started when the request arrived, and
     * by the time anything claims a job, WordPress has booted, the admin page
     * has rendered and a POST has been handled. A budget measured from its own
     * construction is a budget that thinks it has fifteen seconds of a request
     * that has already used four.
     */
    public function spentMs(): float
    {
        return $this->spentBeforeMs + max(0.0, (hrtime(true) / 1e6) - $this->startedAtMs);
    }

    public function remainingMs(): float
    {
        return ($this->seconds * 1000.0) - $this->spentMs();
    }

    public function exhausted(): bool
    {
        return $this->remainingMs() <= 0.0;
    }

    /** A unit finished, and this is how long it took. */
    public function noteUnit(float $ms): void
    {
        $this->units++;
        $this->slowestUnitMs = max($this->slowestUnitMs, max(0.0, $ms));
    }

    public function slowestUnitMs(): float
    {
        return $this->slowestUnitMs;
    }

    public function unitsMeasured(): int
    {
        return $this->units;
    }

    /**
     * Enough time left for another unit the size of the worst one so far.
     *
     * The first unit of a batch always gets its attempt: nothing has been
     * measured, so there is no reserve to hold back, and refusing it would
     * mean a batch that never starts anything.
     */
    public function roomForAnotherUnit(): bool
    {
        return $this->remainingMs() > $this->slowestUnitMs;
    }

    /**
     * One unit alone took longer than this budget — OUR figure, not the host's.
     *
     * ⛔ The distinction is the correction this round was asked for. What is
     * measured is a unit against `DEFAULT_SECONDS` or whatever was declared;
     * what is NOT measured, and cannot be from inside PHP, is the host's own
     * deadline. A unit over our fifteen seconds may well be perfectly
     * survivable on a host that allows sixty, and a unit under it can still
     * be killed by a proxy that allows ten. So the condition is reported with
     * the budget it was compared against and no claim beyond that.
     */
    public function aUnitOutgrewTheBudget(): bool
    {
        return $this->slowestUnitMs > $this->seconds * 1000.0;
    }
}
