<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\DokanRecordImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordPreview;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordScope;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\HandoverPage;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;

/**
 * The staging scenario, reproduced: two imported shops, records owned by six
 * other ids, one shop whose three sources read a verified nought, and sources
 * that cannot be read at all.
 *
 * ⛔ **What this is for.** On `alpha.77`, before the import, the handover page
 * listed vendor 40 and vendor 102 and named one unimported balance row for
 * 102. The run then wrote FOURTEEN rows, owned by 2 (three), 4 (six), 6 (one),
 * 8 (one), 16 (two) and 102 (one) — and the owners of those records appeared
 * on the page only afterwards. Both halves were right about their own
 * question: the preview asked which shops had an import trace and the walk
 * asked Dokan's own tables, which hold every shop at once.
 *
 * So the agreement is asserted **by id set**, never by count. Equal totals is
 * the assertion that would have passed on `alpha.77` for most of the run:
 * «تطابق دامنهٔ پیش‌نمایش و اجرا را با شناسه‌ها بسنج، نه فقط برابری تعداد».
 *
 * And the second half: vendor 40's three sources all read, all empty. Under
 * the old `(balance + withdrawals + staff) > 0 || products > 0` gate that
 * shop could never clear «ورود سابقه» except by somebody inventing a record.
 */
final class ShopRecordScopeTest extends DatabaseTestCase
{
    /** The two shops staging's page listed before the run. */
    private const IMPORTED_EMPTY = 40;
    private const IMPORTED_WITH_ROWS = 102;

    /** The six ids whose records arrived unannounced. */
    private const SURPRISES = [2, 4, 6, 8, 16];

    private const RUN = 'scope-staging-shape';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        // A clean schema per test, because this class writes: a product stamp
        // left behind by one test is an «imported shop» in the next, and the
        // whole subject here is which ids are in scope.
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
    }

    /**
     * Dokan's side behind every service that reads it.
     *
     * Each dependent service is rebound as well as the reader, because
     * `Container::get()` memoises instances and a preview, a scope or a
     * completeness built earlier would still hold `WpDokanReader` — which
     * answers `isAvailable() === false` in this environment and would make
     * every assertion here a statement about an absent Dokan.
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
            $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance::class),
            $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(DokanReaderInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Migration\Application\CategoryMap::class),
            $c->get(ShopRecordPreview::class)
        ));
        $this->bindScope();
        return $fake;
    }

    /**
     * Bind a FRESH `ShopRecordScope`.
     *
     * ⛔ Called again after anything that changes what the scope would say,
     * because the scope memoises its three lists per instance — deliberately,
     * since `admits()` is asked once per unit on the import's write path — and
     * `Container::get()` memoises the instance. A scope built before the run
     * would answer about the state before the run, which is right during the
     * run and wrong for an assertion about afterwards.
     */
    private function bindScope(): void
    {
        Bootstrap::container()->bind(
            ShopRecordScope::class,
            static fn ($c): ShopRecordScope => new ShopRecordScope(
                $c->get(MigrationCompleteness::class),
                $c->get(DokanReaderInterface::class),
                $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class),
                $c->has(\Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface::class)
                    ? $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface::class)
                    : null
            )
        );
    }

    /**
     * Staging's shape.
     *
     * Vendor 40 is a Dokan seller with nothing of its own — the verified
     * nought. Vendor 102 owns one balance row. The five surprises own records
     * and are not sellers, which is exactly how they stayed out of the
     * preview: ids named only by the balance and withdrawal tables.
     */
    private function seedStagingShape(): FakeDokanReader
    {
        $fake = $this->useFakeDokan();
        $fake->vendorRows = [
            ['user_id' => self::IMPORTED_EMPTY, 'store_name' => 'داروخانه چهل', 'email' => 'v40@example.test', 'enabled' => true],
        ];
        $rowId = 0;
        $balance = [];
        // Three for id 2, six for 4, one for 6, one for 8, two for 16 — the
        // counts the owner read off the finished run — plus 102's single row.
        foreach ([2 => 3, 4 => 6, 6 => 1, 8 => 1, 16 => 2, self::IMPORTED_WITH_ROWS => 1] as $owner => $many) {
            for ($i = 0; $i < $many; $i++) {
                $rowId++;
                $balance[] = [
                    'row_id' => $rowId,
                    'trn_id' => 5000 + $rowId,
                    'vendor_user_id' => $owner,
                    'trn_type' => 'dokan_orders',
                    'debit' => '100000.0000',
                    'credit' => '0.0000',
                    'status' => 'approved',
                ];
            }
        }
        $fake->balanceRows = $balance;
        return $fake;
    }

    /** A product stamp: the trace that makes a shop «imported» here. */
    private function seedImportTrace(int $vendorUserId, string $sku): void
    {
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        $id = $products->create(
            $vendorUserId,
            new ProductDetails(title: 'باند کشی ' . $vendorUserId, sku: $sku, priceMinor: 120000, stock: 3),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            9000 + $vendorUserId,
            self::RUN
        );
        self::assertGreaterThan(0, $id, 'the fixture must create a marketplace row for ' . $vendorUserId);
    }

    /**
     * The OTHER import trace: a vendor profile stamped with a run, and not one
     * product.
     *
     * ⛔ This is vendor 40's actual shape on staging, and the fixture has to
     * say so: with a product row present the OLD count-based gate already
     * answered true, so a product-stamped fixture cannot tell the fix from
     * the defect. Here the shop is imported, holds nothing, and its three
     * sources read a verified nought — the exact state whose only exit under
     * the old gate was inventing a record.
     */
    private function seedProfileOnlyTrace(int $vendorUserId, string $storeName): void
    {
        /** @var \Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface $vendors */
        $vendors = Bootstrap::container()->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class);
        // `canSell` false, because admission is `ReviewApplication`'s and an
        // import has never granted it.
        $vendors->upsertProfile($vendorUserId, $storeName, false, false, self::RUN);
        self::assertContains(
            $vendorUserId,
            array_map('intval', $vendors->idsFromImportRun(self::RUN)),
            'the profile stamp is the trace this test hangs on'
        );
    }

    private function scope(bool $fresh = false): ShopRecordScope
    {
        if ($fresh) {
            $this->bindScope();
        }
        /** @var ShopRecordScope $scope */
        $scope = Bootstrap::container()->get(ShopRecordScope::class);
        return $scope;
    }

    /** @return list<int> */
    private function shopIds(): array
    {
        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);
        return $completeness->shopVendorUserIds();
    }

    /** The origin label of every id in scope, keyed by id. */
    private function origins(bool $fresh = false): array
    {
        $out = [];
        foreach ($this->scope($fresh)->rows() as $row) {
            $out[(int) $row['vendor_user_id']] = (string) $row['origin'];
        }
        return $out;
    }

    /**
     * Every id that owns a record in our own three tables, read from the
     * tables rather than from any service — the measurement that caught the
     * mismatch in the first place.
     *
     * @return list<int>
     */
    private function ownersInOurTables(): array
    {
        $ids = [];
        foreach (M0017DokanShopRecords::TABLES as $suffix) {
            $rows = $this->wpdb->get_results(
                'SELECT DISTINCT vendor_user_id FROM ' . $this->wpdb->prefix . $suffix,
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $id = (int) ($row['vendor_user_id'] ?? 0);
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        sort($ids);
        return $ids;
    }

    /** §1 Every owner of a record is in scope BEFORE anything is imported. */
    public function testTheScopeNamesEveryRecordOwnerBeforeAnyImport(): void
    {
        $this->seedStagingShape();
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');
        $this->seedImportTrace(self::IMPORTED_WITH_ROWS, 'DK-SCOPE-102');

        $ids = $this->scope()->ids();

        $expected = array_merge(self::SURPRISES, [self::IMPORTED_EMPTY, self::IMPORTED_WITH_ROWS]);
        sort($expected);
        self::assertSame(
            $expected,
            $ids,
            'the six surprise owners are in scope before the run, not after it'
        );
    }

    /**
     * §1 The real run writes records for no id the scope had not already
     * named — asserted as a SET, not as a total.
     */
    public function testTheImportWritesNoRecordForAnIdOutsideThePreviewScope(): void
    {
        $this->seedStagingShape();
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');
        $this->seedImportTrace(self::IMPORTED_WITH_ROWS, 'DK-SCOPE-102');

        $before = $this->scope()->ids();
        self::assertNotSame([], $before, 'the scope must say something before the run');

        $this->runRecordImport();

        $owners = $this->ownersInOurTables();
        self::assertNotSame([], $owners, 'the run must actually have written records');
        self::assertSame(
            [],
            array_values(array_diff($owners, $before)),
            'no record owner may appear only after the import'
        );
    }

    /** §1 And the page names them while saying they are not shops. */
    public function testTheSourceOnlyIdsAreNamedAndNotPresentedAsShops(): void
    {
        $this->seedStagingShape();
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');
        $this->seedImportTrace(self::IMPORTED_WITH_ROWS, 'DK-SCOPE-102');

        $sourceOnly = $this->scope()->sourceOnlyIds();
        sort($sourceOnly);
        self::assertSame(self::SURPRISES, $sourceOnly, 'the five ids Dokan names and we never imported');

        $rows = [];
        foreach ($this->scope()->rows() as $row) {
            $rows[(int) $row['vendor_user_id']] = $row;
        }
        foreach (self::SURPRISES as $id) {
            self::assertSame(ShopRecordScope::SOURCE_ONLY, $rows[$id]['origin'], 'id ' . $id . ' is a record owner');
            self::assertFalse($rows[$id]['has_profile'], 'id ' . $id . ' has no vendor profile here');
            self::assertFalse($rows[$id]['can_sell'], 'owning records must never read as «may trade»');
        }
        self::assertSame(
            ShopRecordScope::IMPORTED,
            $rows[self::IMPORTED_WITH_ROWS]['origin'],
            'an imported shop stays an imported shop'
        );

        $html = $this->renderHandover();
        foreach (self::SURPRISES as $id) {
            self::assertStringContainsString(
                '#' . PersianDigits::toPersian((string) $id),
                $html,
                'id ' . $id . ' must be on the page before its records are written'
            );
        }
        self::assertStringContainsString('فقط صاحب سابقه در دکان', $html, 'the page says which ids are not shops');
    }

    /**
     * §2 Three sources read, three empty: the gate is clear and the state is
     * named `settled`.
     *
     * ⛔ The old gate was a count, so this shop's only way out was a record
     * somebody made up. The assertion is on the gate AND on the state, because
     * a gate that cleared for the wrong reason would pass the first half
     * alone.
     */
    public function testAVerifiedNoughtSettlesTheRecordGate(): void
    {
        $this->seedStagingShape();
        $this->seedProfileOnlyTrace(self::IMPORTED_EMPTY, 'داروخانه چهل');

        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);

        $state = $completeness->recordsState(self::IMPORTED_EMPTY);
        self::assertSame(MigrationCompleteness::RECORDS_SETTLED, $state['state']);
        self::assertSame(
            [
                'staff' => ShopRecordPreview::EMPTY,
                'balance' => ShopRecordPreview::EMPTY,
                'withdrawals' => ShopRecordPreview::EMPTY,
            ],
            $state['kinds'],
            'all three read, all three empty — a verified nought, not an absent source'
        );

        $row = $completeness->forVendor(self::IMPORTED_EMPTY);
        self::assertTrue(
            $row['gates']['records_imported'],
            'a shop whose three sources were read and are empty has nothing left to import'
        );
    }

    /** §2 A shop with rows waiting is still outstanding, and the gate holds. */
    public function testRowsWaitingInDokanKeepTheRecordGateClosed(): void
    {
        $this->seedStagingShape();
        $this->seedImportTrace(self::IMPORTED_WITH_ROWS, 'DK-SCOPE-102');

        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);

        self::assertSame(
            MigrationCompleteness::RECORDS_OUTSTANDING,
            $completeness->recordsState(self::IMPORTED_WITH_ROWS)['state']
        );
        self::assertFalse($completeness->forVendor(self::IMPORTED_WITH_ROWS)['gates']['records_imported']);
    }

    /**
     * §2 A failed read is neither «settled» nor «nought».
     *
     * The gate must stay closed, and the state must say so by its own name —
     * folding an unreadable source into the settled branch is the collapse
     * this whole round is about.
     */
    public function testAnUnreadableSourceIsNeitherSettledNorNought(): void
    {
        $fake = $this->seedStagingShape();
        $fake->unreadableSources = ['balance'];
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');

        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);

        $state = $completeness->recordsState(self::IMPORTED_EMPTY);
        self::assertSame(MigrationCompleteness::RECORDS_UNREADABLE, $state['state']);
        self::assertSame(ShopRecordPreview::UNREADABLE, $state['kinds']['balance']);
        self::assertFalse($completeness->forVendor(self::IMPORTED_EMPTY)['gates']['records_imported']);
    }

    /**
     * §1 An unreadable source also narrows the SCOPE, and that is said rather
     * than hidden.
     *
     * A short enumeration that looks complete is how two scopes went unnoticed
     * for a round, so the page prints «نتوانستم فهرست را کامل بخوانم».
     */
    public function testAnUnreadableScopeSourceIsReportedOnThePage(): void
    {
        $fake = $this->seedStagingShape();
        $fake->unreadableSources = ['balance'];
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');

        $scope = $this->scope();
        self::assertFalse($scope->sourceReadable(), 'a failed read must not pass as a complete list');
        self::assertStringContainsString('کامل خوانده نشد، پس همین جدول هم می‌تواند ناقص باشد', $this->renderHandover());
    }

    /**
     * §3 With Dokan gone the state says so by its own name, and **no count
     * opens the gate**.
     *
     * ⛔ **This is the assertion `alpha.78` would have failed.** There the
     * gate fell back to `(balance + withdrawals + staff) > 0 || products > 0`
     * for every unaskable state, so this shop — which holds a product row and
     * nothing else — read «ورود سابقه: انجام شده» on the strength of a
     * product. «وجود محصول یا بخشی از سوابق اثبات کامل بودن نیست»: the fixture
     * seeds exactly that product so the old answer is available and refused.
     */
    public function testWithDokanGoneNeitherAProductNorAPartialRecordOpensTheGate(): void
    {
        $fake = $this->seedStagingShape();
        $fake->available = false;
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');

        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);

        $row = $completeness->forVendor(self::IMPORTED_EMPTY);
        self::assertSame(
            MigrationCompleteness::RECORDS_DOKAN_ABSENT,
            $row['detail']['records_state'],
            'Dokan gone has a name of its own, apart from «that source is not on this install»'
        );
        self::assertGreaterThan(
            0,
            (int) $row['detail']['products_observed'] + (int) $row['detail']['products_marketplace'],
            'the fixture must actually hold the product the old gate answered from'
        );
        self::assertFalse(
            $row['gates']['records_imported'],
            'holding a product is not evidence that the records arrived'
        );
    }

    /**
     * §3 «This source is not on this install» is a successful read, and it
     * settles.
     *
     * Dokan Lite has no vendor staff table at all. Dokan answered — through
     * `information_schema`, not through an inference about a past — that
     * nothing could ever write such a row, so nothing is outstanding. Kept
     * apart from `settled` in its own name, because «شمردیم و کامل است» and
     * «منبعی وجود ندارد» are two sentences.
     */
    public function testAnAbsentSourceIsSettledAndNamedApartFromACountedOne(): void
    {
        $fake = $this->seedStagingShape();
        $fake->absentSources = ['staff', 'balance', 'withdrawals'];
        $this->seedProfileOnlyTrace(self::IMPORTED_EMPTY, 'داروخانه چهل');

        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);

        $row = $completeness->forVendor(self::IMPORTED_EMPTY);
        self::assertSame(MigrationCompleteness::RECORDS_NO_SOURCE, $row['detail']['records_state']);
        self::assertSame(
            [
                'staff' => 'source_not_on_this_install',
                'balance' => 'source_not_on_this_install',
                'withdrawals' => 'source_not_on_this_install',
            ],
            $row['detail']['records_reasons'],
            'and WHY each kind is unchecked, which is the reason the three causes can be told apart'
        );
        self::assertTrue($row['gates']['records_imported'], 'there is no source, so nothing is left to bring over');
    }

    /**
     * §1 **The owner's own test: start from the state after the `alpha.77`
     * import.**
     *
     * Fourteen records present, and only two shops carrying a product or
     * profile trace. Ids 2, 4, 6, 8 and 16 must stay in the preview — their
     * records are kept and shown — and must NOT enter the shop completeness
     * table merely for owning them.
     *
     * ⛔ **A test of the state BEFORE the import was not enough, and that is
     * why the defect shipped.** `ShopRecordScopeTest` asserted the labels
     * before the run, where they were right; the criterion was
     * `vendorsWithRecords()`, so the run itself was what turned those five ids
     * into «imported shops». The assertion has to be made on the far side of
     * the run.
     */
    public function testAfterTheImportRecordOwnersStayVisibleAndStayOutOfTheShopTable(): void
    {
        $this->seedStagingShape();
        $this->seedProfileOnlyTrace(self::IMPORTED_EMPTY, 'داروخانه چهل');
        $this->seedImportTrace(self::IMPORTED_WITH_ROWS, 'DK-SCOPE-102');

        $before = $this->origins();
        foreach (self::SURPRISES as $id) {
            self::assertSame(ShopRecordScope::SOURCE_ONLY, $before[$id], 'id ' . $id . ' owns nothing here yet');
        }

        $this->runRecordImport();

        // The records really did land — otherwise everything below is a
        // statement about an import that never happened.
        self::assertSame(
            array_values(array_merge(self::SURPRISES, [self::IMPORTED_WITH_ROWS])),
            $this->ownersInOurTables(),
            'the fourteen rows of the staging run, owned by the six ids that owned them'
        );
        self::assertSame(
            14,
            $this->rowsInOurTables(),
            'fourteen records, the number the owner read off the finished run'
        );

        // AND the five are still listed, now as what they are.
        $after = $this->origins(true);
        foreach (self::SURPRISES as $id) {
            self::assertArrayHasKey($id, $after, 'id ' . $id . ' must stay in the preview after the run');
            self::assertSame(
                ShopRecordScope::RECORDS_ONLY,
                $after[$id],
                'id ' . $id . ' owns records and is not a shop: that is one label, not two'
            );
        }
        self::assertSame(
            self::SURPRISES,
            $this->scope()->recordOnlyIds(),
            'named, so they can never be a surprise again'
        );

        // AND the shop table is still about the two shops.
        self::assertSame(
            [self::IMPORTED_EMPTY, self::IMPORTED_WITH_ROWS],
            $this->shopIds(),
            'a shop is an id this marketplace created something for'
        );
        /** @var MigrationCompleteness $completeness */
        $completeness = Bootstrap::container()->get(MigrationCompleteness::class);
        self::assertSame(
            [self::IMPORTED_EMPTY, self::IMPORTED_WITH_ROWS],
            array_map(static fn (array $row): int => (int) $row['vendor_user_id'], $completeness->all()),
            'and the gates are asked of the shops only'
        );

        // AND the page agrees, in the one card that decides admission.
        $this->bindScope();
        $html = $this->renderHandover();
        $table = $this->completenessCard($html);
        foreach (self::SURPRISES as $id) {
            $fa = PersianDigits::toPersian((string) $id);
            self::assertStringNotContainsString(
                '#' . $fa . '<',
                $table,
                'id ' . $id . ' must not appear in the migration-completeness table'
            );
            self::assertStringContainsString(
                '#' . $fa,
                $html,
                'id ' . $id . ' must still be on the page, with its records'
            );
        }
        self::assertStringContainsString(
            'سابقهٔ این شناسه‌ها این‌طرف ثبت شده و هیچ‌کدام فروشگاه این بازارگاه نیستند',
            $html,
            'the page says, in one sentence, which ids hold records and are not shops'
        );
    }

    /**
     * §2 A row whose owner the page did not show is skipped, named, and not
     * written.
     *
     * ⛔ **The scope was shared with the preview and the button and not with
     * the walk.** The walk reads Dokan's own keysets, which hold every shop at
     * once; the two halves agreed only because the scope is a union of those
     * same tables. `hiddenOwners` is the case that separates them: the
     * enumeration succeeds — `readable` stays true — and comes back without
     * one id whose rows the walk still pages over, which is what a row
     * inserted between the two reads looks like.
     */
    public function testARowWhoseOwnerWasNeverShownIsSkippedRatherThanImported(): void
    {
        $fake = $this->seedStagingShape();
        $fake->hiddenOwners = [4];
        $this->seedProfileOnlyTrace(self::IMPORTED_EMPTY, 'داروخانه چهل');

        $shown = $this->scope()->ids();
        self::assertNotContains(4, $shown, 'the fixture must actually hide this owner from the list');
        self::assertContains(2, $shown, 'and hide only that one');
        self::assertTrue($this->scope()->readable(), 'a short list that reports itself complete is the case under test');

        $this->runRecordImport();

        $owners = $this->ownersInOurTables();
        self::assertNotContains(4, $owners, 'no record may be written for an owner nobody was shown');
        self::assertContains(2, $owners, 'and the owners that WERE shown are imported as before');
        self::assertSame(
            [],
            array_values(array_diff($owners, $shown)),
            'what was written is a subset of what was displayed'
        );
    }

    /**
     * §2 An unreadable scope admits nobody, and the page refuses the button.
     *
     * A short enumeration is exactly the case where the owners it failed to
     * name are the ones nobody has seen, so importing then would permit the
     * one thing the scope exists to stop. Two defences, and both are asserted:
     * the page will not offer the action and the walk writes nothing if it is
     * driven anyway.
     */
    public function testAnUnreadableScopeAdmitsNobodyAndTheButtonRefuses(): void
    {
        $fake = $this->seedStagingShape();
        $fake->unreadableSources = ['balance'];
        $this->seedProfileOnlyTrace(self::IMPORTED_EMPTY, 'داروخانه چهل');

        self::assertFalse($this->scope()->readable());
        self::assertFalse($this->scope()->admits(self::IMPORTED_EMPTY), 'not even a shop we imported ourselves');

        $html = $this->renderHandover();
        self::assertStringContainsString(
            'تا وقتی فهرست صاحبان سابقه در دکان کامل خوانده نشود، ورود انجام نمی‌شود',
            $html,
            'the page states the refusal rather than offering a button that would skip everything'
        );
        self::assertStringNotContainsString('value="import_records"', $html, 'and the button is not drawn');

        $this->runRecordImport();
        self::assertSame([], $this->ownersInOurTables(), 'and a run driven anyway writes nothing');
    }

    /**
     * What the widened scope costs, as a number rather than an adjective.
     *
     * ⛔ **The scope grew on purpose and the cost grew with it**, so the cost
     * is pinned instead of described. Measured, not assumed: `forVendor()`
     * asks Dokan's three sources — one read each with the real reader — and
     * our own held counts, which are **three** reads and not one, because
     * `importedCountsFor()` counts each of the three tables separately. So
     * the preview is six reads per id in production and three here, where
     * Dokan's side answers from the fixture. The first version of this test
     * asserted one and was wrong about our own repository.
     *
     * On this site the scope is seven ids; on a Dokan install with very many
     * sellers this page gets heavier in step with them, which is named as a
     * limit in `docs/phase-59-scope-and-settled-records.md` بند ۵ rather than
     * guessed at here.
     */
    public function testThePreviewCostsThreeOfOurReadsPerIdInScope(): void
    {
        $this->seedStagingShape();
        $this->seedImportTrace(self::IMPORTED_EMPTY, 'DK-SCOPE-40');
        $this->seedImportTrace(self::IMPORTED_WITH_ROWS, 'DK-SCOPE-102');

        /** @var ShopRecordPreview $preview */
        $preview = Bootstrap::container()->get(ShopRecordPreview::class);
        $ids = $this->scope()->ids();
        self::assertCount(7, $ids, 'the measurement is about THIS scope');

        $before = (int) $this->wpdb->num_queries;
        $preview->forVendors($ids);
        $spent = (int) $this->wpdb->num_queries - $before;

        // The fake reader answers Dokan's three sources in memory, so what is
        // counted here is OUR side: `importedCountsFor()`, one `COUNT(*)` per
        // record kind. Three per id, and a fourth would be a regression.
        self::assertSame(
            3 * count($ids),
            $spent,
            'three reads per id for the counts we hold, and not one more'
        );
    }

    /** How many record rows our three tables hold, in total. */
    private function rowsInOurTables(): int
    {
        $total = 0;
        foreach (M0017DokanShopRecords::TABLES as $suffix) {
            $total += (int) $this->wpdb->get_var(
                'SELECT COUNT(*) FROM ' . $this->wpdb->prefix . $suffix
            );
        }
        return $total;
    }

    /**
     * Just the migration-completeness card.
     *
     * Asserting «this id is not on the page» would be wrong — it must be, with
     * its records. The question is whether it is in the ONE table that says
     * whether a shop has been migrated, so the card is cut out and asked.
     */
    private function completenessCard(string $html): string
    {
        $title = 'وضعیت مهاجرت هر فروشگاه';
        $start = strpos($html, $title);
        self::assertIsInt($start, 'the completeness card must be on the page for this assertion to mean anything');
        $end = strpos($html, '</section>', (int) $start);
        self::assertIsInt($end);
        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function renderHandover(): string
    {
        $_GET['page'] = HandoverPage::SLUG;
        return $this->capture(static function (): void {
            (new HandoverPage(Bootstrap::container()))->render();
        });
    }

    /**
     * Run the record import the way production runs it: the real handler,
     * through `JobRunner`, with a real lease.
     *
     * Built here rather than taken from the container because the runner the
     * module registered holds an `ImportFromDokan` wired to `WpDokanReader`,
     * and `Container::get()` memoises — so the registered handler would read
     * an absent Dokan and write nothing, which no assertion below could tell
     * apart from a scope that happens to agree.
     */
    private function runRecordImport(int $passes = 20): void
    {
        $c = Bootstrap::container();
        $c->bind(ImportFromDokan::class, static fn ($c): ImportFromDokan => new ImportFromDokan(
            $c->get(DokanReaderInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(\Tecteb\Marketplace\Contracts\OptionStoreInterface::class),
            $c->get(\Tecteb\Marketplace\Core\Audit\AuditLogger::class),
            $c->get(\Tecteb\Marketplace\Contracts\ClockInterface::class),
            $c->get(\Tecteb\Marketplace\Contracts\CapabilityCheckerInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Migration\Application\OrderHistoryRepositoryInterface::class),
            $c->get(ShopRecordRepositoryInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Migration\Application\CategoryMap::class),
            null,
            // The admission list, on the WRITE path — the half `alpha.78`
            // left out. Without it this run would write whatever it read,
            // which is the behaviour the last three tests in this file are
            // about.
            $c->get(ShopRecordScope::class)
        ));

        /** @var JobRepositoryInterface $jobs */
        $jobs = $c->get(JobRepositoryInterface::class);
        $queued = $jobs->enqueue(
            DokanRecordImportJob::TYPE,
            ['run_id' => self::RUN, 'total' => 40],
            DokanRecordImportJob::TYPE . ':scope-test',
            1
        );
        self::assertGreaterThan(0, (int) ($queued['id'] ?? 0), 'the job must be queued');

        $runner = new JobRunner($jobs, static fn (): string => bin2hex(random_bytes(8)));
        $runner->register(new DokanRecordImportJob($c->get(ImportFromDokan::class)));
        for ($i = 0; $i < $passes; $i++) {
            if ($runner->run(1) === []) {
                break;
            }
        }
    }
}
