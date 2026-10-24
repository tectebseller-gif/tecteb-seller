<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords;
use Tecteb\Marketplace\Infrastructure\Jobs\DbJobRepository;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\DokanRecordImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;

/**
 * Importing a shop's records through the real queue: explicit, resumable,
 * budgeted, and history only.
 *
 * ⛔ **What was broken.** `ImportFromDokan::importShopRecordPage()` existed
 * from `alpha.17` and had no caller in `src/` up to and including `alpha.76`.
 * The handover page asked `vendorsWithRecords()` — a `UNION` of the three
 * history tables — found it empty on every install, and told the manager to
 * run the PRODUCT import again, which could never have written a record. So
 * these tests run the thing that production runs: the handler, through
 * `JobRunner`, with a real lease and a real lock token.
 *
 * Each kind keeps its own cursor, which is the property that was missing from
 * the dormant method: it walked staff only `if ($afterBalanceId === 0 &&
 * $afterWithdrawId === 0)`, so an interrupted staff walk could never resume
 * once the balance cursor moved.
 */
final class DokanRecordImportTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const SHOP_A = 401;
    private const SHOP_B = 402;
    private const RUN = 'dokan-records-test';

    private ImportFromDokan $import;
    private DbShopRecordRepository $records;
    private DbJobRepository $jobs;
    private DokanRecordImportJob $handler;
    private JobRunner $runner;
    private FakeDokanReader $dokan;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        $this->resetSchema($db);
        $this->db = $db;

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);

        $this->records = new DbShopRecordRepository($db, $clock);
        $this->jobs = new DbJobRepository($db, $clock);
        $this->dokan = new FakeDokanReader();
        $this->seedDokan();

        $this->import = new ImportFromDokan(
            $this->dokan,
            new DbVendorRepository($db, $clock),
            new DbProductRepository($db, $clock),
            $options,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            $this->records,
            null,
            null,
            null
        );
        $this->handler = new DokanRecordImportJob($this->import);
        $this->runner = new JobRunner($this->jobs, static fn (): string => bin2hex(random_bytes(8)));
        $this->runner->register($this->handler);
    }

    /**
     * Two shops: three staff between them, four balance rows, three
     * withdrawals.
     *
     * Two shops rather than one, because the staff walk's unit is a SHOP and a
     * single-shop fixture cannot tell «resumed at the second shop» from
     * «started again».
     */
    private function seedDokan(): void
    {
        $this->dokan->vendorRows = [
            ['user_id' => self::SHOP_A, 'store_name' => 'داروخانه الف', 'email' => 'a@example.test', 'enabled' => true],
            ['user_id' => self::SHOP_B, 'store_name' => 'داروخانه ب', 'email' => 'b@example.test', 'enabled' => true],
        ];
        $this->dokan->staffRows = [
            ['staff_user_id' => 7001, 'vendor_user_id' => self::SHOP_A, 'display_name' => 'مریم رضایی', 'user_email' => 'm@example.test', 'dokan_role' => 'vendor_staff'],
            ['staff_user_id' => 7002, 'vendor_user_id' => self::SHOP_A, 'display_name' => 'سینا احمدی', 'user_email' => 's@example.test', 'dokan_role' => 'vendor_staff'],
            ['staff_user_id' => 7003, 'vendor_user_id' => self::SHOP_B, 'display_name' => 'نگار کریمی', 'user_email' => 'n@example.test', 'dokan_role' => 'vendor_staff'],
        ];
        $this->dokan->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SHOP_A, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000', 'credit' => '0.0000', 'status' => 'approved'],
            ['row_id' => 2, 'trn_id' => 9001, 'vendor_user_id' => self::SHOP_A, 'trn_type' => 'dokan_withdraw', 'debit' => '0.0000', 'credit' => '250000.0000', 'status' => 'approved'],
            ['row_id' => 3, 'trn_id' => 5502, 'vendor_user_id' => self::SHOP_B, 'trn_type' => 'dokan_orders', 'debit' => '125000.5000', 'credit' => '0.0000', 'status' => 'approved'],
            ['row_id' => 4, 'trn_id' => 5503, 'vendor_user_id' => self::SHOP_B, 'trn_type' => 'dokan_orders', 'debit' => '1.0001', 'credit' => '0.0000', 'status' => 'pending'],
        ];
        $this->dokan->withdrawRows = [
            ['withdraw_id' => 9001, 'vendor_user_id' => self::SHOP_A, 'amount' => '250000.0000', 'status' => 'dokan:1', 'method' => 'bank'],
            ['withdraw_id' => 9002, 'vendor_user_id' => self::SHOP_B, 'amount' => '10000.0000', 'status' => 'dokan:0', 'method' => 'bank'],
            ['withdraw_id' => 9003, 'vendor_user_id' => self::SHOP_B, 'amount' => '1.5000', 'status' => 'dokan:2', 'method' => 'bank'],
        ];
    }

    private function queue(int $total = 10): int
    {
        $queued = $this->jobs->enqueue(
            DokanRecordImportJob::TYPE,
            ['run_id' => self::RUN, 'total' => $total],
            DokanRecordImportJob::TYPE,
            self::MANAGER
        );
        return (int) $queued['id'];
    }

    private function rows(string $suffix): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->wpdb->prefix . $suffix);
    }

    /**
     * Run the queue until it has nothing live left, bounded.
     *
     * `JobRunner::run()` returns one row per batch it ran, so an empty list
     * is «nothing was due» — the loop's exit, and the reason it is a list
     * rather than a boolean.
     */
    private function drain(int $passes = 12): int
    {
        $ran = 0;
        for ($i = 0; $i < $passes; $i++) {
            if ($this->runner->run(1) === []) {
                break;
            }
            $ran++;
        }
        return $ran;
    }

    public function testTheQueueImportsAllThreeKindsAndFinishes(): void
    {
        $id = $this->queue();
        $this->drain();

        $job = $this->jobs->find($id);
        self::assertNotNull($job);
        self::assertSame('done', $job->status->value, 'the record import finishes');
        self::assertSame(3, $this->rows(M0017DokanShopRecords::STAFF));
        self::assertSame(4, $this->rows(M0017DokanShopRecords::BALANCE));
        self::assertSame(3, $this->rows(M0017DokanShopRecords::WITHDRAW));
        self::assertSame(10, $job->done, 'every record is counted once');
        self::assertSame(0, $job->failed);
    }

    /** Dokan's own decimals arrive unchanged — no rate, no rounding. */
    public function testDokansFiguresArriveExactly(): void
    {
        $this->queue();
        $this->drain();

        $summary = $this->records->summaryForVendor(self::SHOP_B);
        self::assertSame('125001.5001', $summary['debit'], "Dokan's own decimals, summed in SQL");
        self::assertSame('10001.5000', $summary['withdrawn']);
        self::assertSame('250000.0000', $this->records->summaryForVendor(self::SHOP_A)['credit']);
    }

    /**
     * §10: interruption and resumption in the middle of EACH kind.
     *
     * The kill is a process that stops existing — an exception out of the
     * checkpoint, nothing after it runs — which is the shape staging's worker
     * had: no fatal in any log, because nothing was alive to write one.
     *
     * @dataProvider kinds
     */
    public function testAnInterruptionInOneKindResumesWhereItStopped(string $phase, string $table, int $expected): void
    {
        $id = $this->queue();

        // Walk the kinds before this one to completion, so the kill lands
        // inside the kind under test and not before it.
        $this->runUntilPhase($id, $phase);

        $before = $this->rows($table);
        $cursor = $this->dieAfterWrites($id, 2);
        self::assertSame($phase, (string) ($cursor['phase'] ?? ''), 'the kill landed inside ' . $phase);

        // What survived is written down, and the position moved past it.
        $mid = $this->rows($table);
        self::assertGreaterThanOrEqual($before, $mid);

        $this->drain();

        self::assertSame($expected, $this->rows($table), $phase . ' finishes after the interruption');
        self::assertSame(0, $this->duplicateRecords($table), $phase . ' wrote nothing twice');
    }

    /** @return array<string, array{0:string, 1:string, 2:int}> */
    public static function kinds(): array
    {
        return [
            'staff' => [DokanRecordImportJob::PHASE_STAFF, M0017DokanShopRecords::STAFF, 3],
            'balance' => [DokanRecordImportJob::PHASE_BALANCE, M0017DokanShopRecords::BALANCE, 4],
            'withdrawals' => [DokanRecordImportJob::PHASE_WITHDRAWALS, M0017DokanShopRecords::WITHDRAW, 3],
        ];
    }

    /**
     * ⛔ The property the dormant method did not have.
     *
     * An interruption inside the balance walk must leave the STAFF position
     * where it was. The old code rebuilt staff from nought on every page whose
     * other two cursors were zero and never again afterwards; a cursor that
     * carried one position for three kinds has the same defect in the other
     * direction — it would send a resumed job back to the first shop.
     */
    public function testAnInterruptionInOneKindLeavesTheOtherTwoPositionsAlone(): void
    {
        $id = $this->queue();
        $this->runUntilPhase($id, DokanRecordImportJob::PHASE_BALANCE);

        $job = $this->jobs->find($id);
        self::assertNotNull($job);
        $staffAfter = (int) ($job->cursor['after_staff'] ?? 0);
        self::assertGreaterThan(0, $staffAfter, 'the staff walk left a position behind');

        $cursor = $this->dieAfterWrites($id, 2);

        self::assertSame(
            $staffAfter,
            (int) ($cursor['after_staff'] ?? 0),
            "the staff position survived an interruption in another kind's walk"
        );
        self::assertSame(DokanRecordImportJob::PHASE_BALANCE, (string) ($cursor['phase'] ?? ''));
    }

    /**
     * The per-kind key is AUTHORITATIVE, not a copy of the shared `after`.
     *
     * ⛔ Falsification found this one missing: reading `$job->cursor['after']`
     * instead of the kind's own key passed every other test, because the
     * handler writes both and they agree by construction. They stop agreeing
     * the moment a cursor is not one this build wrote — a hand-edited row, or
     * one from a build whose `after` held another kind's position. So the
     * fixture WRITES such a cursor and the position that must win is the
     * kind's own.
     *
     * `after` is set to a withdrawal id while the phase is the balance walk:
     * reading it would skip the first three balance rows.
     */
    public function testTheKindsOwnPositionWinsOverAStaleSharedCursor(): void
    {
        $id = $this->queue();
        $token = 'tok-stale-cursor';
        $job = $this->jobs->claim([DokanRecordImportJob::TYPE], $token, 300);
        self::assertNotNull($job);
        self::assertTrue($this->jobs->checkpoint(
            $id,
            $token,
            [
                'phase' => DokanRecordImportJob::PHASE_BALANCE,
                // A position from somewhere else entirely — past every
                // balance row this fixture has.
                'after' => 9999,
                'after_staff' => 0,
                'after_balance' => 0,
                'after_withdraw' => 0,
            ],
            0,
            0,
            10,
            300
        ));
        self::assertSame('', $this->jobs->handOver($id, '', true));

        $this->drain();

        self::assertSame(
            4,
            $this->rows(M0017DokanShopRecords::BALANCE),
            "the balance walk started from its OWN position, not from the shared cursor"
        );
    }

    /** §10: a repeat run writes no second row and counts no second record. */
    public function testRunningTheWholeImportTwiceWritesEachRecordOnce(): void
    {
        $first = $this->queue();
        $this->drain();
        $firstDone = $this->jobs->find($first)?->done;

        // A second explicit request, exactly as a second press of the button
        // would make it: a new run id, the same Dokan rows.
        $second = (int) $this->jobs->enqueue(
            DokanRecordImportJob::TYPE,
            ['run_id' => self::RUN . '-again', 'total' => 10],
            DokanRecordImportJob::TYPE,
            self::MANAGER
        )['id'];
        self::assertNotSame($first, $second, 'the finished job does not block a new one');
        $this->drain();

        self::assertSame(10, $firstDone);
        self::assertSame(3, $this->rows(M0017DokanShopRecords::STAFF));
        self::assertSame(4, $this->rows(M0017DokanShopRecords::BALANCE));
        self::assertSame(3, $this->rows(M0017DokanShopRecords::WITHDRAW));
        self::assertSame(
            0,
            $this->jobs->find($second)?->done,
            'the unique key answered, so the second run counts nothing as newly recorded'
        );
        self::assertSame(0, $this->jobs->find($second)?->failed, '«already there» is not a failure');
    }

    /** §10: two workers, one job. The live key decides, not a check. */
    public function testASecondRequestCannotStartASecondLiveImport(): void
    {
        $first = $this->queue();
        $again = $this->jobs->enqueue(
            DokanRecordImportJob::TYPE,
            ['run_id' => 'another-run', 'total' => 10],
            DokanRecordImportJob::TYPE,
            self::MANAGER
        );

        self::assertSame($first, (int) $again['id'], 'the same live job is handed back');
        self::assertFalse($again['created'], 'and it is not created a second time');
    }

    public function testASecondWorkerCannotClaimTheSameJob(): void
    {
        $this->queue();
        $first = $this->jobs->claim([DokanRecordImportJob::TYPE], 'tok-one', 300);
        $second = $this->jobs->claim([DokanRecordImportJob::TYPE], 'tok-two', 300);

        self::assertNotNull($first);
        self::assertNull($second, 'a leased job is not claimable by a second worker');
    }

    /**
     * A worker that has lost the job writes nothing further.
     *
     * The guarantee is `writingUnderLease()`: the write sits inside the
     * transaction that read the job row and compared the token, so there is no
     * gap between the question and the write (`alpha.74`).
     */
    public function testAWorkerWhoseLeaseWentWritesNoRecord(): void
    {
        $id = $this->queue();
        $job = $this->jobs->claim([DokanRecordImportJob::TYPE], 'tok-stale', 300);
        self::assertNotNull($job);

        // Somebody else takes the job: the next worker's token lands on the
        // row, which is what ends the first worker's hold.
        // `''` is this method's success: it returns a REASON when it refuses,
        // and the empty string when the lease was ended.
        self::assertSame('', $this->jobs->handOver($id, '', true), 'the lease was ended');
        $this->jobs->claim([DokanRecordImportJob::TYPE], 'tok-fresh', 300);

        $before = $this->rows(M0017DokanShopRecords::BALANCE);
        $this->handler->step(
            $job,
            fn (array $cursor, int $done = 0, int $failed = 0, int $total = 0): bool
                => $this->jobs->checkpoint($id, 'tok-stale', $cursor, $done, $failed, $total, 300),
            fn (\Closure $write): string => $this->jobs->writingUnderLease($id, 'tok-stale', $write)
        );

        self::assertSame($before, $this->rows(M0017DokanShopRecords::BALANCE), 'the stale worker wrote nothing');
    }

    /** §6: history only. No debt, no settlement, no access, no ownership. */
    public function testTheRecordImportCreatesNoDebtNoAccessAndNoOwnership(): void
    {
        $this->queue();
        $this->drain();

        self::assertSame(0, $this->rows(M0004CreateFinanceTables::LEDGER), 'no ledger line');
        self::assertSame(0, $this->rows(M0007SettlementTables::WITHDRAWALS), 'no payable withdrawal');
        self::assertSame(0, $this->rows(M0003CreateStoreAndStaffTables::STAFF), 'no staff membership');
        self::assertSame(0, $this->rows('tmc_products'), 'no product row');
        self::assertSame(0, $this->rows('tmc_vendor_profiles'), 'no vendor profile');
        // And the records themselves ARE there, so the assertions above are
        // about an import that happened rather than one that did nothing.
        self::assertSame(10, $this->rows(M0017DokanShopRecords::STAFF)
            + $this->rows(M0017DokanShopRecords::BALANCE)
            + $this->rows(M0017DokanShopRecords::WITHDRAW));
    }

    /** §7: Dokan's own data is not written, because there is no way to. */
    public function testDokansRowsAreUntouched(): void
    {
        $before = [
            'vendors' => $this->dokan->vendorRows,
            'staff' => $this->dokan->staffRows,
            'balance' => $this->dokan->balanceRows,
            'withdraw' => $this->dokan->withdrawRows,
        ];

        $this->queue();
        $this->drain();

        self::assertSame($before['vendors'], $this->dokan->vendorRows);
        self::assertSame($before['staff'], $this->dokan->staffRows);
        self::assertSame($before['balance'], $this->dokan->balanceRows);
        self::assertSame($before['withdraw'], $this->dokan->withdrawRows);
    }

    /**
     * §10: the product import's finished job row is not reopened.
     *
     * The owner's job #1 reads «انجام شد». A record import that touched it
     * would destroy the only record of what that import did, so it is a
     * separate type with a separate live key — and this asserts the row, not
     * the intention.
     */
    public function testAFinishedProductImportJobIsNotTouched(): void
    {
        $productJob = (int) $this->jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => 'run-one', 'total' => 186],
            DokanImportJob::TYPE,
            self::MANAGER
        )['id'];
        $token = 'tok-products';
        $claimed = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($claimed);
        $this->jobs->checkpoint($productJob, $token, ['phase' => 'orders', 'after' => 77], 190, 0, 186, 300);
        $this->jobs->finish($productJob, $token, \Tecteb\Marketplace\Core\Jobs\JobStatus::Done, '');
        $before = $this->jobs->find($productJob);
        self::assertNotNull($before);

        $this->queue();
        $this->drain();

        $after = $this->jobs->find($productJob);
        self::assertNotNull($after);
        self::assertSame($before->status->value, $after->status->value);
        self::assertSame($before->done, $after->done, "job #1's figures are a record, not a working value");
        self::assertSame($before->total, $after->total);
        self::assertSame($before->cursor, $after->cursor);
    }

    /** Without a record store the job refuses rather than reporting success. */
    public function testWithoutARecordStoreNothingIsImportedAndNothingIsClaimed(): void
    {
        $import = new ImportFromDokan(
            $this->dokan,
            new DbVendorRepository($this->db, new SystemClock()),
            new DbProductRepository($this->db, new SystemClock()),
            new WpOptionStore(),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new SystemClock(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            null,                               // no record store at all
            null,
            null,
            null
        );
        $page = $import->importStaffRecordPage('run-x', 0, 50);

        self::assertSame(0, $page['done']);
        self::assertSame(0, $page['failed']);
        self::assertFalse($page['more'], 'nowhere to keep records is not «there is more to do»');
    }

    // ------------------------------------------------------------------ helpers

    /** Run passes until the job's cursor reaches `$phase`, bounded. */
    private function runUntilPhase(int $id, string $phase): void
    {
        if ($phase === DokanRecordImportJob::PHASE_STAFF) {
            return;
        }
        for ($i = 0; $i < 12; $i++) {
            $job = $this->jobs->find($id);
            if ($job !== null && (string) ($job->cursor['phase'] ?? '') === $phase) {
                return;
            }
            if ($this->runner->run(1) === []) {
                break;
            }
        }
        self::assertSame(
            $phase,
            (string) ($this->jobs->find($id)?->cursor['phase'] ?? ''),
            'the fixture could not reach ' . $phase
        );
    }

    /**
     * Let `$writes` POSITION writes land, then stop existing.
     *
     * A step marker is not a position write: it leaves the position tuple
     * alone, and counting it would move the kill with the number of steps
     * (`alpha.76`).
     *
     * @return array<string,mixed>
     */
    private function dieAfterWrites(int $id, int $writes): array
    {
        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanRecordImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the job has to be claimable for this to mean anything');
        $written = 0;
        $before = null;
        $checkpoint = function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($id, $token, &$written, &$before, $writes): bool {
            $tuple = [
                (string) ($cursor['phase'] ?? ''),
                (int) ($cursor['after'] ?? 0),
                (int) ($cursor['at'] ?? 0),
                (int) ($cursor['tries'] ?? 0),
                (int) ($cursor['page_tries'] ?? 0),
            ];
            $moved = $tuple !== $before;
            $before = $tuple;
            if ($moved && $written >= $writes) {
                throw new RecordWorkerStopped();
            }
            if ($moved) {
                $written++;
            }
            return $this->jobs->checkpoint($id, $token, $cursor, $done, $failed, $total, 300);
        };
        try {
            $this->handler->step(
                $job,
                $checkpoint,
                fn (\Closure $write): string => $this->jobs->writingUnderLease($id, $token, $write)
            );
        } catch (RecordWorkerStopped) {
            // Nothing after this point runs, which is the whole shape of it.
        }
        // The lease is still held by a worker that no longer exists, exactly
        // as it would be on the host. Hand it over so the next pass can run —
        // and CHECK that it was handed over, because a fixture that spends a
        // guarantee and does not get it back makes the next assertion a
        // statement about the fixture (`alpha.76`).
        self::assertSame('', $this->jobs->handOver($id, '', true), 'the dead worker\'s lease was released');
        $row = $this->jobs->find($id);
        self::assertNotNull($row);
        return $row->cursor;
    }

    /** Rows that share a natural identity — the duplicate the key forbids. */
    private function duplicateRecords(string $table): int
    {
        $full = $this->wpdb->prefix . $table;
        $key = match ($table) {
            M0017DokanShopRecords::STAFF => 'staff_user_id, vendor_user_id',
            M0017DokanShopRecords::BALANCE => 'vendor_user_id, trn_id, trn_type',
            default => 'dokan_withdraw_id',
        };
        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM (SELECT ' . $key . ' FROM ' . $full
            . ' GROUP BY ' . $key . ' HAVING COUNT(*) > 1) d'
        );
    }
}

/** A worker that stopped existing: nothing after the throw runs. */
final class RecordWorkerStopped extends \RuntimeException
{
}
