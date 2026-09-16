<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBatch;
use Tecteb\Marketplace\Core\Jobs\JobHandlerInterface;

/**
 * The Dokan import, one page at a time, resumable from wherever it stopped.
 *
 * Before this, the import ran inside the admin POST that started it: a shop
 * with a few thousand products hit `max_execution_time` half way through, the
 * browser showed a blank page, and what had actually happened was unknowable —
 * the manifest was written after both loops, so rows existed that no run
 * claimed. Every part of this class is a consequence of that.
 *
 * **The cursor is a phase and an id, and all three phases need it.** «Vendor
 * 41», «product 41» and «order 41» are three different places; without the
 * phase, a resumed job would restart one of them at another's id. They run in
 * order because a product cannot be attached to a shop that has not arrived,
 * and an order cannot describe a shop that is not there either.
 *
 * **Idempotent against the cursor, not against the clock.** A worker can die
 * after creating rows and before writing the checkpoint, so the next worker
 * hands the handler a cursor whose page is partly done. `upsertProfile` is a
 * no-op for a shop already present, and `judgeProduct` skips a product already
 * mapped by an earlier run — so re-running a page creates nothing twice. That
 * check was already there for the dry run; what is new is that it is now also
 * the thing that makes a crash survivable.
 *
 * **A conflict fails a row, not the job.** A duplicate SKU in one of 400
 * products used to refuse the entire plan. That is right for the dry run, whose
 * whole purpose is for a person to see the conflicts first — and wrong here,
 * where the plan has already been read and accepted. The row is counted in
 * `failed`, its reason is in the notes, and the other 399 arrive.
 */
final class DokanImportJob implements JobHandlerInterface
{
    public const TYPE = 'dokan_import';

    public const PHASE_VENDORS = 'vendors';

    public const PHASE_PRODUCTS = 'products';

    /** Past orders, recorded with Dokan's own figures and no ledger line. */
    public const PHASE_ORDERS = 'orders';

    public function __construct(private readonly ImportFromDokan $import)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function batchSize(): int
    {
        return 50;
    }

    public function step(Job $job): JobBatch
    {
        $runId = (string) ($job->payload['run_id'] ?? '');
        if ($runId === '') {
            return JobBatch::failed('missing_run_id');
        }
        $phase = (string) ($job->cursor['phase'] ?? self::PHASE_VENDORS);
        $after = (int) ($job->cursor['after'] ?? 0);
        $total = (int) ($job->payload['total'] ?? 0);

        if ($phase === self::PHASE_VENDORS) {
            $page = $this->import->importVendorPage($runId, $after, $this->batchSize());
            if (!$page['more']) {
                // Vendors done; products start from zero, not from the last
                // vendor's id. The phase is what makes that unambiguous.
                return JobBatch::progress(
                    ['phase' => self::PHASE_PRODUCTS, 'after' => 0],
                    $page['done'],
                    0,
                    $total
                );
            }
            return JobBatch::progress(
                ['phase' => self::PHASE_VENDORS, 'after' => $page['last']],
                $page['done'],
                0,
                $total
            );
        }

        if ($phase === self::PHASE_PRODUCTS) {
            $page = $this->import->importProductPage($runId, $after, $this->batchSize());
            if ($page['more']) {
                return JobBatch::progress(
                    ['phase' => self::PHASE_PRODUCTS, 'after' => $page['last']],
                    $page['done'],
                    $page['failed'],
                    $total,
                    $page['notes']
                );
            }
            // Products done; the history starts from zero for the same reason
            // products did — an order id and a product id are different places.
            return JobBatch::progress(
                ['phase' => self::PHASE_ORDERS, 'after' => 0],
                $page['done'],
                $page['failed'],
                $total,
                $page['notes']
            );
        }

        $page = $this->import->importOrderPage($runId, $after, $this->batchSize());
        $cursor = ['phase' => self::PHASE_ORDERS, 'after' => $page['last']];
        if ($page['more']) {
            return JobBatch::progress($cursor, $page['done'], 0, $total, ['history:' . $page['done']]);
        }

        $progress = $this->import->runProgress($runId);
        $this->import->finishRun($runId, $progress['vendors'], $progress['products'], $job->failed);
        return JobBatch::finished($cursor, $page['done'], 0, $total, ['history:' . $page['done']]);
    }
}
