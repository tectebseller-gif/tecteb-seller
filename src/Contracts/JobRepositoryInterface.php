<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobStatus;

/**
 * Storage for the job queue.
 *
 * Two of these methods carry a rule that cannot live in the caller:
 *
 *  - **`claim()` must be one atomic write.** It returns the job only to the
 *    single caller whose UPDATE actually changed the row; every other caller
 *    gets null. Implementing it as a read followed by a write re-opens exactly
 *    the race it exists to close.
 *  - **`checkpoint()` and `release()` must match on the lock token.** A worker
 *    whose lease expired while it was working has lost the job; its write must
 *    hit no rows rather than overwrite the checkpoint of the worker that took
 *    over.
 */
interface JobRepositoryInterface
{
    /**
     * Queue a job, or return the live one that already carries this key.
     *
     * @param array<string,mixed> $payload
     * @return array{id:int, created:bool} `created:false` means an existing live job was returned
     */
    public function enqueue(string $type, array $payload, string $liveKey, ?int $actorId): array;

    /**
     * Take ownership of one runnable job for `$leaseSeconds`.
     *
     * Runnable means: pending, or running with an expired lease. Returns null
     * when another worker won the race or nothing is due.
     *
     * @param list<string> $types limit to these handlers; empty means any
     */
    public function claim(array $types, string $lockToken, int $leaseSeconds): ?Job;

    /**
     * Save a batch's progress and extend the lease. Fails (false) when the
     * token no longer owns the job.
     *
     * @param array<string,mixed> $cursor
     */
    public function checkpoint(int $jobId, string $lockToken, array $cursor, int $doneDelta, int $failedDelta, int $total, int $leaseSeconds): bool;

    /** Move a job to a terminal state and drop its dedupe key. */
    public function finish(int $jobId, string $lockToken, JobStatus $status, string $error): bool;

    /** Put a still-unfinished job back for the next worker, clearing the lease. */
    public function release(int $jobId, string $lockToken): bool;

    public function find(int $jobId): ?Job;

    /**
     * @param list<string> $statuses empty means every state
     * @return list<Job>
     */
    public function recent(array $statuses, int $limit, int $offset = 0): array;

    /** @return array<string,int> status => count */
    public function census(): array;

    /**
     * Every job that is not finished, newest first.
     *
     * The queue page needs these as ROWS and not as a count, because the one
     * thing a manager has to be told — «this job has been claimed three times
     * and has not moved» — is in the job's own cursor, and no `COUNT(*)` over
     * a JSON column is going to say it honestly.
     *
     * @return list<Job>
     */
    public function live(int $limit = 50): array;

    /**
     * How long a job row must have been silent before its lease may be ended
     * without an explicit override.
     *
     * Fifteen seconds, derived from how often a healthy worker writes rather
     * than chosen: the checkpoint is per unit now, and a unit is one product —
     * measured at about half a second each, a few seconds for a large image.
     * So fifteen seconds of total silence is well past any single unit and far
     * short of the five-minute lease.
     *
     * ⛔ It does not prove the worker is dead and nothing here can. A process
     * on another request cannot be asked whether it is alive; all this says is
     * that nothing has been written for a while, and the manager is told that
     * in those words, with an override for when they know better.
     */
    public const WRITING_WINDOW = 15;

    /**
     * End one job's lease NOW, so the next worker may claim it.
     *
     * The recovery path for a job whose worker no longer exists. `claim()`
     * cannot tell a live worker from a dead one — only `locked_until` can, and
     * for five minutes after a process is killed it says «somebody is working
     * on this». Measured on `alpha.71`: the page reported «در حال اجرا ۱ /
     * بی‌صاحب ۰» while «اجرای دستی یک دسته» answered «چیزی در صف نبود», and
     * the manager had no action at all.
     *
     * ⛔ CORRECTION, and it was the owner's first finding on `alpha.72`. This
     * docblock used to say the hand-over was «safe even if that worker is in
     * fact alive: its next checkpoint is matched on its token … and writes
     * nothing». The first half is true and the second was not: the checkpoint
     * was refused, but the product row, the media copy and the manifest were
     * all written BEFORE it, so a living worker carried on and wrote them into
     * an import it no longer owned — measured, `a_wrote_after_losing_the_job=yes`.
     * What makes the hand-over safe is the per-unit claim in
     * `ImportProgressInterface`, which comes before those writes; the token
     * match alone never did.
     *
     * Three things are asked before the lease is ended, and each has its own
     * answer: the lock is the one the caller was SHOWN (`lock_changed`
     * otherwise, so a lease somebody claimed a moment ago is not stolen); the
     * row has been silent for a while (`worker_still_writing` otherwise, with
     * an explicit override for the manager who knows better); and the job is
     * running at all. Nothing is deleted and no count is touched — only the
     * lease ends.
     *
     * ⛔ And «silent» is not «dead». Liveness is not observable from here: a
     * PHP process on another request cannot be asked. All this can measure is
     * that nothing has been written for a number of seconds, which is reported
     * as that and never as proof.
     *
     * @param string $seenLock fingerprint of the lock the caller was shown,
     *                         from `lockFingerprint()`; empty skips the check
     * @param bool $evenIfWriting the manager's explicit override of a row that
     *                            has been written to recently
     * @return string `''` when the lease ended; otherwise `not_running`,
     *                `lock_changed`, `worker_still_writing`, `unreadable` or
     *                `not_handed_over`
     */
    public function handOver(int $jobId, string $seenLock = '', bool $evenIfWriting = false): string;

    /**
     * Run a unit's dependent writes while this worker's hold on the job is
     * provably still true — or run none of them.
     *
     * ⛔ This exists because `alpha.73` validated the lease and then wrote,
     * and those are two statements with a gap between them. Measured on its
     * own installed bytes: a worker parked in that gap was refused at its
     * next checkpoint — `outcome:lease_lost` — and had ALREADY replaced the
     * picture a second worker attached, leaving that worker's copy in the
     * shop's media library with nothing pointing at it. «Ask again» cannot
     * close a gap; the question has to become part of the write.
     *
     * **How, and what exactly is promised.** The first statement of the
     * transaction is a locking read of this job's own row, so the token is
     * compared while the row is held. Everything that ends a lease needs that
     * same row — `claim()` writes the next worker's token to it and
     * `handOver()` expires its lease on it — so a hand-over and a unit's write
     * phase cannot interleave: one of them waits. If this worker's token is no
     * longer the row's, the closure is never called and the transaction is
     * rolled back having written nothing.
     *
     * **What is NOT promised.** This is not a lock on the product, and it says
     * nothing about writers who are not workers of this job — a manager
     * editing the same product in wp-admin is not serialised by it. And it
     * covers only statements made on THIS gateway: a closure that reaches for
     * a second connection is outside the transaction and outside the promise.
     *
     * ⛔ **Nothing slow belongs in the closure.** The lock is on a row every
     * worker of this type needs, so the file copy and
     * `wp_generate_attachment_metadata()` stay outside it and only the small
     * writes on our own tables go in. The write phase is measured rather than
     * asserted — see `alpha73-worker.php`'s `worker_write_phase_ms`.
     *
     * ⛔ And this is a different kind of thing from `handOver()`'s
     * `worker_still_writing`, which must not be confused with it. That is a
     * POLICY about apparent silence, with a number in it, and a manager may
     * override it. This is a GUARANTEE about a write that is actually in
     * flight, and nobody can override it because no option reaches it.
     *
     * @param \Closure():bool $write the unit's dependent writes; `false` means
     *                               the work failed and the unit is rolled back
     * @return string `''` when the lease held and the writes are committed;
     *                otherwise `lease_lost` (nothing was written),
     *                `not_written` (the closure said no) or `unreadable`
     *                (the row could not be read, which is not «yes»)
     */
    public function writingUnderLease(int $jobId, string $lockToken, \Closure $write): string;

    /**
     * What the caller must send back to prove it is handing over the lease it
     * was shown, rather than whatever is there by the time the form arrives.
     */
    public function lockFingerprint(string $lockToken, string $lockedUntil): string;

    /** Seconds since this job's row was last written, or `null` if unknown. */
    public function secondsSinceWrite(int $jobId): ?int;

    /** A manager stopping a job they queued; a running job stops after its batch. */
    public function cancel(int $jobId): bool;

    public function lastError(): string;
}
