<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Core\Jobs\JobBudget;
use Tecteb\Marketplace\Core\Jobs\StepTiming;
use Tecteb\Marketplace\Modules\Migration\Domain\LeaseHold;
use Tecteb\Marketplace\Modules\Migration\Domain\UnitClaim;

/**
 * The import's position, kept in the job cursor, one unit at a time.
 *
 * The cursor is this handler's own data — the schema says so in as many words
 * — so the attempt count, the position and the names of what was refused all
 * live there together, and the queue page already prints it. Bounded, because
 * a cursor is a position and not a log.
 *
 * **Why the count is keyed to a unit.** ⛔ `alpha.72` raised one `tries` at
 * the top of the batch and every unit that landed set it back to nought.
 * Measured on its own bytes, with the same interruption in the same place:
 * killed inside the FIRST product's image work the cursor read
 * `{"after":0,"tries":1}`, and killed inside the SECOND it read
 * `{"after":6656,"tries":0}` — the whole attempt on the second product left no
 * trace, so `GIVE_UP_AFTER` could never fire for any unit except the first in
 * its batch. Here the count belongs to `at`, and only that unit landing or
 * being stepped over clears it.
 *
 * **Why `page_tries` is separate.** It is about whether the page could be read
 * at all, which is a different fact with a different disproof: any page coming
 * back clears it, and a unit landing clears it too. One counter serving both
 * facts loses both — which is exactly the defect above, seen from the other
 * side.
 */
final class CursorProgress implements ImportProgressInterface
{
    private int $at;

    private int $tries;

    /** @var list<string> */
    private array $refused;

    private int $done = 0;

    private int $failed = 0;

    private int $recorded = 0;

    private bool $leaseLost = false;

    /** Milliseconds this unit's steps have cost so far. */
    private float $unitMs = 0.0;

    /** Units this batch has begun, so the first one is never refused. */
    private int $unitsStarted = 0;

    /**
     * @param \Closure(array<string,mixed>, int, int): bool $checkpoint
     * @param \Closure(\Closure(): bool): string|null $underLease the unit-of-work
     *        gate from the runner; `null` leaves `writing()` with nothing to
     *        hold, which is a REFUSAL and not a free pass — see `writing()`
     * @param list<string> $refused
     * @param ?JobBudget $budget `null` leaves the batch unbounded, which is
     *        what the tools want and is NOT what a web request wants
     */
    public function __construct(
        private readonly \Closure $checkpoint,
        private readonly ?\Closure $underLease,
        private readonly string $phase,
        private int $after,
        int $at,
        int $tries,
        array $refused,
        private int $pageTries,
        private readonly int $giveUpAfter,
        private readonly int $refusalsKept,
        private readonly ?StepTiming $timing = null,
        private readonly ?JobBudget $budget = null
    ) {
        $this->at = $at;
        $this->tries = $tries;
        $this->refused = $refused;
    }

    public function claim(int $unitId): UnitClaim
    {
        if ($this->leaseLost) {
            return UnitClaim::LeaseLost;
        }
        // An `alpha.72` cursor carries a count that names no unit, and that
        // count is not meaningless: its batch always began at `after`, so it
        // was always about the first unit this page would attempt — which is
        // this one. Adopting it keeps the three attempts a stalled staging job
        // has already paid for, so the upgrade needs no cursor editing and
        // loses no evidence.
        //
        // ⛔ The condition can only be true of a cursor written by that
        // package: `landed()` here clears `at` and `tries` together, so «no
        // unit, and yet a count» is a shape this tree never writes.
        if ($this->at === 0 && $this->tries > 0) {
            $this->at = $unitId;
        }
        if ($unitId === $this->at && $this->tries >= $this->giveUpAfter) {
            // Not counted again. A position nobody can get past is not made
            // passable by counting higher, and the caller is about to name it.
            return UnitClaim::GiveUp;
        }
        $this->tries = ($unitId === $this->at ? $this->tries : 0) + 1;
        $this->at = $unitId;
        // There is a unit to claim, so the page plainly came back: «could not
        // even read the page» is disproved here, and NOT at the next landing.
        // A page that reads and then kills the worker inside its first unit
        // must count against that unit, or the step-over names the reading and
        // the unit is never blamed at all.
        $this->pageTries = 0;
        if (!$this->write(0, 0)) {
            return UnitClaim::LeaseLost;
        }
        $this->unitsStarted++;
        return UnitClaim::Ok;
    }

    public function attempts(int $unitId): int
    {
        return $unitId === $this->at ? $this->tries : 0;
    }

    public function writing(\Closure $write): LeaseHold
    {
        if ($this->leaseLost) {
            return LeaseHold::LeaseLost;
        }
        if ($this->underLease === null) {
            // ⛔ A progress object built without a gate cannot promise what
            // this method promises, so it refuses rather than writing
            // anyway. The alternative — running the closure bare — is a guard
            // that evaporates wherever somebody forgot to wire it, which is
            // the shape of every defect in this file's history. The path with
            // no lease at all does not come through here: it passes no
            // progress object (`DokanImportJob::withoutALease()`).
            return LeaseHold::LeaseLost;
        }
        $answer = ($this->underLease)($write);
        if ($answer === '') {
            return LeaseHold::Held;
        }
        if ($answer === 'not_written') {
            // The work failed and the lease is still ours: this unit is a
            // failure and the import carries on. Nothing about the lease is
            // remembered, because nothing about it changed.
            return LeaseHold::NotWritten;
        }
        // `lease_lost` and `unreadable` both end this worker's writing, and
        // for the same reason: neither is a «yes». They are kept apart by the
        // repository for its own report and joined here, because what the
        // caller must do about them is identical.
        $this->leaseLost = true;
        return LeaseHold::LeaseLost;
    }

    public function entering(string $step, int $unitId): bool
    {
        if ($this->leaseLost) {
            return false;
        }
        if ($this->timing === null) {
            // Nothing is recording, so there is nothing to write and no
            // reason to refuse the step: a build without the timing object
            // behaves exactly as `alpha.74` did.
            return true;
        }
        $this->timing->entering($step, $unitId);
        // ⛔ Written NOW, not at the end of the unit. The failure this is for
        // is a process that stops existing, and a record of where it was has
        // to be on disk before it gets there.
        return $this->write(0, 0);
    }

    public function left(): float
    {
        if ($this->timing === null) {
            return 0.0;
        }
        $ms = $this->timing->left();
        // The unit's own cost is the sum of its steps, and the budget needs
        // the per-UNIT figure to reserve against — so only the step that
        // closes a unit feeds it. `unitFinished()` does that, from
        // `landed()`; this just stops the clock.
        $this->unitMs += $ms;
        return $ms;
    }

    public function keepGoing(): bool
    {
        if ($this->leaseLost) {
            return false;
        }
        if ($this->budget === null) {
            return true;
        }
        if ($this->unitsStarted === 0) {
            // ⛔ The first unit of a batch always gets its attempt. Nothing
            // has been measured yet, so there is no reserve to hold back —
            // and a batch that claims a job and then does nothing at all
            // would take the lease for three hundred seconds to achieve
            // nothing, which is the cost this budget exists to stop paying.
            return true;
        }
        return $this->budget->roomForAnotherUnit();
    }

    /** Why the batch stopped, for the note the handler returns. */
    public function stopReason(): string
    {
        if ($this->budget === null) {
            return '';
        }
        if ($this->budget->aUnitOutgrewTheBudget()) {
            // A product that alone needs more than a whole request will never
            // land however many workers try it, so it is NAMED rather than
            // retried in silence. The attempt count and `GIVE_UP_AFTER` step
            // over it; this says why.
            return 'time_budget_one_unit_longer_than_the_request';
        }
        return 'time_budget_spent';
    }

    /** @param list<string> $notes */
    public function landed(int $unitId, int $done = 0, int $failed = 0, array $notes = []): bool
    {
        if ($this->leaseLost) {
            return false;
        }
        foreach ($notes as $note) {
            if (is_string($note) && $note !== '') {
                $this->refused[] = $note;
            }
        }
        $this->refused = array_slice($this->refused, -$this->refusalsKept);
        $this->after = $unitId;
        // This unit is finished with, so neither count is about anything any
        // more — and the page plainly read, since a unit came out of it.
        $this->at = 0;
        $this->tries = 0;
        if (!$this->write($done, $failed)) {
            return false;
        }
        $this->done += $done;
        $this->failed += $failed;
        $this->recorded++;
        // The unit is over, so what it cost is now a measured fact and the
        // budget can reserve against it. Reset either way: a unit that cost
        // nothing because nothing was timed must not inflate the next one.
        $this->budget?->noteUnit($this->unitMs);
        $this->unitMs = 0.0;
        return true;
    }

    /** @return array<string,mixed> */
    public function cursor(): array
    {
        return $this->cursorFor($this->phase, $this->after);
    }

    /**
     * The cursor as it would be at the START of a named phase.
     *
     * Used when a phase ends: products begin at zero rather than at the last
     * vendor's id, and the attempt counts travel with nothing because they
     * were about a place we have left.
     *
     * @return array<string,mixed>
     */
    public function cursorAtStartOf(string $phase): array
    {
        $cursor = ['phase' => $phase, 'after' => 0, 'at' => 0, 'tries' => 0, 'page_tries' => 0];
        if ($this->refused !== []) {
            $cursor['refused'] = $this->refused;
        }
        if ($this->timing !== null) {
            // The timings travel: they are about this REQUEST, not about the
            // position, and a phase boundary is exactly where somebody wants
            // to see what the phase that just ended cost. Nothing is in
            // flight here, so no stale `running` can ride along.
            $cursor = array_merge($cursor, $this->timing->fragment());
        }
        return $cursor;
    }

    public function after(): int
    {
        return $this->after;
    }

    public function done(): int
    {
        return $this->done;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function recorded(): int
    {
        return $this->recorded;
    }

    public function leaseLost(): bool
    {
        return $this->leaseLost;
    }

    /** @return list<string> */
    public function refusals(): array
    {
        return $this->refused;
    }

    /** @return array<string,mixed> */
    private function cursorFor(string $phase, int $after): array
    {
        $cursor = [
            'phase' => $phase,
            'after' => $after,
            'at' => $this->at,
            'tries' => $this->tries,
            // Raised by the handler before the read and cleared by
            // `pageRead()` the moment one comes back.
            'page_tries' => $this->pageTries,
        ];
        if ($this->refused !== []) {
            $cursor['refused'] = $this->refused;
        }
        if ($this->timing !== null) {
            $cursor = array_merge($cursor, $this->timing->fragment());
        }
        return $cursor;
    }

    private function write(int $done, int $failed): bool
    {
        if (($this->checkpoint)($this->cursor(), $done, $failed)) {
            return true;
        }
        $this->leaseLost = true;
        return false;
    }
}
