<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
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
use Tecteb\Marketplace\Modules\Migration\Application\ImportProgressInterface;
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
     * checkpoints in before the process stops existing.
     *
     * ⛔ Counted in WRITES, not units, and the two are no longer the same: a
     * unit is claimed before its work and recorded after it, so a page costs
     * one write for the read plus two per unit. That is the point — the claim
     * is what makes a worker killed mid-unit leave nothing behind — and a
     * helper that pretended otherwise would hide it.
     *
     * @return array{cursor:array<string,mixed>, done:int, failed:int}
     */
    private function dieAfter(int $jobId, int $writes): array
    {
        $token = 'tok-' . bin2hex(random_bytes(4));
        $job = $this->jobs->claim([DokanImportJob::TYPE], $token, 300);
        self::assertNotNull($job, 'the job has to be claimable for this to mean anything');
        $written = 0;
        $checkpoint = function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token, &$written, $writes): bool {
            if ($written >= $writes) {
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
            $this->handler->step($job, function (array $cursor, int $done = 0, int $failed = 0, int $total = 0) use ($job, $token): bool {
                return $this->jobs->checkpoint($job->id, $token, $cursor, $done, $failed, $total, 300);
            });
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

        // Page, then 1001 claimed, asked and recorded, then 1002 claimed —
        // and gone before 1002 could record itself.
        $this->dieAfter($id, 5);
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

        // Page, then 1001 claimed, asked and recorded: it landed this time.
        $this->dieAfter($id, 4);
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
        self::assertSame(5, $progress->asked, 'and the lease asked again inside each picture');
    }

    /**
     * And a page whose lease is taken mid-way stops at once rather than writing
     * over the work of whoever holds the job now.
     *
     * ⛔ The number that changed with this round is the row count: the lease
     * goes at the THIRD write, which is the second product's claim, and that
     * claim comes before its row. `alpha.72` had already created it by then.
     */
    public function testAPageWhoseLeaseIsTakenStopsInsteadOfCarryingOn(): void
    {
        $progress = new RecordingProgress(1);     // gone inside the first picture
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

    public function __construct(
        private readonly int $loseTheLeaseAfter = PHP_INT_MAX,
        private readonly int $giveUpOn = 0,
        private readonly int $attempts = 0
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

    public function stillOurs(): bool
    {
        if ($this->writes >= $this->loseTheLeaseAfter) {
            return false;
        }
        $this->writes++;
        $this->asked++;
        return true;
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

    public function duplicateForVendor(int $mediaId, int $vendorUserId): int
    {
        if ($mediaId === $this->stopExistingFor) {
            throw new ProcessStoppedExisting();
        }
        if ($mediaId === $this->failFor) {
            return 0;
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
