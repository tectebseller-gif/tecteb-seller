<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBudget;
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
use Tecteb\Marketplace\Modules\Migration\Application\ImportProgressInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\LeaseHold;
use Tecteb\Marketplace\Modules\Migration\Domain\UnitClaim;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
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
    private FakeProductImages $images;
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
                // A picture on the source product, because that is the slow
                // second write and the place staging's worker was killed.
                'source_image_id' => 2000 + $i,
            ];
        }
        $this->images = new FakeProductImages();
        $this->import = new ImportFromDokan(
            $this->dokan,
            new DbVendorRepository($db, $clock),
            $this->products,
            $options,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            null,
            null,
            null,
            $this->images
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
     * Run the handler against a live claim and let it get `$writes`
     * POSITION writes in before the process stops existing.
     *
     * ⛔ Counted in writes, not units, and the two are not the same: a unit is
     * claimed before its work and recorded after it, so a page costs one write
     * for the read plus two per unit. That is the point — the claim is what
     * makes a worker killed mid-unit leave nothing behind — and a helper that
     * pretended otherwise would hide it.
     *
     * ⛔ And «unit» is doing real work in that sentence. From `alpha.75` the
     * cursor also carries which STEP is in flight, written before each step so
     * a worker that stops existing leaves its name behind — which means a unit
     * now costs five or six checkpoints rather than two. Counting those here
     * would silently move every kill in this file to a different place while
     * each test's name went on claiming the old one. So a marker write is not
     * counted, and the numbers below mean exactly what they meant before.
     *
     * ⛔ A marker is told apart by having a step IN FLIGHT, not by «the
     * position did not move» — which is what the first version used, and it
     * was wrong in exactly the case these tests are about: on a RESUMED job
     * the cursor already names the unit, so `claim()` moves nothing and the
     * kill landed a write late. Two tests failed and said so.
     *
     * @return array{cursor:array<string,mixed>, done:int, failed:int}
     */
    /**
     * The runner's unit-of-work gate, built the way `JobRunner` builds it.
     *
     * ⛔ A helper that left this out would hand the handler `null` and every
     * unit would refuse to write — and the first version of these two helpers
     * did exactly that, reporting eight failures about code that was right
     * (`alpha.55`: a fixture that cannot model the verb says the wrong thing
     * about the build). It is the REAL repository method, on the real
     * database, so the `FOR UPDATE` and the token comparison are the ones that
     * ship.
     */
    private function gateFor(int $jobId, string $token): \Closure
    {
        return fn (\Closure $write): string => $this->jobs->writingUnderLease($jobId, $token, $write);
    }

    /**
     * Let the batch's FIRST write land, then stop existing.
     *
     * Not `dieAfter($id, 1)`: that counts only bookkeeping writes, so the
     * step-start write between the page attempt and the claim lands too — and
     * that write rebuilds the step fragment, which hides the very thing being
     * measured. Here the second write of ANY kind is the end of the process.
     *
     * @return array<string,mixed> the cursor as the row holds it afterwards
     */
    private function dieAfterTheFirstWrite(int $jobId): array
    {
        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the job has to be claimable for this to mean anything');
        $written = 0;
        $checkpoint = function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token, &$written): bool {
            if ($written >= 1) {
                throw new ProcessStoppedExisting();
            }
            $written++;
            return $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300);
        };
        try {
            $this->handler->step($job, $checkpoint, $this->gateFor($job->id, $token));
        } catch (ProcessStoppedExisting) {
            // Which is the whole shape of it.
        }
        $row = $this->jobs->find($jobId);
        self::assertNotNull($row);
        self::assertSame(1, $written, 'exactly one write landed');
        return $row->cursor;
    }

    private function dieAfter(int $jobId, int $writes): array
    {
        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the job has to be claimable for this to mean anything');
        $written = 0;
        // WHAT COUNTS AS A WRITE HERE: one that moves the BOOKKEEPING.
        //
        // The handler writes the cursor for two different reasons. One moves
        // the position — the page-read attempt, a claim, a landing — and those
        // are what a test saying «die after four writes» is counting. The
        // other says «I am about to do step X», which happens several times
        // inside one product and must not be counted, or the numbers in every
        // test here would change with the number of steps.
        //
        // ⛔ The rule used to be «a write with `running` set is a step write».
        // That stopped being true this round: the first checkpoint of a batch
        // now carries the step a PREVIOUS worker left open, so on a resumed
        // job the page-read write looked like a step write and every count in
        // this file shifted by one — four failures about a handler that was
        // doing exactly the right thing. The position tuple cannot be fooled
        // that way: a step write leaves all four of its values alone.
        $before = null;
        $checkpoint = function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token, &$written, &$before, $writes): bool {
            $tuple = [
                (int) ($cursor['after'] ?? 0),
                (int) ($cursor['at'] ?? 0),
                (int) ($cursor['tries'] ?? 0),
                (int) ($cursor['page_tries'] ?? 0),
            ];
            $bookkeeping = $tuple !== $before;
            $before = $tuple;
            if ($bookkeeping && $written >= $writes) {
                throw new ProcessStoppedExisting();
            }
            if ($bookkeeping) {
                $written++;
            }
            return $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300);
        };
        try {
            $this->handler->step($job, $checkpoint, $this->gateFor($job->id, $token));
        } catch (ProcessStoppedExisting) {
            // Nothing after this point runs, which is the whole shape of it.
        }
        $row = $this->jobs->find($jobId);
        self::assertNotNull($row);
        return ['cursor' => $row->cursor, 'done' => $row->done, 'failed' => $row->failed];
    }

    /**
     * Let the worker run, and stop it existing INSIDE one product's picture.
     *
     * The closest thing to staging in a suite: every write before the kill
     * lands, the kill falls in the slow second half of a unit — after the row,
     * before the picture is attached — and nothing after it runs. The real
     * SIGKILL on a real second process is measured in
     * `docs/evidence/alpha72-reproduction/`; this is the part that belongs
     * here.
     *
     * @return array{cursor:array<string,mixed>, done:int, failed:int}
     */
    private function dieInsideThePicture(int $jobId, int $sourceImageId, bool $keepFailing = false): array
    {
        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the job has to be claimable for this to mean anything');
        $this->images->stopExistingFor = $sourceImageId;
        try {
            $this->handler->step(
                $job,
                function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token): bool {
                    return $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300);
                },
                $this->gateFor($job->id, $token)
            );
        } catch (ProcessStoppedExisting) {
            // Nothing after this point runs, which is the whole shape of it.
        }
        if (!$keepFailing) {
            $this->images->stopExistingFor = 0;
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

        // Three products through, and gone inside the fourth's picture.
        $after = $this->dieInsideThePicture($id, 2004);
        self::assertSame(DokanImportJob::PHASE_PRODUCTS, $after['cursor']['phase']);
        self::assertSame(1003, (int) $after['cursor']['after'], 'the third product is where it got to');
        self::assertSame(1004, (int) $after['cursor']['at'], 'and the fourth is the one it was attempting');
        self::assertSame(1, (int) $after['cursor']['tries'], 'with its own attempt written down');
        self::assertSame(4, $after['done'], 'one shop and three products, all recorded');
        self::assertSame(4, $this->mapped(), 'four rows: the fourth is the one in flight');
        self::assertSame(0, $this->duplicates());

        // ⛔ AND THE ONE IN FLIGHT IS NOT PASSED OFF AS FINISHED. This is the
        // owner's second finding: the row exists and its picture does not, and
        // `alpha.72` counted exactly this product `done` on the next pass.
        $report = $this->import->importCompletion(self::RUN);
        self::assertSame(4, $report['mapped']);
        self::assertSame(3, $report['complete']);
        self::assertSame(1, $report['without_image'], 'named, not counted');
        self::assertCount(1, $report['incomplete'], 'and listed, so it can be finished');
    }

    /**
     * WHERE INSIDE A PRODUCT the worker was, written before it got there.
     *
     * ⛔ This is the one the staging report could not answer. «۱۰ از ۱۸۶» and
     * «هفت تلاش» say how far and how often; neither says which step the host
     * cut the request off in, and nothing reached `debug.log` because nothing
     * fatal happened. The step's name is written BEFORE the step runs, so a
     * process that stops existing leaves it behind.
     */
    public function testTheStepTheWorkerWasInSurvivesTheWorkerDying(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $after = $this->dieInsideThePicture($id, 2001);

        self::assertSame(
            'image_metadata',
            (string) ($after['cursor']['running'] ?? ''),
            'the step in flight is on the row, and it is the expensive one'
        );
        self::assertSame(1001, (int) ($after['cursor']['running_unit'] ?? 0), 'and which product it was on');
        self::assertGreaterThan(0, (int) ($after['cursor']['running_since'] ?? 0), 'and since when');

        // And the steps that DID finish are measured — a different fact, kept
        // apart on purpose: the step a worker dies in can be fast in the
        // ninety units before it.
        $timings = (array) ($after['cursor']['timings'] ?? []);
        self::assertNotSame([], $timings, 'the finished steps must be measured too');
        $names = array_map(
            static fn (string $record): string => explode(':', $record, 3)[1],
            array_values(array_filter($timings, 'is_string'))
        );
        self::assertContains('judge', $names);
        self::assertContains('row_and_manifest', $names);
        self::assertContains(
            'image_file_copy',
            $names,
            'the copy finished — starting the metadata is what ended it — so it has a number'
        );
        self::assertNotContains(
            'image_metadata',
            $names,
            'and the metadata never finished, so it has none: it is the one still in flight'
        );
    }

    /**
     * THE NEXT ATTEMPT DOES NOT ERASE WHERE THE LAST ONE STOPPED.
     *
     * ⛔ The defect the whole-path review found, and the one that defeats
     * `alpha.75` for exactly the case it was built for. `DokanImportJob::step()`
     * writes the cursor once BEFORE reading a page — the «we tried to read»
     * record — and that cursor was built from five keys and nothing else. So
     * the first write of every batch DELETED `running`, `timings` and `worst`
     * from the row.
     *
     * On a host that cuts off every attempt, that means the step name the last
     * attempt died in was destroyed at the START of the next one: the only
     * fact `alpha.75` exists to leave behind, wiped by the attempt that came
     * looking for it. And it would have been invisible — the owner would have
     * pressed «اجرا» and seen a row with nothing on it.
     */
    public function testTheNextAttemptKeepsWhereTheLastOneStopped(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $died = $this->dieInsideThePicture($id, 2001);
        self::assertSame('image_metadata', (string) ($died['cursor']['running'] ?? ''));
        $timings = (array) ($died['cursor']['timings'] ?? []);
        self::assertNotSame([], $timings);
        $worst = (string) ($died['cursor']['worst'] ?? '');
        self::assertNotSame('', $worst);

        // THE NEXT WORKER DIES IN THE WINDOW THE DEFECT LIVED IN.
        //
        // ⛔ Which is a narrow window, and saying so is the point of this
        // comment. The batch's first write is the page-read attempt; the next
        // write is the first `entering()`, and that one rebuilds the whole
        // step fragment from the `StepTiming` this batch read at the top. So
        // the wipe was only observable to a worker cut off BETWEEN those two
        // — which is to say, inside `productsAfter()`: the page read, one of
        // the six candidates for what is killing these requests. Narrow is
        // not harmless when it is the window the suspect lives in.
        $row = $this->jobs->find($id);
        self::assertNotNull($row);
        $this->jobs->release($id, $row->lockToken);
        $this->dieAfterTheFirstWrite($id);

        $row = $this->jobs->find($id);
        self::assertNotNull($row);
        self::assertSame(
            $worst,
            (string) ($row->cursor['worst'] ?? ''),
            'the worst step ever seen survived the next attempt starting'
        );
        self::assertSame(
            $timings,
            (array) ($row->cursor['timings'] ?? []),
            'and so did every measured step'
        );
        // And the step the dead worker left open is still named.
        //
        // ⛔ Under `running` here, not `stalled`, and the difference is a
        // measurement rather than a detail: at this point the new worker has
        // not entered a step of its own, so the only thing the row can
        // honestly say is «somebody opened this step and never closed it» —
        // which is what `running` plus an expired lease means, and what the
        // page prints. It moves to `stalled` the moment this worker enters
        // its own step, which is covered where that happens
        // (`CursorProgressTest::testAPreviousAttemptsOpenStepSurvivesIntoTheNextOne`).
        self::assertSame(
            'image_metadata',
            (string) ($row->cursor['running'] ?? ''),
            'where the previous attempt stopped is still on the row'
        );
        self::assertSame(1001, (int) ($row->cursor['running_unit'] ?? 0));
        self::assertSame(
            (int) ($died['cursor']['running_since'] ?? 0),
            (int) ($row->cursor['running_since'] ?? 0),
            'and when, unchanged — this worker did not restart that clock'
        );
    }

    /**
     * A refused step-start write is NOT ignored: the worker stops.
     *
     * «ناتوانی در نوشتن checkpoint یا شروع مرحله نادیده گرفته نشود.» The
     * step-start write is an ordinary checkpoint, so a refusal means the lease
     * is gone — and carrying on would be a worker writing products into an
     * import that is no longer its own, which is the whole of `alpha.73`.
     */
    public function testAStepStartThatCannotBeWrittenStopsTheWorker(): void
    {
        $id = $this->queue();
        $this->runner->run(1);

        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job);

        // Every write lands until a step marker asks, and that one is refused
        // — the shape of a lease taken by somebody else mid-batch.
        $refusedOne = false;
        $checkpoint = function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token, &$refusedOne): bool {
            if (!$refusedOne && ((string) ($cursor['running'] ?? '')) !== '') {
                $refusedOne = true;
                return false;
            }
            return $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300);
        };
        $batch = $this->handler->step($job, $checkpoint, $this->gateFor($job->id, $token));

        self::assertTrue($refusedOne, 'the premise: a step-start write really was refused');
        self::assertSame(0, $this->mapped(), 'and not one product was written after it');
        self::assertSame(0, $batch->done);
        self::assertSame(0, $batch->failed);
    }

    /**
     * A clean unit leaves a number against every step it took.
     *
     * Nothing else in the plugin measured anything before this — `microtime`
     * and `hrtime` appeared nowhere in `src/` — so «which step is slow» had no
     * answer at all.
     */
    public function testAFinishedUnitLeavesANumberAgainstEveryStep(): void
    {
        $id = $this->queue();

        // The vendors phase first: one page read and no per-unit steps, so
        // this is where `page_read` is asserted. ⛔ Not after the products
        // phase — that fixture has twelve products at five steps each, which
        // is sixty records through a window of forty-eight, and `page_read`
        // leaves the window exactly as the bound says it must. An assertion
        // there would be an assertion against the documented bound, and the
        // first version of this test was.
        $this->runner->run(1);
        $afterVendors = $this->jobs->find($id);
        self::assertNotNull($afterVendors);
        self::assertContains(
            'page_read',
            $this->stepNames($afterVendors->cursor),
            'reading a page from Dokan is a step, and it is measured'
        );

        $this->runner->run(1);
        $row = $this->jobs->find($id);
        self::assertNotNull($row);
        $timings = array_values(array_filter((array) ($row->cursor['timings'] ?? []), 'is_string'));
        self::assertNotSame([], $timings);
        foreach ($timings as $record) {
            [$unit, , $ms] = explode(':', $record, 3);
            self::assertMatchesRegularExpression('~^\d+$~', $ms, 'a duration is a number of milliseconds');
            self::assertMatchesRegularExpression('~^\d+$~', $unit, 'and a unit is an id');
        }
        $names = $this->stepNames($row->cursor);
        foreach (['judge', 'row_and_manifest', 'image_file_copy', 'image_metadata', 'attach'] as $step) {
            self::assertContains($step, $names, $step . ' must be measured; got ' . implode(',', $timings));
        }
        self::assertSame(
            '',
            (string) ($row->cursor['running'] ?? ''),
            'and nothing is left in flight when the batch returns normally'
        );
        // ⛔ And the worst step ever seen is NOT taken from the window, so a
        // slow page read three hundred products ago is still the answer.
        self::assertMatchesRegularExpression(
            '~^[a-z0-9_]{1,32}:\d+$~',
            (string) ($row->cursor['worst'] ?? ''),
            'the slowest step is carried outside the window'
        );
    }

    /**
     * A BATCH WHOSE BUDGET IS ALREADY GONE CLAIMS NOTHING.
     *
     * ⛔ THIS TEST USED TO ASSERT THE OPPOSITE, and that is why the defect
     * shipped. It built a budget of fifteen seconds started fifteen seconds
     * ago and demanded that the first product still be imported, with the
     * comment «the first unit always runs» — because `alpha.75` asked the
     * first-unit exception BEFORE asking whether any time was left. The owner
     * named it: «اگر خواندن صفحه بودجه را مصرف کرده باشد، چرا اولین محصول
     * باید بدون توجه به زمان باقی‌مانده شروع شود».
     *
     * Spent is spent. Nothing is claimed, the position does not move, the
     * attempt count of the unit that was next is untouched, and the next
     * request starts with a whole budget.
     */
    public function testABudgetAlreadySpentClaimsNothingAndBlamesNoProduct(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $before = $this->jobs->find($id);
        self::assertNotNull($before);

        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job);
        // Fifteen seconds of budget, started fifteen seconds ago. Deterministic
        // with no sleeping — a timing test that waits is a timing test that is
        // flaky.
        $spent = JobBudget::of(15.0, (hrtime(true) / 1e6) - 15_000.0);
        self::assertTrue($spent->exhausted(), 'the premise: there really is no time');
        $batch = $this->handler->step(
            $job,
            fn (array $cursor, int $done = 0, int $failed = 0, int $total = 0): bool
                => $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300),
            $this->gateFor($job->id, $token),
            $spent
        );

        self::assertTrue($batch->more, 'the phase is NOT finished, and the job stays claimable');
        self::assertSame(
            'time_budget_spent_before_first_unit',
            (string) ($batch->cursor['stopped'] ?? ''),
            'and the reason names what spent it: not a product'
        );
        self::assertSame(0, $this->mapped(), 'no product was imported');
        self::assertSame(
            (int) ($before->cursor['after'] ?? 0),
            (int) $batch->cursor['after'],
            'the position did not move'
        );
        self::assertSame(0, (int) $batch->cursor['at'], 'and nothing is mid-attempt');
        self::assertSame(0, (int) $batch->cursor['tries'], 'the next unit was not charged an attempt');
        self::assertSame(0, $batch->done, 'and nothing was counted done');
        self::assertSame(0, $batch->failed, 'the end of a budget is not a product failing');

        // THE FIGURES TRAVEL, so the page can say what happened in a later
        // request — «بودجهٔ مصرف‌شده و باقی‌مانده».
        self::assertSame(15_000, (int) $batch->cursor['budget_ms']);
        self::assertGreaterThanOrEqual(15_000, (int) $batch->cursor['budget_spent_ms']);

        // And it carries on from there with a budget of its own.
        $this->jobs->release($job->id, $token);
        $this->runner->run(1);
        self::assertGreaterThanOrEqual(1, $this->mapped(), 'the next request did the work');
        $row = $this->jobs->find($id);
        self::assertNotNull($row);
        self::assertArrayNotHasKey(
            'stopped',
            $row->cursor,
            'and last request\'s reason does not sit on the page for ever'
        );
    }

    /**
     * A SMALL BUDGET STOPS A FIFTY-PRODUCT PAGE EARLY AND KEEPS WHAT LANDED.
     *
     * «یک دستهٔ چندمحصولی با بودجهٔ کوچک، پیشرفت را ذخیره و عادی بازگردد.»
     *
     * ⛔ The assertions are invariants rather than a product count, on
     * purpose. How many products fit in fifty milliseconds depends on the
     * machine, and a test that demanded «exactly one» would be asserting the
     * speed of this container. What must hold on every machine: it stopped
     * before the page ended, it said why, nothing is mid-attempt, nothing is
     * duplicated, and the rest is still there to do.
     */
    public function testASmallBudgetStopsEarlyAndKeepsTheProgressItMade(): void
    {
        $id = $this->queue();
        $this->runner->run(1);

        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job);
        $small = JobBudget::of(0.05);
        $batch = $this->handler->step(
            $job,
            fn (array $cursor, int $done = 0, int $failed = 0, int $total = 0): bool
                => $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300),
            $this->gateFor($job->id, $token),
            $small
        );

        self::assertTrue($batch->more, 'there is more to do and the job stays claimable');
        self::assertContains(
            (string) ($batch->cursor['stopped'] ?? ''),
            [
                'time_budget_spent',
                'time_budget_spent_before_first_unit',
                'time_budget_one_unit_over_budget',
            ],
            'it ended itself for time, and said which kind'
        );
        self::assertSame(0, (int) $batch->cursor['at'], 'nothing is left mid-attempt');
        self::assertSame(0, $this->duplicates(), 'and nothing was imported twice');
        self::assertLessThan(50, $this->mapped(), 'it really did stop before the page ended');

        // Whatever landed is still there, and the rest follows.
        $landed = $this->mapped();
        $this->jobs->release($job->id, $token);
        $this->runner->run(1);
        self::assertGreaterThanOrEqual($landed, $this->mapped(), 'nothing that landed was lost');
        self::assertSame(0, $this->duplicates());
    }

    /**
     * «That we are trying THIS one» is written before any of its writes.
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
        $after = $this->dieInsideThePicture($id, 2001);
        self::assertSame(1001, (int) $after['cursor']['at'], 'the unit being attempted is named');
        self::assertSame(1, (int) $after['cursor']['tries'], 'the attempt is on the row');
        self::assertSame(0, (int) $after['cursor']['after'], 'and the position has not moved');
        self::assertSame(1, $after['done'], 'nor the figure: still just the shop');
        self::assertSame([], $this->products->images($this->onlyProductRow()), 'its picture never arrived');
    }

    /**
     * The step names in a cursor's timing window.
     *
     * @param array<string,mixed> $cursor
     * @return list<string>
     */
    private function stepNames(array $cursor): array
    {
        $names = [];
        foreach ((array) ($cursor['timings'] ?? []) as $record) {
            if (is_string($record)) {
                $names[] = explode(':', $record, 3)[1] ?? '';
            }
        }
        return $names;
    }

    /** The one marketplace row there is, for a test that made exactly one. */
    private function onlyProductRow(): int
    {
        return (int) $this->wpdb->get_var(
            'SELECT MIN(id) FROM ' . $this->wpdb->prefix . 'tmc_products WHERE wc_product_id > 0'
        );
    }

    /**
     * The count belongs to the unit, so a unit that lands does not erase the
     * count of a DIFFERENT one.
     *
     * ⛔ This is the owner's third finding. `alpha.72` raised one `tries` at
     * the top of the batch and `recordOne` set it to nought after every
     * product, so the second unit of a batch could be attempted any number of
     * times with nothing written down. Measured on its own bytes: the same
     * interruption read `tries:1` inside the first product and `tries:0`
     * inside the second.
     */
    public function testTheAttemptCountIsKeptPerUnitAndNotPerBatch(): void
    {
        $id = $this->queue();
        $this->runner->run(1);

        // Page, then 1001 claimed and recorded, then 1002 claimed — and gone
        // before 1002 could record itself.
        //
        // ⛔ Four, not five: a unit costs one checkpoint fewer this round.
        // `alpha.73` asked the lease with a checkpoint of its own
        // (`stillOurs()` wrote the same cursor back so the token could be
        // matched); the hold asks the job row directly and writes no cursor,
        // so the budget here counts claims and landings and nothing else.
        $this->dieAfter($id, 4);
        $row = $this->jobs->find($id);
        self::assertSame(1001, (int) $row->cursor['after'], 'the first product landed');
        self::assertSame(1002, (int) $row->cursor['at'], 'and the second is the one being attempted');
        self::assertSame(1, (int) $row->cursor['tries'], 'ITS first attempt — not nought');
        $this->jobs->release($id, $row->lockToken);

        // Again, and again: the same unit, so the same count carries on.
        for ($expected = 2; $expected <= 3; $expected++) {
            $this->dieAfter($id, 2);
            $row = $this->jobs->find($id);
            self::assertSame(1002, (int) $row->cursor['at']);
            self::assertSame($expected, (int) $row->cursor['tries'], 'the count follows the unit');
            $this->jobs->release($id, $row->lockToken);
        }
        self::assertSame(2, $this->mapped(), 'the two rows that were reached, and no more');
        self::assertSame(0, $this->duplicates(), 'however many times it was attempted');
    }

    /**
     * A unit that lands says this position is not the one killing workers —
     * for ITSELF.
     */
    public function testAUnitThatLandsResetsItsOwnAttemptCount(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $this->dieAfter($id, 2);
        $row = $this->jobs->find($id);
        self::assertSame(1, (int) $row->cursor['tries']);
        self::assertSame(1001, (int) $row->cursor['at']);
        $this->jobs->release($id, $row->lockToken);

        // Page, then 1001 claimed and recorded: it landed this time, and the
        // process stops at 1002's claim — which writes nothing.
        $this->dieAfter($id, 3);
        $row = $this->jobs->find($id);
        self::assertSame(0, (int) $row->cursor['tries'], 'it landed, so nought');
        self::assertSame(0, (int) $row->cursor['at'], 'and no unit is being attempted');
        self::assertSame(1001, (int) $row->cursor['after']);
    }

    /**
     * THE OWNER'S TEST, in his words: the first product succeeds and the
     * second is interrupted for ever.
     *
     * «شمارنده باید برای همان شناسه قبل از عملیات ثبت شود و بعد از حد
     * تعیین‌شده دقیقاً همان قلم با دلیل قابل‌پیگیری رد شود.» On `alpha.72`
     * this loop never ended: the first product's landing reset the only
     * counter there was, so the second product's attempts were never written
     * and `GIVE_UP_AFTER` could not fire for it. The only reason the import
     * did not stall for ever was the OTHER defect — the half-made product
     * being counted done — which skipped past it.
     */
    public function testASecondProductThatKeepsKillingWorkersIsRefusedByName(): void
    {
        $id = $this->queue();
        $this->runner->run(1);

        // The second product's picture stops the process, every single time.
        for ($attempt = 1; $attempt <= DokanImportJob::GIVE_UP_AFTER; $attempt++) {
            $after = $this->dieInsideThePicture($id, 2002, true);
            self::assertSame(1002, (int) $after['cursor']['at'], 'the same unit, every time');
            self::assertSame($attempt, (int) $after['cursor']['tries'], 'and ITS count rises');
            self::assertSame(1001, (int) $after['cursor']['after'], 'the first product stays done');
            $this->jobs->release($id, $this->jobs->find($id)->lockToken);
        }
        $this->images->stopExistingFor = 2002;

        $arrived = $this->mapped();
        $results = $this->runner->run(1);
        self::assertSame('more', $results[0]['outcome']);
        $row = $this->jobs->find($id);
        self::assertSame(1, $row->failed, 'exactly that item is refused');
        $refused = implode('|', (array) ($row->cursor['refused'] ?? []));
        self::assertStringContainsString('1002:image_missing_after_3_attempts', $refused, 'named, with the count');
        self::assertStringNotContainsString('1001:', $refused, 'and the product that worked is not blamed');
        self::assertGreaterThan($arrived, $this->mapped(), 'the rest of the page carried on');

        // The import still finishes, which is the whole reason for stepping
        // over rather than retrying for ever.
        for ($i = 0; $i < 20 && !$this->jobs->find($id)->status->isTerminal(); $i++) {
            if ($this->runner->run(1) === []) {
                break;
            }
        }
        $row = $this->jobs->find($id);
        self::assertSame(JobStatus::Done, $row->status);
        self::assertSame(self::PRODUCTS, $this->mapped(), 'every row is there');
        self::assertSame(0, $this->duplicates());
        // And the one that could not be finished is REPORTED, not averaged
        // away by a figure that says a hundred per cent.
        $report = $this->import->importCompletion(self::RUN);
        self::assertSame(1, $report['without_image']);
        self::assertSame(self::PRODUCTS - 1, $report['complete']);
    }

    /**
     * A refusal written in the middle of a batch survives the batch not
     * finishing.
     *
     * ⛔ `alpha.72` built the cursor once at the top and its per-unit writes
     * carried no names, so a conflict recorded half way through was only ever
     * written by the batch's FINAL checkpoint — and a batch that never gets to
     * return has no final checkpoint. «ردهای ثبت‌شده هم پس از قطع دسته گم
     * نشوند.»
     */
    public function testARefusalRecordedMidBatchIsNotLostWhenTheBatchIsCut(): void
    {
        // The second product collides with a SKU this shop already uses.
        $this->products->create(
            self::SELLER,
            new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                title: 'کالای از قبل',
                categoryKey: '',
                priceMinor: 1000,
                sku: 'DK-2',
                stock: 1
            ),
            \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Draft
        );
        $id = $this->queue();
        $this->runner->run(1);
        // Page, 1001 claimed and recorded, 1002 claimed and REFUSED, 1003
        // claimed — and gone before the batch can return anything.
        $after = $this->dieAfter($id, 6);
        $refused = implode('|', (array) ($after['cursor']['refused'] ?? []));
        self::assertStringContainsString(
            '1002:sku_already_used_in_this_shop',
            $refused,
            'the name is on the row, written when it happened'
        );
        self::assertSame(1, $this->jobs->find($id)->failed, 'and counted when it happened');
    }

    /**
     * Interrupted, resumed, finished: every unit accounted for, once.
     */
    public function testAnInterruptedImportResumesAndCompletesWithoutDuplicating(): void
    {
        $id = $this->queue();
        $this->runner->run(1);
        $this->dieInsideThePicture($id, 2004);
        $row = $this->jobs->find($id);
        $this->jobs->release($id, $row->lockToken);
        self::assertSame(4, $this->mapped(), 'three finished, one half made');
        self::assertSame(1, $this->import->importCompletion(self::RUN)['without_image']);

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
        // ⛔ AND THE HALF-MADE ONE WAS FINISHED, not skipped. On `alpha.72`
        // this product kept its empty gallery and the import said 100%.
        $report = $this->import->importCompletion(self::RUN);
        self::assertSame(0, $report['without_image'], 'the picture it was missing arrived');
        self::assertSame(self::PRODUCTS, $report['complete']);
    }

    /**
     * And the completion report answers the four questions separately.
     *
     * «نتیجهٔ کامل ورود باید تعداد واقعی، محصولات نیمه‌تمام، تصاویر و
     * نگاشت‌های تکراری را جدا گزارش کند؛ عدد ۱۰۰٪ به‌تنهایی کافی نیست.»
     */
    public function testTheCompletionReportSeparatesTheNumbers(): void
    {
        $id = $this->queue();
        for ($i = 0; $i < 20 && !$this->jobs->find($id)->status->isTerminal(); $i++) {
            if ($this->runner->run(1) === []) {
                break;
            }
        }
        $report = $this->import->importCompletion(self::RUN);
        self::assertSame(self::PRODUCTS, $report['mapped']);
        self::assertSame(self::PRODUCTS, $report['complete'], 'every picture carried over');
        self::assertSame(0, $report['without_image']);
        self::assertSame(0, $report['duplicate_mappings']);
        self::assertSame([], $report['incomplete']);
        self::assertTrue($report['finished'], 'and the scan saw everything');
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

    /** Every unit of a page claims itself and records itself. */
    public function testEveryProductOnAPageClaimsAndRecordsItsOwnPosition(): void
    {
        $progress = new RecordingProgress();
        $this->import->importProductPage(self::RUN, 0, 5, $progress);
        self::assertSame([1001, 1002, 1003, 1004, 1005], $progress->claims, 'claimed once each');
        self::assertSame([1001, 1002, 1003, 1004, 1005], $progress->landings, 'recorded once each');
        // Two holds per product, and that is the shape of the fix rather than
        // a number that happens to be right: the row WITH its manifest entry,
        // and then the picture. `alpha.73` had one hold here and a check — and
        // the check was not a hold.
        self::assertSame(10, $progress->asked, 'two holds per product: its row, and its picture');
    }

    /**
     * And a page whose lease is taken mid-way stops at once rather than writing
     * over the work of whoever holds the job now.
     *
     * ⛔ The budget is counted in writes, and this round added one: a unit now
     * costs a claim, a hold for its row, a hold for its picture and a landing.
     * Two means the lease goes at the picture's hold — the row is in, the copy
     * is made, and the attach never happens.
     */
    public function testAPageWhoseLeaseIsTakenStopsInsteadOfCarryingOn(): void
    {
        $progress = new RecordingProgress(2);     // gone at the picture's hold
        $page = $this->import->importProductPage(self::RUN, 0, 10, $progress);
        self::assertTrue($page['lease_lost']);
        self::assertFalse($page['more'], 'a page the lease was taken from is not a page that ended');
        self::assertSame([], $progress->landings, 'nothing was recorded');
        self::assertSame(1, $this->mapped(), 'the row it was inside is there');
        // ⛔ AND ITS COPY WAS HANDED BACK. The picture is not attached to an
        // import somebody else now owns, and no orphan attachment is left in
        // the shop's media library either.
        self::assertSame([], $this->products->images($this->onlyProductRow()), 'nothing attached');
        self::assertCount(1, $this->images->discarded, 'and the copy it had made was discarded');
    }

    /**
     * And a lease taken BEFORE the row is written leaves no row at all.
     *
     * ⛔ This is the second window the owner named, between `claim(unit)` and
     * `create()`/`rememberRun()`. On `alpha.73`'s bytes it produced no
     * duplicate — but the thing holding it was the UNIQUE key on
     * `wc_product_id`, not the lease, which is a different promise: the day
     * that index goes the window reopens. Here the lease holds it, so the
     * insert is not attempted at all.
     */
    public function testALeaseTakenBeforeTheRowIsWrittenLeavesNoRow(): void
    {
        $progress = new RecordingProgress(1);     // gone at the row's hold
        $page = $this->import->importProductPage(self::RUN, 0, 10, $progress);
        self::assertTrue($page['lease_lost']);
        self::assertSame([], $progress->landings, 'nothing was recorded');
        self::assertSame(0, $this->mapped(), 'and no row was made either');
        self::assertSame(0, $this->duplicates());
        self::assertSame([], $this->images->discarded, 'no copy had been made yet to hand back');
    }

    /**
     * A unit the progress refuses to claim is not worked on at all, and the
     * page says which it was.
     */
    public function testAUnitTheProgressGivesUpOnIsNamedAndNotWorkedOn(): void
    {
        $progress = new RecordingProgress(giveUpOn: 1002, attempts: 3);
        $page = $this->import->importProductPage(self::RUN, 0, 4, $progress);
        self::assertNotContains(1002, $progress->claims, 'never claimed');
        self::assertContains('1002:stalled_after_3_attempts', $page['notes']);
        self::assertSame(1, $page['failed'] + $progress->failed, 'counted once, as failed');
        self::assertSame(3, $this->mapped(), 'the other three arrived');
    }
}

/**
 * A progress sink that remembers what it was asked, and can be told to lose
 * the lease at a given write or to give up on a named unit.
 *
 * ⛔ It models the VERB, not a boolean: a double that answered `true`/`false`
 * could not express «this unit has been attempted to the limit», which is the
 * state the step-over exists for — the same mistake, one object over, as the
 * `isset()` stand-in for `hash_equals` in `alpha.69`.
 */
final class RecordingProgress implements ImportProgressInterface
{
    /** @var list<int> */
    public array $claims = [];

    /** @var list<int> */
    public array $landings = [];

    /** @var list<string> */
    public array $notes = [];

    public int $done = 0;

    public int $failed = 0;

    public int $writes = 0;

    /** How many times the slow half asked whether the lease still held. */
    public int $asked = 0;

    /** Every step this progress object was told about, as `<unit>:<step>`. */
    public array $steps = [];

    public function __construct(
        private readonly int $loseTheLeaseAfter = PHP_INT_MAX,
        private readonly int $giveUpOn = 0,
        private readonly int $attempts = 0,
        private readonly int $stopAfter = 0
    ) {
    }

    public function claim(int $unitId): UnitClaim
    {
        if ($this->giveUpOn !== 0 && $unitId === $this->giveUpOn) {
            return UnitClaim::GiveUp;
        }
        if ($this->writes >= $this->loseTheLeaseAfter) {
            return UnitClaim::LeaseLost;
        }
        $this->writes++;
        $this->claims[] = $unitId;
        return UnitClaim::Ok;
    }

    public function attempts(int $unitId): int
    {
        return $this->attempts;
    }

    /**
     * The step marker, refusable but NOT counted as one of the writes the
     * `loseTheLeaseAfter` knob counts.
     *
     * ⛔ Deliberate, and the reason is written here so the next round does not
     * read it as an oversight. The real `CursorProgress::entering()` does
     * checkpoint, so it can be refused — and this models that. What it must
     * not do is change the ARITHMETIC of the knob: that number means «after
     * this many of the unit's own operations, pretend the lease went», and
     * folding diagnostic markers into it would make every existing budget in
     * this file a number about something else.
     */
    public function entering(string $step, int $unitId): bool
    {
        if ($this->writes >= $this->loseTheLeaseAfter) {
            return false;
        }
        $this->steps[] = $unitId . ':' . $step;
        return true;
    }

    public function left(): float
    {
        return 0.0;
    }

    public function keepGoing(): bool
    {
        return $this->stopAfter === 0 || count($this->landings) < $this->stopAfter;
    }

    public function stopReason(): string
    {
        return $this->keepGoing() ? '' : 'time_budget_spent';
    }

    /**
     * The gate, modelled as the REPOSITORY models it — the closure runs only
     * while the lease holds, and does not run at all when it has gone.
     *
     * ⛔ A double that ran the closure regardless would make an unguarded tree
     * look guarded, which is `alpha.70`'s rule: a stand-in that cannot express
     * the state the guard exists to catch reports a complete guard where there
     * is a hole.
     */
    public function writing(\Closure $write): LeaseHold
    {
        if ($this->writes >= $this->loseTheLeaseAfter) {
            return LeaseHold::LeaseLost;
        }
        $this->writes++;
        $this->asked++;
        return $write() === true ? LeaseHold::Held : LeaseHold::NotWritten;
    }

    public function landed(int $unitId, int $done = 0, int $failed = 0, array $notes = []): bool
    {
        if ($this->writes >= $this->loseTheLeaseAfter) {
            return false;
        }
        $this->writes++;
        $this->landings[] = $unitId;
        $this->done += $done;
        $this->failed += $failed;
        foreach ($notes as $note) {
            $this->notes[] = (string) $note;
        }
        return true;
    }
}

/** Not an error: the shape of a process that is no longer there. */
final class ProcessStoppedExisting extends \RuntimeException
{
}

/**
 * The vendor's media library, stood in for — and able to stop existing in the
 * middle of a copy, which is where staging's worker went.
 *
 * ⛔ The real copy is a file copy plus a full
 * `wp_generate_attachment_metadata()`, measured at about half a second per
 * product and seconds for a large one; fifty of them in a batch took 24.4
 * seconds. That is the slow half of a unit and the only part of it that
 * happens AFTER the product row, which is why «the row exists» and «the import
 * finished this product» are different facts and why a fixture without
 * pictures cannot tell them apart.
 */
final class FakeProductImages implements ProductImageLibraryInterface
{
    /** @var list<int> source ids copied, in order */
    public array $copied = [];

    /** @var list<int> copies handed back */
    public array $discarded = [];

    private int $next = 5000;

    /** A source whose copy stops the process, every time it is attempted. */
    public int $stopExistingFor = 0;

    /** A source whose copy simply fails, which is a different answer. */
    public int $failFor = 0;

    public function store(UploadedFile $file, int $vendorUserId, string $title): array
    {
        return ['ok' => false, 'code' => 'not_in_this_test', 'media_id' => 0];
    }

    public function ownedBy(int $mediaId, int $vendorUserId): bool
    {
        return $mediaId > 5000;
    }

    /**
     * ⛔ The two named halves are reported HERE, in the same order and at the
     * same points the real one reports them, and the process stops existing
     * AFTER the second has been named.
     *
     * Without that, this double could not express the state the step record
     * exists to catch — a worker cut off inside the metadata — and a test
     * about it would pass on a build that recorded nothing (`alpha.70`).
     */
    public function duplicateForVendor(int $mediaId, int $vendorUserId, ?\Closure $step = null): int
    {
        if ($step !== null) {
            $step('image_file_copy');
        }
        if ($mediaId === $this->failFor) {
            return 0;
        }
        if ($step !== null) {
            $step('image_metadata');
        }
        if ($mediaId === $this->stopExistingFor) {
            throw new ProcessStoppedExisting();
        }
        $this->copied[] = $mediaId;
        return ++$this->next;
    }

    public function thumbnailUrl(int $mediaId): string
    {
        return '';
    }

    public function discard(int $mediaId, int $vendorUserId): bool
    {
        $this->discarded[] = $mediaId;
        return true;
    }
}
