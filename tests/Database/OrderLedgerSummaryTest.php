<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderLedgerReport;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Core\Support\SystemClock;

/**
 * WHAT THIS PROVES: the figures of the WHOLE filtered set — which is what the
 * owner's «گزارش هر محصول» and the «تمام نتایج فیلترشده» export both need.
 *
 * ⛔ **WHY A SECOND SET OF TOTALS AT ALL.** `alpha.99` printed three totals
 * and they were the totals of the PAGE, said so in words. That is honest and
 * useless for the question a manager actually asks: filter to one product,
 * and the answer must be that product's sales — not the sales of the
 * twenty-five rows that happened to fit on the screen.
 *
 * ⛔ **AND WHY IT REFUSES RATHER THAN COMBINING.** A bare `SUM()` across two
 * exponents of one currency is wrong by a factor of ten thousand
 * (`alpha.67`), and across two currencies there is no sum at all
 * (`alpha.68`). So the query groups by unit and the reader is handed the
 * groups; with one group — every real catalogue — the figures are exact and
 * cannot disagree with the page's own totals, because both are summing
 * identical rows at one scale.
 */
final class OrderLedgerSummaryTest extends DatabaseTestCase
{
    private const VENDOR_A = 102;
    private const VENDOR_B = 40;

    private OrderItemRepositoryInterface $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        Bootstrap::container()->get(MigrationRunner::class)->run();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS),
            $db->prefix() . 'tmc_ledger_entries',
        ] as $table) {
            $this->wpdb->pdo()->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $vendors = Bootstrap::container()->get(VendorRepositoryInterface::class);
        $vendors->upsertProfile(self::VENDOR_A, 'فروشگاه ۱۰۲', true, false);
        $vendors->upsertProfile(self::VENDOR_B, 'فروشگاه ۴۰', true, false);
        $this->items = Bootstrap::container()->get(OrderItemRepositoryInterface::class);
    }

    /**
     * The three figures of the whole set, with the cancelled line in the
     * historic total and out of the valid one — the §3 and §4 rule.
     */
    public function testTheWholeSetsThreeTotalsAreExactAndSeparate(): void
    {
        $this->line(self::VENDOR_A, 12299, 206010000, ['commission' => 0, 'share' => 206010000]);
        $this->line(self::VENDOR_A, 12300, 206010000, ['commission' => 10300500, 'share' => 195709500]);
        $this->line(self::VENDOR_A, 12301, 100000, ['status' => OrderItemStatus::Cancelled]);

        $answer = $this->report()->summaryForManager(OrderLedgerFilter::fromInput([]));
        self::assertTrue($answer['allowed']);
        self::assertTrue($answer['readable']);
        self::assertSame('', $answer['refused'], 'one currency, one scale');
        self::assertCount(1, $answer['groups']);
        $group = $answer['groups'][0];

        self::assertSame(3, $group['lines']);
        self::assertSame(1, $group['cancelled']);
        // ⛔ The owner's two orders, to the toman. 206,010,000 + 195,709,500 is
        // the pair staging measured, and the cancelled 90,000 share sits in
        // «historic» only.
        self::assertSame(206010000 + 195709500 + 90000, $group['historic']);
        self::assertSame(206010000 + 195709500, $group['valid']);
        self::assertSame(206010000 + 195709500, $group['pending']);
        self::assertSame('IRT', $group['currency']);
        self::assertSame(0, $group['exponent']);
    }

    /**
     * ⛔ THE PRODUCT REPORT IS THE SAME METHOD WITH A PRODUCT FILTER, and
     * this is the test that says so: no second query shape, no second
     * definition of «valid sales» (`alpha.87`).
     */
    public function testFilteringToOneProductGivesThatProductsOwnReport(): void
    {
        $this->line(self::VENDOR_A, 12310, 500000, ['product' => 9319]);
        $this->line(self::VENDOR_A, 12311, 700000, ['product' => 9319]);
        $this->line(self::VENDOR_A, 12312, 900000, ['product' => 9999]);

        $all = $this->report()->summaryForManager(OrderLedgerFilter::fromInput([]));
        $one = $this->report()->summaryForManager(OrderLedgerFilter::fromInput(['product' => '9319']));

        self::assertSame(3, $all['groups'][0]['lines']);
        self::assertSame(2, $one['groups'][0]['lines'], 'only that product');
        self::assertSame(500000 + 700000, $one['groups'][0]['base']);
        self::assertSame(450000 + 630000, $one['groups'][0]['valid'], 'nine tenths of each');
    }

    /**
     * ⛔ `SUM()` SKIPS `NULL`, SO THE UNRECORDED ARE COUNTED BY NAME. A line
     * whose rate could not be resolved holds `NULL` and contributes nothing;
     * a total printed without this count would read as «that is all of it»
     * (FIN-02).
     */
    public function testLinesWithNoShareAreCountedRatherThanSummedAsNought(): void
    {
        $this->line(self::VENDOR_A, 12320, 100000);
        $this->line(self::VENDOR_A, 12321, 100000, [
            'commission' => null, 'share' => null, 'rate_bp' => null, 'ledger_event' => '',
        ]);

        $group = $this->report()->summaryForManager(OrderLedgerFilter::fromInput([]))['groups'][0];
        self::assertSame(2, $group['lines']);
        self::assertSame(1, $group['unrecorded'], 'named, not folded into the total');
        self::assertSame(1, $group['unrecorded_event']);
        self::assertSame(90000, $group['valid'], 'only the resolved line');
    }

    /** Two currencies have no sum, and the reader says so with no figure. */
    public function testTwoCurrenciesAreReportedAndNeverAdded(): void
    {
        $this->line(self::VENDOR_A, 12330, 100000);
        $this->line(self::VENDOR_A, 12331, 100000, ['currency' => 'USD', 'exponent' => 2]);

        $answer = $this->report()->summaryForManager(OrderLedgerFilter::fromInput([]));
        self::assertTrue($answer['readable']);
        self::assertSame('mixed_currencies', $answer['refused']);
        self::assertCount(2, $answer['groups'], 'each group on its own');
    }

    /**
     * Two exponents of ONE currency are legitimate (`alpha.67`) and still not
     * added here: the rescale is deliberately not done in SQL.
     */
    public function testTwoScalesOfOneCurrencyAreReportedAndNeverAdded(): void
    {
        $this->line(self::VENDOR_A, 12340, 100000);
        $this->line(self::VENDOR_A, 12341, 1000000000, ['exponent' => 4]);

        $answer = $this->report()->summaryForManager(OrderLedgerFilter::fromInput([]));
        self::assertSame('mixed_scales', $answer['refused']);
        self::assertCount(2, $answer['groups']);
    }

    /** No rows is nought groups and `readable` — not a refusal. */
    public function testAnEmptySetIsReadableAndEmpty(): void
    {
        $answer = $this->report()->summaryForManager(OrderLedgerFilter::fromInput(['order' => '999999']));
        self::assertTrue($answer['readable']);
        self::assertSame([], $answer['groups']);
        self::assertSame('', $answer['refused']);
    }

    /**
     * ⛔ A READ THAT FAILED IS NOT A SET OF NOUGHT ROWS. `getResults()`
     * answers `[]` for both, so the count is asked separately and `null` from
     * it is what says «could not read» — the `alpha.8` rule, and the one
     * direction a financial summary must never be wrong in.
     */
    public function testAFailedReadIsUnreadableRatherThanEmpty(): void
    {
        $this->line(self::VENDOR_A, 12350, 100000);

        $db = new FailingDatabase(new WpDatabase($this->wpdb));
        $db->failReadWhen(['COUNT(*)', 'tmc_order_items']);
        $blind = new DbOrderItemRepository($db, new SystemClock());
        $report = new OrderLedgerReport(
            $blind,
            Bootstrap::container()->get(LedgerRepositoryInterface::class),
            Bootstrap::container()->get(StaffAccess::class),
            new FakeCapabilityChecker(1, [Capabilities::VIEW_VENDOR_FINANCE])
        );
        $answer = $report->summaryForManager(OrderLedgerFilter::fromInput([]));
        self::assertTrue($answer['allowed']);
        self::assertFalse($answer['readable'], 'could not read is not nought');
        self::assertSame('unreadable', $answer['refused']);
        self::assertSame([], $answer['groups']);
    }

    /** The marketplace-wide summary needs the finance capability. */
    public function testTheSummaryNeedsTheFinanceCapability(): void
    {
        $this->line(self::VENDOR_A, 12360, 100000);
        $refused = $this->reportAs([Capabilities::VIEW_VENDOR_SHOPS])
            ->summaryForManager(OrderLedgerFilter::fromInput([]));
        self::assertFalse($refused['allowed']);
        self::assertSame([], $refused['groups']);

        $allowed = $this->reportAs([Capabilities::VIEW_VENDOR_FINANCE])
            ->summaryForManager(OrderLedgerFilter::fromInput([]));
        self::assertTrue($allowed['allowed']);
    }

    /** A vendor's own summary is their shop's, whatever the filter carried. */
    public function testAVendorsSummaryIsRewrittenToTheirOwnShop(): void
    {
        $this->line(self::VENDOR_A, 12370, 100000);
        $this->line(self::VENDOR_B, 12371, 900000);

        $answer = $this->report()->summaryForVendor(
            self::VENDOR_A,
            self::VENDOR_A,
            OrderLedgerFilter::fromInput(['vendor' => (string) self::VENDOR_B])
        );
        self::assertTrue($answer['allowed']);
        self::assertSame(1, $answer['groups'][0]['lines'], 'their own shop, not the one they asked for');
        self::assertSame(90000, $answer['groups'][0]['valid']);
    }

    /**
     * The sweep hands over every row of the set in batches and says it
     * reached the end — the CSV's «تمام نتایج فیلترشده».
     */
    public function testTheSweepHandsOverEveryRowInBatchesAndFinishes(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->line(self::VENDOR_A, 12400 + $i, 100000 + $i);
        }
        $seen = [];
        $batches = 0;
        $answer = $this->report()->sweepForManager(
            OrderLedgerFilter::fromInput([]),
            function (array $batch) use (&$seen, &$batches): void {
                $batches++;
                foreach ($batch as $row) {
                    $seen[] = (int) $row['wc_order_id'];
                }
            },
            100
        );
        self::assertTrue($answer['allowed']);
        self::assertTrue($answer['finished']);
        self::assertTrue($answer['readable']);
        self::assertSame(7, $answer['rows']);
        self::assertSame(7, count($seen));
        self::assertSame(7, count(array_unique($seen)), 'no row twice');
        self::assertSame(1, $batches, 'seven rows fit one batch');
    }

    /**
     * ⛔ THE CAP IS ANNOUNCED, NEVER SILENT. A spreadsheet cut at the cap and
     * not saying so is a spreadsheet somebody sums (`alpha.85`).
     */
    public function testTheSweepStopsAtTheCapAndSaysItDidNotFinish(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->line(self::VENDOR_A, 12500 + $i, 100000);
        }
        $rows = 0;
        $answer = $this->report()->sweepForManager(
            OrderLedgerFilter::fromInput([]),
            static function (array $batch) use (&$rows): void {
                $rows += count($batch);
            },
            4
        );
        self::assertSame(4, $answer['rows'], 'exactly the cap');
        self::assertSame(4, $rows, 'and not one row more was handed over');
        self::assertFalse($answer['finished'], 'and it says so');
    }

    /** The sweep refuses without the finance capability, before reading. */
    public function testTheSweepNeedsTheFinanceCapability(): void
    {
        $this->line(self::VENDOR_A, 12600, 100000);
        $touched = 0;
        $answer = $this->reportAs([Capabilities::VIEW_VENDOR_SHOPS])->sweepForManager(
            OrderLedgerFilter::fromInput([]),
            static function (array $batch) use (&$touched): void {
                $touched += count($batch);
            },
            100
        );
        self::assertFalse($answer['allowed']);
        self::assertSame(0, $touched, 'not one row was handed over');
    }

    /**
     * ⛔ AND THE SWEEP AND THE PAGE DESCRIBE A ROW IDENTICALLY. The CSV and
     * the table both print the derived states, and two derivations would be
     * two answers (`alpha.78`).
     */
    public function testTheSweepDescribesARowExactlyAsThePageDoes(): void
    {
        $this->line(self::VENDOR_A, 12700, 100000, ['status' => OrderItemStatus::Cancelled]);
        $page = $this->report()->forManager(OrderLedgerFilter::fromInput([]))['rows'][0];
        $swept = null;
        $this->report()->sweepForManager(
            OrderLedgerFilter::fromInput([]),
            static function (array $batch) use (&$swept): void {
                $swept = $batch[0] ?? null;
            },
            100
        );
        self::assertIsArray($swept);
        self::assertSame($page, $swept, 'one description, two readers');
    }

    private function report(): OrderLedgerReport
    {
        return new OrderLedgerReport(
            $this->items,
            Bootstrap::container()->get(LedgerRepositoryInterface::class),
            Bootstrap::container()->get(StaffAccess::class),
            Bootstrap::container()->get(\Tecteb\Marketplace\Contracts\CapabilityCheckerInterface::class)
        );
    }

    /** @param list<string> $capabilities */
    private function reportAs(array $capabilities): OrderLedgerReport
    {
        return new OrderLedgerReport(
            $this->items,
            Bootstrap::container()->get(LedgerRepositoryInterface::class),
            Bootstrap::container()->get(StaffAccess::class),
            new FakeCapabilityChecker(1, $capabilities)
        );
    }

    /** @param array<string,mixed> $overrides */
    private function line(int $vendorUserId, int $orderId, int $baseMinor, array $overrides = []): int
    {
        $id = $this->items->record(new VendorOrderItem(
            0,
            $orderId,
            $orderId,
            5000 + $orderId,
            $vendorUserId,
            (string) ($overrides['title'] ?? 'کالای آزمایشی'),
            (string) ($overrides['sku'] ?? 'SKU-' . $orderId),
            1,
            null,
            $baseMinor,
            0,
            array_key_exists('commission', $overrides) ? $overrides['commission'] : intdiv($baseMinor, 10),
            array_key_exists('share', $overrides) ? $overrides['share'] : $baseMinor - intdiv($baseMinor, 10),
            array_key_exists('rate_bp', $overrides) ? $overrides['rate_bp'] : 1000,
            'general',
            array_key_exists('ledger_event', $overrides)
                ? (string) $overrides['ledger_event']
                : 'order:' . $orderId . ':item:' . $orderId,
            $overrides['status'] ?? OrderItemStatus::Placed,
            '',
            '',
            null,
            null,
            // ⛔ `wcProductId`, which is what the `product` filter reads —
            // NOT `productId` above it, which is our own row's id. Two
            // columns one letter apart, and the filter means the WooCommerce
            // one.
            (int) ($overrides['product'] ?? 5000 + $orderId),
            '',
            (string) ($overrides['currency'] ?? 'IRT'),
            (int) ($overrides['exponent'] ?? 0)
        ));
        self::assertGreaterThan(0, $id, 'the fixture row must be written');
        return $id;
    }
}
