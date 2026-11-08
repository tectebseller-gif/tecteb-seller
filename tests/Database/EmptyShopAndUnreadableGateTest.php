<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist as Plan;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipState;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbFinanceHandoverRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Migration\Presentation\MigrationWizardText;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;

/**
 * The shop that holds nothing, and the gate that could not be read.
 *
 * ⛔ **Both of these are about shop 40 on the owner's own site**, and both are
 * the shape this project has now met five times: a readiness answer derived
 * from a figure that cannot carry the case in front of it.
 *
 *  - Shop 40 carries a profile import stamp and no products. Its
 *    `ownership_taken` gate was `marketplace > 0 && observed === 0` ⇒ false
 *    for ever, and steps 9 and 10 of the wizard printed
 *    `TODO / rows_still_observed / ۰` — instructions that contradict
 *    themselves. The only exits were to invent a product or delete the shop,
 *    and the owner's §1 refuses both.
 *
 *  - `staffForVendor()` answers `[]` for a shop with no staff and `[]` for a
 *    statement that failed, and `StaffRoleMap::undecided([])` is `[]`. So a
 *    broken read made `staff_decided` report **DONE** — a false positive on a
 *    migration-readiness gate, which is the one direction that must never be
 *    wrong.
 *
 *  - And `ownershipTallyForVendor()` could not say «I could not read» at all,
 *    so a failed `GROUP BY` arrived as `marketplace => 0, observed => 0` and
 *    was judged as fact — breaking `MigrationPlanSnapshot`'s own promise that
 *    «every absent fact arrives as `null`, never as nought».
 */
final class EmptyShopAndUnreadableGateTest extends DatabaseTestCase
{
    private const MANAGER = 9;

    /** The shop with products, transferred — shop 102's shape. */
    private const FULL = 102;

    /** The shop with a profile stamp and no products — shop 40's shape. */
    private const EMPTY_SHOP = 40;

    /** A shop nothing says was ever imported. The control. */
    private const STRANGER = 777;

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbVendorRepository $vendors;
    private DbShopRecordRepository $records;
    private DbFinanceHandoverRepository $handover;
    private DbLedgerRepository $ledger;
    private WpOptionStore $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->options = new WpOptionStore();
        $this->products = new DbProductRepository($this->db, $clock);
        $this->vendors = new DbVendorRepository($this->db, $clock);
        $this->records = new DbShopRecordRepository($this->db, $clock);
        $this->handover = new DbFinanceHandoverRepository($this->db, $clock);
        $this->ledger = new DbLedgerRepository($this->db, $clock);

        // Shop 102: a profile stamped by an import, and one product that has
        // been transferred. The ordinary finished shape.
        $this->vendors->upsertProfile(self::FULL, 'tecteb', true, false, 'run-102');
        $this->products->create(
            self::FULL,
            new ProductDetails(title: 'کالای منتقل‌شده', priceMinor: 450000, sku: 'SKU-102', stock: 3),
            ProductStatus::Published,
            LinkOwnership::Marketplace,
            9319,
            'run-102'
        );

        // Shop 40: the profile stamp, and nothing else at all.
        $this->vendors->upsertProfile(self::EMPTY_SHOP, 'فروشگاه بی‌محصول', false, false, 'run-40');
    }

    // ============================================ the empty shop, judged

    /**
     * A shop that holds no products has a FINISHED ownership answer.
     *
     * Not `TAKEN` — it never took anything on — and not a failure either.
     * `NOTHING_TO_TRANSFER` is a measured nought on a shop something on this
     * side says was imported, and `OwnershipState::settled()` is true of it.
     */
    public function testAnImportedShopWithNoProductsHasNothingToTransfer(): void
    {
        $row = $this->completeness()->forVendor(self::EMPTY_SHOP);

        self::assertSame(
            OwnershipState::NOTHING_TO_TRANSFER,
            $row['detail']['ownership_state'],
            'a measured nought on an imported shop is not a failure'
        );
        self::assertTrue($row['gates']['ownership_taken'], 'and the gate is closed');
        self::assertNotContains('ownership_taken', $row['missing']);
    }

    /**
     * And the wizard's steps 9 and 10 say so, instead of «۰ ردیف مانده».
     *
     * The detail is a NAME here rather than a number, and
     * `MigrationWizardText` turns it into a Persian sentence — an internal
     * identifier on an admin screen is forbidden by `alpha.85` and asserted
     * below, because that rule is exactly the sort a new detail string
     * quietly breaks.
     */
    public function testTheConsentAndTransferStepsAreDoneForAShopWithNoProducts(): void
    {
        $state = $this->stateFor(self::EMPTY_SHOP);

        foreach ([Plan::STEP_CONSENT, Plan::STEP_TRANSFER] as $key) {
            $step = $this->stepOf($state, $key);
            self::assertSame(Plan::DONE, $step['status'], $key . ' is finished');
            self::assertSame(OwnershipState::NOTHING_TO_TRANSFER, $step['detail'], $key . ' names why');

            $said = MigrationWizardText::explain(
                $step['status'],
                $step['blocker'],
                $step['detail'],
                static fn (string $value): string => $value
            );
            self::assertStringContainsString('محصولی ندارد', $said, $key . ' is explained in Persian');
            self::assertStringNotContainsString(
                OwnershipState::NOTHING_TO_TRANSFER,
                $said,
                $key . ' never prints the identifier itself'
            );
        }
    }

    /**
     * ⛔ And the control: a nought on a shop NOTHING says was imported is
     * not «nothing to transfer».
     *
     * Drop the stamp from the rule and any id at all reads as finished —
     * which is `alpha.72`'s circularity from the other end, a gate satisfied
     * by a shop nobody ever migrated. This test fails if the stamp check is
     * removed.
     */
    public function testANoughtOnAShopNobodyImportedIsNotFinished(): void
    {
        $this->vendors->upsertProfile(self::STRANGER, 'غریبه', false, false);

        $row = $this->completeness()->forVendor(self::STRANGER);

        self::assertSame(OwnershipState::ROWS_OBSERVED, $row['detail']['ownership_state']);
        self::assertFalse($row['gates']['ownership_taken'], 'an unimported shop has taken nothing');
    }

    /** And the ordinary shape is untouched: products, transferred, done. */
    public function testAShopWhoseProductsAreTransferredStillReadsAsTaken(): void
    {
        $row = $this->completeness()->forVendor(self::FULL);

        self::assertSame(OwnershipState::TAKEN, $row['detail']['ownership_state']);
        self::assertTrue($row['gates']['ownership_taken']);
        self::assertSame(Plan::DONE, $this->stepOf($this->stateFor(self::FULL), Plan::STEP_TRANSFER)['status']);
    }

    /** A shop with a row still observed is still waiting, as it always was. */
    public function testAShopWithAnObservedRowIsStillWaiting(): void
    {
        $this->products->create(
            self::EMPTY_SHOP,
            new ProductDetails(title: 'یادداشت کاتالوگ', priceMinor: 1000, sku: 'SKU-40', stock: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            9400,
            'run-40'
        );

        $row = $this->completeness()->forVendor(self::EMPTY_SHOP);

        self::assertSame(OwnershipState::ROWS_OBSERVED, $row['detail']['ownership_state']);
        self::assertFalse($row['gates']['ownership_taken']);
        $step = $this->stepOf($this->stateFor(self::EMPTY_SHOP), Plan::STEP_TRANSFER);
        self::assertSame(Plan::TODO, $step['status']);
        self::assertSame('1', $step['detail'], 'and the figure is the row, not a nought');
    }

    // ===================================== the reads that could not be read

    /**
     * An ownership count that failed is `readable => false`, not nought.
     *
     * The breakdown is a `GROUP BY`, and `getResults()` gives `[]` for an
     * empty shop and `[]` for a statement that failed. The total is therefore
     * counted first and separately, and a breakdown that does not add up to
     * it is a read that came up short (`alpha.81`).
     */
    public function testAnOwnershipCountThatFailedSaysSoRatherThanNought(): void
    {
        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['SELECT COUNT(*)', 'tmc_products', 'vendor_user_id']);
        $products = new DbProductRepository($failing, new SystemClock());

        $tally = $products->ownershipTallyForVendor(self::FULL);

        self::assertFalse($tally['readable'], 'the count did not run');
        self::assertSame(OwnershipState::UNREADABLE, OwnershipState::of($tally, true));
        self::assertFalse(OwnershipState::settled(OwnershipState::UNREADABLE), 'and nothing is settled by it');
    }

    /** A breakdown shorter than the total is a hole, not a tally. */
    public function testABreakdownThatDoesNotAddUpIsUnreadable(): void
    {
        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['GROUP BY', 'tmc_products']);
        $products = new DbProductRepository($failing, new SystemClock());

        $tally = $products->ownershipTallyForVendor(self::FULL);

        self::assertFalse($tally['readable']);
        self::assertSame(0, $tally['marketplace'], 'and the numbers mean nothing');
    }

    /**
     * ⛔ **BOTH reads failing — the case the early return is the only guard
     * for, and the one my first set of tests never reached.**
     *
     * The count guard and the «breakdown must add up» guard are a DOUBLE
     * guard, and until a fixture makes both halves fail at once the first
     * half is unmeasured (`alpha.91`). Falsification found this: removing the
     * early return SURVIVED, because every test so far failed one read and
     * let the other answer.
     *
     * With both gone — a dropped or renamed table, which is one event, not
     * two — nought rows come back from the breakdown AND nought is what the
     * count was coerced to, so `0 === 0` would read as a readable nought: the
     * exact collapse this method exists to refuse.
     */
    public function testBothReadsFailingIsUnreadableAndNotAReadableNought(): void
    {
        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['tmc_products', 'vendor_user_id']);
        $products = new DbProductRepository($failing, new SystemClock());

        $tally = $products->ownershipTallyForVendor(self::FULL);

        self::assertFalse($tally['readable'], 'neither statement ran');
        self::assertSame(0, $tally['marketplace']);
        self::assertSame(0, $tally['observed']);
        self::assertSame(
            OwnershipState::UNREADABLE,
            OwnershipState::of($tally, true),
            'and a stamped shop does NOT become «nothing to transfer» on an unreadable nought'
        );
    }

    /** The healthy read says so too, in both directions. */
    public function testAnOwnershipCountThatRanIsReadable(): void
    {
        self::assertSame(
            ['marketplace' => 1, 'observed' => 0, 'readable' => true],
            $this->products->ownershipTallyForVendor(self::FULL)
        );
        self::assertSame(
            ['marketplace' => 0, 'observed' => 0, 'readable' => true],
            $this->products->ownershipTallyForVendor(self::EMPTY_SHOP),
            'an empty shop is a readable nought'
        );
    }

    /**
     * ⛔ The one the wizard rests on: an unreadable tally makes steps 9, 10
     * and 12 UNKNOWN, not TODO and never DONE.
     *
     * `UNKNOWN` never counts towards «آماده», so this is the difference
     * between a migration that waits and a migration declared ready on a
     * figure nobody measured.
     */
    public function testAnUnreadableTallyLeavesTheWizardStepsUnmeasured(): void
    {
        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['SELECT COUNT(*)', 'tmc_products', 'vendor_user_id']);
        $completeness = $this->completeness(new DbProductRepository($failing, new SystemClock()));

        $row = $completeness->forVendor(self::FULL);
        self::assertSame(OwnershipState::UNREADABLE, $row['detail']['ownership_state']);
        self::assertFalse($row['gates']['ownership_taken']);

        $state = ['ownership_state' => $row['detail']['ownership_state'], 'observed' => 0, 'marketplace' => 0];
        foreach ([Plan::STEP_CONSENT, Plan::STEP_TRANSFER] as $key) {
            $step = $this->stepOf($state, $key);
            self::assertSame(Plan::UNKNOWN, $step['status'], $key . ' is unmeasured');
            self::assertSame('ownership_unreadable', $step['blocker']);
        }
        self::assertFalse(Plan::tally(Plan::steps($state))['ready'], 'and nothing is ready');
    }

    /**
     * ⛔ A staff list that could not be read does NOT close `staff_decided`.
     *
     * This is the false positive: before the fix, failing the list read left
     * `undecided([])` empty and the gate reported DONE about a table nobody
     * could see.
     */
    public function testAStaffListThatCouldNotBeReadLeavesTheStaffGateOpen(): void
    {
        $this->records->recordStaff('run-40', [
            'vendor_user_id' => self::EMPTY_SHOP,
            'staff_user_id' => 51,
            'display_name' => 'کارمند',
            'user_email' => 'k@example.test',
            'dokan_role' => 'vendor_staff',
        ]);

        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['SELECT staff_user_id', 'tmc_dokan_staff_history']);
        $records = new DbShopRecordRepository($failing, new SystemClock());

        self::assertSame([], $records->staffForVendor(self::EMPTY_SHOP), 'the list came back empty');
        self::assertSame(1, $records->importedCountsFor(self::EMPTY_SHOP)['staff'], 'and the count says otherwise');

        $row = $this->completeness(null, $records)->forVendor(self::EMPTY_SHOP);

        self::assertFalse($row['detail']['staff_readable'], 'the list is not the whole list');
        self::assertFalse($row['gates']['staff_decided'], 'so the gate stays open');
        self::assertContains('staff_decided', $row['missing']);
    }

    /** And a shop that genuinely has no staff still closes it. */
    public function testAShopWithNoStaffAtAllStillClosesTheStaffGate(): void
    {
        $row = $this->completeness()->forVendor(self::EMPTY_SHOP);

        self::assertTrue($row['detail']['staff_readable'], 'nought read is nought');
        self::assertTrue($row['gates']['staff_decided']);
    }

    /** A staff count that failed is also not «every role answered». */
    public function testAStaffCountThatFailedLeavesTheStaffGateOpen(): void
    {
        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['SELECT COUNT(*)', 'tmc_dokan_staff_history']);
        $records = new DbShopRecordRepository($failing, new SystemClock());

        $row = $this->completeness(null, $records)->forVendor(self::EMPTY_SHOP);

        self::assertFalse($row['detail']['staff_readable']);
        self::assertFalse($row['gates']['staff_decided']);
    }

    // =============================================================== helpers

    private function completeness(
        ?DbProductRepository $products = null,
        ?DbShopRecordRepository $records = null
    ): MigrationCompleteness {
        $clock = new SystemClock();
        $records ??= $this->records;
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $caps = new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]);

        return new MigrationCompleteness(
            $records,
            new StaffRoleMap($this->options),
            new ReconcileDokanFinance(
                $records,
                $this->handover,
                $this->db,
                $audit,
                $clock,
                $caps,
                $this->ledger
            ),
            $this->vendors,
            $products ?? $this->products
        );
    }

    /**
     * The checklist state for one shop, without the container: only the keys
     * steps 9, 10 and 12 read, so a failure here is about those steps.
     *
     * @return array<string,mixed>
     */
    private function stateFor(int $vendorUserId): array
    {
        $row = $this->completeness()->forVendor($vendorUserId);
        return [
            'ownership_state' => $row['detail']['ownership_state'],
            'observed' => $row['detail']['products_observed'],
            'marketplace' => $row['detail']['products_marketplace'],
            'gates_missing' => $row['missing'],
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array{key:string, status:string, blocker:string, detail:string}
     */
    private function stepOf(array $state, string $key): array
    {
        foreach (Plan::steps($state) as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        self::fail('no step ' . $key);
    }
}
