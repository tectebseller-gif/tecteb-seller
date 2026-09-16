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
     */
    public function step(Job $job): JobBatch;
}
