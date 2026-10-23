<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\Migrations\M0013JobsAndOutbox;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\Jobs\DbJobRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;

/**
 * Interruption, resume and completion for the Dokan import — the thing that
 * stopped on staging, on real MariaDB.
 *
 * ⛔ `DokanImportJob` had NO test at all until this file. Every suite that
 * touched the queue used a throwaway handler that counts to three, and every
 * suite that touched the import called `ImportFromDokan` directly; the class
 * where the two meet — the one holding the cursor that decides whether 184
 * products arrive — was only ever read. `alpha.42`'s rule, one module over:
 * code with no test does not break when it is wrong.
 *
 * **What a dying process is modelled as, and why not an exception.** The
 * failure on staging was a request the host gave up on: nothing is written and
 * nothing is logged. A `throw` inside the handler is caught by `JobRunner` and
 * recorded as a failure, and an `exit` still runs shutdown functions — so
 * neither models it. What DOES model it exactly is a checkpoint closure that
 * writes `n` times and then stops the handler where it stands: afterwards the
 * row holds whatever the n-th checkpoint left, and nothing else, which is
 * precisely the state a killed worker leaves behind. The real SIGKILL on a
 * real second process is measured in
 * `docs/evidence/alpha71-reproduction/` — this is the part that belongs in a
 * suite.
 */
final class DokanImportJobTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const SELLER = 401;
    private const RUN = 'run-alpha72';
    private const PRODUCTS = 12;

    private ImportFromDokan $import;
    private DbProductRepository $products;
    private DbJobRepository $jobs;
    private DokanImportJob $handler;
    private JobRunner $runner;
    private FakeDokanReader $dokan;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
            ...M0013JobsAndOutbox::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);
        $this->db = $db;

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);
        $this->products = new DbProductRepository($db, $clock);
        $this->jobs = new DbJobRepository($db, $clock);
        $this->dokan = new FakeDokanReader();
        $this->dokan->vendorRows = [
            ['user_id' => self::SELLER, 'store_name' => 'داروخانه دکان', 'email' => 's@example.test', 'enabled' => true],
        ];
        for ($i = 1; $i <= self::PRODUCTS; $i++) {
            $this->dokan->productRows[] = [
                'wc_product_id' => 1000 + $i,
                'vendor_user_id' => self::SELLER,
                'title' => 'کالای ' . $i,
                'sku' => 'DK-' . $i,
                'post_status' => 'publish',
                'price_minor' => 100000 + $i,
                'price_recorded' => true,
                'price_not_exact' => false,
                'stock' => 3,
            ];
        }
        $this->import = new ImportFromDokan(
            $this->dokan,
            new DbVendorRepository($db, $clock),
            $this->products,
            $options,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
        );
        $this->handler = new DokanImportJob($this->import);
        $this->runner = new JobRunner($this->jobs, static fn (): string => bin2hex(random_bytes(8)));
        $this->runner->register($this->handler);
    }

    /** The job as the page queues it: one live key, the plan's total. */
    private function queue(): int
    {
        $queued = $this->jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => self::RUN, 'total' => self::PRODUCTS + 1],
            DokanImportJob::TYPE,
            self::MANAGER
        );
        return (int) $queued['id'];
    }

    /** How many marketplace rows point at a WooCommerce product. */
    private function mapped(): int
    {
        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $this->wpdb->prefix . 'tmc_products WHERE wc_product_id > 0'
        );
    }

    /** Rows pointing at the SAME WooCommerce product — the duplicate the owner forbade. */
    private function duplicates(): int
    {
        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM (SELECT wc_product_id FROM ' . $this->wpdb->prefix
            . 'tmc_products WHERE wc_product_id > 0 GROUP BY wc_product_id HAVING COUNT(*) > 1) d'
        );
    }

    /**
     * Run the handler against a live claim and let it get `$units` units in
     * before the process stops existing.
     *
     * @return array{cursor:array<string,mixed>, done:int, failed:int}
     */
    private function dieAfter(int $jobId, int $units): array
    {
        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the job has to be claimable for this to mean anything');
        $written = 0;
        $checkpoint = function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token, &$written, $units): bool {
            if ($written >= $units) {
                throw new ProcessStoppedExisting();
            }
            $written++;
            return $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300);
        };
        try {
            $this->handler->step($job, $checkpoint);
        } catch (ProcessStoppedExisting) {
            // Nothing after this point runs, which is the whole shape of it.
        }
        $row = $this->jobs->find($jobId);
        self::assertNotNull($row);
        return ['cursor' => $row->cursor, 'done' => $row->done, 'failed' => $row->failed];
    }

    /**
     * THE ONE THAT WOULD HAVE CAUGHT STAGING. A worker that stops existing part
     * way through a page leaves the row knowing where it got to.
     *
     * On `alpha.71` this row would still carry the cursor it started with and
     * `done = 1`, because the only checkpoint was the one after all fifty
     * products — and the next worker would read that same cursor and die in
     * the same place for ever.
     */
    public function testAWorkerThatStopsExistingMidPageLeavesThePositionItReached(): void
    {
        $id = $this->queue();
        // The vendors phase first, exactly as the real run does.
        $this->runner->run(1);
        $before = $this->jobs->find($id);
        self::assertSame(DokanImportJob::PHASE_PRODUCTS, $before->cursor['phase']);
        self::assertSame(0, (int) $before->cursor['after']);
        self::assertSame(1, $before->done, 'one shop recorded');

        // Four units in — one «we tried» plus three products — and then gone.
        $after = $this->dieAfter($id, 4);
        self::assertSame(DokanImportJob::PHASE_PRODUCTS, $after['cursor']['phase']);
        self::assertSame(1003, (int) $after['cursor']['after'], 'the third product is where it got to');
        self::assertSame(4, $after['done'], 'one shop and three products, all recorded');
        // FOUR arrived, THREE are recorded: the fourth was created and the
        // process stopped existing before its own checkpoint could run. One
        // unit in flight is the whole remaining exposure, and the next worker
        // skips it by its mapping rather than making a second one.
        self::assertSame(4, $this->mapped(), 'four arrived — the fourth was in flight');
        self::assertSame(1, $this->mapped() - ($after['done'] - 1), 'exactly one unit unrecorded');
    }

    /**
     * «That we tried» is written BEFORE the work, because a process that stops
     * existing writes nothing afterwards.
     *
     * The one write this worker is allowed is the attempt itself, so the row
     * knows somebody tried here even though the figure did not move — which is
     * exactly what `alpha.71` could not record and what made three attempts
     * indistinguishable from none.
     */
    public function testTheAttemptIsRecordedBeforeAnyOfTheWork(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $after = $this->dieAfter($id, 1);
        self::assertSame(1, (int) $after['cursor']['tries'], 'the attempt is on the row');
        self::assertSame(0, (int) $after['cursor']['after'], 'and the position has not moved');
        self::assertSame(1, $after['done'], 'nor the figure: still just the shop');
        self::assertLessThanOrEqual(1, $this->mapped(), 'at most the one unit in flight');
    }

    /** A unit that lands says this position is not the one killing workers. */
    public function testAUnitThatLandsResetsTheAttemptCount(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $this->dieAfter($id, 1);
        self::assertSame(1, (int) $this->jobs->find($id)->cursor['tries']);

        $this->jobs->release($id, (string) $this->jobs->find($id)->lockToken);
        $this->dieAfter($id, 3);
        self::assertSame(0, (int) $this->jobs->find($id)->cursor['tries'], 'two products landed, so nought');
    }

    /**
     * A position that has killed `GIVE_UP_AFTER` workers is named and stepped
     * over — so the other eleven products arrive instead of none of them.
     */
    public function testAPositionThatKeepsKillingWorkersIsNamedAndSteppedOver(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        for ($i = 0; $i < DokanImportJob::GIVE_UP_AFTER; $i++) {
            $this->dieAfter($id, 1);
            $row = $this->jobs->find($id);
            $this->jobs->release($id, $row->lockToken);
        }
        $row = $this->jobs->find($id);
        self::assertSame(DokanImportJob::GIVE_UP_AFTER, (int) $row->cursor['tries']);
        self::assertSame(0, (int) $row->cursor['after'], 'and it never got past the first one');
        $arrivedBefore = $this->mapped();

        $results = $this->runner->run(1);
        self::assertSame('more', $results[0]['outcome']);
        $after = $this->jobs->find($id);
        self::assertSame(1001, (int) $after->cursor['after'], 'the cursor is past the unit it stopped on');
        self::assertSame(0, (int) $after->cursor['tries'], 'with a clean slate for the next one');
        self::assertSame($arrivedBefore, $this->mapped(), 'stepping over creates nothing');
        self::assertNotEmpty($after->cursor['refused'] ?? [], 'and the unit is named');
        self::assertStringContainsString('1001:', (string) ($after->cursor['refused'][0] ?? ''));
        // Each of those dying attempts let its in-flight product land, so 1001
        // is already ours — and a unit that ARRIVED is not blamed for the
        // deaths. The position moves either way; only the verdict differs.
        self::assertSame(0, $after->failed, 'a row that arrived is not counted as refused');
        self::assertStringContainsString('arrived_before_the_worker_died', (string) ($after->cursor['refused'][0] ?? ''));
    }

    /**
     * Interrupted, resumed, finished: every unit accounted for, once.
     */
    public function testAnInterruptedImportResumesAndCompletesWithoutDuplicating(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $this->dieAfter($id, 4);
        $row = $this->jobs->find($id);
        $this->jobs->release($id, $row->lockToken);
        self::assertSame(4, $this->mapped(), 'three recorded, one in flight');

        // However many batches it takes, the way cron or the button does it.
        for ($i = 0; $i < 20; $i++) {
            $row = $this->jobs->find($id);
            if ($row->status->isTerminal()) {
                break;
            }
            if ($this->runner->run(1) === []) {
                break;
            }
        }
        $row = $this->jobs->find($id);
        self::assertSame(JobStatus::Done, $row->status, 'the import finishes');
        self::assertSame(self::PRODUCTS + 1, $row->done + $row->failed, 'every unit accounted for');
        self::assertSame(self::PRODUCTS, $this->mapped(), 'every product mapped');
        self::assertSame(0, $this->duplicates(), 'and not one of them twice');
    }

    /**
     * The same page run twice over creates nothing twice — the property every
     * resume rests on, asserted directly rather than inferred from the resume.
     */
    public function testRunningTheSamePageTwiceCreatesNothingTwice(): void
    {
        $first = $this->import->importProductPage(self::RUN, 0, 5);
        self::assertSame(5, $first['done']);
        self::assertSame(5, $this->mapped());
        $second = $this->import->importProductPage(self::RUN, 0, 5);
        self::assertSame(5, $second['done'], 'counted as done, because they are');
        self::assertSame(5, $this->mapped(), 'and no second row for any of them');
        self::assertSame(0, $this->duplicates());
    }

    /** Every unit of a page records itself, not just the page. */
    public function testEveryProductOnAPageRecordsItsOwnPosition(): void
    {
        $seen = [];
        $this->import->importProductPage(self::RUN, 0, 5, function (int $at, int $done, int $failed) use (&$seen): bool {
            $seen[] = $at;
            return true;
        });
        self::assertSame([1001, 1002, 1003, 1004, 1005], $seen);
    }

    /**
     * And a page whose lease is taken mid-way stops at once rather than writing
     * over the work of whoever holds the job now.
     */
    public function testAPageWhoseLeaseIsTakenStopsInsteadOfCarryingOn(): void
    {
        $calls = 0;
        $page = $this->import->importProductPage(self::RUN, 0, 10, function () use (&$calls): bool {
            $calls++;
            return $calls < 3;                   // the third unit loses the lease
        });
        self::assertTrue($page['lease_lost']);
        self::assertFalse($page['more'], 'a page the lease was taken from is not a page that ended');
        self::assertSame(3, $this->mapped(), 'it stopped at the one it was on');
    }
}

/** Not an error: the shape of a process that is no longer there. */
final class ProcessStoppedExisting extends \RuntimeException
{
}
