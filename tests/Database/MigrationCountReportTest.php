<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCountReport;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;
use Tecteb\Marketplace\Modules\Operations\Presentation\Admin\QueuePage;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * §9: the two pairs of figures the owner read, decomposed — and NOT corrected.
 *
 * «۱۰۰٪ (۱۹۰ از ۱۸۶)» and «۲ فروشنده و ۱۷۹ محصول» beside a report saying ۱۸۴
 * are four numbers that are each true about a different question. The owner's
 * instruction was explicit: «علت نساز و شمارنده‌ها را دستی اصلاح نکن». So the
 * tests here assert that each figure is REPORTED with its source and that the
 * job row the numbers came from is left exactly as it was.
 */
final class MigrationCountReportTest extends DatabaseTestCase
{
    private const SHOP = 481;
    private const RUN = 'run-counted';
    private const MANAGER = 9;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
        Bootstrap::container()->get(OptionStoreInterface::class)->delete(ImportFromDokan::RUNS_OPTION);
    }

    private function createMapped(int $wcId, string $sku, string $runId): int
    {
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        $id = $products->create(
            self::SHOP,
            new ProductDetails(title: 'کالا ' . $sku, sku: $sku, priceMinor: 100000, stock: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            $wcId,
            $runId
        );
        self::assertGreaterThan(0, $id);
        return $id;
    }

    /**
     * ۱۸۴ against ۱۷۹: the catalogue-wide count and one run's manifest.
     *
     * The fixture WRITES the state rather than performing work to reach it
     * (`alpha.72`): two products carry the run's stamp and the run's manifest
     * claims one of them — which is what the SKIP branch of
     * `importProductPage()` produces, because a product an earlier attempt
     * already mapped is counted as done and is NOT remembered as this run's
     * creation.
     */
    public function testTheCatalogueCountAndTheRunManifestAreReportedSeparately(): void
    {
        $claimed = $this->createMapped(9101, 'DK-C-1', self::RUN);
        $orphan = $this->createMapped(9102, 'DK-C-2', self::RUN);
        Bootstrap::container()->get(OptionStoreInterface::class)->set(
            ImportFromDokan::RUNS_OPTION,
            [self::RUN => ['vendors' => [self::SHOP], 'products' => [$claimed], 'at' => '2026-01-01 00:00:00']]
        );

        $report = Bootstrap::container()->get(MigrationCountReport::class)->report();

        self::assertSame(2, $report['catalogue']['mapped'], 'the catalogue-wide figure counts both');
        self::assertSame('observedFromImportRuns', $report['catalogue']['source']);
        self::assertSame(1, $report['manifest_total'], "the run's own ledger claims one");
        self::assertSame(1, $report['mapped_in_no_manifest']['count']);
        self::assertSame(
            [$orphan],
            $report['mapped_in_no_manifest']['ids'],
            'and the difference is NAMED, so «five are missing» cannot be assumed'
        );
    }

    /** The run row says whether the run wrote its own stamp or only rows. */
    public function testARunWithNoManifestIsNamedAsRowsOnly(): void
    {
        $this->createMapped(9103, 'DK-C-3', 'run-interrupted');

        $report = Bootstrap::container()->get(MigrationCountReport::class)->report();
        $runs = array_column($report['runs'], 'source', 'run_id');

        self::assertSame('rows_only', $runs['run-interrupted'] ?? '', 'an interrupted run is named, not hidden');
    }

    /**
     * ۱۹۰ از ۱۸۶: the declared ceiling was short, and the row is not edited.
     *
     * This is the exact shape of the owner's job #1 — three phases counted,
     * two declared.
     */
    public function testAJobThatPassedItsDeclaredTotalIsReportedAndLeftAlone(): void
    {
        /** @var JobRepositoryInterface $jobs */
        $jobs = Bootstrap::container()->get(JobRepositoryInterface::class);
        $id = (int) $jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => 'run-one', 'total' => 186],
            DokanImportJob::TYPE,
            self::MANAGER
        )['id'];
        $token = 'tok-counted';
        self::assertNotNull($jobs->claim([DokanImportJob::TYPE], $token, 300));
        $jobs->checkpoint($id, $token, ['phase' => 'orders', 'after' => 77], 190, 0, 186, 300);
        $jobs->finish($id, $token, JobStatus::Done, '');
        $before = $jobs->find($id);
        self::assertNotNull($before);

        $report = Bootstrap::container()->get(MigrationCountReport::class)->report();
        $row = null;
        foreach ($report['jobs']['rows'] as $candidate) {
            if ($candidate['id'] === $id) {
                $row = $candidate;
            }
        }

        self::assertTrue($report['jobs']['read'], 'the job rows were read');
        self::assertNotNull($row, 'the import job is in the report');
        self::assertSame(186, $row['declared_total']);
        self::assertSame(190, $row['done']);
        self::assertSame(4, $row['over_declared'], 'the ceiling was four short of the work');

        $after = $jobs->find($id);
        self::assertNotNull($after);
        self::assertSame($before->done, $after->done, 'reporting a figure does not change it');
        self::assertSame($before->total, $after->total);
        self::assertSame($before->status->value, $after->status->value);
    }

    /**
     * And the QUEUE page stops printing a clamped hundred per cent.
     *
     * ⛔ `Job::progress()` clamps, so the row used to read «۱۰۰٪ (۱۹۰ از
     * ۱۸۶)» — a figure that cannot be read as anything but an error, with
     * nothing saying which of the two numbers was wrong.
     */
    public function testTheQueuePageSaysWhenAJobPassedItsDeclaredTotal(): void
    {
        /** @var JobRepositoryInterface $jobs */
        $jobs = Bootstrap::container()->get(JobRepositoryInterface::class);
        $id = (int) $jobs->enqueue(
            DokanImportJob::TYPE,
            ['run_id' => 'run-one', 'total' => 186],
            DokanImportJob::TYPE,
            self::MANAGER
        )['id'];
        $token = 'tok-queue';
        self::assertNotNull($jobs->claim([DokanImportJob::TYPE], $token, 300));
        $jobs->checkpoint($id, $token, ['phase' => 'orders', 'after' => 77], 190, 0, 186, 300);
        $jobs->finish($id, $token, JobStatus::Done, '');

        $_GET['page'] = QueuePage::SLUG;
        $html = $this->capture(static function (): void {
            (new QueuePage(Bootstrap::container()))->render();
        });

        self::assertStringContainsString('بیشتر از سقف اعلام‌شدهٔ', $html);
        self::assertStringNotContainsString(
            '۱۰۰٪ (۱۹۰ از ۱۸۶)',
            $html,
            'a clamped hundred per cent over a short denominator is not a progress figure'
        );
    }

    /** And the page says it, in words a manager can act on. */
    public function testThePageExplainsWhereEachFigureComesFrom(): void
    {
        $this->createMapped(9104, 'DK-C-4', self::RUN);

        $_GET['page'] = MigrationPage::SLUG;
        $html = $this->capture(static function (): void {
            (new MigrationPage(Bootstrap::container()))->render();
        });

        self::assertStringContainsString('این عددها از کجا می‌آیند؟', $html);
        self::assertStringContainsString('نگاشت‌شده در کلِ کاتالوگ', $html);
        self::assertStringContainsString('جمعِ دفترِ اجراها', $html);
    }

    /**
     * From this round the enqueue declares all three phases.
     *
     * ⛔ Asserted on the SUMMARY the page reads, not on a hand-written number:
     * a test that typed «186» would be a statement about one fixture and
     * would go stale the moment the plan changed (`alpha.34`).
     */
    public function testTheEnqueuedTotalCountsEveryPhaseTheHandlerWalks(): void
    {
        $page = file_get_contents(dirname(__DIR__, 2) . '/src/Modules/Migration/Presentation/Admin/MigrationPage.php');
        self::assertIsString($page);
        $code = '';
        foreach (token_get_all($page) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        foreach (["\$summary['vendors']", "\$summary['products']", "\$summary['orders']"] as $part) {
            self::assertStringContainsString(
                $part,
                $code,
                'the declared total must include ' . $part . ', because the handler counts it'
            );
        }
    }
}
