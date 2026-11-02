<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use TmcWpStubs\State;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\ConfirmShopRecords;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordConfirmationRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordPreview;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordConfirmationRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\Migrations\M0037ShopRecordConfirmation;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;

/**
 * The owner's option ب, on the real database: an explicit confirmation that a
 * shop's Dokan records came over, which closes the one gate that cannot be
 * answered once Dokan is gone — and closes nothing else.
 *
 * ⛔ **Every test starts from an imported shop**, because `eligibility()`
 * refuses an id that only OWNS records (`alpha.79`), and because the gate
 * being tested is one of six judged per shop.
 *
 * The one assertion that is NOT a number: the gate's answer is compared
 * against `recordsState()`'s own name, so a test cannot pass by agreeing with
 * a verdict nobody produced.
 */
final class ShopRecordConfirmationTest extends DatabaseTestCase
{
    private const SHOP = 7701;
    private const RECORD_OWNER_ONLY = 7702;
    private const RUN = 'confirm-records-run';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        // A clean schema per test: a product stamp left behind by one test is
        // an «imported shop» in the next, and which ids are shops is half the
        // subject here.
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
    }

    /**
     * Dokan's side behind every service that reads it, plus the completeness
     * class rebuilt with ALL of its dependencies.
     *
     * ⛔ The ninth argument matters: a fixture that rebinds the service with
     * fewer dependencies than production gives it answers a different question
     * (`alpha.79`), and the ninth is the confirmation this whole class is
     * about.
     */
    private function useFakeDokan(): FakeDokanReader
    {
        $fake = new FakeDokanReader();
        $c = Bootstrap::container();
        $c->bind(DokanReaderInterface::class, static fn (): FakeDokanReader => $fake);
        $c->bind(ShopRecordPreview::class, static fn ($c): ShopRecordPreview => new ShopRecordPreview(
            $c->get(DokanReaderInterface::class),
            $c->get(ShopRecordRepositoryInterface::class)
        ));
        $c->bind(MigrationCompleteness::class, static fn ($c): MigrationCompleteness => new MigrationCompleteness(
            $c->get(ShopRecordRepositoryInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap::class),
            $c->get(ReconcileDokanFinance::class),
            $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(DokanReaderInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Migration\Application\CategoryMap::class),
            $c->get(ShopRecordPreview::class),
            $c->get(ShopRecordConfirmationRepositoryInterface::class)
        ));
        $c->bind(ConfirmShopRecords::class, static fn ($c): ConfirmShopRecords => new ConfirmShopRecords(
            $c->get(MigrationCompleteness::class),
            $c->get(ShopRecordRepositoryInterface::class),
            $c->get(ShopRecordConfirmationRepositoryInterface::class),
            $c->get(\Tecteb\Marketplace\Contracts\CapabilityCheckerInterface::class),
            $c->get(\Tecteb\Marketplace\Core\Audit\AuditLogger::class)
        ));
        return $fake;
    }

    /** One imported product, so this vendor is a SHOP and not a record owner. */
    private function seedImportedShop(int $vendorUserId = self::SHOP): int
    {
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        // The run stamp is what makes this id a SHOP rather than a record
        // owner (`MigrationCompleteness::shopVendorUserIds()`), and `observed`
        // is what every imported row is at the moment it is created.
        $id = $products->create(
            $vendorUserId,
            new ProductDetails(title: 'کالای واردشده ' . $vendorUserId, priceMinor: 450000, stock: 3),
            ProductStatus::Published,
            LinkOwnership::Observed,
            null,
            self::RUN
        );
        self::assertGreaterThan(0, $id, 'the fixture needs a product row');
        return $id;
    }

    private function completeness(): MigrationCompleteness
    {
        return Bootstrap::container()->get(MigrationCompleteness::class);
    }

    private function service(): ConfirmShopRecords
    {
        return Bootstrap::container()->get(ConfirmShopRecords::class);
    }

    private function gateFor(int $vendorUserId): bool
    {
        return (bool) $this->completeness()->forVendor($vendorUserId)['gates']['records_imported'];
    }

    private function stateFor(int $vendorUserId): string
    {
        return (string) $this->completeness()->recordsState($vendorUserId)['state'];
    }

    /** @return array<string,mixed> one Dokan-shaped balance row for the SOURCE side */
    private function sourceBalanceRow(int $trnId): array
    {
        return [
            'id' => $trnId,
            'trn_id' => $trnId,
            'vendor_id' => self::SHOP,
            'vendor_user_id' => self::SHOP,
            'trn_type' => 'dokan_orders',
            'perticulars' => '',
            'debit' => '0.0000',
            'credit' => '5.0000',
            'status' => 'approved',
            'trn_date' => '2026-01-02 00:00:00',
            'balance_date' => '2026-01-02 00:00:00',
        ];
    }

    /** @return array<string,mixed> the same row in the shape the archive takes */
    private function archiveBalanceRow(int $trnId): array
    {
        return [
            'trn_id' => $trnId,
            'vendor_user_id' => self::SHOP,
            'debit' => '0.0000',
            'credit' => '5.0000',
            'trn_type' => 'dokan_orders',
            'trn_date' => '2026-01-02 00:00:00',
            'particulars' => '',
            'status' => 'approved',
        ];
    }

    /**
     * One archived balance row written WITHOUT the version bump.
     *
     * Raw SQL rather than `recordBalance()`, because the bump happens inside
     * that method's own transaction and the state under test is a shop that
     * holds rows while the counter still reads zero.
     */
    private function insertArchiveRowWithoutBumpingTheVersion(int $trnId): void
    {
        $db = new WpDatabase($this->wpdb);
        $written = $db->execute(
            'INSERT INTO `' . M0017DokanShopRecords::table($db, M0017DokanShopRecords::BALANCE) . '`
               (run_id, vendor_user_id, trn_id, trn_type, particulars, debit, credit, status, trn_date, source, imported_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s)',
            [
                self::RUN, self::SHOP, $trnId, 'dokan_orders', '', '0.0000', '5.0000',
                'approved', '2026-01-02 00:00:00', 'dokan', '2026-01-02 00:00:00',
            ]
        );
        self::assertSame(1, $written, 'the fixture row must be written');
    }

    /** @return list<array<string,mixed>> every confirmation row, oldest first */
    private function confirmationRows(): array
    {
        $db = new WpDatabase($this->wpdb);
        return $db->getResults(
            'SELECT * FROM `' . M0037ShopRecordConfirmation::table($db) . '` ORDER BY id ASC'
        ) ?? [];
    }

    private function rowCount(): int
    {
        $db = new WpDatabase($this->wpdb);
        return (int) $db->getVar('SELECT COUNT(*) FROM `' . M0037ShopRecordConfirmation::table($db) . '`');
    }

    /** ⛔ The migration creates the table and seeds NOTHING. */
    public function testTheMigrationWritesNoConfirmation(): void
    {
        $db = new WpDatabase($this->wpdb);
        self::assertTrue(M0037ShopRecordConfirmation::carriedBy($db), 'the table must be there');
        self::assertSame(0, $this->rowCount(), 'no confirmation may be backfilled');
    }

    /** With Dokan answering and nothing outstanding, the gate is already open. */
    public function testWithDokanRunningTheGateNeedsNoConfirmation(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertSame(MigrationCompleteness::RECORDS_SETTLED, $this->stateFor(self::SHOP));
        self::assertTrue($this->gateFor(self::SHOP));
        self::assertSame(0, $this->rowCount(), 'and nothing was written to get there');
    }

    /** Dokan gone, nobody confirmed: the gate stays open and says why. */
    public function testWithoutDokanAndWithoutAConfirmationTheGateStaysOpen(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();
        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_DOKAN_ABSENT, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));
    }

    /** The whole point: confirm while it is checkable, and the gate closes after. */
    public function testAConfirmationClosesTheGateOnceDokanIsGone(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();

        $result = $this->service()->confirm(self::SHOP);
        self::assertTrue($result->ok, 'confirming a settled reconciliation must succeed');
        self::assertSame('records_confirmed', $result->code);
        self::assertSame(1, $this->rowCount());

        // While Dokan still answers, the LIVE read is the reference and the
        // confirmation changes nothing.
        self::assertSame(MigrationCompleteness::RECORDS_SETTLED, $this->stateFor(self::SHOP));

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_CONFIRMED, $this->stateFor(self::SHOP));
        self::assertTrue($this->gateFor(self::SHOP));
    }

    /**
     * ⛔ A confirmation can never cover a present problem.
     *
     * Dokan comes back and one source now has rows we do not hold. The live
     * read wins, the gate opens again, and the confirmation is still on the
     * row — it was never deleted and it is simply not what is being asked.
     */
    public function testAConfirmationDoesNotCoverAnOutstandingRecordWhenDokanReturns(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        $fake->balanceRows = [[
            'row_id' => 1,
            'vendor_user_id' => self::SHOP,
            'debit' => '0.0000',
            'credit' => '10.0000',
            'trn_type' => 'dokan_orders',
            'trn_date' => '2026-01-01 00:00:00',
            'balance_date' => '2026-01-01 00:00:00',
            'perticulars' => '',
            'status' => 'approved',
        ]];
        self::assertSame(MigrationCompleteness::RECORDS_OUTSTANDING, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));
        self::assertSame(1, $this->rowCount(), 'and the confirmation row is untouched');
    }

    /**
     * ⛔ A confirmation taken against an archive that has since MOVED is
     * stale, and the gate says so by name.
     *
     * The archive is moved on the real path — one recorded balance row, which
     * bumps `records_version` inside its own transaction — rather than by
     * hand-editing the counter.
     */
    public function testAnArchiveThatMovedMakesTheConfirmationStale(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        $records = Bootstrap::container()->get(ShopRecordRepositoryInterface::class);
        $before = $records->recordsVersion(self::SHOP);
        $records->recordBalance(self::RUN, [
            'trn_id' => 991,
            'vendor_user_id' => self::SHOP,
            'debit' => '0.0000',
            'credit' => '5.0000',
            'trn_type' => 'dokan_orders',
            'trn_date' => '2026-01-02 00:00:00',
            'particulars' => '',
            'status' => 'approved',
        ]);
        self::assertGreaterThan($before, $records->recordsVersion(self::SHOP), 'the archive must have moved');

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_CONFIRMATION_STALE, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP), 'a stale confirmation closes nothing');
    }

    /**
     * ⛔ A confirmation taken against a KNOWN version goes stale when the
     * archive moves — and this test exists because falsification found that
     * the two halves of `withoutDokan()` were covering each other.
     *
     * The earlier stale test confirmed at version **zero** (the fixture holds
     * no record rows), so removing the version comparison left the
     * zero-version guard to answer, and removing the zero-version guard left
     * the comparison to answer. Each mutation SURVIVED while the other half
     * stood in for it — `alpha.82`'s «double guard», in a new place. Here the
     * shop holds a balance row BEFORE the confirmation, so `records_version`
     * is already non-zero and only the comparison can refuse.
     */
    public function testAConfirmationTakenAtAKnownVersionGoesStaleWhenTheArchiveMoves(): void
    {
        $fake = $this->useFakeDokan();
        // The source holds one balance row, so what we hold matches it and
        // the reconciliation reads settled.
        $fake->balanceRows = [$this->sourceBalanceRow(991)];
        $this->seedImportedShop();

        $records = Bootstrap::container()->get(ShopRecordRepositoryInterface::class);
        self::assertSame('recorded', $records->recordBalance(self::RUN, $this->archiveBalanceRow(991)));
        $taken = $records->recordsVersion(self::SHOP);
        self::assertGreaterThan(0, $taken, 'the confirmation must be taken against a REAL version');

        self::assertTrue($this->service()->confirm(self::SHOP)->ok);
        $live = Bootstrap::container()->get(ShopRecordConfirmationRepositoryInterface::class)
            ->liveFor(self::SHOP);
        self::assertSame($taken, (int) ($live['records_version'] ?? 0));

        // One more archived row, on the real path, so the counter moves.
        self::assertSame('recorded', $records->recordBalance(self::RUN, $this->archiveBalanceRow(992)));
        self::assertGreaterThan($taken, $records->recordsVersion(self::SHOP));

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_CONFIRMATION_STALE, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));
    }

    /**
     * ⛔ A version of **zero** is not believed while rows are held.
     *
     * `recordsVersion()` reads one counter with `(int) getVar()`, and that
     * answers zero both for «no row» and for a read that failed
     * (`alpha.8`). So a confirmation stored against zero is only worth
     * anything when the shop genuinely holds nothing; with rows in the
     * archive and the counter still at zero, the number the confirmation
     * names cannot be compared against anything and the gate says so.
     *
     * The row is inserted with raw SQL on purpose — `recordBalance()` bumps
     * the counter inside its own transaction, which is the very thing this
     * state is the absence of.
     */
    public function testAVersionOfZeroIsNotBelievedWhileRowsAreHeld(): void
    {
        $fake = $this->useFakeDokan();
        $fake->balanceRows = [$this->sourceBalanceRow(993)];
        $this->seedImportedShop();
        $this->insertArchiveRowWithoutBumpingTheVersion(993);

        $records = Bootstrap::container()->get(ShopRecordRepositoryInterface::class);
        self::assertSame(0, $records->recordsVersion(self::SHOP), 'the counter never moved');
        self::assertSame(1, (int) $records->importedCountsFor(self::SHOP)['balance'], 'but a row is held');

        self::assertTrue($this->service()->confirm(self::SHOP)->ok, 'the reconciliation is settled');

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_CONFIRMATION_STALE, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));
    }

    /**
     * ⛔ Revoking the live decision does not rewrite the ones before it.
     *
     * The `UPDATE` matches on `live_vendor_user_id`, not on `vendor_user_id`,
     * and with one row those are the same row — so the narrower condition was
     * unmeasured until there was HISTORY to damage. Two revocations, and the
     * first one's stamp has to be exactly where it was.
     */
    public function testRevokingDoesNotRewriteTheHistoryItKeeps(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();

        self::assertTrue($this->service()->confirm(self::SHOP)->ok);
        self::assertTrue($this->service()->revoke(self::SHOP)->ok);
        $first = $this->confirmationRows();
        self::assertCount(1, $first);
        $stamp = (string) $first[0]['revoked_at'];
        $by = (int) $first[0]['revoked_by'];
        self::assertNotSame('', $stamp, 'the revoked row carries its own stamp');

        self::assertTrue($this->service()->confirm(self::SHOP)->ok);
        self::assertTrue($this->service()->revoke(self::SHOP)->ok);

        $rows = $this->confirmationRows();
        self::assertCount(2, $rows, 'both decisions are history');
        self::assertSame($stamp, (string) $rows[0]['revoked_at'], 'the first revocation was not rewritten');
        self::assertSame($by, (int) $rows[0]['revoked_by']);
        self::assertNotSame('', (string) $rows[1]['revoked_at'], 'and the second has its own');
        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_DOKAN_ABSENT, $this->stateFor(self::SHOP));
    }

    /** Revocation keeps the row, re-opens the gate, and lets a second confirmation in. */
    public function testRevokingKeepsTheHistoryAndTheGateReopens(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        $revoked = $this->service()->revoke(self::SHOP);
        self::assertTrue($revoked->ok);
        self::assertSame('records_confirmation_revoked', $revoked->code);
        self::assertSame(1, $this->rowCount(), 'the revoked row is HISTORY and stays');

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_DOKAN_ABSENT, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));

        // And a second confirmation is possible — «قابل لغو» would mean
        // nothing if withdrawing it locked the shop out for ever.
        $fake->available = true;
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);
        self::assertSame(2, $this->rowCount(), 'two decisions, one live');
        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_CONFIRMED, $this->stateFor(self::SHOP));
    }

    /** A second live confirmation loses at the INDEX, not at a check. */
    public function testASecondConfirmationIsRefusedAndWritesNothing(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        $again = $this->service()->confirm(self::SHOP);
        self::assertFalse($again->ok);
        self::assertSame('already_confirmed', $again->code);
        self::assertSame(1, $this->rowCount());
    }

    /** Revoking what was never confirmed is named, and writes nothing. */
    public function testRevokingNothingIsNamed(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        $result = $this->service()->revoke(self::SHOP);
        self::assertFalse($result->ok);
        self::assertSame('not_confirmed', $result->code);
        self::assertSame(0, $this->rowCount());
    }

    /** ⛔ An incomplete reconciliation cannot be confirmed, in either shape. */
    public function testAnIncompleteReconciliationIsRefused(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();

        // (a) rows waiting on Dokan's side
        $fake->balanceRows = [[
            'row_id' => 1,
            'vendor_user_id' => self::SHOP,
            'debit' => '0.0000',
            'credit' => '10.0000',
            'trn_type' => 'dokan_orders',
            'trn_date' => '2026-01-01 00:00:00',
            'balance_date' => '2026-01-01 00:00:00',
            'perticulars' => '',
            'status' => 'approved',
        ]];
        $result = $this->service()->confirm(self::SHOP);
        self::assertFalse($result->ok);
        self::assertSame('records_not_settled', $result->code);

        // (b) Dokan cannot be asked at all — which is the state this whole
        // decision exists for, and the one moment it may NOT be taken.
        $fake->balanceRows = [];
        $fake->available = false;
        $result = $this->service()->confirm(self::SHOP);
        self::assertFalse($result->ok);
        self::assertSame('records_not_settled', $result->code);
        self::assertSame(0, $this->rowCount());
    }

    /** ⛔ An unreadable source is not a nought, and cannot be confirmed. */
    public function testAnUnreadableSourceIsRefused(): void
    {
        $fake = $this->useFakeDokan();
        $this->seedImportedShop();
        $fake->unreadableSources = ['balance'];
        $result = $this->service()->confirm(self::SHOP);
        self::assertFalse($result->ok);
        self::assertSame('records_not_settled', $result->code);
    }

    /** An id that only owns records is not a shop, and is refused by name. */
    public function testARecordOwnerThatIsNotAShopIsRefused(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        $result = $this->service()->confirm(self::RECORD_OWNER_ONLY);
        self::assertFalse($result->ok);
        self::assertSame('not_an_imported_shop', $result->code);
        self::assertSame(0, $this->rowCount());
    }

    /** A manager without the capability writes nothing, in both directions. */
    public function testWithoutTheCapabilityNothingIsWritten(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        State::loginAs(2, []);
        self::assertSame('forbidden', $this->service()->confirm(self::SHOP)->code);
        self::assertSame('forbidden', $this->service()->revoke(self::SHOP)->code);
        self::assertSame(1, $this->rowCount(), 'and the live confirmation is untouched');
    }

    /** A failed write is `storage_failed`, and the gate does not move. */
    public function testAFailedWriteIsNamedAndChangesNothing(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();

        $c = Bootstrap::container();
        $failing = new FailingDatabase(new WpDatabase($this->wpdb));
        $failing->failWhen(['INSERT', M0037ShopRecordConfirmation::TABLE]);
        $c->bind(
            ShopRecordConfirmationRepositoryInterface::class,
            static fn ($c): DbShopRecordConfirmationRepository => new DbShopRecordConfirmationRepository(
                $failing,
                $c->get(\Tecteb\Marketplace\Contracts\ClockInterface::class)
            )
        );
        // ⛔ The fake is REPLACED here, because rebinding the repository
        // above does not rebuild the services that hold the old one — and a
        // fixture that then sets `available` on the discarded fake is talking
        // to nobody (`alpha.79`).
        $fake = $this->useFakeDokan();

        $result = $this->service()->confirm(self::SHOP);
        self::assertFalse($result->ok);
        self::assertSame('storage_failed', $result->code);
        self::assertSame(0, $this->rowCount());

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_DOKAN_ABSENT, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));
    }

    /**
     * ⛔ A same-named table with the WRONG shape reads as absent, and the gate
     * behaves exactly as `alpha.90` shipped.
     *
     * That direction is the conservative one: omitting the confirmation can
     * only leave MORE work waiting, never less (`alpha.68`).
     */
    public function testAMisShapedTableLeavesTheGateWhereAlpha90HadIt(): void
    {
        $db = new WpDatabase($this->wpdb);
        $table = M0037ShopRecordConfirmation::table($db);
        $db->execute('DROP TABLE IF EXISTS `' . $table . '`');
        $db->execute('CREATE TABLE `' . $table . '` (`id` BIGINT UNSIGNED NOT NULL)');
        self::assertFalse(M0037ShopRecordConfirmation::carriedBy($db), 'the columns decide, not the name');

        $fake = $this->useFakeDokan();
        $this->seedImportedShop();
        $result = $this->service()->confirm(self::SHOP);
        self::assertFalse($result->ok);
        self::assertSame('confirmation_unsupported', $result->code);

        $fake->available = false;
        self::assertSame(MigrationCompleteness::RECORDS_DOKAN_ABSENT, $this->stateFor(self::SHOP));
        self::assertFalse($this->gateFor(self::SHOP));
    }

    /**
     * ⛔ And it changes NOTHING ELSE: not ownership, not the selling
     * permission, not the financial decision.
     *
     * Read before and after from the three places that answer those
     * questions, rather than asserted from the service's own absence of
     * methods — that half is `ShopRecordConfirmationIsNeverAutomaticTest`.
     */
    public function testConfirmingTouchesNoOwnershipPermissionOrMoney(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        $c = Bootstrap::container();
        $products = $c->get(ProductRepositoryInterface::class);
        $vendors = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class);
        $finance = $c->get(ReconcileDokanFinance::class);

        $tallyBefore = $products->ownershipTallyForVendor(self::SHOP);
        $canSellBefore = $vendors->findProfileByUser(self::SHOP)?->canSell;
        $financeBefore = $finance->decisionFor(self::SHOP);

        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        self::assertSame($tallyBefore, $products->ownershipTallyForVendor(self::SHOP));
        self::assertSame($canSellBefore, $vendors->findProfileByUser(self::SHOP)?->canSell);
        self::assertSame($financeBefore, $finance->decisionFor(self::SHOP));
    }

    /**
     * The confirmation is audited, and the row names the version it was taken
     * against.
     *
     * ⛔ `records_version` is asserted PRESENT because the allowlist dropped
     * it silently for the financial decision until this round — a payload key
     * outside `AuditEventCatalog::allowlist()` is discarded with no word
     * anywhere.
     */
    public function testTheConfirmationIsAudited(): void
    {
        $this->useFakeDokan();
        $this->seedImportedShop();
        self::assertTrue($this->service()->confirm(self::SHOP)->ok);

        // ⛔ Read from the RECORDING repository, not from `tmc_audit_events`:
        // this suite binds a fake, so a test that queried the table would
        // assert «no audit row» about a trail that was written (`alpha.86`).
        $written = array_values(array_filter(
            $this->audit->records,
            static fn ($record): bool => $record->eventType === AuditEventCatalog::DOKAN_RECORDS_CONFIRMED
        ));
        self::assertCount(1, $written, 'the confirmation must be audited exactly once');
        $payload = $written[0]->payload;
        // The payload AFTER the sanitiser, which drops every key outside
        // `AuditEventCatalog::allowlist()` without a word — the way
        // `records_version` was being lost on the financial decision until
        // this round.
        self::assertArrayHasKey('records_version', $payload);
        self::assertArrayHasKey('reconciliation', $payload);
        self::assertSame(self::SHOP, (int) $payload['vendor_id']);
        self::assertStringContainsString('balance:', (string) $payload['reconciliation']);
    }
}
