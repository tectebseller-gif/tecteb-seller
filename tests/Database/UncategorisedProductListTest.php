<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMapping;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\HandoverPage;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;
use Tecteb\Marketplace\Tests\Support\FakeProductCategoryDirectory;
use Tecteb\Marketplace\Tests\Support\FakeStorefrontFields;
use TmcWpStubs\State;

/**
 * The waiting products, NAMED on the page about handing the migration over.
 *
 * ⛔ **The defect this is for.** Until `alpha.88` «آنچه اعمال نمی‌شود، و
 * دلیلش» printed a reason and a count and nothing else, so a manager told
 * «four of 184 are left» had no way to reach those four but to search 184
 * products by hand. The rows were in `plan()['held']` all along and were
 * thrown away by the renderer.
 *
 * The list is built from the rows `CategoryMapping::plan()` already walked —
 * `importedWithoutCategory()`, the same predicate
 * `countImportedWithoutCategory()` counts and the gate reads. «فهرست باید
 * دقیقاً از همان داده‌هایی ساخته شود که شمارنده دروازه استفاده می‌کند.»
 *
 * The two ways in are gated SEPARATELY and neither is ever drawn dead: the
 * WooCommerce editor (where core's own `edit_post` decides, by giving no
 * link at all) and this plugin's review screen (behind `REVIEW_PRODUCTS`,
 * which this page's own `REVIEW_VENDOR` does not imply).
 */
final class UncategorisedProductListTest extends DatabaseTestCase
{
    private const SHOP = 612;
    private const OTHER_SHOP = 613;
    private const WC_PRODUCT = 9701;
    private const RUN = 'run-uncategorised-list';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        // A clean schema per test, because this class writes rows (`alpha.79`:
        // the rows of one test turning up inside the next reports a page that
        // is right as wrong).
        $this->resetSchema(new WpDatabase($this->wpdb));
        Bootstrap::container()->get(MigrationRunner::class)->run();
    }

    /**
     * Dokan's side, plus a WooCommerce category directory.
     *
     * Bound before `CategoryMapping` is ever resolved, and the mapping is
     * rebound as well, because `Container::get()` memoises: binding the reader
     * does not replace a mapping that already holds the old one.
     */
    private function useFakes(): FakeDokanReader
    {
        $dokan = new FakeDokanReader();
        $directory = new FakeProductCategoryDirectory();
        $directory->terms = [71 => ['name' => 'تجهیزات', 'path' => 'پزشکی › تجهیزات']];

        $c = Bootstrap::container();
        $c->bind(DokanReaderInterface::class, static fn (): FakeDokanReader => $dokan);
        $c->bind(
            ProductCategoryDirectoryInterface::class,
            static fn (): FakeProductCategoryDirectory => $directory
        );
        $c->bind(CategoryMapping::class, static fn ($c): CategoryMapping => new CategoryMapping(
            $c->get(CategoryMap::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(DokanReaderInterface::class),
            $c->get(ProductCategoryDirectoryInterface::class)
        ));
        return $dokan;
    }

    /** The storefront reader the page asks for the editor link. */
    private function useStorefront(string $editorUrl): FakeStorefrontFields
    {
        $fake = new FakeStorefrontFields(
            Bootstrap::container()->get(ProductRepositoryInterface::class)
        );
        $fake->editorUrl = $editorUrl;
        Bootstrap::container()->bind(
            StorefrontFieldsInterface::class,
            static fn (): FakeStorefrontFields => $fake
        );
        return $fake;
    }

    /**
     * One imported row with nothing recorded — the shape of the owner's four.
     *
     * The same single call the intake makes: an observed row pointing at an
     * existing WooCommerce product, stamped with its run, and carrying no
     * category of any kind.
     */
    private function seedWaitingProduct(
        int $shop = self::SHOP,
        int $wcProductId = self::WC_PRODUCT,
        string $title = 'باند کشی',
        string $sku = 'UNCAT-1'
    ): int {
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        $id = $products->create(
            $shop,
            new ProductDetails(title: $title, sku: $sku, priceMinor: 120000, stock: 3),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            $wcProductId,
            self::RUN
        );
        self::assertGreaterThan(0, $id, 'the fixture must create a marketplace row');
        self::assertSame(
            '',
            (string) ($products->find($id)?->details->categoryKey ?? 'x'),
            'the fixture row must be one the counter counts'
        );
        return $id;
    }

    /**
     * The held table ALONE, sliced out of the page.
     *
     * ⛔ Every row assertion goes through this, and falsification is why: the
     * shop id `#۶۱۲` is printed by three other sections of this page, so
     * `assertStringContainsString` against the whole document passed happily
     * with the shop column blanked out — the mutation survived. The
     * substring trap of `alpha.33`, met on a page with seven tables.
     */
    private function heldTable(string $html): string
    {
        $marker = 'aria-label="جدول محصولات بی‌دسته"';
        $at = strpos($html, $marker);
        self::assertIsInt($at, 'the waiting-products table must be on the page');
        $end = strpos($html, '</table>', $at);
        self::assertIsInt($end);
        return substr($html, $at, $end - $at);
    }

    private function render(): string
    {
        $_GET['page'] = HandoverPage::SLUG;
        return $this->capture(static function (): void {
            (new HandoverPage(Bootstrap::container()))->render();
        });
    }

    /** @return array<string,string> every product row, as a digest */
    private function productRows(): array
    {
        $rows = (new WpDatabase($this->wpdb))->getResults(
            'SELECT id, category_key, title, vendor_user_id, row_version
               FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC'
        );
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(string) $row['id']] = implode('|', array_map('strval', $row));
        }
        return $out;
    }

    /** §2: the name, the WooCommerce id and the shop, for each waiting row. */
    public function testEachWaitingProductIsNamedWithItsShopAndItsWooCommerceId(): void
    {
        $this->useFakes();
        $this->seedWaitingProduct();

        $html = $this->render();

        self::assertStringContainsString('آنچه اعمال نمی‌شود، و دلیلش', $html, 'the section is still there');
        $table = $this->heldTable($html);
        self::assertStringContainsString('باند کشی', $table, 'the product is named');
        self::assertStringContainsString(
            '#' . PersianDigits::toPersian((string) self::WC_PRODUCT),
            $table,
            'its WooCommerce id is printed'
        );
        self::assertStringContainsString(
            '#' . PersianDigits::toPersian((string) self::SHOP),
            $table,
            'and the shop that owns it — in THIS table, not elsewhere on the page'
        );
        // The four column headings the owner asked for.
        foreach (['محصول', 'شناسهٔ ووکامرس', 'فروشگاه', 'ویرایش در ووکامرس', 'بررسی در بازارگاه'] as $heading) {
            self::assertStringContainsString($heading, $html, 'column: ' . $heading);
        }
    }

    /** A row with no title is still reachable: it is named by its own id. */
    public function testAProductWithNoTitleIsStillNamed(): void
    {
        $this->useFakes();
        $id = $this->seedWaitingProduct(title: '', sku: 'UNCAT-NO-TITLE');

        $html = $this->render();

        self::assertStringContainsString(
            'بدون عنوان — محصول #' . PersianDigits::toPersian((string) $id),
            $this->heldTable($html),
            'an untitled product must not render as an empty cell'
        );
    }

    /**
     * §5 on the page: the rows shown are the rows the figure counts.
     *
     * The card prints its own total from `countImportedWithoutCategory()`; the
     * table prints the rows of `importedWithoutCategory()`. Two shops are
     * seeded so the assertion is about a SET and not about one row.
     */
    public function testTheRowsShownAreTheRowsTheFigureCounts(): void
    {
        $this->useFakes();
        $first = $this->seedWaitingProduct();
        $second = $this->seedWaitingProduct(
            shop: self::OTHER_SHOP,
            wcProductId: self::WC_PRODUCT + 1,
            title: 'سرنگ',
            sku: 'UNCAT-2'
        );

        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        self::assertSame(2, $products->countImportedWithoutCategory(), 'the figure the card prints');

        $html = $this->render();

        self::assertStringContainsString(
            'همین حالا ' . PersianDigits::toPersian('2') . ' محصول',
            $html,
            'the card states the global figure'
        );
        $table = $this->heldTable($html);
        self::assertStringContainsString('باند کشی', $table);
        self::assertStringContainsString('سرنگ', $table);
        self::assertStringContainsString('#' . PersianDigits::toPersian((string) self::SHOP), $table);
        self::assertStringContainsString('#' . PersianDigits::toPersian((string) self::OTHER_SHOP), $table);
        self::assertNotSame($first, $second);
    }

    /** §4: no WooCommerce product behind the row ⇒ no link, and it says why. */
    public function testARowWithNoWooCommerceProductGetsNoEditLinkAndASaidReason(): void
    {
        $this->useFakes();
        $this->useStorefront('https://example.test/wp-admin/post.php?post=1&action=edit');
        /** @var ProductRepositoryInterface $products */
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        $id = $products->create(
            self::SHOP,
            new ProductDetails(title: 'بی‌پست', sku: 'UNCAT-NOWC', priceMinor: 1000, stock: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            0,
            self::RUN
        );
        self::assertGreaterThan(0, $id);

        $html = $this->render();

        self::assertStringContainsString('محصول ووکامرسی ندارد', $this->heldTable($html), 'the reason is named');
        self::assertStringNotContainsString('post.php?post=1', $html, 'and no editor link is drawn');
    }

    /** §4: WooCommerce absent ⇒ no link, and a different reason. */
    public function testNoWooCommerceMeansNoEditLinkAndItsOwnReason(): void
    {
        $this->useFakes();
        // The binding is nullable by design, and in this environment there is
        // no WooCommerce — so this is the production answer, not a stub trick.
        Bootstrap::container()->bind(StorefrontFieldsInterface::class, static fn () => null);
        $this->seedWaitingProduct();

        $html = $this->render();

        self::assertStringContainsString('ووکامرس در دسترس نیست', $this->heldTable($html));
        self::assertStringNotContainsString('ویرایش در ووکامرس</a>', $html);
    }

    /** §4: the reader gave nothing ⇒ no link, and both causes are said. */
    public function testAnEditorThatGivesNothingIsReportedRatherThanLinked(): void
    {
        $this->useFakes();
        $this->useStorefront('');
        $this->seedWaitingProduct();

        $html = $this->render();

        self::assertStringContainsString('پستش پیدا نشد یا مجوز ویرایشش را ندارید', $this->heldTable($html));
        self::assertStringNotContainsString('<a href="" ', $html, 'never an empty href');
    }

    /** §3: the editor link is offered when the reader hands one out. */
    public function testTheEditorLinkIsOfferedWhenThereIsOne(): void
    {
        $this->useFakes();
        $url = 'https://example.test/wp-admin/post.php?post=9701&action=edit';
        $this->useStorefront($url);
        $this->seedWaitingProduct();

        $html = $this->render();

        $table = $this->heldTable($html);
        self::assertStringContainsString(esc_url($url), $table, 'the direct edit link is printed');
        self::assertStringContainsString('ویرایش در ووکامرس', $table);
    }

    /**
     * §3: the marketplace review link is behind ITS OWN capability.
     *
     * A manager who may review vendors — which is what opens this page — does
     * not thereby may review products. Printing the address anyway would put a
     * screen in front of somebody who cannot open it.
     */
    public function testTheReviewLinkIsWithheldFromAManagerWhoMayNotReviewProducts(): void
    {
        $this->useFakes();
        $id = $this->seedWaitingProduct();
        State::loginAs(77, ['manage_options', Capabilities::REVIEW_VENDOR]);

        $html = $this->render();

        self::assertStringContainsString('مجوز بررسی محصولات را ندارید', $this->heldTable($html), 'the refusal is named');
        self::assertStringNotContainsString(
            ProductReviewPage::SLUG . '&product=' . $id,
            $html,
            'and the address is not printed'
        );
        // ⛔ The control, and it is the measured answer rather than the one I
        // first expected: this environment DOES bind the real reader (the stub
        // defines a `WooCommerce` class), so `get_edit_post_link()` is what
        // answers — and it gives nothing here. The point stands and is the
        // point of the column: our capability moved the marketplace cell and
        // left the WooCommerce cell exactly as it was.
        self::assertStringContainsString('پستش پیدا نشد یا مجوز ویرایشش را ندارید', $html);
    }

    /** The other side of the same rule: with the capability, the link is there. */
    public function testTheReviewLinkIsOfferedToAManagerWhoMayReviewProducts(): void
    {
        $this->useFakes();
        $id = $this->seedWaitingProduct();
        State::loginAs(78, ['manage_options', Capabilities::REVIEW_VENDOR, Capabilities::REVIEW_PRODUCTS]);

        $html = $this->render();

        $table = $this->heldTable($html);
        self::assertStringContainsString(ProductReviewPage::SLUG, $table);
        self::assertStringContainsString('product=' . $id, $table, 'it points at this product');
        self::assertStringContainsString('بررسی در بازارگاه', $table);
    }

    /** §6: a finished page says so, and nothing claims more than it read. */
    public function testAFinishedPageSaysTheRowsAreTheOnesTheCounterCounts(): void
    {
        $this->useFakes();
        $this->seedWaitingProduct();

        $html = $this->render();

        self::assertStringContainsString('این ردیف‌ها همان‌هایی‌اند که شمارندهٔ دروازه می‌شمارد.', $html);
        self::assertStringNotContainsString('این ردیف‌ها فقط همین صفحه‌اند، نه همهٔ آن‌ها.', $html);
    }

    /** Drawing the page decides nothing: not one row moves. */
    public function testDrawingThePageWritesNothing(): void
    {
        $this->useFakes();
        $this->useStorefront('https://example.test/wp-admin/post.php?post=9701&action=edit');
        $this->seedWaitingProduct();

        $before = $this->productRows();
        $this->render();

        self::assertSame($before, $this->productRows(), 'the list is a reading, not a decision');
    }
}
