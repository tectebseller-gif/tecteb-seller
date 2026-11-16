<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontImageReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\NullStorefrontImages;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WcStorefrontImages;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use TmcWpStubs\State;

/**
 * AN IMPORTED PRODUCT'S PICTURE IS THE ONE WOOCOMMERCE HOLDS.
 *
 * The owner's report: «برخی تصاویر بندانگشتی خالی‌اند، درحالی‌که تصویر در
 * WooCommerce موجود است». Measured cause: `listThumbnails()` resolved the
 * cell from `tmc_products.main_image_id` and the gallery rows beside it, and
 * an intake writes neither — so for an imported product there was no id to
 * resolve and the view drew its «no picture» placeholder.
 *
 * The reader under test is the REAL one, against the suite's real `postmeta`
 * table, because the whole question is which row the picture comes from.
 *
 * ⛔ And the thing that must NOT happen is asserted as hard as the thing that
 * must: no attachment is created, re-parented or deleted, no gallery row
 * appears, and the product row does not change. The fallback is a read.
 */
final class StorefrontImageFallbackTest extends DatabaseTestCase
{
    private const VENDOR = 9601;
    private const IMPORTED_WC = 7700;
    private const OWN_WC = 7701;
    private const SHOP_ATTACHMENT = 991;
    private const OTHER_SHOP_ATTACHMENT = 992;
    private const OWN_ATTACHMENT = 11;

    private ProductRepositoryInterface $products;
    private int $imported = 0;
    private int $withOwnGallery = 0;
    private int $withNothing = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);
        $this->bootPlugin(false);
        Bootstrap::container()->get(MigrationRunner::class)->run();

        (new DbVendorRepository($db, new SystemClock()))->upsertProfile(self::VENDOR, 'تجهیزات تک‌طب', true, false);
        $this->products = Bootstrap::container()->get(ProductRepositoryInterface::class);

        // The REAL reader, over the suite's real `postmeta`. Bound rather than
        // faked: a fake would prove the wiring and nothing about the row the
        // picture is actually read from.
        Bootstrap::container()->bind(
            StorefrontImageReaderInterface::class,
            fn () => new WcStorefrontImages(new WpDatabase($this->wpdb))
        );

        // An imported product: a WooCommerce id, no gallery of its own, and a
        // featured image on the shop.
        $this->imported = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'ست تزریق واردشده', categoryKey: 'sets', priceMinor: 450000, sku: 'SKU-IMPORTED', stock: 2),
            ProductStatus::Published,
            LinkOwnership::Observed,
            self::IMPORTED_WC
        );
        update_post_meta(self::IMPORTED_WC, '_thumbnail_id', self::SHOP_ATTACHMENT);

        // A product with its OWN gallery, which also has a shop picture: the
        // control that says the vendor's own image still wins.
        $this->withOwnGallery = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'دستکش خودمان', categoryKey: 'gloves', priceMinor: 200000, sku: 'SKU-OWN', stock: 5),
            ProductStatus::Published,
            LinkOwnership::Marketplace,
            self::OWN_WC
        );
        $this->products->saveImages($this->withOwnGallery, [self::OWN_ATTACHMENT], self::OWN_ATTACHMENT);
        update_post_meta(self::OWN_WC, '_thumbnail_id', self::OTHER_SHOP_ATTACHMENT);

        // And one with neither: the placeholder is a real state of a draft.
        $this->withNothing = $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'پیش‌نویس بی‌تصویر', categoryKey: 'gloves', priceMinor: 100000, sku: 'SKU-NONE', stock: 1),
            ProductStatus::Draft
        );

        State::loginAs(self::VENDOR, []);
    }

    /** @param array<string,string> $query */
    private function render(array $query): string
    {
        $_GET = $query;
        $_POST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return (new ProductArea(Bootstrap::container()))->render(new VendorAreaView(
            Request::capture(),
            self::VENDOR,
            new VendorUrls(
                'https://example.test/vendor/',
                'https://example.test/vendor/application/',
                '',
                '',
                '',
                'https://example.test/vendor/products/'
            ),
            '<input type="hidden" name="_wpnonce" value="t" />',
            null
        ));
    }

    /** The `src` of the thumbnail in one product's row of the rendered list. */
    private static function thumbnailIn(string $html, string $sku): string
    {
        // One `<li>` per product; the SKU is printed in the same row.
        preg_match_all('#<li class="tv-product.*?</li>#s', $html, $rows);
        foreach ($rows[0] as $row) {
            if (str_contains($row, $sku) && preg_match('#<img[^>]+src="([^"]+)"#', $row, $img) === 1) {
                return html_entity_decode($img[1], ENT_QUOTES);
            }
        }
        return '';
    }

    /** @return array<string,int> row counts of everything a picture could touch */
    private function mediaFootprint(): array
    {
        $pdo = $this->wpdb->pdo();
        $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $p = $this->wpdb->prefix;
        return [
            'postmeta' => $count("SELECT COUNT(*) FROM `{$this->wpdb->postmeta}`"),
            'thumbnail_ids' => $count("SELECT COUNT(*) FROM `{$this->wpdb->postmeta}` WHERE meta_key = '_thumbnail_id'"),
            'images' => $count("SELECT COUNT(*) FROM `{$p}" . M0005CreateProductTables::PRODUCT_IMAGES . "`"),
            'products' => $count("SELECT COUNT(*) FROM `{$p}" . M0005CreateProductTables::PRODUCTS . "`"),
        ];
    }

    /**
     * WHAT THIS PROVES: the imported product's row shows the shop's picture.
     * RED on `alpha.106`, where the cell was the placeholder.
     */
    public function testTheImportedProductsRowShowsTheShopsPicture(): void
    {
        $html = $this->render([]);
        $src = self::thumbnailIn($html, 'SKU-IMPORTED');
        self::assertNotSame('', $src, 'the imported row has a picture at all');
        self::assertStringContainsString((string) self::SHOP_ATTACHMENT, $src, 'and it is the shop\'s featured image');
    }

    /**
     * WHAT THIS PROVES: a product with a gallery of its own still shows its
     * own picture — the control. Without it, «the shop always wins» would
     * pass every other assertion here while quietly replacing the picture
     * vendors uploaded.
     */
    public function testAProductWithItsOwnGalleryStillShowsItsOwnPicture(): void
    {
        $html = $this->render([]);
        $src = self::thumbnailIn($html, 'SKU-OWN');
        self::assertStringContainsString((string) self::OWN_ATTACHMENT . '-', $src, 'the vendor\'s own image');
        self::assertStringNotContainsString((string) self::OTHER_SHOP_ATTACHMENT, $src, 'not the shop\'s');
    }

    /**
     * WHAT THIS PROVES: a product with no picture anywhere gets the labelled
     * placeholder, not a broken frame and not a borrowed picture.
     */
    public function testAProductWithNoPictureAnywhereGetsThePlaceholder(): void
    {
        $html = $this->render([]);
        self::assertSame('', self::thumbnailIn($html, 'SKU-NONE'), 'no img tag in that row');
        self::assertStringContainsString('بدون تصویر', $html, 'the placeholder says so');
    }

    /**
     * WHAT THIS PROVES: the edit form shows the SAME picture the list shows —
     * «تصویر باید در فهرست و فرم ویرایش یکسان و درست باشد» — and shows it as
     * the shop's, not as a row of the vendor's gallery.
     */
    public function testTheEditFormShowsTheSamePictureAndCallsItTheShops(): void
    {
        $inList = self::thumbnailIn($this->render([]), 'SKU-IMPORTED');
        $form = $this->render(['product' => (string) $this->imported, 'step' => '1']);

        preg_match('#<div class="tv-gallery__shop">.*?</div>#s', $form, $block);
        self::assertNotSame([], $block, 'the form has the shop-picture block');
        self::assertStringContainsString($inList, html_entity_decode($block[0], ENT_QUOTES), 'the same URL as the list');
        self::assertStringContainsString('این تصویر فعلی محصول در فروشگاه است', $form);
        self::assertStringNotContainsString('هنوز تصویری ندارید', $form, 'the false sentence is gone');

        // ⛔ And the shop's attachment is NOT offered as a gallery row: no
        // `image_ids[]`, no «main image» radio and no delete checkbox carries
        // it, so a save cannot adopt somebody else's attachment id.
        self::assertStringNotContainsString('name="image_ids[]" value="' . self::SHOP_ATTACHMENT . '"', $form);
        self::assertStringNotContainsString('name="main_image_id" value="' . self::SHOP_ATTACHMENT . '"', $form);
        self::assertStringNotContainsString('name="remove_image_ids[]" value="' . self::SHOP_ATTACHMENT . '"', $form);
    }

    /**
     * WHAT THIS PROVES: rendering either screen writes nothing — no
     * attachment row, no gallery row, no change to the product. The fallback
     * is a read, and «هیچ فایل رسانه‌ای، شناسه attachment یا گالری قبلی را
     * تغییر نده و تصاویر تکراری نساز» is measured rather than promised.
     */
    public function testShowingTheShopsPictureWritesNothing(): void
    {
        $before = $this->mediaFootprint();
        $this->render([]);
        $this->render(['product' => (string) $this->imported, 'step' => '1']);
        $this->render(['product' => (string) $this->imported, 'step' => '3']);
        self::assertSame($before, $this->mediaFootprint(), 'nothing about pictures changed');

        $row = $this->products->findOwned($this->imported, self::VENDOR);
        self::assertNotNull($row);
        self::assertSame([], $row->imageIds, 'the gallery is still empty');
        self::assertSame(0, $row->mainImageId, 'and no main image was adopted');
    }

    /**
     * WHAT THIS PROVES: with no WooCommerce the screen is unchanged from
     * `alpha.106` — placeholder, no error — because the reader bound in that
     * case answers with nothing. The fallback adds a picture where one
     * exists; it does not become a new way for the list to fail.
     */
    public function testWithNoWooCommerceTheListStillRenders(): void
    {
        Bootstrap::container()->bind(StorefrontImageReaderInterface::class, static fn () => new NullStorefrontImages());
        $html = $this->render([]);
        self::assertSame('', self::thumbnailIn($html, 'SKU-IMPORTED'));
        self::assertStringContainsString('SKU-IMPORTED', $html, 'the row is still there');
        self::assertStringContainsString('بدون تصویر', $html);
    }

    /**
     * WHAT THIS PROVES: the reader asks ONE query for a page of rows. Twenty
     * product loads for twenty thumbnails is the shape `alpha.91` opened on
     * the handover screen, and a list is where it costs most. Measured as an
     * invariant — one query for one id and one for twelve — rather than as a
     * fixed number, which would be a claim about today's fixture.
     */
    public function testThePageOfRowsCostsOneQuery(): void
    {
        $reader = new WcStorefrontImages(new WpDatabase($this->wpdb));
        $before = $this->wpdb->num_queries;
        $reader->thumbnailUrls([self::IMPORTED_WC]);
        $one = $this->wpdb->num_queries - $before;

        $many = [];
        for ($n = 0; $n < 12; $n++) {
            $many[] = 8800 + $n;
        }
        $before = $this->wpdb->num_queries;
        $reader->thumbnailUrls($many);
        self::assertSame($one, $this->wpdb->num_queries - $before, 'twelve ids cost what one costs');
        self::assertSame(1, $one, 'and that is a single query');
    }
}
