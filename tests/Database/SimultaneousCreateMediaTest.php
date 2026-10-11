<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: §3 — the media window two simultaneous creates leave open.
 *
 * **The type of test, stated plainly:** this is a SCHEDULED INTERLEAVING in
 * one process on one connection, NOT a multi-process race. The other request
 * runs inside this one, immediately before its `INSERT`, so the unique key on
 * `(vendor_user_id, create_token)` is what decides the winner — every run, in
 * the same order. A real two-process race is a separate instrument
 * (`tools/alpha43-reproduction.sh`, on a real WordPress with a real media
 * library) and is reported separately; neither is presented as the other.
 *
 * **Why the form and not the service.** `alpha.42` closed the SEQUENTIAL
 * replay by asking `createdAlready()` before the file is taken off the wire,
 * and that ask is in `ProductArea`, not in `ManageProducts`. The window that
 * is left is also in `ProductArea`: two requests that arrive together both
 * ask before either has inserted, both get 0, and both upload. So the only
 * place the defect exists is the POST path, and the only honest measurement
 * is a POST.
 *
 * What is asserted is what the owner asked for in both directions: the loser
 * leaves no unused attachment, AND the winner's attachment is still there.
 */
final class SimultaneousCreateMediaTest extends DatabaseTestCase
{
    private const VENDOR = 8402;
    private const TOKEN = 'token-simultaneous-media-0001';

    private DbProductRepository $products;
    private FakeProductImages $images;
    private InterferingDatabase $interfering;
    private string $tempFile = '';

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
        // Bound BEFORE anything resolves a repository. `Container::bind()`
        // clears only the instance it names, so a service already built with
        // the plain database keeps it — and the first version of this test
        // bound the interfering one afterwards and measured a request that
        // never went near it (`fired=[]`, one upload, no race). No rules are
        // registered yet, so the migrations below run straight through.
        $this->interfering = new InterferingDatabase(new WpDatabase($this->wpdb));
        $this->images = new FakeProductImages();
        $container = Bootstrap::container();
        $container->bind(DatabaseInterface::class, fn (): DatabaseInterface => $this->interfering);
        $container->bind(
            ProductImageLibraryInterface::class,
            fn (): ProductImageLibraryInterface => $this->images
        );
        // `plugins_loaded` has already built a repository on the plain
        // database, and `Container::bind()` clears only the id it names — so
        // the first version of this test measured a request that never went
        // near the interfering connection (`fired=[]`, one upload, no race).
        // Re-registering the module re-binds its factories, which drops those
        // cached instances and rebuilds them lazily against the binding
        // above. The PRODUCTION wiring, run twice, rather than a second copy
        // of it written out here.
        (new \Tecteb\Marketplace\Modules\Product\ProductModule())->register($container);
        $container->bind(
            ProductImageLibraryInterface::class,
            fn (): ProductImageLibraryInterface => $this->images
        );
        $container->get(MigrationRunner::class)->run();

        $clock = new SystemClock();
        (new DbVendorRepository($db, $clock))->upsertProfile(self::VENDOR, 'داروخانهٔ رقابت', true, false);
        // Built HERE rather than taken from the container, so the container
        // never caches a repository holding the plain database — every
        // service the request uses has to go through the interfering one.
        $this->products = new DbProductRepository($db, $clock);
        State::loginAs(self::VENDOR, []);

        $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'tmc-image-');
        file_put_contents($this->tempFile, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        ));
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    /**
     * THE DEFECT: the loser uploaded a file and then wrote nothing, so the
     * file stayed on the vendor's disk referenced by nothing.
     */
    public function testTheLoserOfTwoSimultaneousCreatesLeavesNoOrphanedMedia(): void
    {
        $interfering = $this->interfering;

        // The winner arrives in the gap between the loser's token read and
        // the loser's insert — which is exactly the gap two simultaneous
        // requests create, and the only gap in which both of them upload.
        $interfering->before(['INSERT INTO', M0005CreateProductTables::PRODUCTS], 1, function (): void {
            $this->postCreate('نسخهٔ برنده');
        });

        $loser = $this->postCreate('نسخهٔ بازنده');
        self::assertNotSame([], $interfering->fired, 'the interleaving has to have happened');

        self::assertSame('product_created_replayed', $loser->code, 'the loser must not claim a creation');
        self::assertCount(1, $this->products->forVendor(self::VENDOR), 'one product, whoever lost');

        // Two uploads happened — that is the window, and it is not closed by
        // pretending otherwise.
        $stored = $this->images->stored();
        self::assertCount(2, $stored, 'both requests really did take a file off the wire');

        [$loserImage, $winnerImage] = $stored;   // the loser uploaded first
        $productId = $this->products->forVendor(self::VENDOR)[0]->id;

        self::assertSame(
            [$winnerImage],
            $this->products->images($productId),
            "the winner's picture is the one on the product"
        );
        self::assertSame(
            [$loserImage],
            $this->images->discarded(),
            'the loser discarded exactly its own upload — and nothing else'
        );
        self::assertSame(1, (int) ($loser->context['discarded_uploads'] ?? 0));
        self::assertTrue(
            $this->images->ownedBy($winnerImage, self::VENDOR),
            "and the winner's attachment is still in the library"
        );
    }

    /**
     * And the one thing a cleanup must never do: touch a picture that is used.
     *
     * The guard is `mediaInUse()`, so this asks the question from the other
     * side — an attachment the gallery names is refused even when it is
     * handed in directly. «پاک‌سازی فقط دربارهٔ رسانه‌ای که ... بی‌استفاده‌بودنش
     * اثبات شده».
     */
    public function testMediaAProductUsesIsNeverDiscarded(): void
    {
        $container = Bootstrap::container();
        $this->images->give(7731, self::VENDOR);
        $this->images->give(7732, self::VENDOR);

        $productId = $this->postCreate('با تصویر')->context['product_id'] ?? 0;
        self::assertGreaterThan(0, (int) $productId);
        self::assertTrue($this->products->saveImages((int) $productId, [7731], 7731));

        $manage = $container->get(\Tecteb\Marketplace\Modules\Product\Application\ManageProducts::class);
        self::assertSame(0, $manage->discardUnusedUploads(self::VENDOR, self::VENDOR, [7731]));
        self::assertSame([], $this->images->discarded());
        self::assertTrue($this->images->ownedBy(7731, self::VENDOR));

        // The featured picture is a COLUMN, not a gallery row, so it needs
        // its own half of the question — and an id nothing names at all is
        // the one case where a discard is allowed.
        self::assertTrue($this->products->saveImages((int) $productId, [], 7732));
        self::assertSame(0, $manage->discardUnusedUploads(self::VENDOR, self::VENDOR, [7732]));
        self::assertSame(1, $manage->discardUnusedUploads(self::VENDOR, self::VENDOR, [7733]));
    }

    // ----------------------------------------------------------------- setup

    private function postCreate(string $title): \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome
    {
        $_POST = [
            'tmc_vendor_action' => 'save_product',
            'product_id' => '0',
            'step' => '1',
            'revision' => '',
            'create_token' => self::TOKEN,
            'title' => $title,
            'type' => 'simple',
            'category' => 'gloves',
            'brand' => '',
            'short_description' => 'یک جمله',
            'description' => 'متن کامل',
            'description_base' => 'none',
            'price' => '200000',
            'sale_price' => '',
            'sale_from' => '',
            'sale_to' => '',
            'sku' => '',
            'stock' => '5',
            'min_purchase' => '1',
            'max_purchase' => '',
            'weight_grams' => '0',
            'dimensions' => '',
            'tax_class' => '',
            'main_image_id' => '0',
        ];
        // A real temporary file, so `Request::file()` reads a real mime type
        // and the upload path is the one a browser produces.
        $_FILES = ['product_image' => [
            'name' => 'glove.png',
            'tmp_name' => $this->tempFile,
            'size' => (int) filesize($this->tempFile),
            'error' => UPLOAD_ERR_OK,
        ]];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $outcome = (new ProductArea(Bootstrap::container()))->handle(
            'save_product',
            Request::capture(),
            self::VENDOR,
            new VendorUrls(
                'https://example.test/vendor/',
                'https://example.test/vendor/application/',
                'https://example.test/vendor/store/',
                'https://example.test/vendor/staff/',
                'https://example.test/vendor/invite/',
                'https://example.test/vendor/products/'
            )
        );
        self::assertNotNull($outcome, 'the router did not handle save_product at all');
        return $outcome;
    }
}
