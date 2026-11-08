<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SiteTime;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderLedgerReport;
use Tecteb\Marketplace\Modules\Order\Application\SyncOrderLifecycle;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0039WcOrderStatusOnRows as WcStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Core\Support\SystemClock;

/**
 * WHAT THIS PROVES: the per-line order report filters, pages and counts in
 * SQL — and shows no customer.
 *
 * It reads a real table through the real repository, because every claim here
 * is about a query: a filter that narrows, a count that is separate from the
 * page, a sort that cannot come from a request, and a column set that holds
 * nothing confidential. None of those can be measured against an array.
 */
final class OrderLedgerReportTest extends DatabaseTestCase
{
    private const VENDOR_A = 701;
    private const VENDOR_B = 702;
    private const TEHRAN = 'Asia/Tehran';

    private OrderItemRepositoryInterface $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        Bootstrap::container()->get(MigrationRunner::class)->run();
        // ⛔ The rows go, between every test in this class.
        //
        // `DatabaseTestCase` truncates the audit table and `postmeta`; the
        // plugin's own tables survive, which is right for a suite whose other
        // classes rebuild the schema themselves. Here it meant each test saw
        // the previous one's rows and five of them compared a filtered list
        // against a table that had grown — a fixture that spends something has
        // to give it back (`alpha.33`), and this one spends the whole table.
        $db = new WpDatabase($this->wpdb);
        foreach ([
            M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS),
            $db->prefix() . 'tmc_ledger_entries',
        ] as $table) {
            $this->wpdb->pdo()->exec('TRUNCATE TABLE `' . $table . '`');
        }
        // A shop that exists, so `StaffAccess` can answer «yes» for its owner.
        // Without it the vendor-scoped read is refused — a true answer about a
        // shop nobody runs, and nothing about the scoping being measured.
        $vendors = Bootstrap::container()->get(
            \Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class
        );
        $vendors->upsertProfile(self::VENDOR_A, 'فروشگاه الف', true, false);
        $vendors->upsertProfile(self::VENDOR_B, 'فروشگاه ب', true, false);
        $this->items = new DbOrderItemRepository(
            new WpDatabase($this->wpdb),
            new SystemClock(),
            new SiteTime(new \DateTimeZone(self::TEHRAN))
        );
    }

    /**
     * ⛔ THE STRONGEST FORM OF «no customer data is exposed»: the table has
     * nowhere to put any.
     *
     * Measured against `information_schema` rather than parsed out of the
     * migration sources — an attempt at the latter was first vacuous and then
     * wrong, because a migration file that touches this table also declares a
     * vendor's bank details for another one. A column set is a fact about a
     * table, so it is read off the table.
     */
    public function testTheOrderItemsTableHasNoColumnThatCouldHoldACustomer(): void
    {
        $table = M0006CatalogAndOrders::table(new WpDatabase($this->wpdb), M0006CatalogAndOrders::ORDER_ITEMS);
        $columns = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );
        self::assertGreaterThan(20, count($columns), 'the column scan must find the real table');
        foreach ($columns as $column) {
            $name = strtolower((string) $column);
            foreach ([
                'customer', 'billing', 'buyer', 'email', 'mail', 'phone', 'mobile', 'tel',
                'address', 'postcode', 'national', 'iban', 'sheba', 'card', 'ip_address',
            ] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $name,
                    'the order-items table has a column named `' . $name . '`, which could hold customer data'
                );
            }
        }
    }

    /** Migration 39's own column and both of the indexes the report needs. */
    public function testTheStatusMirrorAndItsIndexesExist(): void
    {
        $db = new WpDatabase($this->wpdb);
        $table = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
        $nullable = $db->getVar(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, WcStatus::COLUMN]
        );
        self::assertSame('YES', strtoupper((string) $nullable), '«nobody has told us» has to be expressible');
        foreach ([WcStatus::STATUS_INDEX, WcStatus::CREATED_INDEX] as $index) {
            self::assertSame(
                1,
                (int) $db->getVar(
                    'SELECT COUNT(*) FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                        AND INDEX_NAME = %s AND SEQ_IN_INDEX = 1',
                    [$table, $index]
                ),
                $index . ' is missing, so a filter the report issues is a table scan'
            );
        }
        self::assertTrue((new WcStatus())->verify($db), 'the step must agree that it is done');
    }

    /** The page, the count and the offset are all in the statement. */
    public function testThePageAndTheCountAreBothServerSide(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->line(self::VENDOR_A, 9000 + $i, 100000 * $i);
        }
        $first = $this->items->detailed(OrderLedgerFilter::fromInput(['per_page' => 25]));
        self::assertSame(7, $first['total']);
        self::assertCount(7, $first['rows']);

        // A page smaller than the set: the count stays the whole set, which is
        // what a pager is built from.
        $paged = $this->items->detailed(OrderLedgerFilter::fromInput(['per_page' => 25, 'paged' => '2']));
        self::assertSame(7, $paged['total'], 'the count is of the filter, not of the page');
        self::assertSame([], $paged['rows'], 'and page two of seven rows at twenty-five a page is empty');
    }

    /** Every filter narrows, and each one narrows to the right rows. */
    public function testEachFilterNarrowsToItsOwnRows(): void
    {
        $a = $this->line(self::VENDOR_A, 9101, 500000, ['title' => 'دفیبریلاتور ریلایو', 'sku' => 'AED-5']);
        $b = $this->line(self::VENDOR_B, 9102, 900000, ['title' => 'ماسک سه‌لایه', 'sku' => 'MASK-1']);
        $this->items->recordWcOrderStatus(9101, 'processing');
        $this->items->recordWcOrderStatus(9102, 'cancelled');

        $only = function (array $input) use (&$ids): array {
            $answer = $this->items->detailed(OrderLedgerFilter::fromInput($input));
            return array_map(static fn (array $r): int => (int) $r['id'], $answer['rows']);
        };

        self::assertSame([$a], $only(['vendor' => (string) self::VENDOR_A]));
        self::assertSame([$a], $only(['order' => '9101']));
        self::assertSame([$a], $only(['title' => 'ریلایو']));
        self::assertSame([$a], $only(['sku' => 'AED']));
        self::assertSame([$a], $only(['payment' => 'paid']));
        self::assertSame([$b], $only(['payment' => 'void']));
        self::assertSame([$b], $only(['min_amount' => '900000']));
        self::assertSame([$a], $only(['max_amount' => '500000']));
        self::assertSame([$b], $only(['vendor' => (string) self::VENDOR_B]));
        // And an order id nobody has narrows to nothing rather than everything.
        self::assertSame([], $only(['order' => '999999']));
    }

    /**
     * A `%` or a `_` typed into a search box is a character, not a wildcard.
     *
     * `wpdb::esc_like()`'s own rule, applied here because the repository holds
     * a `DatabaseInterface` rather than a `wpdb` — and without it a search for
     * «%» would return the whole table.
     */
    public function testASearchTermIsEscapedForTheLike(): void
    {
        $plain = $this->line(self::VENDOR_A, 9201, 100000, ['title' => 'ماسک معمولی']);
        $withPercent = $this->line(self::VENDOR_A, 9202, 100000, ['title' => 'تخفیف ۵۰% ویژه']);

        $answer = $this->items->detailed(OrderLedgerFilter::fromInput(['title' => '%']));
        self::assertSame(
            [$withPercent],
            array_map(static fn (array $r): int => (int) $r['id'], $answer['rows']),
            'a per-cent sign must match the row that contains one, not every row'
        );
        unset($plain);
    }

    /** The commission shape is derived, and the filter and the row agree. */
    public function testTheCommissionKindIsDerivedAndFilterable(): void
    {
        $percent = $this->line(self::VENDOR_A, 9301, 500000, ['commission' => 50000, 'rate_bp' => 1000]);
        $fixed = $this->line(self::VENDOR_A, 9302, 500000, ['commission' => 20000, 'rate_bp' => 0]);
        $none = $this->line(self::VENDOR_A, 9303, 500000, ['commission' => 0, 'rate_bp' => 0]);
        $unrecorded = $this->line(self::VENDOR_A, 9304, 500000, ['commission' => null, 'rate_bp' => null]);

        foreach ([
            'percent' => $percent,
            'fixed' => $fixed,
            'none' => $none,
            'unrecorded' => $unrecorded,
        ] as $kind => $id) {
            $answer = $this->items->detailed(OrderLedgerFilter::fromInput(['commission' => $kind]));
            self::assertSame(
                [$id],
                array_map(static fn (array $r): int => (int) $r['id'], $answer['rows']),
                $kind . ' did not narrow to its own row'
            );
        }

        // …and the row the filter matched prints the kind it was matched on.
        $report = $this->report()->forManager(OrderLedgerFilter::fromInput([]));
        self::assertTrue($report['allowed']);
        $kinds = [];
        foreach ($report['rows'] as $row) {
            $kinds[(int) $row['id']] = (string) $row['commission_kind'];
        }
        self::assertSame('percent', $kinds[$percent]);
        self::assertSame('fixed', $kinds[$fixed]);
        self::assertSame('none', $kinds[$none]);
        self::assertSame('unrecorded', $kinds[$unrecorded]);
    }

    /**
     * The date filter means the days the manager's calendar shows.
     *
     * The row is at 21:30 UTC, which in Tehran is the next day — the same
     * measurement as the audit page's, on the report's own query.
     */
    public function testTheDateFilterIsReadInTheSitesZone(): void
    {
        $id = $this->line(self::VENDOR_A, 9401, 100000, ['created_at' => '2026-11-08 21:30:00']);

        $from = $this->items->detailed(OrderLedgerFilter::fromInput(['from' => '2026-11-09']));
        self::assertSame([$id], array_map(static fn (array $r): int => (int) $r['id'], $from['rows']));

        $to = $this->items->detailed(OrderLedgerFilter::fromInput(['to' => '2026-11-08']));
        self::assertSame([], $to['rows'], 'the row is on the 9th locally, so «to the 8th» must not find it');

        // A build with no zone to read answers as every release before this
        // one did, rather than inheriting a guess.
        $utc = new DbOrderItemRepository(new WpDatabase($this->wpdb), new SystemClock());
        self::assertSame([], $utc->detailed(OrderLedgerFilter::fromInput(['from' => '2026-11-09']))['rows']);
    }

    /** Every sort is a fixed expression, and all of them end in `id`. */
    public function testTheSortsOrderTheRowsAndAreStableOnTies(): void
    {
        // Three rows written in the same second, so only the `id` tie-break
        // can make the order repeatable (`alpha.32`).
        $one = $this->line(self::VENDOR_A, 9501, 300000, ['created_at' => '2026-11-08 09:00:00']);
        $two = $this->line(self::VENDOR_A, 9502, 100000, ['created_at' => '2026-11-08 09:00:00']);
        $three = $this->line(self::VENDOR_A, 9503, 200000, ['created_at' => '2026-11-08 09:00:00']);

        $ids = fn (string $sort): array => array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->items->detailed(OrderLedgerFilter::fromInput(['sort' => $sort]))['rows']
        );
        self::assertSame([$three, $two, $one], $ids('recent'), 'newest first, id descending on a tie');
        self::assertSame([$one, $two, $three], $ids('oldest'));
        self::assertSame([$one, $three, $two], $ids('amount_high'));
        self::assertSame([$two, $three, $one], $ids('amount_low'));
        // Twice, to show the tie-break makes it repeatable rather than lucky.
        self::assertSame($ids('recent'), $ids('recent'));
        // An injection attempt is the default sort, not an error and not a
        // statement.
        self::assertSame($ids('recent'), $ids('base_minor; DROP TABLE x'));
    }

    /**
     * ⛔ A VENDOR READS THEIR OWN SHOP, whatever they asked for.
     *
     * The scope is overwritten rather than validated: a request carrying
     * another shop's id reads their own, because telling them the request was
     * refused would tell them the other shop exists.
     */
    public function testAVendorsScopeIsOverwrittenAndCannotBeWidened(): void
    {
        $mine = $this->line(self::VENDOR_A, 9601, 100000);
        $theirs = $this->line(self::VENDOR_B, 9602, 100000);

        $answer = $this->report()->forVendor(
            self::VENDOR_A,
            self::VENDOR_A,
            OrderLedgerFilter::fromInput(['vendor' => (string) self::VENDOR_B])
        );
        self::assertTrue($answer['allowed']);
        self::assertSame(
            [$mine],
            array_map(static fn (array $r): int => (int) $r['id'], $answer['rows']),
            'the filter said the other shop and the answer is this one'
        );
        self::assertSame(self::VENDOR_A, $answer['filter']->vendorUserId);
        unset($theirs);
    }

    /** Somebody with no access to a shop gets no rows and no error detail. */
    public function testSomebodyWithNoAccessToAShopReadsNothing(): void
    {
        $this->line(self::VENDOR_A, 9701, 100000);
        $answer = $this->report()->forVendor(999, self::VENDOR_A, OrderLedgerFilter::fromInput([]));
        self::assertFalse($answer['allowed']);
        self::assertSame([], $answer['rows']);
        self::assertNull($answer['total']);
    }

    /**
     * The financial state says whether a reversing document stands beside the
     * accrual — in one query for the page, not one per row.
     */
    public function testTheReversalStateIsReadForTheWholePageAtOnce(): void
    {
        $recorded = $this->line(self::VENDOR_A, 9801, 500000, ['ledger_event' => 'order:9801:item:9801']);
        $unrecorded = $this->line(self::VENDOR_A, 9802, 500000, ['commission' => null, 'ledger_event' => '']);
        $reversed = $this->line(self::VENDOR_A, 9803, 500000, ['ledger_event' => 'order:9803:item:9803']);
        $this->writeLedgerEvent(SyncOrderLifecycle::voidEventKey(9803, 9803), self::VENDOR_A);

        $before = $this->wpdb->num_queries;
        $answer = $this->report()->forManager(OrderLedgerFilter::fromInput([]));
        $queries = $this->wpdb->num_queries - $before;

        $states = [];
        foreach ($answer['rows'] as $row) {
            $states[(int) $row['id']] = (string) $row['financial_state'];
        }
        self::assertSame('recorded', $states[$recorded]);
        self::assertSame('unrecorded', $states[$unrecorded]);
        self::assertSame('reversed', $states[$reversed]);
        // The count, the page and ONE bulk reversal lookup. A query per row
        // would be the shape that makes a per-line report unusable at volume.
        self::assertLessThanOrEqual(
            4,
            $queries,
            'the page cost ' . $queries . ' queries for three rows, which is per-row work'
        );
    }

    /** A line with no mirrored status says «I cannot tell», not «unpaid». */
    public function testARowWithNoMirroredStatusIsUnknownRatherThanAGuess(): void
    {
        $id = $this->line(self::VENDOR_A, 9901, 100000);
        $answer = $this->report()->forManager(OrderLedgerFilter::fromInput([]));
        $row = null;
        foreach ($answer['rows'] as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $row = $candidate;
            }
        }
        self::assertNotNull($row);
        self::assertNull($row['wc_order_status']);
        self::assertTrue($row['wc_status_readable'], 'the column exists on this schema');
        self::assertSame('unknown', $row['wc_disposition']);

        // And «unknown» is filterable, because «which orders have we never
        // been told about» is a real question on a site mid-migration.
        $unknown = $this->items->detailed(OrderLedgerFilter::fromInput(['payment' => 'unknown']));
        self::assertContains($id, array_map(static fn (array $r): int => (int) $r['id'], $unknown['rows']));
    }

    /** The settlement state comes from the row, and a cancelled line is void. */
    public function testTheSettlementStateNamesWhyALineIsNotPayable(): void
    {
        $awaiting = $this->line(self::VENDOR_A, 9911, 100000);
        $cancelled = $this->line(self::VENDOR_A, 9912, 100000);
        self::assertTrue($this->items->updateStatus($cancelled, OrderItemStatus::Cancelled, '', '', null));
        $unrecorded = $this->line(self::VENDOR_A, 9913, 100000, ['commission' => null, 'share' => null]);

        $answer = $this->report()->forManager(OrderLedgerFilter::fromInput([]));
        $states = [];
        foreach ($answer['rows'] as $row) {
            $states[(int) $row['id']] = (string) $row['settlement_state'];
        }
        self::assertSame('awaiting_completion', $states[$awaiting]);
        self::assertSame('void', $states[$cancelled]);
        self::assertSame('unrecorded', $states[$unrecorded]);
    }

    private function report(): OrderLedgerReport
    {
        return new OrderLedgerReport(
            $this->items,
            Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class),
            Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess::class),
            Bootstrap::container()->get(\Tecteb\Marketplace\Contracts\CapabilityCheckerInterface::class)
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
            (string) ($overrides['ledger_event'] ?? 'order:' . $orderId . ':item:' . $orderId),
            OrderItemStatus::Placed,
            '',
            '',
            null,
            null,
            5000 + $orderId,
            '',
            'IRT',
            0
        ));
        self::assertGreaterThan(0, $id, 'the fixture row must be written');
        if (isset($overrides['created_at'])) {
            // Written directly, because `record()` stamps `now` on purpose and
            // a report about dates needs rows on known ones.
            self::assertNotNull((new WpDatabase($this->wpdb))->execute(
                'UPDATE `' . M0006CatalogAndOrders::table(
                    new WpDatabase($this->wpdb),
                    M0006CatalogAndOrders::ORDER_ITEMS
                ) . '` SET created_at = %s WHERE id = %d',
                [(string) $overrides['created_at'], $id]
            ));
        }
        return $id;
    }

    private function writeLedgerEvent(string $eventKey, int $vendorUserId): void
    {
        $db = new WpDatabase($this->wpdb);
        self::assertNotNull($db->execute(
            'INSERT INTO `' . $db->prefix() . 'tmc_ledger_entries`
              (event_key, vendor_user_id, account, amount_minor, currency, exponent, order_ref, item_ref, reason, created_at)
             VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s, %s)',
            [$eventKey, $vendorUserId, 'commission', 1, 'IRT', 0, '0', '0', 'fixture', '2026-11-08 09:00:00']
        ));
    }

    /**
     * WHAT THIS PROVES: the marketplace-wide report asks for the FINANCE
     * capability, and a manager without it reads nothing.
     *
     * ⛔ **Found by the falsification loop, not by reading.** A mutation that
     * removed this check entirely **survived every suite**: the guard had no
     * test. It matters because this report prints commission and vendor share
     * per line, and `alpha.86` separated «which shops exist»
     * (`tmc_view_vendor_shops`) from «what they are owed»
     * (`tmc_view_vendor_finance`) for exactly that reason — a manager who was
     * given the list capability by hand must not read the figures.
     *
     * The control is the other half: the SAME filter, the SAME rows, with the
     * capability granted. Without it, «reads nothing» would also be satisfied
     * by a report that always reads nothing.
     */
    public function testTheMarketplaceWideReportNeedsTheFinanceCapability(): void
    {
        $this->line(self::VENDOR_A, 7301, 1_000_000);

        $refused = $this->reportAs([Capabilities::VIEW_VENDOR_SHOPS])
            ->forManager(OrderLedgerFilter::fromInput([]));
        self::assertFalse($refused['allowed'], 'the list capability is not the finance one');
        self::assertSame([], $refused['rows']);
        self::assertNull($refused['total']);

        $allowed = $this->reportAs([Capabilities::VIEW_VENDOR_FINANCE])
            ->forManager(OrderLedgerFilter::fromInput([]));
        self::assertTrue($allowed['allowed']);
        self::assertNotSame([], $allowed['rows'], 'the control must actually read rows');
    }

    /**
     * WHAT THIS PROVES: a report built with NO capability checker at all
     * refuses rather than defaults to yes.
     *
     * The parameter is nullable for the reason every other service in this
     * module has one — an existing construction site must keep working
     * (`alpha.32`) — and the conservative answer must be the one a forgetful
     * caller gets.
     */
    public function testAReportWithNoCapabilityCheckerRefuses(): void
    {
        $this->line(self::VENDOR_A, 7302, 1_000_000);

        $answer = (new OrderLedgerReport(
            $this->items,
            Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class),
            Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess::class)
        ))->forManager(OrderLedgerFilter::fromInput([]));

        self::assertFalse($answer['allowed']);
        self::assertSame([], $answer['rows']);
    }

    /** @param list<string> $capabilities */
    private function reportAs(array $capabilities): OrderLedgerReport
    {
        return new OrderLedgerReport(
            $this->items,
            Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class),
            Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess::class),
            new FakeCapabilityChecker(1, $capabilities)
        );
    }

    /**
     * WHAT THIS PROVES: a count that could not be taken is `null`, never
     * nought — and the rows are still read.
     *
     * ⛔ **Found by the falsification loop, not by reading.** The docblock on
     * `detailed()` cites `alpha.8` for exactly this and the guard had NO
     * test: a mutation replacing `$counted === null ? null : (int) $counted`
     * with `?? 0` survived every suite. The direction is the whole point — a
     * manager reading «۰ ردیف» off a broken `COUNT(*)` would be told the shop
     * had sold nothing, which is the one answer a financial report must never
     * invent.
     *
     * The failure is INJECTED by name on the read, not simulated: every other
     * statement on the same connection keeps working, so the page half is
     * measured against the rows that are really in the table.
     */
    public function testACountThatCouldNotBeTakenIsUnknownRatherThanNought(): void
    {
        $this->line(self::VENDOR_A, 7401, 1_000_000);
        $this->line(self::VENDOR_A, 7402, 2_000_000);

        $db = new FailingDatabase(new WpDatabase($this->wpdb));
        $db->failReadWhen(['COUNT(*)', 'tmc_order_items']);
        $repository = new DbOrderItemRepository($db, new SystemClock(), new SiteTime(new \DateTimeZone(self::TEHRAN)));

        $answer = $repository->detailed(OrderLedgerFilter::fromInput([]));

        self::assertNull($answer['total'], 'a failed count is «نامعلوم», not nought');
        self::assertNotSame([], $answer['rows'], 'the page itself is still read');
        self::assertNotSame([], $db->refused, 'the count must really have been refused');
    }

    /**
     * The control: with nothing refused, the SAME call answers a number.
     *
     * Without it, «null on failure» would also be satisfied by a repository
     * that always answered null (`alpha.80`).
     */
    public function testACountThatCanBeTakenIsANumber(): void
    {
        $this->line(self::VENDOR_A, 7403, 1_000_000);

        $answer = $this->items->detailed(OrderLedgerFilter::fromInput([]));

        self::assertSame(1, $answer['total']);
    }

    /**
     * WHAT THIS PROVES: a shop with no lines reads nought, and that is a
     * different answer from «I could not count».
     *
     * The pair is the measurement: nought and null must both be reachable,
     * or neither means anything.
     */
    public function testAShopWithNoLinesIsNoughtAndNotUnknown(): void
    {
        $answer = $this->items->detailed(OrderLedgerFilter::fromInput(['vendor' => (string) self::VENDOR_B]));

        self::assertSame(0, $answer['total']);
        self::assertSame([], $answer['rows']);
    }

    /**
     * WHAT THIS PROVES: a catalogue read that failed is its own answer, and
     * the step says WHICH read it was.
     *
     * ⛔ **Found by the falsification loop, not by reading.** A mutation
     * collapsing `columnState()`'s `null` into `false` survived every suite.
     * Both versions throw, so «it fails either way» was true and useless —
     * the difference is what happens before the throw. Reading the failure as
     * «the column is absent» sends `ALTER TABLE … ADD COLUMN` against a
     * column that is already there, and the message a manager then reads on
     * the health page says the step «failed adding» a column that exists.
     * Which is the one sentence that makes somebody add it by hand.
     *
     * So the measurement is the MESSAGE, because the message is the artefact:
     * it has to name the read, and the column has to be untouched afterwards.
     */
    public function testAFailedColumnReadIsNamedRatherThanReadAsAbsent(): void
    {
        $db = new FailingDatabase(new WpDatabase($this->wpdb));
        $db->failReadWhen(['information_schema.COLUMNS', 'tmc_order_items']);

        $thrown = null;
        try {
            (new WcStatus())->up($db);
        } catch (MigrationException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'a read it could not make must stop the step');
        self::assertStringContainsString('could not read the columns', $thrown->getMessage());
        self::assertStringNotContainsString('failed adding', $thrown->getMessage());
        self::assertNotSame([], $db->refused, 'the read must really have been refused');

        // And the column is exactly as it was: nothing was altered on the way
        // to the refusal.
        $db->stopFailing();
        self::assertTrue(
            (new WcStatus())->verify(new WpDatabase($this->wpdb)),
            'the table must be untouched and still verify'
        );
    }

    /**
     * The same question about the INDEX read, which has its own three answers
     * and its own `ALTER`.
     *
     * Separate because the index half runs AFTER the column half and a single
     * fixture cannot reach both: the first refusal stops the step.
     */
    public function testAFailedIndexReadIsNamedRatherThanReadAsAbsent(): void
    {
        $db = new FailingDatabase(new WpDatabase($this->wpdb));
        $db->failReadWhen(['information_schema.STATISTICS', 'tmc_order_items']);

        $thrown = null;
        try {
            (new WcStatus())->up($db);
        } catch (MigrationException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertStringContainsString('could not read the indexes', $thrown->getMessage());
        self::assertStringNotContainsString('failed adding index', $thrown->getMessage());

        $db->stopFailing();
        self::assertTrue((new WcStatus())->verify(new WpDatabase($this->wpdb)));
    }

    /**
     * The control: with nothing refused, `up()` on a table that ALREADY has
     * the column and both indexes is a no-op and throws nothing.
     *
     * This is the resumed run, and without it «it throws on a failed read»
     * would also be satisfied by a step that throws always.
     */
    public function testARerunOnATableThatAlreadyHasEverythingIsSilent(): void
    {
        (new WcStatus())->up(new WpDatabase($this->wpdb));
        self::assertTrue((new WcStatus())->verify(new WpDatabase($this->wpdb)));
    }
}
