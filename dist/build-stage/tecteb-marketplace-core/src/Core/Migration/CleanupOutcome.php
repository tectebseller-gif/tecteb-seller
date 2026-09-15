<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

/**
 * What happened to the recorded-failure option at the end of a run.
 *
 * A successful migration clears the failure a previous run recorded. That
 * clear is itself a write that can go four different ways, and folding them
 * into "the run succeeded" hides two of them:
 *
 *  - reporting a failure when there was simply nothing to clear would make
 *    every ordinary first run look broken;
 *  - reporting success when the clear actually failed leaves a stale failure
 *    record behind while telling everyone the run was clean.
 *
 * The health page reads the same distinction: a record left behind by a run
 * that reached the target version describes a failure that no longer exists.
 */
enum CleanupOutcome: string
{
    /** No failure was recorded, so there was nothing to clear. Normal. */
    case NotNeeded = 'not_needed';

    /** A recorded failure existed and was removed. */
    case Cleared = 'cleared';

    /**
     * The clear was refused because this run no longer owns the lock (taken
     * over) or never held it (another run does). The owner is authoritative
     * and will clear or replace the record itself; this run must not.
     */
    case SkippedNotOwner = 'skipped_not_owner';

    /**
     * The storage operation failed. The migration itself is applied, but a
     * stale failure record is still in the database. Never reported as a
     * clean success.
     */
    case Failed = 'failed';
}
