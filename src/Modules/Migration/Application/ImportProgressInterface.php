<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

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
     * Still ours? Asked in the MIDDLE of a unit, between its slow halves.
     *
     * ⛔ A question, not a second claim, and the difference is the attempt
     * count: asking «do I still hold this?» three times inside one product
     * must not read as three attempts on it. The first version of this round
     * reused `claim()` here and the count rose with every picture — a counter
     * that counts the wrong thing is the defect being fixed, one layer down.
     */
    public function stillOurs(): bool;

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
