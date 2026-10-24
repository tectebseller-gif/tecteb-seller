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

    /** Below this there is no point starting a batch at all. */
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
        private readonly float $seconds
    ) {
    }

    public static function of(float $seconds, ?float $startedAtMs = null): self
    {
        return new self(
            $startedAtMs ?? hrtime(true) / 1e6,
            max(0.0, $seconds)
        );
    }

    /**
     * The budget this host allows, as far as it can be worked out.
     *
     * @param int  $phpLimit `max_execution_time`, where zero means «no limit»
     *                       — which is what CLI reports and is NOT a reason to
     *                       run without a budget
     * @param ?int $declared an explicit figure from whoever knows the host
     */
    public static function forRequest(int $phpLimit, ?int $declared = null): self
    {
        $seconds = $declared !== null && $declared > 0 ? $declared : self::DEFAULT_SECONDS;
        if ($phpLimit > 0) {
            // Never more than PHP's own share of its limit, even when a
            // larger figure was declared: the declaration is about the host's
            // wall, and this one is a wall we can actually read.
            $seconds = min($seconds, max(1, (int) floor($phpLimit * self::SHARE_OF_PHP_LIMIT)));
        }
        return self::of((float) max(self::FLOOR_SECONDS, $seconds));
    }

    public function totalSeconds(): float
    {
        return $this->seconds;
    }

    public function spentMs(): float
    {
        return max(0.0, (hrtime(true) / 1e6) - $this->startedAtMs);
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
     * One unit alone needs more than this whole request has.
     *
     * A named condition rather than a silent stall: a product whose picture
     * takes longer than any single request will never land however many
     * workers try, so somebody has to be told which product it is and what
     * it would take. The handler reports it; this only answers the question.
     */
    public function aUnitOutgrewTheBudget(): bool
    {
        return $this->slowestUnitMs > $this->seconds * 1000.0;
    }
}
