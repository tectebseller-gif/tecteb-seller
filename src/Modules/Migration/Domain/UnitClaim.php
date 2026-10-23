<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

/**
 * The answer to «may I start working on this one?» — and there are three.
 *
 * ⛔ A boolean is what `alpha.72` had, and it could not tell «carry on» from
 * «this position has killed three workers, step over it», so the give-up test
 * sat at the top of the batch instead of next to the unit it was about. Two
 * answers where three are needed is the oldest defect in this repository.
 *
 * `LeaseLost` is not a failure of the unit. It means somebody else owns this
 * import now, so the only correct thing to do is write NOTHING and stop —
 * which is the whole reason the claim happens before the product row, the
 * media copy and the manifest rather than after them.
 */
enum UnitClaim: string
{
    /** The attempt is recorded and the lease is ours. Work may begin. */
    case Ok = 'ok';

    /** Another worker holds this job. Nothing may be written, by anyone here. */
    case LeaseLost = 'lease_lost';

    /**
     * This exact unit has been attempted up to the limit without ever landing.
     *
     * The attempt is NOT recorded again — a position nobody can get past is
     * not made more passable by counting higher. The caller names it and moves
     * the cursor beyond it, so the rest of the import can finish.
     */
    case GiveUp = 'give_up';
}
