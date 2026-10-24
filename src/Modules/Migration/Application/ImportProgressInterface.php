<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Migration\Domain\LeaseHold;
use Tecteb\Marketplace\Modules\Migration\Domain\UnitClaim;

/**
 * How an import tells the queue where it is — per unit, and before the work.
 *
 * **Claim, then write, then record.** `alpha.72` recorded a unit as it landed,
 * which fixed the stall it was written for and left two holes the owner then
 * read out of the source: the product row, the media copy and the manifest all
 * happened BEFORE anything token-guarded, so a worker whose lease had been
 * handed to somebody else still wrote a product and an image into an import it
 * no longer owned; and the attempt counter was raised once per batch, so the
 * second unit of a batch could die for ever without a single attempt being
 * written down.
 *
 * Both are the same mistake seen from two sides — the bookkeeping was not
 * attached to the unit — so both are answered by the same shape: every unit is
 * claimed by its own id before any of its writes, and the count of attempts
 * belongs to that id.
 *
 * ⛔ The claim is not advice. A `LeaseLost` means the caller must write
 * nothing further, including anything it is half way through; and the
 * guarantee the caller owes in return is that whatever it has already written
 * for that unit is either complete or recognisably incomplete to whoever
 * arrives next — never a duplicate and never silently counted as finished.
 */
interface ImportProgressInterface
{
    /**
     * Record that this unit is being attempted, before attempting it.
     *
     * The only moment this can be written is beforehand, because the failure
     * being guarded against is a process that stops existing — measured on
     * staging, where nothing reached `debug.log` because nothing was alive to
     * write it.
     */
    public function claim(int $unitId): UnitClaim;

    /** How many times this id has been claimed without ever landing. */
    public function attempts(int $unitId): int;

    /**
     * The unit's dependent writes, run under the lease or not at all.
     *
     * ⛔ **This replaced a question, and the question was removed rather than
     * kept beside it.** `alpha.73` asked `stillOurs()` between the slow half
     * and the attach, which is CHECK and then WRITE with two round trips
     * between them. Measured on its own installed bytes: a worker parked in
     * that gap was refused at its next checkpoint — `outcome:lease_lost` —
     * and had already replaced the picture a second worker attached, leaving
     * that worker's copy in the shop's media library with nothing pointing at
     * it. Keeping the question as well would leave a decoy that reads like a
     * guard, which is how this gap came to exist; and `alpha.69`'s rule is
     * that a guard which is no longer needed is REMOVED, not loosened.
     *
     * Everything the unit must not outlive its hold on the job goes in here:
     * the row, the attach, the manifest entry. Nothing slow does — the file
     * copy and the metadata are the caller's business and happen before.
     *
     * @param \Closure():bool $write `false` means the work failed, and the
     *                               whole unit is rolled back
     */
    public function writing(\Closure $write): LeaseHold;

    /**
     * About to do a named step on a unit — written BEFORE the step runs.
     *
     * ⛔ This is the only record that can survive the thing it is for. On
     * staging, a worker is cut off mid-product with nothing in `debug.log`,
     * because nothing fatal happens: the request is killed from outside and
     * a dead process writes nothing afterwards. So «where did it stop» can
     * only be answered by something written beforehand — the same reasoning
     * as `page_tries` and as `claim()` itself.
     *
     * It also flushes the durations of the steps already finished, which
     * costs nothing: the write was happening anyway.
     *
     * Step names are machine keys and are reduced to `[a-z0-9_]` by
     * `StepTiming`, so nothing a product carries — a title, a SKU, a file
     * name, a URL — can be recorded through here. That is a property of the
     * type and not a promise.
     *
     * @return bool false when the lease went, in which case the step must
     *              NOT be run
     */
    public function entering(string $step, int $unitId): bool;

    /**
     * The step in flight returned, and this is how long it took, in ms.
     *
     * A separate fact from `entering()` and deliberately not merged with it:
     * a step can be slow in every unit and never be the one a worker dies
     * in, and the step a worker dies in can be fast in the ninety units
     * before it. One number serving both questions is wrong about one of
     * them.
     */
    public function left(): float;

    /**
     * Is there time for another unit of this request?
     *
     * `false` means stop the batch and return what has been done — not that
     * anything failed. The caller must stop BETWEEN units: stopping inside
     * one is what the host already does, and the point of asking is to not
     * be in the middle of a unit when it happens.
     */
    public function keepGoing(): bool;

    /**
     * Why `keepGoing()` said no, as a machine key for the notes.
     *
     * Two answers, because they call for different things from a human:
     * `time_budget_spent` is ordinary — the request did what it could and the
     * next one carries on — while
     * `time_budget_one_unit_longer_than_the_request` says a single product
     * needs more than any one request has, which no amount of retrying will
     * fix. `''` when nothing stopped it.
     */
    public function stopReason(): string;

    /**
     * This unit is finished with: counted, named if it was refused, and the
     * cursor moved past it.
     *
     * @param list<string> $notes ids and reasons, carried in the cursor so a
     *                            refusal recorded mid-batch survives a batch
     *                            that never gets to return
     * @return bool false when the lease went while this was being written
     */
    public function landed(int $unitId, int $done = 0, int $failed = 0, array $notes = []): bool;
}
