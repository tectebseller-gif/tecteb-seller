<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

/**
 * What one pass over a window of rows actually achieved — including the part a
 * count cannot say.
 *
 * `alpha.34` answered this question with an `int`, and an `int` has no room for
 * «and then row 41 was refused». So the caller advanced its cursor past row 41,
 * and — when the window came back short — wrote down that the sweep had
 * finished and that every key in the table was now in the current format. Both
 * were false, and nothing would look at row 41 again: the recorded build
 * matched, so no sweep would open for it.
 *
 * Hence three numbers and a stop, not one number:
 *
 *  - `repaired` — rows this pass WROTE. Used for the counters, so a retry that
 *    re-reads rows already put right does not count them a second time: their
 *    key now matches their title, so they are examined and skipped.
 *  - `examined` — rows left in a correct state, written or already right. This
 *    is what «how much of the table did we get through» means.
 *  - `cursor` — the highest id it is SAFE to resume after. Never the id of the
 *    row that stopped the pass, and never past it.
 *  - `stoppedAt` — the row that is still unresolved, or `null` when the whole
 *    window came out right. This is the fact that must reach the caller.
 */
final class TitleSortRepairBatch
{
    /** The database refused the write. */
    public const WRITE_FAILED = 'write_failed';

    /** The row changed under us between the read and the write. */
    public const ROW_MOVED = 'row_moved';

    public function __construct(
        public readonly int $repaired,
        public readonly int $examined,
        public readonly int $cursor,
        public readonly ?int $stoppedAt = null,
        public readonly string $reason = ''
    ) {
    }

    /**
     * Did every row in the window come out right?
     *
     * Asked as «is there a row we did not settle», not as «did we write
     * anything» — a window where nothing needed writing is a complete success,
     * and a window where three rows were written and the fourth was refused is
     * not.
     */
    public function complete(): bool
    {
        return $this->stoppedAt === null;
    }
}
