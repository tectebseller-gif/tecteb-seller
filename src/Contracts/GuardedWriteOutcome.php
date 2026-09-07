<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * What a guarded write actually did.
 *
 * A boolean cannot carry this. "false" would fold together three completely
 * different situations — the guard did not match, the store was already in the
 * desired state, and the write itself failed — and a caller that has to guess
 * between them will guess wrong: it either reports a failure that did not
 * happen, or swallows one that did.
 */
enum GuardedWriteOutcome: string
{
    /** The guard matched and the store changed: a row was written or removed. */
    case Written = 'written';

    /**
     * The guard matched, but there was nothing to do: the value was already
     * exactly what we asked for, or the row we asked to delete did not exist.
     * This is a normal result, NOT a failure.
     */
    case NoChangeNeeded = 'no_change_needed';

    /**
     * The guard did not match, so nothing was written. For the migration lock
     * this means this run no longer owns it (taken over, expired, or another
     * run holds it). The other run is authoritative; this one must not act.
     */
    case NotOwner = 'not_owner';

    /** The storage operation itself failed. Nothing can be concluded about the row. */
    case Failed = 'failed';
}
