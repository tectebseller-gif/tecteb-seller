<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBatch;
use Tecteb\Marketplace\Core\Jobs\JobBudget;
use Tecteb\Marketplace\Core\Jobs\JobHandlerInterface;
use Tecteb\Marketplace\Core\Jobs\StepTiming;

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
 *  - **that we are trying THIS one**, written BEFORE its product row, its
 *    media copy and its manifest entry, because a process that stops existing
 *    writes nothing afterwards and a guard that runs after the writes is not a
 *    guard. A unit that has killed `GIVE_UP_AFTER` workers is not tried again:
 *    it is NAMED, counted, and stepped over, so the other hundred and eighty
 *    arrive instead of nothing arriving.
 *
 * ⛔ And the attempt count is keyed to the UNIT, which `alpha.72` got wrong in
 * a way only its own bytes could show: it raised one `tries` at the top of the
 * batch and every unit that landed set it back to nought, so the same
 * interruption read `tries:1` inside the first product of a batch and
 * `tries:0` inside the second. The second product's attempts were never
 * written down at all, which means the give-up could not fire for it however
 * many workers it killed — and the only reason that did not stall the import
 * for ever is that the OTHER defect of that package, counting a half-made
 * product as done, skipped past it. Two defects covering for each other is
 * why both are fixed here together: see `ImportProgressInterface` and
 * `CursorProgress`.
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

    public function step(
        Job $job,
        ?\Closure $checkpoint = null,
        ?\Closure $underLease = null,
        ?JobBudget $budget = null
    ): JobBatch {
        $runId = (string) ($job->payload['run_id'] ?? '');
        if ($runId === '') {
            return JobBatch::failed('missing_run_id');
        }
        $phase = (string) ($job->cursor['phase'] ?? self::PHASE_VENDORS);
        $after = (int) ($job->cursor['after'] ?? 0);
        // The unit being attempted, and how many times it has been.
        //
        // ⛔ `alpha.72` kept one `tries` and raised it at the top of the batch,
        // so every unit that landed set it back to nought — measured on its own
        // bytes, an identical interruption read `tries:1` inside the FIRST
        // product of a batch and `tries:0` inside the second. The whole attempt
        // on the second product left no trace, which means `GIVE_UP_AFTER`
        // could never fire for it however many workers it killed. The count
        // belongs to a unit, so it is keyed to one.
        $at = (int) ($job->cursor['at'] ?? 0);
        $tries = (int) ($job->cursor['tries'] ?? 0);
        // And a SEPARATE count for «could not even read the page».
        //
        // A different fact with a different disproof: any page coming back
        // clears it, and that is not the same event as a unit landing. One
        // counter serving both loses both.
        $pageTries = (int) ($job->cursor['page_tries'] ?? 0);
        $total = (int) ($job->payload['total'] ?? 0);
        // Which rows this import has REFUSED, carried in the cursor.
        //
        // `JobBatch::notes()` is read by nothing: the runner drops it, so until
        // `alpha.72` a conflict was a number in `failed` with no id anywhere.
        // The cursor is the handler's own data — the schema says so in as many
        // words — and the queue page already prints it, so the names travel
        // with the position that produced them. Bounded, newest last, because
        // a cursor is a position and not a log.
        $refused = array_values(array_filter(
            (array) ($job->cursor['refused'] ?? []),
            static fn (mixed $n): bool => is_string($n)
        ));

        if ($checkpoint === null) {
            // No runner and therefore no lease: the path the tools and the
            // preview take. Nothing to claim, nothing to lose, and the page
            // reports what it did in its return value.
            return $this->withoutALease($runId, $phase, $after, $total, $refused);
        }

        $here = static function (string $p, int $a, int $u, int $t, int $pt, array $names): array {
            $cursor = ['phase' => $p, 'after' => $a, 'at' => $u, 'tries' => $t, 'page_tries' => $pt];
            if ($names !== []) {
                $cursor['refused'] = array_slice($names, -self::REFUSALS_KEPT);
            }
            return $cursor;
        };

        // THAT WE TRIED TO READ A PAGE, written before reading one.
        //
        // The only moment this can be recorded is beforehand, because the
        // failure being guarded against is a process that stops existing. If
        // the lease has already gone, say nothing and change nothing.
        $pageTries++;
        if (!$checkpoint($here($phase, $after, $at, $tries, $pageTries, $refused), 0, 0, $total)) {
            return JobBatch::progress($here($phase, $after, $at, $tries, $pageTries - 1, $refused), 0, 0, $total);
        }
        if ($pageTries > self::GIVE_UP_AFTER) {
            // Not one unit could be read at this position, three times over.
            // That is not a product's fault and must not be reported as one.
            return $this->stepOver($phase, $after, $total, $pageTries - 1, $refused, $here);
        }

        // The step record travels in the cursor, so a worker that is cut off
        // leaves behind the name of the step it was in — see `StepTiming`.
        $timing = StepTiming::fromCursor($job->cursor);
        $progress = new CursorProgress(
            static fn (array $cursor, int $done, int $failed): bool
                => $checkpoint($cursor, $done, $failed, $total),
            // The unit-of-work gate. A runner that hands a checkpoint and no
            // gate gets a progress object whose `writing()` REFUSES — not one
            // that writes unguarded, because a guard that disappears wherever
            // somebody forgot to wire it is the shape of this file's whole
            // history of defects.
            $underLease,
            $phase,
            $after,
            $at,
            $tries,
            $refused,
            $pageTries,
            self::GIVE_UP_AFTER,
            self::REFUSALS_KEPT,
            $timing,
            $budget
        );

        if ($phase === self::PHASE_VENDORS) {
            $page = $this->import->importVendorPage($runId, $after, $this->batchSize(), $progress);
            if (($page['stopped'] ?? '') !== '') {
                $page['notes'][] = (string) $page['stopped'];
            }
            if ($progress->leaseLost()) {
                return JobBatch::progress($progress->cursor(), 0, 0, $total, $page['notes'] ?? []);
            }
            if (!$page['more']) {
                // Vendors done; products start from zero, not from the last
                // vendor's id. The phase is what makes that unambiguous.
                return JobBatch::progress(
                    $progress->cursorAtStartOf(self::PHASE_PRODUCTS),
                    $page['done'],
                    $page['failed'] ?? 0,
                    $total,
                    $page['notes'] ?? []
                );
            }
            return JobBatch::progress(
                $this->stoppedBecause(
                    $this->pageRead($progress->cursor()),
                    (string) ($page['stopped'] ?? '')
                ),
                $page['done'],
                $page['failed'] ?? 0,
                $total,
                $page['notes'] ?? []
            );
        }

        if ($phase === self::PHASE_PRODUCTS) {
            $page = $this->import->importProductPage($runId, $after, $this->batchSize(), $progress);
            $notes = $page['notes'];
            if (($page['stopped'] ?? '') !== '') {
                // The batch ended itself. Said out loud, because «more» on
                // its own looks the same as a full page — and a manager
                // watching the queue should see the difference between «there
                // is more to do» and «this request ran out of time».
                $notes[] = (string) $page['stopped'];
            }
            if (($page['repaired'] ?? 0) > 0) {
                // Finishing a half-made product is not an ordinary import and
                // the report says so separately — «عدد ۱۰۰٪ به‌تنهایی کافی
                // نیست».
                $notes[] = 'repaired:' . (int) $page['repaired'];
            }
            if ($progress->leaseLost()) {
                return JobBatch::progress($progress->cursor(), 0, 0, $total, $notes);
            }
            if ($page['more']) {
                return JobBatch::progress(
                    $this->stoppedBecause(
                        $this->pageRead($progress->cursor()),
                        (string) ($page['stopped'] ?? '')
                    ),
                    $page['done'],
                    $page['failed'],
                    $total,
                    $notes
                );
            }
            // Products done; the history starts from zero for the same reason
            // products did — an order id and a product id are different places.
            return JobBatch::progress(
                $progress->cursorAtStartOf(self::PHASE_ORDERS),
                $page['done'],
                $page['failed'],
                $total,
                $notes
            );
        }

        $page = $this->import->importOrderPage($runId, $after, $this->batchSize(), $progress);
        $cursor = $this->stoppedBecause(
            $this->pageRead($progress->cursor()),
            (string) ($page['stopped'] ?? '')
        );
        $historyNotes = ['history:' . $page['done']];
        if (($page['stopped'] ?? '') !== '') {
            $historyNotes[] = (string) $page['stopped'];
        }
        if ($progress->leaseLost() || $page['more']) {
            return JobBatch::progress($cursor, $page['done'], 0, $total, $historyNotes);
        }

        $progressFigures = $this->import->runProgress($runId);
        $this->import->finishRun($runId, $progressFigures['vendors'], $progressFigures['products'], $job->failed);
        return JobBatch::finished($cursor, $page['done'], 0, $total, $historyNotes);
    }

    /**
     * The page came back, so «could not even read the page» is disproved.
     *
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    private function pageRead(array $cursor): array
    {
        $cursor['page_tries'] = 0;
        // A batch that read a page has not stopped for time YET, so last
        // batch's reason goes. Left behind it would be a sentence on the
        // queue page about a request that finished minutes ago.
        unset($cursor['stopped']);
        return $cursor;
    }

    /**
     * Carry «why this batch ended itself» in the cursor.
     *
     * ⛔ In the CURSOR and not in `JobBatch::notes()`, because the runner
     * drops notes — that is the `alpha.72` finding, and a reason nobody reads
     * is not a reason. The cursor is the handler's own data and the queue page
     * already prints it.
     *
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    private function stoppedBecause(array $cursor, string $why): array
    {
        if ($why === '') {
            unset($cursor['stopped']);
            return $cursor;
        }
        $cursor['stopped'] = $why;
        return $cursor;
    }

    /**
     * One batch with no lease to lose: the tools' path and the preview's.
     *
     * Kept separate rather than threaded through the claiming version with
     * null checks everywhere, because the two have different guarantees and
     * pretending otherwise is how a guard ends up optional.
     */
    private function withoutALease(string $runId, string $phase, int $after, int $total, array $refused): JobBatch
    {
        $cursor = static function (string $p, int $a) use ($refused): array {
            $c = ['phase' => $p, 'after' => $a, 'at' => 0, 'tries' => 0, 'page_tries' => 0];
            if ($refused !== []) {
                $c['refused'] = $refused;
            }
            return $c;
        };
        if ($phase === self::PHASE_VENDORS) {
            $page = $this->import->importVendorPage($runId, $after, $this->batchSize());
            return $page['more']
                ? JobBatch::progress($cursor(self::PHASE_VENDORS, (int) $page['last']), $page['done'], 0, $total)
                : JobBatch::progress($cursor(self::PHASE_PRODUCTS, 0), $page['done'], 0, $total);
        }
        if ($phase === self::PHASE_PRODUCTS) {
            $page = $this->import->importProductPage($runId, $after, $this->batchSize());
            return $page['more']
                ? JobBatch::progress($cursor(self::PHASE_PRODUCTS, (int) $page['last']), $page['done'], $page['failed'], $total, $page['notes'])
                : JobBatch::progress($cursor(self::PHASE_ORDERS, 0), $page['done'], $page['failed'], $total, $page['notes']);
        }
        $page = $this->import->importOrderPage($runId, $after, $this->batchSize());
        $at = $cursor(self::PHASE_ORDERS, (int) $page['last']);
        if ($page['more']) {
            return JobBatch::progress($at, $page['done'], 0, $total, ['history:' . $page['done']]);
        }
        $figures = $this->import->runProgress($runId);
        $this->import->finishRun($runId, $figures['vendors'], $figures['products'], 0);
        return JobBatch::finished($at, $page['done'], 0, $total, ['history:' . $page['done']]);
    }

    /**
     * Name the position that no worker can read past, and go beyond it.
     *
     * ⛔ This is about the READ, not about a unit: three workers died without a
     * single product coming back from the reader, which is a different failure
     * from a product that kills workers — that one is counted against its own
     * id by `CursorProgress` and named by `giveUpOnProduct()`. Blaming a row
     * for a page nobody could read would name an innocent one.
     *
     * @param list<string> $refused
     */
    private function stepOver(string $phase, int $after, int $total, int $tries, array $refused, \Closure $here): JobBatch
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
            $note = 'stalled_at_end_of:' . $phase;
            return JobBatch::progress(
                $here($next, 0, 0, 0, 0, array_merge($refused, [$note])),
                0,
                0,
                $total,
                [$note]
            );
        }
        if ($this->import->unitIsAlreadyOurs($phase, $id)) {
            // It arrived in one of those dying attempts — created, and gone
            // before its own checkpoint could run. The position has to move,
            // but blaming this row would be naming an innocent one.
            $note = $id . ':arrived_before_the_worker_died';
            return JobBatch::progress(
                $here($phase, $id, 0, 0, 0, array_merge($refused, [$note])),
                1,
                0,
                $total,
                [$note]
            );
        }
        $note = $id . ':page_unreadable_after_' . $tries . '_attempts';
        return JobBatch::progress(
            $here($phase, $id, 0, 0, 0, array_merge($refused, [$note])),
            0,
            1,
            $total,
            [$note]
        );
    }
}
