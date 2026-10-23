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
 *
 * **And the checkpoint follows the product, not the page.** ⛔ Until
 * `alpha.72` it followed the page, and on staging that stopped the import
 * dead: one page is fifty products, each one a file copy and a full
 * `wp_generate_attachment_metadata()`, measured at 24.4 seconds for the fifty.
 * A request the host gave up on half way wrote NOTHING — ten products had
 * arrived, the row still said «۲ از ۱۸۶», and the next worker started at the
 * same place and died at the same place. Three attempts, no movement, no
 * fatal in any log, and a progress figure that was wrong about work that had
 * really happened.
 *
 * So two things are recorded per unit now, through the runner's `$checkpoint`:
 *
 *  - **where we got to**, so a killed worker loses at most one product and the
 *    next one carries on rather than repeating a page it cannot finish; and
 *  - **that we tried**, written BEFORE the work, because a process that stops
 *    existing writes nothing afterwards. `tries` lives in the cursor because
 *    the cursor is this handler's own data, and any unit that lands resets it
 *    to nought. A position that has killed `GIVE_UP_AFTER` workers is not
 *    tried again: that one unit is NAMED, counted as failed and stepped over,
 *    so the other hundred and eighty arrive instead of nothing arriving.
 */
final class DokanImportJob implements JobHandlerInterface
{
    public const TYPE = 'dokan_import';

    public const PHASE_VENDORS = 'vendors';

    public const PHASE_PRODUCTS = 'products';

    /** Past orders, recorded with Dokan's own figures and no ledger line. */
    public const PHASE_ORDERS = 'orders';

    /**
     * How many workers one position may kill before it is stepped over.
     *
     * Three, because each one costs a whole request and a manager watching the
     * queue page should not have to wait out ten of them — and because the
     * fourth attempt would be the same attempt. It is deliberately not one: a
     * single death can be a restart, a deploy or a host hiccup, and failing a
     * product on the strength of that would name the wrong row.
     */
    public const GIVE_UP_AFTER = 3;

    /**
     * How many refused rows the cursor carries.
     *
     * Enough for a manager to go and look, few enough that a cursor stays a
     * position. The count in `failed` is the whole number; this is the names.
     */
    public const REFUSALS_KEPT = 20;

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

    public function step(Job $job, ?\Closure $checkpoint = null): JobBatch
    {
        $runId = (string) ($job->payload['run_id'] ?? '');
        if ($runId === '') {
            return JobBatch::failed('missing_run_id');
        }
        $phase = (string) ($job->cursor['phase'] ?? self::PHASE_VENDORS);
        $after = (int) ($job->cursor['after'] ?? 0);
        $tries = (int) ($job->cursor['tries'] ?? 0);
        $total = (int) ($job->payload['total'] ?? 0);
        // Which rows this import has REFUSED, carried in the cursor.
        //
        // `JobBatch::notes()` is read by nothing: the runner drops it, so until
        // now a conflict was a number in `failed` with no id anywhere. The
        // cursor is the handler's own data — the schema says so in as many
        // words — and the queue page already prints it, so the names travel
        // with the position that produced them. Bounded, newest last, because
        // a cursor is a position and not a log.
        $refused = array_values(array_filter(
            (array) ($job->cursor['refused'] ?? []),
            static fn (mixed $n): bool => is_string($n)
        ));
        $here = static function (string $p, int $at, int $t = 0, array $add = []) use ($refused): array {
            $cursor = ['phase' => $p, 'after' => $at, 'tries' => $t];
            $all = array_merge($refused, array_values(array_filter($add, 'is_string')));
            if ($all !== []) {
                $cursor['refused'] = array_slice($all, -self::REFUSALS_KEPT);
            }
            return $cursor;
        };

        // THAT WE TRIED, written before trying.
        //
        // The only moment this can be recorded is before the work, because the
        // failure being guarded against is a process that stops existing. If
        // the lease has already gone, say nothing and change nothing.
        if ($checkpoint !== null && !$checkpoint($here($phase, $after, $tries + 1), 0, 0, $total)) {
            return JobBatch::progress($here($phase, $after, $tries), 0, 0, $total);
        }

        if ($checkpoint !== null && $tries >= self::GIVE_UP_AFTER) {
            return $this->stepOver($phase, $after, $total, $tries, $here);
        }

        // Each unit records itself as it lands, and a unit that lands means
        // this position is not the one killing workers — so `tries` goes back
        // to nought in the same write.
        $recordOne = $checkpoint === null
            ? null
            : static fn (int $at, int $done, int $failed): bool => $checkpoint($here($phase, $at), $done, $failed, $total);

        if ($phase === self::PHASE_VENDORS) {
            $page = $this->import->importVendorPage($runId, $after, $this->batchSize(), $recordOne);
            if ($page['lease_lost'] ?? false) {
                return JobBatch::progress($here($phase, (int) $page['last']), 0, 0, $total);
            }
            if (!$page['more']) {
                // Vendors done; products start from zero, not from the last
                // vendor's id. The phase is what makes that unambiguous.
                return JobBatch::progress(
                    $here(self::PHASE_PRODUCTS, 0),
                    $page['done'],
                    0,
                    $total
                );
            }
            return JobBatch::progress(
                $here(self::PHASE_VENDORS, (int) $page['last']),
                $page['done'],
                0,
                $total
            );
        }

        if ($phase === self::PHASE_PRODUCTS) {
            $page = $this->import->importProductPage($runId, $after, $this->batchSize(), $recordOne);
            if ($page['lease_lost'] ?? false) {
                return JobBatch::progress($here($phase, (int) $page['last'], 0, $page['notes']), 0, 0, $total, $page['notes']);
            }
            if ($page['more']) {
                return JobBatch::progress(
                    $here(self::PHASE_PRODUCTS, (int) $page['last'], 0, $page['notes']),
                    $page['done'],
                    $page['failed'],
                    $total,
                    $page['notes']
                );
            }
            // Products done; the history starts from zero for the same reason
            // products did — an order id and a product id are different places.
            return JobBatch::progress(
                $here(self::PHASE_ORDERS, 0, 0, $page['notes']),
                $page['done'],
                $page['failed'],
                $total,
                $page['notes']
            );
        }

        $page = $this->import->importOrderPage($runId, $after, $this->batchSize(), $recordOne);
        $cursor = $here(self::PHASE_ORDERS, (int) $page['last']);
        if (($page['lease_lost'] ?? false) || $page['more']) {
            return JobBatch::progress($cursor, $page['done'], 0, $total, ['history:' . $page['done']]);
        }

        $progress = $this->import->runProgress($runId);
        $this->import->finishRun($runId, $progress['vendors'], $progress['products'], $job->failed);
        return JobBatch::finished($cursor, $page['done'], 0, $total, ['history:' . $page['done']]);
    }

    /**
     * Name the one unit that has killed `GIVE_UP_AFTER` workers, and go past it.
     *
     * It is counted as FAILED and its id is in the notes, so the manager has
     * the row to look at — a product with a photograph too big for this host to
     * process is the measured example, and «۱۸۳ آمد و این یکی نامش این است» is
     * an answer, while «هیچ‌چیز نیامد» is not.
     *
     * Nothing is deleted, nothing of Dokan's is touched, and the unit itself is
     * left exactly as it was: stepping over a row is a decision to import the
     * rest, not a decision about that row.
     */
    private function stepOver(string $phase, int $after, int $total, int $tries, \Closure $here): JobBatch
    {
        $id = $this->import->unitAfter($phase, $after);
        $next = match ($phase) {
            self::PHASE_VENDORS => self::PHASE_PRODUCTS,
            self::PHASE_PRODUCTS => self::PHASE_ORDERS,
            default => self::PHASE_ORDERS,
        };
        if ($id === null) {
            // Nothing there any more: the position was the END of the phase and
            // the deaths were about something else. Move on without blaming a
            // row that does not exist.
            return JobBatch::progress(
                $here($next, 0, 0, ['stalled_at_end_of:' . $phase]),
                0,
                0,
                $total,
                ['stalled_at_end_of:' . $phase]
            );
        }
        if ($this->import->unitIsAlreadyOurs($phase, $id)) {
            // It arrived in one of those dying attempts — created, and gone
            // before its own checkpoint could run. The position has to move,
            // but blaming this row would be naming an innocent one.
            return JobBatch::progress(
                $here($phase, $id, 0, [$id . ':arrived_before_the_worker_died']),
                1,
                0,
                $total,
                [$id . ':arrived_before_the_worker_died']
            );
        }
        return JobBatch::progress(
            $here($phase, $id, 0, [$id . ':stalled_after_' . $tries . '_attempts']),
            0,
            1,
            $total,
            [$id . ':stalled_after_' . $tries . '_attempts']
        );
    }
}
