<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\MoneyScaleRollbackGate;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0033MoneyUnitOnRows as Unit;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;

/**
 * «ABSENT», «PRESENT» AND «READ FAILED», ON REAL MariaDB — and the rollback
 * gate that counts what a release before `alpha.67` would misread.
 *
 * ⛔ **What this measures, and why it is a database test.** `alpha.67` asked
 * the question with one `COUNT(*)` on `information_schema.COLUMNS` and read a
 * failed read as «the columns are not there». On an upgraded database they ARE
 * there and they carry exponent 4, so the reader substituted `NULL` for them
 * and a share of ۸۸٫۳۷۵ تومان was read at the vendor's exponent 0 — ۸۸۳٬۷۵۰.
 * Measured on `alpha.67`'s own bytes with that one read blinded:
 * `exact_row_read_at=0`, `exact_row_stated_at=4`,
 * `exact_row_factor_applied=10000`, and `balance_unreadable=no`.
 *
 * Nothing here is a stub: the columns are really dropped, the catalogue read
 * is really blinded, and the figures asserted are read back out of MariaDB.
 */
final class MoneyUnitColumnStateTest extends DatabaseTestCase
{
    private const VENDOR = 68;

    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
    }

    public function testAHealthyReadSaysThePresentColumnsArePresent(): void
    {
        self::assertSame(Unit::PRESENT, Unit::unitColumnState($this->db, $this->items()));
    }

    public function testAnAbsentColumnIsAbsentAndNotUnknown(): void
    {
        $this->dropUnitColumns();
        try {
            self::assertSame(Unit::ABSENT, Unit::unitColumnState($this->db, $this->items()));
        } finally {
            $this->restoreUnitColumns();
        }
    }

    /**
     * ⛔ THE FINDING. With the catalogue read blinded, the columns are still
     * PRESENT — asked a second way — rather than being reported absent and
     * having their contents reinterpreted.
     */
    public function testAFailedCatalogueReadDoesNotTurnPresentColumnsIntoAbsentOnes(): void
    {
        $blinded = new BlindedCatalogueDatabase($this->db);

        self::assertSame(
            Unit::PRESENT,
            Unit::unitColumnState($blinded, $this->items()),
            'the second read asks the table itself, so a failed catalogue read is not an answer'
        );
        self::assertGreaterThan(0, $blinded->blinded, 'sanity: the catalogue read really was blinded');
    }

    /** And with the columns genuinely gone, the blinded read says ABSENT. */
    public function testAFailedCatalogueReadStillSeesAGenuinelyAbsentColumn(): void
    {
        $this->dropUnitColumns();
        try {
            self::assertSame(
                Unit::ABSENT,
                Unit::unitColumnState(new BlindedCatalogueDatabase($this->db), $this->items())
            );
        } finally {
            $this->restoreUnitColumns();
        }
    }

    /**
     * AND WHEN NEITHER READ CAN ANSWER, THE ANSWER IS «UNKNOWN» — which is
     * not «absent». Both reads are blinded, which is what a database that
     * cannot be reached looks like from here.
     */
    public function testWhenNeitherReadCanAnswerTheStateIsUnknown(): void
    {
        $blinded = new BlindedCatalogueDatabase($this->db, blindShowColumns: true);

        self::assertSame(Unit::UNKNOWN, Unit::unitColumnState($blinded, $this->items()));
        self::assertNotSame(Unit::ABSENT, Unit::unitColumnState($blinded, $this->items()));
    }

    /**
     * A ROW AT THE FINER UNIT IS NOT REPORTED AS CARRYING NO UNIT while the
     * column state is unknown — and the balance says so rather than
     * multiplying it by ten thousand.
     */
    public function testAnUnknownColumnStateIsReportedRatherThanResolved(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 100, 100, null, null);          // the legacy shape
        $this->writeLine(2, 1010000, 883750, 'IRT', 4);      // ۸۸٫۳۷۵ تومان

        $view = (new \Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository(
            new BlindedCatalogueDatabase($this->db, blindShowColumns: true),
            new SystemClock()
        ))->settlementView(self::VENDOR);

        self::assertCount(2, $view);
        foreach ($view as $row) {
            self::assertTrue($row['unit_unreadable'], 'every row says the state could not be read');
            self::assertNull($row['exponent'], 'and no row claims a unit it was not allowed to read');
        }
    }

    /**
     * THE SAME TWO ROWS, READ HEALTHILY, ADD UP EXACTLY — the control.
     *
     * ۱۰۰ تومان at exponent 0 plus ۸۸٫۳۷۵ تومان at exponent 4 is ۱۸۸٫۳۷۵, so
     * `1883750` at exponent 4. The unit-less row is resolved from the
     * REGISTRY rather than from whichever exponent some other row happens to
     * name — which is what `alpha.67` did, and which read ۱۰۰ تومان as
     * ۰٫۰۱ تومان on this very fixture.
     */
    public function testTheTotalsResolveAUnitLessRowFromTheRegistryRatherThanFromTheOtherRows(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 100, 100, null, null);
        $this->writeLine(2, 1010000, 883750, 'IRT', 4);
        $items = new \Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository(
            $this->db,
            new SystemClock()
        );

        $withRegistry = $items->totalsForVendor(self::VENDOR, 0);
        self::assertTrue($withRegistry['resolved']);
        self::assertSame(4, $withRegistry['exponent']);
        self::assertSame(1883750, $withRegistry['share'], '۱۰۰ + ۸۸٫۳۷۵ = ۱۸۸٫۳۷۵');

        // WITHOUT the registry figure the answer REFUSES rather than guessing.
        $without = $items->totalsForVendor(self::VENDOR);
        self::assertFalse($without['resolved']);
        self::assertNull($without['exponent']);
        self::assertSame(0, $without['share'], 'no combined figure is offered at all');
        self::assertCount(2, $without['groups'], 'and the per-unit truth is still there');
    }

    /** A catalogue with no unit anywhere still reads the way it always did. */
    public function testACatalogueWithNoUnitAnywhereStillReadsAtExponentZero(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 450000, 405000, null, null);
        $items = new \Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository(
            $this->db,
            new SystemClock()
        );

        $totals = $items->totalsForVendor(self::VENDOR);

        self::assertTrue($totals['resolved'], 'one unit, so nothing has to be combined');
        self::assertSame(0, $totals['exponent']);
        self::assertSame(405000, $totals['share']);
    }

    // ------------------------------------------------------------- §4

    public function testTheRollbackGateIsSafeWhileNothingIsFiner(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 450000, 405000, 'IRT', 0);

        $answer = (new MoneyScaleRollbackGate($this->db))->check();

        self::assertSame(MoneyScaleRollbackGate::SAFE, $answer['verdict']);
        self::assertTrue($answer['safe']);
        self::assertTrue($answer['readable']);
        self::assertSame(0, $answer['counts']['order_items']);
        self::assertSame([], $answer['vendors']);
    }

    /** ⛔ ONE ROW IS ENOUGH. This is a gate, not a threshold. */
    public function testOneRowAtAFinerUnitBlocksTheRollback(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 1010000, 883750, 'IRT', 4);

        $answer = (new MoneyScaleRollbackGate($this->db))->check();

        self::assertSame(MoneyScaleRollbackGate::BLOCKED, $answer['verdict']);
        self::assertFalse($answer['safe']);
        self::assertSame(1, $answer['counts']['order_items']);
        self::assertSame([self::VENDOR], $answer['vendors'], 'and whose rows they are');
        self::assertStringContainsString('10,000 times too large', $answer['reason']);
    }

    /** A LEDGER entry at the finer unit blocks it too — it is the record. */
    public function testALedgerEntryAtAFinerUnitBlocksTheRollback(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLedgerEntry(126250, 'IRT', 4);

        $answer = (new MoneyScaleRollbackGate($this->db))->check();

        self::assertSame(MoneyScaleRollbackGate::BLOCKED, $answer['verdict']);
        self::assertSame(1, $answer['counts']['ledger_entries']);
    }

    /**
     * A READ THAT FAILED IS «UNKNOWN», NOT «SAFE».
     *
     * A gate that answers «go ahead» because it could not look is worse than
     * no gate at all (`alpha.60`).
     */
    public function testAGateThatCannotReadDoesNotAnswerSafe(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 450000, 405000, 'IRT', 0);

        $answer = (new MoneyScaleRollbackGate(
            new BlindedCatalogueDatabase($this->db, blindShowColumns: true)
        ))->check();

        self::assertSame(MoneyScaleRollbackGate::UNKNOWN, $answer['verdict']);
        self::assertFalse($answer['safe']);
        self::assertFalse($answer['readable']);
        self::assertStringContainsString('not an answer of «safe»', $answer['reason']);
    }

    /** WHAT A RESTORE WOULD COST, counted rather than described. */
    public function testTheLossSinceABackupIsCounted(): void
    {
        $this->registerUnit('IRT', 0);
        $this->writeLine(1, 1010000, 883750, 'IRT', 4);
        $this->writeLedgerEntry(126250, 'IRT', 4);
        $gate = new MoneyScaleRollbackGate($this->db);

        $before = $gate->lossSince('2099-01-01 00:00:00');
        self::assertTrue($before['readable']);
        self::assertSame(0, $before['total'], 'nothing was written after a moment in the future');

        $after = $gate->lossSince('2000-01-01 00:00:00');
        self::assertTrue($after['readable']);
        self::assertSame(1, $after['rows']['order_items']);
        self::assertSame(1, $after['rows']['ledger_entries']);
        self::assertSame(2, $after['total'], 'and the total is what a restore would drop');
    }

    // ------------------------------------------------------------- fixtures

    private function items(): string
    {
        return M0006CatalogAndOrders::table($this->db, M0006CatalogAndOrders::ORDER_ITEMS);
    }

    private function dropUnitColumns(): void
    {
        $table = $this->items();
        foreach ([Unit::CURRENCY, Unit::EXPONENT] as $column) {
            $this->wpdb->pdo()->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }

    private function restoreUnitColumns(): void
    {
        $table = $this->items();
        foreach (Unit::COLUMNS as $column => $definition) {
            $this->wpdb->pdo()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function registerUnit(string $currency, int $exponent): void
    {
        $written = $this->db->execute(
            'INSERT INTO `' . M0022VendorMoneyUnit::table($this->db, M0022VendorMoneyUnit::UNITS) . '`
             (vendor_user_id, currency, exponent, source_event, created_at)
             VALUES (%d, %s, %d, %s, %s)',
            [self::VENDOR, $currency, $exponent, 'alpha68-test', gmdate('Y-m-d H:i:s')]
        );
        self::assertNotNull($written, 'the registry row has to land first');
    }

    private function writeLine(
        int $seq,
        int $baseMinor,
        int $shareMinor,
        ?string $currency,
        ?int $exponent
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $written = $this->db->execute(
            'INSERT INTO `' . $this->items() . '`
             (wc_order_id, wc_order_item_id, wc_product_id, product_id, vendor_user_id, title, sku,
              quantity, unit_price_minor, base_minor, tax_minor, commission_minor, vendor_share_minor,
              rate_bp, rate_source, ledger_event, status, `' . Unit::CURRENCY . '`, `' . Unit::EXPONENT . '`,
              created_at, updated_at)
             VALUES (%d, %d, 0, 0, %d, %s, %s, 1, NULL, %d, 0, %d, %d, 1250, %s, %s, %s, '
             . ($currency === null ? 'NULL' : '%s') . ', '
             . ($exponent === null ? 'NULL' : '%d') . ', %s, %s)',
            array_values(array_filter(
                [
                    6800 + $seq,
                    6900 + $seq,
                    self::VENDOR,
                    'قلم ' . $seq,
                    'SKU-A68-' . $seq,
                    $baseMinor,
                    $baseMinor - $shareMinor,
                    $shareMinor,
                    'product',
                    'alpha68:' . $seq,
                    OrderItemStatus::Placed->value,
                    $currency,
                    $exponent,
                    $now,
                    $now,
                ],
                static fn (mixed $value): bool => $value !== null
            ))
        );
        self::assertNotNull($written, 'the fixture line has to land before anything is measured');
    }

    private function writeLedgerEntry(int $minor, string $currency, int $exponent): void
    {
        $written = $this->db->execute(
            'INSERT INTO `' . M0004CreateFinanceTables::table($this->db, M0004CreateFinanceTables::LEDGER) . '`
             (event_key, vendor_user_id, account, amount_minor, currency, exponent,
              order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
             VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s, NULL, %s, %s)',
            [
                'alpha68:ledger',
                self::VENDOR,
                'commission',
                $minor,
                $currency,
                $exponent,
                'alpha68',
                '',
                'test',
                '{}',
                gmdate('Y-m-d H:i:s'),
            ]
        );
        self::assertNotNull($written);
    }
}

/**
 * A REAL DATABASE WITH THE CATALOGUE READ BLINDED, by name.
 *
 * `information_schema.COLUMNS` is the read `M0033::unitColumnState()` asks
 * first, and `SHOW COLUMNS` is the one it asks second. Blinding one measures
 * the fix; blinding both measures «unknown». Every other statement goes to
 * the real MariaDB on the same connection, so the rows asserted against are
 * the rows that are actually there.
 *
 * The transaction half is passed straight through: a second gateway on one
 * connection would count nested transactions in the wrong object
 * (`alpha.56`).
 */
final class BlindedCatalogueDatabase implements DatabaseInterface
{
    public int $blinded = 0;

    public function __construct(
        private readonly DatabaseInterface $inner,
        private readonly bool $blindShowColumns = false
    ) {
    }

    private function blind(string $sql): bool
    {
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return true;
        }
        return $this->blindShowColumns && str_contains($sql, 'SHOW COLUMNS');
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }

    public function execute(string $sql, array $params = []): ?int
    {
        return $this->inner->execute($sql, $params);
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        if ($this->blind($sql)) {
            $this->blinded++;
            return null;
        }
        return $this->inner->getVar($sql, $params);
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        if ($this->blind($sql)) {
            $this->blinded++;
            return null;
        }
        return $this->inner->getRow($sql, $params);
    }

    public function getResults(string $sql, array $params = []): array
    {
        if ($this->blind($sql)) {
            $this->blinded++;
            return [];
        }
        return $this->inner->getResults($sql, $params);
    }

    public function lastError(): string
    {
        return $this->inner->lastError();
    }

    public function begin(): bool
    {
        return $this->inner->begin();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollback(): bool
    {
        return $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}
