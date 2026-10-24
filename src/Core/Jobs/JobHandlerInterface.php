<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

/**
 * A handler does one batch and says where the next one starts. It never loops
 * to completion itself: that is the difference between work that survives a
 * timeout and work that does not.
 *
 * Handlers are pure application code. They may not call WordPress — a handler
 * that needs the site reads it through an injected gateway, exactly as every
 * other service in this plugin does.
 */
interface JobHandlerInterface
{
    /** Matches `tmc_jobs.job_type`. */
    public function type(): string;

    /**
     * How many units this handler is willing to do in one batch. The runner
     * may ask for fewer; it never asks for more.
     */
    public function batchSize(): int;

    /**
     * Do at most `batchSize()` units starting from `$job->cursor`, then return.
     *
     * MUST be idempotent with respect to the cursor: the same cursor run twice
     * has to produce the same rows once, because a worker killed after doing
     * the work and before writing the checkpoint will hand that exact cursor to
     * the next worker.
     *
     * **`$checkpoint` is how a handler keeps what it has already done**, and a
     * handler whose units are expensive has to use it. Signature:
     *
     *     fn(array $cursor, int $done = 0, int $failed = 0, int $total = 0): bool
     *
     * It writes the cursor and the counts under this worker's own lock token
     * and extends the lease, exactly as the runner's final checkpoint does.
     * `false` means the lease is gone and somebody else owns the job: the
     * handler must stop at once and write nothing more.
     *
     * Whatever it has recorded is NOT counted again, so the `JobBatch` a
     * handler returns carries only the counts it has not already checkpointed.
     *
     * ⛔ Why this exists, measured on `alpha.71`: one page of the Dokan import
     * was 50 products and took 24.4 seconds on the evidence machine — a file
     * copy and a full `wp_generate_attachment_metadata()` for each. The cursor
     * was written only when all fifty returned, so a request the host gave up
     * on recorded NOTHING: ten products had arrived, the row still said «۲ از
     * ۱۸۶», and the next attempt started at the same place. Three attempts, no
     * movement, and a progress figure that was wrong about work that really
     * happened. A batch that cannot finish inside one request must not be the
     * unit of checkpointing.
     *
     * @param \Closure|null $underLease a second closure, of the shape
     *
     *     fn(\Closure(): bool $write): string
     *
     * which runs `$write` only while this worker's hold on the job is
     * provably still true, and otherwise runs nothing at all. See
     * `JobRepositoryInterface::writingUnderLease()` for the guarantee and for
     * why `$checkpoint` alone could not be one: a checkpoint validates and
     * returns, and whatever the handler writes afterwards is written in a gap.
     * `null` when there is no lease to hold — the tools' and the preview's
     * path.
     *
     * **`$budget` is how long this REQUEST may go on**, and a handler whose
     * units are expensive has to consult it between them. One budget covers
     * every batch of one `run()`, because what is being rationed is the
     * request and not the batch.
     *
     * ⛔ Why this exists, measured on `alpha.74`'s staging run: `batchSize()`
     * for the Dokan import is fifty, and a single product with a large source
     * picture costs seconds. Nothing in this plugin knew how long it had, so
     * the only thing that ended a long batch was the host killing the request
     * — one to three products landed and then the job sat locked for the rest
     * of its three-hundred-second lease, because a dead request calls no
     * `release()`. A handler that stops itself returns `more`, the lease is
     * released at once, and the next worker starts where this one finished.
     *
     * `null` leaves a handler unbounded, which is the behaviour before this
     * parameter existed and is right only where something else bounds the
     * work — the tools, which run one batch deliberately.
     */
    public function step(
        Job $job,
        ?\Closure $checkpoint = null,
        ?\Closure $underLease = null,
        ?JobBudget $budget = null
    ): JobBatch;
}
