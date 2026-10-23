<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

/**
 * What became of a unit's dependent writes — and there are three answers.
 *
 * ⛔ `alpha.73` asked «is this still mine?» and then wrote, which is two
 * round trips with a gap between them. Measured on its own installed bytes:
 * a worker parked in that gap had its checkpoint REFUSED a moment later
 * (`outcome:lease_lost`) and its `saveImages()` had already replaced the
 * picture the new worker attached — one vendor copy left in the shop's media
 * library with nothing pointing at it. A refusal that arrives after the write
 * is a report, not a guard.
 *
 * So the question and the write are now ONE unit of work, and this is its
 * answer. `LeaseLost` is the only one that promises anything about the
 * database: it means the closure never ran and not one statement of it
 * reached a table.
 */
enum LeaseHold: string
{
    /** The lease was ours throughout, and the writes are committed. */
    case Held = 'held';

    /**
     * Somebody else owns this import. **Nothing was written** — the closure
     * was never called, because the lock on the job row was taken and the
     * token compared before it could be.
     */
    case LeaseLost = 'lease_lost';

    /**
     * The lease was ours and the work itself did not succeed, so the whole
     * unit was rolled back.
     *
     * Told apart from `LeaseLost` because the two want opposite things of the
     * caller: one means stop and touch nothing further, the other means this
     * one product failed and the import carries on and names it.
     */
    case NotWritten = 'not_written';
}
