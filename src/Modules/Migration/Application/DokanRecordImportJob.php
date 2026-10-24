<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBatch;
use Tecteb\Marketplace\Core\Jobs\JobBudget;
use Tecteb\Marketplace\Core\Jobs\JobHandlerInterface;
use Tecteb\Marketplace\Core\Jobs\StepTiming;

/**
 * Importing the REST of a Dokan shop's past: its staff, its balance ledger and
 * its withdrawal requests.
 *
 * ⛔ **Why this exists at all.** `ImportFromDokan::importShopRecordPage()` was
 * written in `alpha.17` and, up to and including `alpha.76`, had no caller
 * anywhere in `src/` — only an evidence tool and six tests. The queue's only
 * handler walked vendors, products and orders; the handover page asked
 * `vendorsWithRecords()`, which is a `UNION` of the three history tables, got
 * an empty list on every install that had ever existed, and printed «هنوز
 * سابقه‌ای وارد نشده است» with a link inviting the manager to run the product
 * import again. So the message was true and its advice was useless: no amount
 * of re-importing products would ever write a record, because nothing in
 * production called the method that writes them.
 *
 * **Three kinds, three cursors, and that is the whole design.** The dormant
 * method walked staff only `if ($afterBalanceId === 0 && $afterWithdrawId ===
 * 0)` — a handful of shops needs no paging, which is true, and the coupling it
 * paid for is not: once the balance cursor moved past nought, an interrupted
 * staff walk could never resume, and the shops after the interruption had no
 * staff records with nothing on any page saying so. Each kind now keeps its
 * own position in the cursor and they do not disturb each other.
 *
 * **It is history and nothing else.** No ledger line, no withdrawal this
 * marketplace must honour, no staff permission, no product ownership, no sale
 * opened. That is a property of what this handler can reach:
 * `ShopRecordRepositoryInterface` has three write methods and all three insert
 * into a history table, and this class holds no other repository.
 * `GrantImportedStaff` is where an imported staff RECORD may become an actual
 * permission, by a manager deciding, and this never calls it.
 *
 * **Nothing of Dokan's is written.** `DokanReaderInterface` has no write
 * method, so the capability is absent rather than guarded.
 *
 * **A repeat is not a duplicate**, and not because anything here checks: all
 * three tables carry a `UNIQUE` key over the row's natural identity
 * (`M0017DokanShopRecords`), `recordStaff()`/`recordBalance()`/
 * `recordWithdrawal()` answer `ALREADY` when the key refuses, and only a
 * `RECORDED` moves a count. A worker killed after writing and before its
 * checkpoint hands the next worker a cursor whose page is partly done — and
 * that page lands once.
 */
final class DokanRecordImportJob implements JobHandlerInterface
{
    public const TYPE = 'dokan_records';

    /** Shops, for their staff. The unit is a shop; the cursor is a user id. */
    public const PHASE_STAFF = 'staff_records';

    /** Dokan's balance ledger. The unit is a row; the cursor is its `row_id`. */
    public const PHASE_BALANCE = 'balance_records';

    /** Withdrawal requests. The unit is a request; the cursor is its id. */
    public const PHASE_WITHDRAWALS = 'withdrawal_records';

    /** The three phases in order, and where each keeps its own position. */
    private const POSITION = [
        self::PHASE_STAFF => 'after_staff',
        self::PHASE_BALANCE => 'after_balance',
        self::PHASE_WITHDRAWALS => 'after_withdraw',
    ];

    /** Same policy as the product import, for the same reason. */
    public const GIVE_UP_AFTER = 3;

    public const REFUSALS_KEPT = 20;

    public function __construct(private readonly ImportFromDokan $import)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * Two hundred, not fifty.
     *
     * A record is one small `INSERT` with no file copy and no image metadata —
     * the whole reason the product import's page is fifty. The budget is still
     * asked between units, so a slow host ends the batch on time rather than
     * on the count.
     */
    public function batchSize(): int
    {
        return 200;
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

        $phase = (string) ($job->cursor['phase'] ?? self::PHASE_STAFF);
        if (!isset(self::POSITION[$phase])) {
            $phase = self::PHASE_STAFF;
        }
        // All three positions, every request. The active phase's own `after`
        // is the authoritative one for this batch; the other two are carried
        // through untouched, which is what makes them independent.
        $kept = [
            'after_staff' => (int) ($job->cursor['after_staff'] ?? 0),
            'after_balance' => (int) ($job->cursor['after_balance'] ?? 0),
            'after_withdraw' => (int) ($job->cursor['after_withdraw'] ?? 0),
        ];
        $after = $kept[self::POSITION[$phase]];
        $at = (int) ($job->cursor['at'] ?? 0);
        $tries = (int) ($job->cursor['tries'] ?? 0);
        $pageTries = (int) ($job->cursor['page_tries'] ?? 0);
        $total = (int) ($job->payload['total'] ?? 0);
        $refused = array_values(array_filter(
            (array) ($job->cursor['refused'] ?? []),
            static fn (mixed $n): bool => is_string($n)
        ));

        // Every cursor this handler writes carries the other two positions.
        //
        // `CursorProgress` knows about one phase and one `after`, which is
        // right for an import with a single walk and would silently drop two
        // thirds of this one's place. So the merge lives here, in the
        // handler's own data, and it mirrors the active `after` into that
        // phase's own key as it goes.
        // ⛔ `$kept` BY REFERENCE, not by value. The first version captured it
        // by value and the test for this found the defect at once: during the
        // staff walk every `landed()` wrote the right `after_staff`, and then
        // the write at the PHASE BOUNDARY — built from a `cursorAtStartOf()`
        // and merged with the request-start copy — put `after_staff` back to
        // nought. A resumed job would have walked every shop's staff again.
        // The positions move as the walk moves, so what carries them has to
        // move with it.
        $carry = static function (array $cursor) use (&$kept): array {
            $own = self::POSITION[(string) ($cursor['phase'] ?? '')] ?? null;
            if ($own !== null) {
                $kept[$own] = (int) ($cursor['after'] ?? 0);
            }
            return array_merge($cursor, $kept);
        };
        // And the boundary reads the kept position BEFORE `$carry` mirrors
        // it, which is why the two lines below are in that order.


        if ($checkpoint === null) {
            // No runner and therefore no lease: the preview's and the tools'
            // path. One batch of the active phase, counted in the return
            // value, with nothing claimed and nothing to lose.
            return $this->withoutALease($runId, $phase, $after, $total, $refused, $kept);
        }

        $timing = StepTiming::fromCursor($job->cursor);
        $here = static function (string $p, int $a, int $u, int $t, int $pt, array $names) use ($timing, $budget, $carry): array {
            $cursor = ['phase' => $p, 'after' => $a, 'at' => $u, 'tries' => $t, 'page_tries' => $pt];
            if ($names !== []) {
                $cursor['refused'] = array_slice($names, -self::REFUSALS_KEPT);
            }
            $cursor = array_merge($cursor, $timing->fragment());
            if ($budget !== null) {
                $cursor['budget_ms'] = (int) round($budget->totalSeconds() * 1000.0);
                $cursor['budget_spent_ms'] = (int) round($budget->spentMs());
                if ($budget->belowFloor()) {
                    $cursor['budget_low'] = true;
                }
            }
            return $carry($cursor);
        };

        // That we tried to read a page, written before reading one.
        $pageTries++;
        if (!$checkpoint($here($phase, $after, $at, $tries, $pageTries, $refused), 0, 0, $total)) {
            return JobBatch::progress($here($phase, $after, $at, $tries, $pageTries - 1, $refused), 0, 0, $total);
        }
        if ($pageTries > self::GIVE_UP_AFTER) {
            return $this->stepOver($phase, $after, $total, $pageTries - 1, $refused, $here);
        }

        $progress = new CursorProgress(
            static fn (array $cursor, int $done, int $failed): bool
                => $checkpoint($carry($cursor), $done, $failed, $total),
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

        $page = $this->pageOf($phase, $runId, $after, $progress);
        $notes = $page['notes'];
        if (($page['stopped'] ?? '') !== '') {
            $notes[] = (string) $page['stopped'];
        }
        if ($page['already'] > 0) {
            // «Already there» is a resumed page re-running its last rows, and
            // it is said out loud rather than folded into the done figure:
            // one number serving both would report records as newly arrived
            // on the strength of writes a unique key refused.
            $notes[] = $phase . '_already:' . (int) $page['already'];
        }

        if ($progress->leaseLost()) {
            return JobBatch::progress($carry($progress->cursor()), 0, 0, $total, $notes);
        }

        if ($page['more']) {
            return JobBatch::progress(
                $this->stoppedBecause(
                    $this->pageRead($carry($progress->cursor())),
                    (string) ($page['stopped'] ?? '')
                ),
                $page['done'],
                $page['failed'],
                $total,
                $notes
            );
        }

        $next = $this->after($phase);
        if ($next !== null) {
            // This kind is finished. The next one starts from ITS OWN kept
            // position, not from zero and not from this kind's last id: a
            // vendor id, a balance `row_id` and a withdrawal id are three
            // different places, and the second request of a resumed job must
            // not restart a kind that had already got half way.
            // ⛔ The kept position is read FIRST and written into `after`
            // before `$carry` runs, not after. `$carry` mirrors whatever
            // `after` holds into that phase's own key, so merging a
            // `cursorAtStartOf()` — whose `after` is nought by definition —
            // would have carried the next kind back to the beginning and
            // undone exactly the independence this cursor exists for.
            $at = $progress->cursorAtStartOf($next);
            $at['after'] = $kept[self::POSITION[$next]];
            return JobBatch::progress($carry($at), $page['done'], $page['failed'], $total, $notes);
        }

        return JobBatch::finished(
            $this->pageRead($carry($progress->cursor())),
            $page['done'],
            $page['failed'],
            $total,
            $notes
        );
    }

    /** The kind after this one, or null when the last is done. */
    private function after(string $phase): ?string
    {
        return match ($phase) {
            self::PHASE_STAFF => self::PHASE_BALANCE,
            self::PHASE_BALANCE => self::PHASE_WITHDRAWALS,
            default => null,
        };
    }

    /**
     * @return array{kind:string, done:int, recorded:int, already:int, failed:int, last:int, more:bool, lease_lost:bool, stopped:string, notes:list<string>}
     */
    private function pageOf(string $phase, string $runId, int $after, ?ImportProgressInterface $progress): array
    {
        return match ($phase) {
            self::PHASE_BALANCE => $this->import->importBalanceRecordPage($runId, $after, $this->batchSize(), $progress),
            self::PHASE_WITHDRAWALS => $this->import->importWithdrawalRecordPage($runId, $after, $this->batchSize(), $progress),
            default => $this->import->importStaffRecordPage($runId, $after, $this->batchSize(), $progress),
        };
    }

    /**
     * One batch with no lease: what the preview and the tools get.
     *
     * @param list<string> $refused
     * @param array{after_staff:int, after_balance:int, after_withdraw:int} $kept
     */
    private function withoutALease(string $runId, string $phase, int $after, int $total, array $refused, array $kept): JobBatch
    {
        $page = $this->pageOf($phase, $runId, $after, null);
        $cursor = static function (string $p, int $a) use ($refused, $kept): array {
            $c = ['phase' => $p, 'after' => $a, 'at' => 0, 'tries' => 0, 'page_tries' => 0];
            if ($refused !== []) {
                $c['refused'] = $refused;
            }
            $kept[self::POSITION[$p]] = $a;
            return array_merge($c, $kept);
        };
        if ($page['more']) {
            return JobBatch::progress($cursor($phase, (int) $page['last']), $page['done'], $page['failed'], $total, $page['notes']);
        }
        $next = $this->after($phase);
        if ($next === null) {
            return JobBatch::finished($cursor($phase, (int) $page['last']), $page['done'], $page['failed'], $total, $page['notes']);
        }
        // The next kind resumes from ITS kept position, for the same reason
        // as the leased path above.
        return JobBatch::progress(
            $cursor($next, $kept[self::POSITION[$next]]),
            $page['done'],
            $page['failed'],
            $total,
            $page['notes']
        );
    }

    /**
     * Three workers died without one row coming back from the reader.
     *
     * ⛔ **And unlike the product import, this cannot say whether the row at
     * that position arrived.** `unitIsAlreadyOurs()` answers for a product
     * because a product's mapping is stored under the id the cursor holds;
     * our balance table keeps Dokan's `trn_id` and `trn_type` and NOT its
     * `row_id`, which is the key the cursor pages on, so there is nothing to
     * ask. So the position is named, the note says the arrival is
     * unestablished, and it is counted against the figure rather than for it
     * — «I could not tell» leaning towards «something is missing», which is
     * the direction a person can act on.
     *
     * @param list<string> $refused
     */
    private function stepOver(string $phase, int $after, int $total, int $tries, array $refused, \Closure $here): JobBatch
    {
        $id = $this->import->unitAfter($phase, $after);
        if ($id === null) {
            // The position was the END of this kind, so the deaths were about
            // something else. Move on without naming a row that is not there.
            $note = 'stalled_at_end_of:' . $phase;
            $next = $this->after($phase);
            if ($next === null) {
                return JobBatch::progress(
                    $here($phase, $after, 0, 0, 0, array_merge($refused, [$note])),
                    0,
                    0,
                    $total,
                    [$note]
                );
            }
            return JobBatch::progress(
                $here($next, 0, 0, 0, 0, array_merge($refused, [$note])),
                0,
                0,
                $total,
                [$note]
            );
        }
        $note = $phase . ':' . $id . ':page_unreadable_after_' . $tries . '_attempts_arrival_unestablished';
        return JobBatch::progress(
            $here($phase, $id, 0, 0, 0, array_merge($refused, [$note])),
            0,
            1,
            $total,
            [$note]
        );
    }

    /** @param array<string,mixed> $cursor @return array<string,mixed> */
    private function pageRead(array $cursor): array
    {
        $cursor['page_tries'] = 0;
        unset($cursor['stopped']);
        return $cursor;
    }

    /** @param array<string,mixed> $cursor @return array<string,mixed> */
    private function stoppedBecause(array $cursor, string $why): array
    {
        if ($why === '') {
            unset($cursor['stopped']);
            return $cursor;
        }
        $cursor['stopped'] = $why;
        return $cursor;
    }
}
