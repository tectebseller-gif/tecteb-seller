<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Product\Presentation\VariationsView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Presentation\StorePageView;

/**
 * ⛔ WHERE A VARIABLE PRODUCT'S PRICE IS READ FROM, on the two surfaces a
 * person outside this panel sees.
 *
 * Both were measured on the `alpha.105` bytes and both answered about the
 * parent row's own `price_minor`, which for an imported variable parent is
 * nought because the price is on the combinations:
 *
 *  - the public shop page printed NO price at all, beside a product selling
 *    three sizes for millions, because the only condition was `> 0`;
 *  - and the REST endpoint printed `price_minor: 0` with nothing beside it to
 *    say that the number is not the product's price.
 *
 * The REST half of this file lives beside the store half on purpose: they are
 * one finding with two consumers, and the fix is the same read
 * (`rangesFor()`, one indexed query for the page) in both.
 *
 * The ids here are this test's own. Nothing in this file can reach a live
 * site and nothing in it writes.
 */
final class VariablePriceSurfacesTest extends ContractTestCase
{
    private const VENDOR = 102;
    private const WC_PARENT = 7700;

    private function store(): StoreSettings
    {
        return new StoreSettings('تجهیزات پزشکی نمونه', 'تهران');
    }

    private function parent(string $type = ProductType::VARIABLE, int $priceMinor = 0): Product
    {
        return new Product(
            189,
            self::VENDOR,
            new ProductDetails(
                title: 'ست کامل تجهیزات نمونه',
                type: $type,
                categoryKey: '9559',
                priceMinor: $priceMinor,
                sku: 'FIXTURE-PARENT',
                stock: 0
            ),
            ProductStatus::Published,
            wcProductId: self::WC_PARENT
        );
    }

    /** The three real children of the measured fixture, as a range. */
    private function range(): StorefrontPriceRange
    {
        return StorefrontPriceRange::of(2_593_700, 10_374_800, 0, 'instock', null);
    }

    /** @param array<int,StorefrontPriceRange> $ranges */
    private function page(Product $product, array $ranges): string
    {
        return StorePageView::body(
            self::VENDOR,
            $this->store(),
            [$product],
            [],
            'https://example.test/?tmc_store=' . self::VENDOR,
            1,
            1,
            $ranges
        );
    }

    public function testTheShopPagePrintsTheRealRangeRatherThanNothing(): void
    {
        $html = $this->page($this->parent(), [self::WC_PARENT => $this->range()]);

        self::assertStringContainsString('۲,۵۹۳,۷۰۰', $html, 'the cheapest child');
        self::assertStringContainsString('۱۰,۳۷۴,۸۰۰', $html, 'and the dearest');
        self::assertStringContainsString('tmc-store__price', $html);
        // Not a nought, in any spelling.
        self::assertStringNotContainsString('>۰ تومان<', $html);
    }

    public function testOneNumberWhenEveryChildAgrees(): void
    {
        $html = $this->page(
            $this->parent(),
            [self::WC_PARENT => StorefrontPriceRange::of(5_187_400, 5_187_400, 0, 'instock', null)]
        );

        self::assertStringContainsString('۵,۱۸۷,۴۰۰', $html);
        self::assertStringNotContainsString('از ', $html, 'a single price is not a range');
    }

    /**
     * ⛔ AND AN UNREADABLE READ PRINTS NOTHING — not «نامشخص» and not a nought.
     *
     * A shopper cannot act on «نامشخص», and a nought would be a lie. The
     * vendor's own panel is where that word belongs, and it says it there.
     */
    public function testAnUnreadableRangePrintsNoPriceAtAll(): void
    {
        $html = $this->page($this->parent(), [self::WC_PARENT => StorefrontPriceRange::unreadable()]);

        self::assertStringNotContainsString('tmc-store__price', $html);
        self::assertStringNotContainsString('۰ تومان', $html);
        self::assertStringNotContainsString('نامشخص', $html);
        // The card itself is still there: a product with no printable price is
        // still a product a shopper may open.
        self::assertStringContainsString('ست کامل تجهیزات نمونه', $html);
    }

    /**
     * THE CONTROLS. A simple product keeps the price it has always printed,
     * and a caller that passes no ranges behaves exactly as before — which is
     * every earlier test of this view (`alpha.55`).
     */
    public function testASimpleProductKeepsItsOwnPrice(): void
    {
        $html = $this->page($this->parent(ProductType::SIMPLE, 450_000), []);

        self::assertStringContainsString('۴۵۰,۰۰۰', $html);
    }

    public function testNoRangesAtAllChangesNothing(): void
    {
        $withOwnPrice = $this->page($this->parent(ProductType::VARIABLE, 450_000), []);
        self::assertStringContainsString('۴۵۰,۰۰۰', $withOwnPrice);

        $withNought = $this->page($this->parent(), []);
        self::assertStringNotContainsString('tmc-store__price', $withNought);
    }
    /**
     * ⛔ P1: A PERSIAN ATTRIBUTE NAME IS READ, NOT PRINTED AS AN ENCODING.
     *
     * Seen by the owner in their own panel: `sanitize_title()`
     * percent-encodes every non-ASCII character, so a custom attribute typed
     * as «اندازه» is stored as `%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87` and
     * `wc_attribute_label()` with no product to consult handed that back.
     *
     * Decoded in the DISPLAY layer and nowhere else. The stored key is
     * untouched — it is what joins a child to its parent's axis — and
     * `AttributeLabelTest` pins the one-pass, valid-UTF-8 rules of the decode
     * itself.
     *
     * ⛔ THE OTHER HALF IS `Not Run` HERE, and said so rather than claimed:
     * `WcStorefrontVariationReader` now passes the parent to
     * `wc_attribute_label($key, $parent)` so WooCommerce resolves the original
     * name itself, and that function does not exist in this harness. It needs
     * real WooCommerce, which is a staging step.
     */
    public function testThePanelPrintsTheReadableAttributeName(): void
    {
        $html = VariationsView::render(
            189,
            [],
            [],
            [],
            new VendorUrls('https://example.test/vendor/', 'https://example.test/vendor/application/'),
            '',
            StorefrontVariationSet::read([
                new StorefrontVariation(
                    11965,
                    ['%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87' => '%db%b1%db%b0%db%b0'],
                    'BPI-100',
                    5_187_400,
                    null,
                    5_187_400,
                    true,
                    false,
                    null,
                    'instock',
                    'no',
                    0
                ),
            ], 0, 0)
        );

        self::assertStringContainsString('اندازه: ۱۰۰', $html);
        self::assertStringNotContainsString('%d8%a7', $html, 'and the encoding is not shown');
    }

    /**
     * AND IT IS STILL ESCAPED. Decoding is the one step that can turn stored
     * text into markup, so the print site escapes what comes back — proved
     * with a name that really is an encoded tag.
     */
    public function testADecodedNameIsEscapedRatherThanRendered(): void
    {
        $html = VariationsView::render(
            189,
            [],
            [],
            [],
            new VendorUrls('https://example.test/vendor/', 'https://example.test/vendor/application/'),
            '',
            StorefrontVariationSet::read([
                new StorefrontVariation(
                    11965,
                    ['%3Cscript%3E' => '%3Cimg%3E'],
                    'X',
                    1,
                    null,
                    1,
                    true,
                    false,
                    null,
                    'instock',
                    'no',
                    0
                ),
            ], 0, 0)
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
