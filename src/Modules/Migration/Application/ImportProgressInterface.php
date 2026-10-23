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
