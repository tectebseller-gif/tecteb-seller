<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\DecideImportedShopSelling;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Presentation\Admin\VendorShopsPage;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;
use TmcWpStubs\State;

/**
 * The owner's six decisions, measured from the state staging is actually in.
 *
 * Every test here starts from a shop that arrived through the import: a
 * profile row carrying a run stamp with `can_sell = 0`, products linked as
 * `observed`, and NO application row — because the import writes none. That
 * shape is why the shop list showed one shop while forty profiles existed,
 * and why no screen could grant such a shop the right to sell.
 */
final class ImportedShopSellingTest extends DatabaseTestCase
{
    private const IMPORTED = 4071;
    private const APPLIED = 4072;
    private const WC_PRODUCT = 8871;
    private const RUN = 'run-imported-shop-selling';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        // A clean schema per test, because every test here writes.
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
        State::$users = [];
    }

    private function vendors(): VendorRepositoryInterface
    {
        return Bootstrap::container()->get(VendorRepositoryInterface::class);
    }

    private function service(): DecideImportedShopSelling
    {
        return Bootstrap::container()->get(DecideImportedShopSelling::class);
    }

    /**
     * The shop exactly as the import leaves it.
     *
     * One call to `upsertProfile()` with the same arguments
     * `ImportFromDokan::importVendorPage()` passes — `can_sell = false`, a run
     * id — and one `observed` product row. Nothing else: a fixture that
     * states values it is not testing invites the next reader to think they
     * matter.
     */
    private function seedImportedShop(int $vendorUserId = self::IMPORTED): void
    {
        $this->vendors()->upsertProfile($vendorUserId, 'فروشگاه واردشدهٔ آزمایشی', false, false, self::RUN);
        State::$users[$vendorUserId] = ['user_login' => 'imported-' . $vendorUserId];
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        $id = $products->create(
            $vendorUserId,
            new ProductDetails(title: 'سرنگ ۵ میلی‌لیتر', sku: 'DK-SELL-' . $vendorUserId, priceMinor: 90000, stock: 4),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            self::WC_PRODUCT + $vendorUserId,
            self::RUN
        );
        self::assertGreaterThan(0, $id, 'the fixture must create the observed row it is about');
    }

    /** A shop that came the ordinary way: an application, then approval. */
    private function seedAppliedShop(): void
    {
        $this->vendors()->saveDraft(self::APPLIED, new ApplicantDetails(
            storeName: 'فروشگاه تأییدشدهٔ آزمایشی',
            legalName: 'شرکت آزمایشی',
            contactEmail: 'applied@example.test',
            contactMobile: '09120000000'
        ));
        $this->vendors()->upsertProfile(self::APPLIED, 'فروشگاه تأییدشدهٔ آزمایشی', true, false, '');
        State::$users[self::APPLIED] = ['user_login' => 'applied'];
    }

    // ---- §1 the list ------------------------------------------------------

    /**
     * WHAT THIS PROVES: the list shows a shop that never applied.
     *
     * ⛔ The defect, measured on the disposable database before the fix: 40
     * profiles existed and the page listed 14 — every one of them a row in the
     * APPLICATIONS table with `status = 'approved'`. Planting one
     * imported-shaped profile left the count at 14 and the shop out of the
     * list, while its own detail view opened perfectly by direct address.
     */
    public function testTheListShowsAnImportedShopThatHasNoApplication(): void
    {
        $this->seedImportedShop();
        self::assertNull(
            $this->vendors()->findApplicationByUser(self::IMPORTED),
            'the shape this test is about is a shop with NO application row'
        );
        self::assertSame(
            [],
            $this->vendors()->approvedVendorUserIds(50, 0),
            'and the old predicate must still answer nothing, or this test proves nothing'
        );

        $rows = $this->vendors()->shopRows(50, 0);
        self::assertSame([self::IMPORTED], array_map(static fn (array $r): int => $r['user_id'], $rows));
        self::assertTrue($rows[0]['imported'], 'the run stamp is what says where it came from');
        self::assertFalse($rows[0]['can_sell'], 'and the import grants nothing');
        self::assertSame(1, $this->vendors()->countShops());
    }

    /** WHAT THIS PROVES: origin has two answers and they do not run together. */
    public function testOriginSeparatesTheImportedShopFromTheApprovedOne(): void
    {
        $this->seedImportedShop();
        $this->seedAppliedShop();

        $byId = [];
        foreach ($this->vendors()->shopRows(50, 0) as $row) {
            $byId[(int) $row['user_id']] = $row;
        }
        self::assertCount(2, $byId);
        self::assertTrue($byId[self::IMPORTED]['imported']);
        self::assertFalse($byId[self::APPLIED]['imported'], 'no stamp means it came through review');
        self::assertFalse($byId[self::IMPORTED]['can_sell']);
        self::assertTrue($byId[self::APPLIED]['can_sell'], 'and permission is a separate column');
    }

    /**
     * WHAT THIS PROVES: an imported shop can be found by the name it shows.
     *
     * ⛔ A second defect, not in the report: search went to the shop's own
     * SETTINGS row, and only the review route ever creates one. So an
     * imported shop could not be found by the very name the page printed for
     * it. The name shown and the name searched now come from one table.
     */
    public function testSearchFindsAnImportedShopByItsOwnName(): void
    {
        $this->seedImportedShop();
        $hits = $this->vendors()->searchShopsByName('واردشدهٔ', 50);
        self::assertSame([self::IMPORTED], array_map(static fn (array $r): int => $r['user_id'], $hits));
        self::assertTrue($hits[0]['imported']);
        self::assertSame([], $this->vendors()->searchShopsByName('نامی که وجود ندارد', 50));
    }

    /**
     * WHAT THIS PROVES: one statement for the page, not one per row.
     *
     * The page used to follow its id list with a `findProfileByUser()` each.
     * Counted rather than asserted as a sentence, and asserted as an
     * INVARIANT over two sizes, so the number cannot be a lucky constant.
     */
    public function testTheListCostsOneStatementWhateverItsLength(): void
    {
        $this->seedImportedShop();
        $before = $this->wpdb->num_queries;
        $this->vendors()->shopRows(50, 0);
        $one = $this->wpdb->num_queries - $before;

        for ($n = 2; $n <= 6; $n++) {
            $this->vendors()->upsertProfile(self::IMPORTED + $n, 'فروشگاه ' . $n, false, false, self::RUN);
        }
        $before = $this->wpdb->num_queries;
        $rows = $this->vendors()->shopRows(50, 0);
        $many = $this->wpdb->num_queries - $before;

        self::assertCount(6, $rows);
        self::assertSame(1, $one);
        self::assertSame($one, $many, 'six shops must cost what one shop costs');
    }

    // ---- §2/§3 the decision ----------------------------------------------

    /** WHAT THIS PROVES: a manager can grant it, and the trail says who. */
    public function testGrantingFlipsThePermissionAndWritesOneAuditRow(): void
    {
        $this->seedImportedShop();
        $before = $this->auditRows();

        $result = $this->service()->grant(self::IMPORTED);
        self::assertTrue($result->ok, $result->code);
        self::assertSame('selling_granted', $result->code);
        self::assertTrue($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
        self::assertSame($before + 1, $this->auditRows());
        self::assertSame('grant', $this->lastSellingDecision());
    }

    /** WHAT THIS PROVES: it is reversible, and the reverse is audited too. */
    public function testWithdrawingIsTheSameRouteInReverse(): void
    {
        $this->seedImportedShop();
        self::assertTrue($this->service()->grant(self::IMPORTED)->ok);
        $before = $this->auditRows();

        $result = $this->service()->withdraw(self::IMPORTED);
        self::assertTrue($result->ok, $result->code);
        self::assertSame('selling_withdrawn', $result->code);
        self::assertFalse($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
        self::assertSame($before + 1, $this->auditRows());
        self::assertSame('withdraw', $this->lastSellingDecision());
    }

    /**
     * WHAT THIS PROVES: withdrawing drafts nothing.
     *
     * `ReviewApplication::suspend()` drafts a shop's products on purpose. This
     * route does not: drafting rows as a side effect of a permission change is
     * a data change nobody asked for, and a non-selling vendor's products
     * already refuse the purchase through the policy.
     */
    public function testWithdrawingDoesNotTouchOneProductRow(): void
    {
        $this->seedImportedShop();
        self::assertTrue($this->service()->grant(self::IMPORTED)->ok);
        $before = $this->productShape();

        self::assertTrue($this->service()->withdraw(self::IMPORTED)->ok);
        self::assertSame($before, $this->productShape(), 'statuses and ownership must be byte-identical');
    }

    /**
     * WHAT THIS PROVES: the act does not transfer ownership of anything.
     *
     * «این اقدام نباید به‌طور ضمنی مالکیت محصولات را منتقل کند» — asked of the
     * data rather than of the code, because the architecture test only proves
     * the file names no writer.
     */
    public function testGrantingTransfersNoOwnership(): void
    {
        $this->seedImportedShop();
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        $before = $products->ownershipTallyForVendor(self::IMPORTED);
        self::assertSame(0, (int) $before['marketplace'], 'the fixture is an imported shop: nothing transferred');
        self::assertSame(1, (int) $before['observed']);

        self::assertTrue($this->service()->grant(self::IMPORTED)->ok);
        self::assertSame($before, $products->ownershipTallyForVendor(self::IMPORTED));
    }

    /** WHAT THIS PROVES: nothing happens without the capability. */
    public function testWithoutTheReviewCapabilityNothingIsWrittenOrAudited(): void
    {
        $this->seedImportedShop();
        State::loginAs(1, ['manage_options', Capabilities::VIEW_VENDOR_SHOPS]);
        $before = $this->auditRows();

        $result = $this->service()->grant(self::IMPORTED);
        self::assertFalse($result->ok);
        self::assertSame('forbidden', $result->code);
        self::assertFalse($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
        self::assertSame($before, $this->auditRows(), 'a refused act leaves no trail of a change');
    }

    /**
     * WHAT THIS PROVES: an applicant's shop is not decided from this route.
     *
     * ⛔ The first version of this test expected `application_exists`, and the
     * run returned `not_an_imported_shop` — which is the RIGHT answer for this
     * fixture, because a shop that came through review carries no run stamp
     * and the stamp is asked about first. The test had named the wrong one of
     * two correct refusals; the dangerous case the other code is actually for
     * is the test below.
     */
    public function testAShopWithAnApplicationIsSentToTheReviewRoute(): void
    {
        $this->seedAppliedShop();
        $result = $this->service()->withdraw(self::APPLIED);
        self::assertFalse($result->ok);
        self::assertSame('not_an_imported_shop', $result->code);
        self::assertTrue(
            $this->vendors()->findProfileByUser(self::APPLIED)?->canSell,
            'and its permission is untouched, so the two routes never both write'
        );
    }

    /**
     * WHAT THIS PROVES: the one case where both routes could reach one column.
     *
     * An imported shop whose vendor later applied: the run stamp says this
     * route may act, and the application says the review route may too. Two
     * writers on one column with no knowledge of each other is the thing the
     * refusal exists for, so this route stands down and names where to go.
     */
    public function testAnImportedShopThatLaterAppliedIsLeftToTheReviewRoute(): void
    {
        $this->seedImportedShop();
        $this->vendors()->saveDraft(self::IMPORTED, new ApplicantDetails(
            storeName: 'فروشگاه واردشدهٔ آزمایشی',
            legalName: 'شرکت واردشده',
            contactEmail: 'imported@example.test',
            contactMobile: '09121111111'
        ));
        $before = $this->auditRows();

        $result = $this->service()->grant(self::IMPORTED);
        self::assertFalse($result->ok);
        self::assertSame('application_exists', $result->code);
        self::assertFalse($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
        self::assertSame($before, $this->auditRows());
    }

    /** WHAT THIS PROVES: a shop with no run stamp is not this route's subject. */
    public function testAShopThatWasNeverImportedIsRefusedByName(): void
    {
        // A profile with no stamp AND no application: neither route's subject,
        // and this route must still say which one it is not.
        $this->vendors()->upsertProfile(4099, 'فروشگاه بی‌منشأ', false, false, '');
        State::$users[4099] = ['user_login' => 'unstamped'];
        $result = $this->service()->grant(4099);
        self::assertFalse($result->ok);
        self::assertSame('not_an_imported_shop', $result->code);
    }

    /** WHAT THIS PROVES: a permission for nobody is not a permission. */
    public function testAnImportedShopWithNoUserAccountIsRefused(): void
    {
        $this->seedImportedShop();
        unset(State::$users[self::IMPORTED]);
        $result = $this->service()->grant(self::IMPORTED);
        self::assertFalse($result->ok);
        self::assertSame('no_user_account', $result->code);
        self::assertFalse($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
    }

    /** WHAT THIS PROVES: there is nothing to decide about a shop that is not there. */
    public function testAnUnknownShopIsRefused(): void
    {
        $result = $this->service()->grant(999777);
        self::assertFalse($result->ok);
        self::assertSame('shop_not_found', $result->code);
    }

    /**
     * WHAT THIS PROVES: an act with nothing to do says so and writes nothing.
     *
     * Not an error — the manager asked for a state the shop is already in —
     * and not a success story either: no row moves and no audit line claims
     * one did.
     */
    public function testRepeatingTheGrantWritesNothingAndAuditsNothing(): void
    {
        $this->seedImportedShop();
        self::assertTrue($this->service()->grant(self::IMPORTED)->ok);
        $before = $this->auditRows();

        $again = $this->service()->grant(self::IMPORTED);
        self::assertTrue($again->ok);
        self::assertSame('already_granted', $again->code);
        self::assertSame($before, $this->auditRows());
    }

    /**
     * WHAT THIS PROVES: the guard lives inside the statement.
     *
     * A second manager grants the same permission in the window between this
     * one's read and its write. The expected value travels in the `WHERE`, so
     * the write matches no row, the row is re-read, and nothing is claimed —
     * and critically no audit line is written for a change this call did not
     * make (`alpha.50`).
     */
    public function testAPermissionThatMovedMidWayIsReportedNotClaimed(): void
    {
        $this->seedImportedShop();
        $c = Bootstrap::container();
        $real = new WpDatabase($this->wpdb);
        $interfering = new InterferingDatabase($real);
        $interfering->before(['UPDATE', 'can_sell = ', 'WHERE user_id'], 1, function () use ($real): void {
            // The other manager, on the raw gateway: this is the state the
            // service is about to assume has not changed.
            $real->execute(
                'UPDATE `' . $this->wpdb->prefix . 'tmc_vendor_profiles` SET can_sell = 1 WHERE user_id = %d',
                [self::IMPORTED]
            );
        });
        $vendors = new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository(
            $interfering,
            $c->get(\Tecteb\Marketplace\Contracts\ClockInterface::class)
        );
        $service = new DecideImportedShopSelling(
            $vendors,
            $c->get(\Tecteb\Marketplace\Core\Audit\AuditLogger::class),
            $c->get(\Tecteb\Marketplace\Contracts\CapabilityCheckerInterface::class),
            $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface::class)
        );
        $before = $this->auditRows();

        $result = $service->grant(self::IMPORTED);
        self::assertFalse($result->ok);
        self::assertSame('not_stored', $result->code);
        self::assertTrue((bool) $result->context['can_sell'], 'and it reports what the row holds NOW');
        self::assertSame($before, $this->auditRows(), 'no trail for a change this call did not make');
    }

    /**
     * WHAT THIS PROVES: the expected value in the statement refuses a wrong
     * claim — and the POLARITY of this test is the whole measurement.
     *
     * ⛔ The first version wrote `false` while claiming the row held `true`,
     * and the falsification loop showed the mutation «take the expected value
     * out of the statement» SURVIVING it. The reason is `alpha.78`'s rule: the
     * un-guarded statement satisfied the assertion too, because MySQL counts
     * CHANGED rows and writing `false` onto a row already holding `false`
     * changes nothing — zero either way. The fixture had built a state the
     * old code also passed.
     *
     * Writing `true` on a wrong claim is the case that separates them: with
     * the guard no row matches and the permission stays withheld; without it
     * one row changes and a shop is granted the right to sell on an
     * expectation nobody checked.
     */
    public function testTheWriteRefusesWhenTheExpectedValueIsWrong(): void
    {
        $this->seedImportedShop();
        // The row holds false. Claim it already holds true, and ask for true.
        self::assertSame(0, $this->vendors()->setSellingPermission(self::IMPORTED, true, true));
        self::assertFalse(
            $this->vendors()->findProfileByUser(self::IMPORTED)?->canSell,
            'a wrong expectation must not grant the right to sell'
        );
        // And with the right expectation it moves exactly one row.
        self::assertSame(1, $this->vendors()->setSellingPermission(self::IMPORTED, true, false));
        self::assertTrue($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
        // The mirror case, so the guard is pinned in both directions.
        self::assertSame(0, $this->vendors()->setSellingPermission(self::IMPORTED, false, false));
        self::assertTrue($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
        self::assertSame(1, $this->vendors()->setSellingPermission(self::IMPORTED, false, true));
        self::assertFalse($this->vendors()->findProfileByUser(self::IMPORTED)?->canSell);
    }

    /**
     * WHAT THIS PROVES: a permission change rewrites no other field.
     *
     * `upsertProfile()` would also have written the store name and the
     * direct-publish switch. This writer touches one column.
     */
    public function testTheDecisionRewritesNoOtherProfileField(): void
    {
        $this->seedImportedShop();
        $this->vendors()->upsertProfile(self::IMPORTED, 'نامی که مدیر گذاشته', false, true, '');
        self::assertTrue($this->service()->grant(self::IMPORTED)->ok);

        $profile = $this->vendors()->findProfileByUser(self::IMPORTED);
        self::assertSame('نامی که مدیر گذاشته', $profile?->storeName);
        self::assertTrue($profile?->canPublishDirectly, 'the direct-publish switch is not this act\'s business');
        self::assertSame(self::RUN, $this->vendors()->importRunIdFor(self::IMPORTED), 'and the stamp survives');
    }

    // ---- §4 the money split ----------------------------------------------

    /**
     * WHAT THIS PROVES: widening the list did not widen financial access.
     *
     * The same manager, the same shop, two capability sets. The list is
     * readable in both; the figures only in one — and the refusal is NAMED,
     * because a figure that is simply absent reads as a zero.
     */
    public function testTheFiguresNeedTheirOwnCapabilityWhileTheListDoesNot(): void
    {
        $this->seedImportedShop();
        $page = new VendorShopsPage(Bootstrap::container());
        $_GET = ['page' => VendorShopsPage::SLUG, 'shop' => (string) self::IMPORTED];

        State::loginAs(1, ['manage_options', Capabilities::VIEW_VENDOR_SHOPS, Capabilities::VIEW_VENDOR_FINANCE]);
        $withFinance = $this->capture(static fn () => $page->render());
        self::assertStringContainsString('کل درآمد ثبت‌شده', $withFinance);

        State::loginAs(1, ['manage_options', Capabilities::VIEW_VENDOR_SHOPS]);
        $withoutFinance = $this->capture(static fn () => $page->render());
        self::assertStringNotContainsString('کل درآمد ثبت‌شده', $withoutFinance);
        self::assertStringContainsString('مجوز مدیریتی جداگانه', $withoutFinance);
        // And the shop itself is still readable: the split withheld the money,
        // not the page.
        self::assertStringContainsString('فروشگاه واردشدهٔ آزمایشی', $withoutFinance);
        $_GET = [];
    }

    /** WHAT THIS PROVES: the list itself renders the imported shop and its origin. */
    public function testThePageListsTheImportedShopWithItsOrigin(): void
    {
        $this->seedImportedShop();
        $page = new VendorShopsPage(Bootstrap::container());
        $_GET = ['page' => VendorShopsPage::SLUG];
        State::loginAs(1, ['manage_options', Capabilities::VIEW_VENDOR_SHOPS]);

        $html = $this->capture(static fn () => $page->render());
        self::assertStringContainsString('فروشگاه واردشدهٔ آزمایشی', $html);
        self::assertStringContainsString('واردشده از دکان', $html);
        self::assertStringContainsString('اجازهٔ فروش', $html);
        $_GET = [];
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * The trail as the harness stores it.
     *
     * ⛔ The first version of these two helpers counted rows in
     * `wp_tmc_audit_events`, and every audit assertion failed against a
     * service that was logging perfectly: this harness binds a recording
     * double for the audit repository, so no row ever reaches the table here.
     * The fixture was measuring the wrong store — `alpha.55`'s rule in a new
     * place: ask the thing that actually holds the answer.
     */
    private function auditRows(): int
    {
        return count($this->audit->records);
    }

    /** The decision recorded by the newest selling-decision audit row. */
    private function lastSellingDecision(): string
    {
        $found = '';
        foreach ($this->audit->records as $record) {
            if ($record->eventType === AuditEventCatalog::VENDOR_SELLING_DECIDED) {
                $found = (string) ($record->payload['decision'] ?? '');
            }
        }
        return $found;
    }

    /**
     * Every product row's status and ownership, as one comparable value.
     *
     * Read from the table rather than through a repository, because the
     * question is whether anything moved at all.
     *
     * @return list<array<string,string>>
     */
    private function productShape(): array
    {
        return $this->wpdb->get_results(
            'SELECT id, status, link_ownership FROM ' . $this->wpdb->prefix . 'tmc_products ORDER BY id ASC',
            'ARRAY_A'
        ) ?: [];
    }
}
