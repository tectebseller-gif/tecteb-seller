<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

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

    /**
     * @param \Closure(array<string,mixed>, int, int): bool $checkpoint
     * @param list<string> $refused
     */
    public function __construct(
        private readonly \Closure $checkpoint,
        private readonly string $phase,
        private int $after,
        int $at,
        int $tries,
        array $refused,
        private int $pageTries,
        private readonly int $giveUpAfter,
        private readonly int $refusalsKept
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
        return UnitClaim::Ok;
    }

    public function attempts(int $unitId): int
    {
        return $unitId === $this->at ? $this->tries : 0;
    }

    public function stillOurs(): bool
    {
        if ($this->leaseLost) {
            return false;
        }
        // The same position, the same counts, nothing moved: the write exists
        // only so the token can be matched, which is the only way to ask the
        // lease a question from here.
        return $this->write(0, 0);
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
