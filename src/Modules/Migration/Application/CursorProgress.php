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
        // SPENT IS SPENT, including before the first unit.
        //
        // ⛔ The first version answered `true` for the first unit whatever the
        // clock said, on the reasoning that nothing had been measured yet so
        // there was no reserve to hold back. That reasoning is about the
        // RESERVE and was applied to the wrong question: the owner asked it
        // straight — «اگر خواندن صفحه بودجه را مصرف کرد، چرا باید اولین محصول
        // بدون توجه به زمان باقی‌مانده شروع شود؟» — and there is no answer. A
        // page read, or a lock wait, or a slow bootstrap can take the whole
        // budget before any product is looked at, and starting a four-second
        // product in a request with nothing left is choosing to be killed.
        //
        // This is not the dead end refusing everything would be: the batch
        // returns normally, the lease is released, the position is already
        // written down, and the NEXT request starts with a whole budget. What
        // it costs is one cheap round trip; what it buys is not being cut off.
        if ($this->budget->exhausted()) {
            return false;
        }
        if ($this->unitsStarted === 0) {
            // Still no reserve for the first unit, and that part stands: a
            // reserve is a measurement, and refusing a unit on the strength
            // of a figure from a previous request would stall a job for ever
            // on one bad number. The honest limit, stated rather than
            // papered over: a single unit bigger than the remaining budget
            // will overrun it, and what bounds the damage is that the
            // per-unit attempt count names the position and steps over it.
            return true;
        }
        return $this->budget->roomForAnotherUnit();
    }

    /**
     * Why the batch stopped — three different facts, named apart.
     *
     * ⛔ Not one of them says anything about the HOST's deadline, and the
     * middle one used to: `time_budget_one_unit_longer_than_the_request` read
     * as «this product cannot fit in a web request», which is a claim about a
     * number PHP cannot see. What was measured is a unit against OUR declared
     * budget, so that is what it is called.
     */
    public function stopReason(): string
    {
        if ($this->budget === null || $this->leaseLost) {
            // A lost lease is not a budget reason and must not borrow one:
            // the batch stopped because the job is somebody else's now, and
            // `cursor()` is already the honest record of that.
            return '';
        }
        if ($this->budget->exhausted()) {
            // ⛔ «Before the first unit» is named separately because the next
            // thing a person does about it is completely different: whatever
            // spent the budget was not a product — the page read, a lock
            // wait, or the request's own start-up before the queue was
            // reached.
            return $this->unitsStarted === 0
                ? 'time_budget_spent_before_first_unit'
                : 'time_budget_spent';
        }
        if ($this->unitsStarted === 0) {
            // Nothing has stopped this batch. ⛔ The first version answered
            // `time_budget_spent_before_first_unit` here, on the theory that
            // the method is only ever called after a stop — a theory, not a
            // guarantee, and a reason invented for a batch that is running
            // would be printed on the queue page as fact.
            return '';
        }
        if ($this->budget->aUnitOutgrewTheBudget()) {
            // One unit alone took longer than the whole declared budget. Said
            // with that comparison in its name, and the figures travel beside
            // it so the budget it was compared against is visible.
            return 'time_budget_one_unit_over_budget';
        }
        if (!$this->budget->roomForAnotherUnit()) {
            // Time left, but less than the slowest unit this batch measured.
            return 'time_budget_spent';
        }
        return '';
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
        return array_merge($cursor, $this->budgetFragment());
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
        return array_merge($cursor, $this->budgetFragment());
    }

    /**
     * What this request's budget was and how much of it had gone.
     *
     * Asked for in as many words — «بودجهٔ مصرف‌شده و باقی‌مانده» — and it has
     * to be in the cursor rather than worked out on the page, because the page
     * is read in a LATER request whose own budget says nothing about the one
     * that stopped. Remaining is not stored: it is the difference, and storing
     * both would let the two disagree.
     *
     * `budget_low` is `JobBudget::belowFloor()` — the host left so little that
     * the batch started on borrowed time. A fact about the host, printed so
     * that «the batch stopped at once» does not read as a defect in the queue.
     *
     * @return array<string,int|bool>
     */
    private function budgetFragment(): array
    {
        if ($this->budget === null) {
            return [];
        }
        $fragment = [
            'budget_ms' => (int) round($this->budget->totalSeconds() * 1000.0),
            'budget_spent_ms' => (int) round($this->budget->spentMs()),
        ];
        if ($this->budget->belowFloor()) {
            $fragment['budget_low'] = true;
        }
        return $fragment;
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
