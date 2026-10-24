<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

/**
 * Which step a worker is in, and how long the steps it finished took.
 *
 * ### The problem this is for
 *
 * On staging with `alpha.74`, job #1 advances a product or two per manual run
 * and then the host answers Request Timeout. Nothing reaches `debug.log` or
 * `error_log`, and that is correct rather than mysterious: no PHP error
 * happens. The request is cut off from outside, and `JobRunner` has described
 * that case since it was written — «the process dies, no batch comes back,
 * nothing is written, the lease simply expires».
 *
 * «Nothing is written» is the part that has to change. Until this class,
 * nothing in the plugin measured anything: `microtime` and `hrtime` appeared
 * nowhere in `src/`. So a worker that died left behind its position and not
 * one word about WHERE it died, and six different explanations fitted the
 * same evidence equally well.
 *
 * ### Two facts, written separately — and one of them BEFORE the work
 *
 * * **«I am about to do step X on unit Y»**, written before X starts. This is
 *   the only one that can survive a process ceasing to exist, and it is the
 *   one that names a STALLED step. Same reasoning as `page_tries` and the
 *   per-unit claim one layer up: a guard, or a record, that runs after the
 *   work is neither.
 * * **«step X took N milliseconds»**, written once X returns. This names a
 *   SLOW step, which is a different question — a step can be slow in every
 *   unit and never be the one a worker dies in, and the step a worker dies in
 *   may be fast in the ninety units before it.
 *
 * Joining them into one number would lose both, which is this repository's
 * oldest recurring mistake.
 *
 * ### Nothing sensitive can get in here, by construction
 *
 * The instruction says «اطلاعات حساس ثبت نشود», and a promise in a docblock
 * is not a guarantee. So the TYPES do it: a unit is an `int`, a duration is a
 * number, and a step is **one of a closed list of names** (`STEPS`).
 *
 * ⛔ The closed list is the correction this round was asked for. Reducing a
 * string to `[a-z0-9_]` and thirty-two characters was NOT a proof that what
 * is left carries nothing: a SKU, a slug, an order reference or an e-mail's
 * local part survive that reduction almost intact. A name that is not on the
 * list is stored as `UNLISTED` — the record still exists, because the fact
 * that a worker was in SOME step is the fact this class is for, and it now
 * carries nothing whatever about which. An architecture test holds that every
 * call site in `src/` passes a listed name, so an unlisted one is a defect
 * caught in the build rather than a value quietly renamed in production.
 *
 * And it is bounded. A cursor is a position, not a log: the last
 * `self::KEPT` finished steps are carried and the rest fall off the front.
 */
final class StepTiming
{
    /**
     * How many finished steps the cursor carries.
     *
     * Forty-eight, which is about nine products' worth of the import's five
     * per-unit steps plus the page read — enough to see a pattern across
     * several units, few enough that the cursor stays a position rather than
     * becoming a log. The count in the report is not derived from this; these
     * are the names and the numbers.
     *
     * ⛔ Twenty-four at first, and the number was wrong for a reason worth
     * keeping: with five steps a product, a batch of five products pushed the
     * `page_read` record off the front — so the one step that happens once a
     * batch was the one that always fell out, and a test asserting it was
     * present failed about code that was working to its stated bound.
     */
    public const KEPT = 48;

    /** The longest a step name may be, after reduction. */
    public const NAME_LIMIT = 32;

    /**
     * Every step this plugin may record. Nothing else is a step.
     *
     * In the order one product goes through them, which is also the order a
     * reader of the queue page needs: the page read happens once a batch, the
     * rest once a unit.
     */
    public const STEPS = [
        'page_read',
        'judge',
        'row_and_manifest',
        'image_file_copy',
        'image_metadata',
        'attach',
        // Writing ONE shop record — a staff member, a balance row, a
        // withdrawal request. One name for the three because what a person
        // reading the row needs to know is «it was writing a record», and
        // which kind is already in the cursor's phase.
        'record_write',
    ];

    /**
     * What a name that is not on the list becomes.
     *
     * Not an empty string, because «some step was in flight» and «no step was
     * in flight» are different facts and the first is the one that survives a
     * killed worker. Not the caller's string either — see the class docblock.
     */
    public const UNLISTED = 'step_not_listed';

    private string $running = '';

    private int $runningUnit = 0;

    private int $runningSince = 0;

    /** @var list<string> `<unit>:<step>:<ms>` */
    private array $finished = [];

    /** Monotonic, so a clock that jumps cannot produce a negative duration. */
    private ?float $enteredAt = null;

    /**
     * The worst step ever seen on this job, outside the window.
     *
     * ⛔ Because the window is the wrong place to answer «which step is
     * slow». Measured: the import's fixture has twelve products and five
     * steps each, so sixty records went through a window of forty-eight and
     * the `page_read` — the one step that happens once a batch, and the one
     * that would be slow if the READER were the problem — fell off the front
     * every time. A window is for seeing a pattern across recent units; the
     * worst thing that ever happened has to survive leaving it.
     */
    private string $worstStep = '';

    private int $worstMs = 0;

    /**
     * Where a PREVIOUS worker was when it stopped writing — never this one.
     *
     * ⛔ Added because the alternative loses the only fact this class exists
     * to produce. A restored `running` was written by a process that never
     * closed the step, and the next worker's first `entering()` used to
     * overwrite it — so on a host that cuts off every attempt, each attempt
     * erased the evidence of the one before it, and the owner would be left
     * with a single data point after being told the point of the round was to
     * narrow the step down.
     *
     * Keeping them apart is also what lets the page obey «مرحلهٔ کهنه یا قفل
     * منقضی نباید به‌عنوان اجرای زنده … نمایش داده شود»: `running` is a step
     * THIS process entered, `stalled` is a step somebody wrote down and never
     * closed, and no reader has to guess which one it is holding.
     */
    private string $stalledStep = '';

    private int $stalledUnit = 0;

    private int $stalledSince = 0;

    /**
     * @param list<mixed> $finished as read back from a cursor; anything that is
     *                              not a well-shaped record is dropped rather
     *                              than repaired, because a half-read record
     *                              would be a measurement nobody took
     */
    public function __construct(
        string $running = '',
        int $runningUnit = 0,
        int $runningSince = 0,
        array $finished = [],
        string $worst = '',
        string $stalled = ''
    ) {
        $this->running = self::key($running);
        $this->runningUnit = max(0, $runningUnit);
        $this->runningSince = max(0, $runningSince);
        foreach ($finished as $record) {
            // Shape first, then the name against the list — a cursor is a
            // value from the database and a record naming an unlisted step is
            // either from a build that had a step we have since dropped or
            // from somebody who edited the row. Either way it is dropped
            // rather than printed: this table is read by a person deciding
            // what to do next.
            if (!is_string($record) || preg_match('~^\d+:([a-z0-9_]{1,32}):\d+$~', $record, $parts) !== 1) {
                continue;
            }
            if (!in_array($parts[1], self::STEPS, true) && $parts[1] !== self::UNLISTED) {
                continue;
            }
            $this->finished[] = $record;
        }
        $this->finished = array_slice($this->finished, -self::KEPT);
        // The worst as recorded, or — for a cursor written before this key
        // existed — the worst still inside the window. Never worse than what
        // the window can prove, which is the conservative direction.
        if (preg_match('~^([a-z0-9_]{1,32}):(\d+)$~', $worst, $parts) === 1
            && (in_array($parts[1], self::STEPS, true) || $parts[1] === self::UNLISTED)
        ) {
            $this->worstStep = $parts[1];
            $this->worstMs = (int) $parts[2];
        }
        foreach ($this->finished as $record) {
            [, $step, $ms] = explode(':', $record, 3);
            if ((int) $ms > $this->worstMs) {
                $this->worstStep = $step;
                $this->worstMs = (int) $ms;
            }
        }
        if (preg_match('~^([a-z0-9_]{1,32}):(\d+):(\d+)$~', $stalled, $parts) === 1
            && (in_array($parts[1], self::STEPS, true) || $parts[1] === self::UNLISTED)
        ) {
            $this->stalledStep = $parts[1];
            $this->stalledUnit = (int) $parts[2];
            $this->stalledSince = (int) $parts[3];
        }
    }

    /** @param array<string,mixed> $cursor */
    public static function fromCursor(array $cursor): self
    {
        return new self(
            (string) ($cursor['running'] ?? ''),
            (int) ($cursor['running_unit'] ?? 0),
            (int) ($cursor['running_since'] ?? 0),
            (array) ($cursor['timings'] ?? []),
            (string) ($cursor['worst'] ?? ''),
            (string) ($cursor['stalled'] ?? '')
        );
    }

    /**
     * Note that a step is starting. The caller writes the cursor after this.
     *
     * ⛔ Starting a step ENDS the one in flight, with its real duration. The
     * first version marked it «abandoned» with a duration of nought, on the
     * theory that `entering()` twice without `left()` between them was a
     * caller that had lost track. It is not: a sequence of steps is exactly
     * that shape, and the honest reading of «A was in flight and now B is» is
     * «A took this long». The cost of getting it wrong was measured — the
     * file copy, which is always followed by the metadata, never once had a
     * number against it, and on network-backed storage that is the half most
     * worth knowing about.
     *
     * The genuinely abandoned case needs no name of its own: a worker that
     * stops existing leaves `running` set and no record at all, which is
     * precisely the fact `entering()` exists to leave behind.
     */
    public function entering(string $step, int $unitId, ?int $now = null): void
    {
        if ($this->running !== '' && $this->enteredAt === null) {
            // Not ours. A cursor restored it, which means the process that
            // wrote it never closed the step — so it becomes the stall record
            // instead of being overwritten by ours. See `$stalledStep`.
            $this->stalledStep = $this->running;
            $this->stalledUnit = $this->runningUnit;
            $this->stalledSince = $this->runningSince;
            $this->running = '';
        }
        $this->left();
        $this->running = self::key($step);
        $this->runningUnit = max(0, $unitId);
        $this->runningSince = $now ?? time();
        $this->enteredAt = hrtime(true) / 1e6;
    }

    /**
     * The step in flight returned. Gives back how long it took, in ms.
     *
     * Zero and no record when nothing was in flight — a caller that leaves
     * and never entered has measured nothing, and inventing a duration for it
     * would put a number in the table that no step produced.
     */
    public function left(): float
    {
        if ($this->running === '' || $this->enteredAt === null) {
            return 0.0;
        }
        $ms = max(0.0, (hrtime(true) / 1e6) - $this->enteredAt);
        $this->record($this->running, $this->runningUnit, (int) round($ms));
        $this->running = '';
        $this->runningUnit = 0;
        $this->runningSince = 0;
        $this->enteredAt = null;
        return $ms;
    }

    /** The step in flight, or `''` when none is. */
    public function running(): string
    {
        return $this->running;
    }

    public function runningUnit(): int
    {
        return $this->runningUnit;
    }

    public function runningSince(): int
    {
        return $this->runningSince;
    }

    /**
     * The step a previous worker left open, as `[step, unit, unix time]`.
     *
     * `['', 0, 0]` when no worker has ever left one open on this job. It is
     * not cleared by the work that follows it: it is the record of an attempt
     * that ended without closing its step, and that stays true afterwards.
     *
     * @return array{0:string, 1:int, 2:int}
     */
    public function stalled(): array
    {
        return [$this->stalledStep, $this->stalledUnit, $this->stalledSince];
    }

    /**
     * The longest step EVER seen on this job, as `[step, ms]`, or `['', 0]`.
     *
     * Ever, not «in the window»: see `$worstStep`. A step that was slow three
     * hundred products ago is still the answer to «which step is slow».
     *
     * @return array{0:string, 1:int}
     */
    public function slowest(): array
    {
        return [$this->worstStep, $this->worstMs];
    }

    /** @return list<string> */
    public function finished(): array
    {
        return $this->finished;
    }

    /**
     * The part of a cursor this owns.
     *
     * Keys are absent rather than empty when there is nothing to say, so a
     * cursor from a build without this class and a cursor with nothing in
     * flight read the same — and neither grows by four keys for no reason.
     *
     * @return array<string,mixed>
     */
    public function fragment(): array
    {
        $fragment = [];
        if ($this->running !== '') {
            $fragment['running'] = $this->running;
            $fragment['running_unit'] = $this->runningUnit;
            $fragment['running_since'] = $this->runningSince;
        }
        if ($this->finished !== []) {
            $fragment['timings'] = $this->finished;
        }
        if ($this->worstStep !== '') {
            $fragment['worst'] = $this->worstStep . ':' . $this->worstMs;
        }
        if ($this->stalledStep !== '') {
            $fragment['stalled'] = $this->stalledStep . ':' . $this->stalledUnit . ':' . $this->stalledSince;
        }
        return $fragment;
    }

    private function record(string $step, int $unitId, int $ms): void
    {
        $this->finished[] = $unitId . ':' . $step . ':' . $ms;
        $this->finished = array_slice($this->finished, -self::KEPT);
        if ($ms > $this->worstMs) {
            $this->worstStep = $step;
            $this->worstMs = $ms;
        }
    }

    /**
     * A step name reduced to something that cannot carry anything but a key.
     *
     * This is the privacy guarantee, and it is structural: whatever a caller
     * passes, what is stored is lower-case ASCII letters, digits and
     * underscores, at most `NAME_LIMIT` of them. A product title, a path, an
     * address or an amount cannot survive it.
     */
    private static function key(string $step): string
    {
        $key = strtolower($step);
        $key = (string) preg_replace('~[^a-z0-9_]+~', '_', $key);
        $key = trim($key, '_');
        if ($key === '') {
            return '';
        }
        $key = substr($key, 0, self::NAME_LIMIT);
        // THE LIST, and it is checked after the reduction rather than before:
        // a caller that passes `Page Read` means the step, and a caller that
        // passes anything else gets no say in what is written down.
        return in_array($key, self::STEPS, true) ? $key : self::UNLISTED;
    }
}
