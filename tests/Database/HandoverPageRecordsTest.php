<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordPreview;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordScope;
use Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\HandoverPage;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffUserDirectoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;

/**
 * §3: an imported shop is visible on the handover page even with no records,
 * and nothing on that page tells the manager to run the product import again.
 *
 * ⛔ **The defect this is for.** `render()` asked
 * `ShopRecordRepositoryInterface::vendorsWithRecords()` — a `UNION` of the
 * three Dokan history tables — and short-circuited to an empty state when it
 * came back empty. On every install that has ever existed it came back empty,
 * because nothing in production ever wrote a record: the method that writes
 * them had no caller. So the one screen about handing a shop over said «هنوز
 * سابقه‌ای وارد نشده است» and offered a button to the product import, which
 * could not have helped — the owner's job #1 had already finished and
 * imported 184 products.
 *
 * The universe is now `MigrationCompleteness::vendorUserIds()`, which unions
 * the three import traces: the records, the product stamp (migration 14) and
 * the profile stamp (migration 16). A shop whose products arrived is an
 * imported shop.
 */
final class HandoverPageRecordsTest extends DatabaseTestCase
{
    private const SHOP = 471;
    private const WC_PRODUCT = 8801;
    private const RUN = 'run-with-products-only';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        // ⛔ A clean schema per test, because this class WRITES. The first
        // version left it out and the rows of one test turned up inside the
        // next: the empty-state test saw a shop the previous test had
        // imported and reported a page that was perfectly right as wrong,
        // while the create of the second test hit the SKU's unique key and
        // returned nought.
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
    }

    /**
     * Put Dokan's data behind the reader the container hands out.
     *
     * The stub environment has no Dokan, and `WpDokanReader::isAvailable()`
     *answers false — which is the right answer there and useless for testing the
     * four verdicts. The fake is bound before the preview is ever built,
     * because `Container::get()` memoises instances; the preview is rebound
     * for the same reason, since binding the reader does not clear a preview
     * that already holds the old one.
     */
    private function useFakeDokan(): FakeDokanReader
    {
        $fake = new FakeDokanReader();
        $c = Bootstrap::container();
        $c->bind(DokanReaderInterface::class, static fn (): FakeDokanReader => $fake);
        $c->bind(
            ShopRecordPreview::class,
            static fn ($c): ShopRecordPreview => new ShopRecordPreview(
                $c->get(DokanReaderInterface::class),
                $c->get(ShopRecordRepositoryInterface::class)
            )
        );
        // ⛔ And the completeness and the scope too, for the same memo
        // reason — but the cost of leaving them out changed with
        // `alpha.79`, so it is written down. Until then an unreadable scope
        // only printed a notice, so a page whose PREVIEW read the fake while
        // its SCOPE still held `WpDokanReader` looked right; now an
        // unreadable scope also withholds the import button, and this test
        // reported a defect about a page that was behaving correctly for the
        // state it had been given. That state cannot occur in production —
        // there is one reader — so the fixture was wrong, not the page.
        $c->bind(
            MigrationCompleteness::class,
            static fn ($c): MigrationCompleteness => new MigrationCompleteness(
                $c->get(ShopRecordRepositoryInterface::class),
                $c->get(StaffRoleMap::class),
                $c->get(ReconcileDokanFinance::class),
                $c->get(VendorRepositoryInterface::class),
                $c->get(ProductRepositoryInterface::class),
                $c->get(DokanReaderInterface::class),
                $c->get(CategoryMap::class),
                $c->get(ShopRecordPreview::class)
            )
        );
        $c->bind(
            ShopRecordScope::class,
            static fn ($c): ShopRecordScope => new ShopRecordScope(
                $c->get(MigrationCompleteness::class),
                $c->get(DokanReaderInterface::class),
                $c->get(VendorRepositoryInterface::class),
                $c->has(StaffUserDirectoryInterface::class)
                    ? $c->get(StaffUserDirectoryInterface::class)
                    : null
            )
        );
        return $fake;
    }

    /**
     * A shop with a product stamp and NOT one record of any kind.
     *
     * The exact state staging is in: products imported, records never, because
     * the record import had no caller.
     */
    private function seedProductOnlyImport(): void
    {
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        // ONE call, the same one the intake makes: an observed row pointing at
        // an existing WooCommerce product and stamped with its run. Every
        // other field keeps its default, because a fixture that states values
        // it is not testing invites the next reader to think they matter.
        $id = $products->create(
            self::SHOP,
            new ProductDetails(
                title: 'باند کشی',
                sku: 'DK-HANDOVER-1',
                priceMinor: 120000,
                stock: 3
            ),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            self::WC_PRODUCT,
            self::RUN
        );
        self::assertGreaterThan(0, $id, 'the fixture must create a marketplace row');
        // ⛔ And the stamp is what makes this shop «imported», so the fixture
        // checks the trace it is spending rather than assuming it.
        self::assertContains(
            $id,
            array_map('intval', $products->idsFromImportRun(self::RUN)),
            'the product stamp is the trace this whole test hangs on'
        );
    }

    private function render(): string
    {
        $_GET['page'] = HandoverPage::SLUG;
        return $this->capture(static function (): void {
            (new HandoverPage(Bootstrap::container()))->render();
        });
    }

    public function testAShopWithProductsAndNoRecordsIsListed(): void
    {
        $this->seedProductOnlyImport();

        $html = $this->render();

        self::assertStringContainsString(
            '#' . \Tecteb\Marketplace\Core\Support\PersianDigits::toPersian((string) self::SHOP),
            $html,
            'the imported shop must appear on the page about handing it over'
        );
        self::assertStringContainsString('پیش‌نمایش سوابق فروشگاه', $html, 'the record preview is the first section');
    }

    /** ⛔ The sentence that sent a manager back to a finished import is gone. */
    public function testThePageNeverTellsTheManagerToRunTheProductImportAgain(): void
    {
        $this->seedProductOnlyImport();

        $html = $this->render();

        self::assertStringNotContainsString('هنوز سابقه‌ای وارد نشده است', $html);
        // ⛔ The needle is the empty state's own BUTTON LABEL, not
        // `page=tmc-import`: that address is in the navigation of every admin
        // page this plugin draws, so asserting its absence would be asserting
        // something about the menu and would fail on a page that is right.
        // The substring trap of `alpha.33`, met again.
        self::assertStringNotContainsString(
            'رفتن به مهاجرت از دکان',
            $html,
            'an imported shop needs no call to action back to the import it already completed'
        );
    }

    /** The one explicit action, and it is not pressed by drawing the page. */
    public function testTheImportButtonIsOfferedAndNothingIsImportedByRendering(): void
    {
        $this->seedProductOnlyImport();
        $this->seedDokanRecords();

        $before = $this->recordRows();
        $html = $this->render();

        self::assertStringContainsString('value="import_records"', $html, 'the manager gets a button');
        self::assertSame($before, $this->recordRows(), 'drawing the page imported nothing');
    }

    /**
     * Four answers, and «خواندن ناموفق» is never «صفر سابقه».
     *
     * The page is rendered with a reader whose balance read fails, so the
     * sentence on the row is the one the owner asked for.
     */
    public function testAFailedSourceReadIsSaidOnThePageAndNotShownAsZero(): void
    {
        $this->seedProductOnlyImport();
        $this->seedDokanRecords()->unreadableSources = ['balance'];

        $html = $this->render();

        self::assertStringContainsString('خواندن ناموفق', $html);
        self::assertStringContainsString('این «صفر» نیست', $html, 'the page says what the empty cell means');
    }

    /** With no imported shop at all, the empty state is honest about THAT. */
    public function testWithNoImportedShopTheEmptyStateSaysSo(): void
    {
        $html = $this->render();

        self::assertStringContainsString('هنوز هیچ فروشگاهی از دکان وارد نشده است', $html);
        // And here the call to action IS right, because there is genuinely
        // nothing imported and the import is the next step. Asserted on the
        // same needle as its absence above, so the pair cannot disagree.
        self::assertStringContainsString('رفتن به مهاجرت از دکان', $html);
        self::assertStringContainsString('page=' . MigrationPage::SLUG, $html);
    }

    private function seedDokanRecords(): FakeDokanReader
    {
        $fake = $this->useFakeDokan();
        $fake->vendorRows = [
            ['user_id' => self::SHOP, 'store_name' => 'داروخانه', 'email' => 's@example.test', 'enabled' => true],
        ];
        $fake->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SHOP, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000', 'credit' => '0.0000', 'status' => 'approved'],
        ];
        return $fake;
    }

    private function recordRows(): int
    {
        $total = 0;
        foreach (\Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords::TABLES as $suffix) {
            $total += (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->wpdb->prefix . $suffix);
        }
        return $total;
    }
}
