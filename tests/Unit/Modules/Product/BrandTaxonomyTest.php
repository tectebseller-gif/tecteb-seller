<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\BrandTaxonomy;

/**
 * WHAT THIS PROVES: which taxonomy the brand list is read from, on a shop
 * whose brands are not where this plugin assumed they were.
 *
 * ⛔ THE DEFECT, AND THE TWO TRUE SENTENCES THAT HID IT. `WooCommerce
 * BrandDirectory` had `const TAXONOMY = 'product_brand'` and gated every
 * method on `taxonomy_exists()` of it — correct, and measured on the
 * disposable install. `ManageBrands`'s docblock says the brand reaching the
 * product is «the path the owner measured working — WooCommerce → Product
 * data → Attributes → Brand» — also correct. Together they are false: that
 * screen is where a WooCommerce ATTRIBUTE lives, and a global one is the
 * taxonomy `pa_<slug>`, not `product_brand`. On such a shop the list is
 * empty, every keystroke is answered `count: 0`, and nothing says why. The
 * `alpha.105` shape, and the `alpha.34` rule: discover a foreign plugin's
 * key, never type it.
 */
final class BrandTaxonomyTest extends TestCase
{
    /**
     * ⛔ THE CONTROL THAT MATTERS MOST: a shop that has `product_brand` is
     * answered exactly as before.
     *
     * The discovery may only ADD an answer where there was none. A rule that
     * reordered the preferences would change every shop that works today.
     */
    public function testWooCommercesOwnTaxonomyStillWins(): void
    {
        self::assertSame(
            'product_brand',
            BrandTaxonomy::discover(['product_cat', 'product_tag', 'product_brand', 'pa_brand'], [
                ['taxonomy' => 'pa_brand', 'label' => 'برند'],
            ])
        );
    }

    /** And it wins over every third-party name too. */
    public function testTheCoreTaxonomyWinsOverAPluginsOwn(): void
    {
        self::assertSame(
            'product_brand',
            BrandTaxonomy::discover(['pwb-brand', 'product_brand', 'yith_product_brand'])
        );
    }

    /** A shop with only a plugin's brand taxonomy is answered with it. */
    public function testAPluginsBrandTaxonomyIsFound(): void
    {
        self::assertSame('pwb-brand', BrandTaxonomy::discover(['product_cat', 'pwb-brand']));
        self::assertSame('yith_product_brand', BrandTaxonomy::discover(['yith_product_brand']));
        self::assertSame('product_brands', BrandTaxonomy::discover(['product_brands']));
    }

    /**
     * ⛔ THE SHOP THE OWNER ACTUALLY HAS, as far as the source can tell: the
     * brand is a global ATTRIBUTE, so the taxonomy is `pa_<something>` and
     * only its LABEL says it is a brand.
     *
     * The slug is matched on nothing, deliberately: a Persian shop's
     * attribute slug is transliterated or percent-encoded and is not a word
     * anybody chose. The label is.
     */
    public function testAGlobalBrandAttributeIsFoundByItsLabel(): void
    {
        self::assertSame(
            'pa_brand',
            BrandTaxonomy::discover(['product_cat', 'pa_brand', 'pa_size'], [
                ['taxonomy' => 'pa_size', 'label' => 'اندازه'],
                ['taxonomy' => 'pa_brand', 'label' => 'برند'],
            ])
        );
    }

    /** The label is folded, so every spelling of «برند» is one word. */
    public function testTheLabelIsFoldedLikeEveryOtherBrandName(): void
    {
        foreach (['برند', 'برَند', 'بِرند', ' برند ', 'Brand', 'BRAND', 'brand', 'مارک'] as $label) {
            self::assertSame(
                'pa_x',
                BrandTaxonomy::discover(['pa_x'], [['taxonomy' => 'pa_x', 'label' => $label]]),
                $label
            );
        }
    }

    /**
     * ⛔ AND AN ATTRIBUTE THAT IS DEFINED BUT NOT REGISTERED IS NOT AN ANSWER.
     *
     * `wc_get_attribute_taxonomies()` lists what the shop DEFINED;
     * `get_object_taxonomies()` lists what WooCommerce actually registered
     * this request. A row in the first and not the second is one no term can
     * be read from, and answering with it would trade an empty list for an
     * error — a guard wider than the defect (`alpha.60`).
     */
    public function testAnUnregisteredAttributeIsNotAnswered(): void
    {
        self::assertNull(
            BrandTaxonomy::discover(['product_cat'], [['taxonomy' => 'pa_brand', 'label' => 'برند']])
        );
    }

    /** An attribute that is not a brand is never mistaken for one. */
    public function testAnotherAttributeIsNotTakenForTheBrand(): void
    {
        self::assertNull(
            BrandTaxonomy::discover(['pa_size', 'pa_colour'], [
                ['taxonomy' => 'pa_size', 'label' => 'اندازه'],
                ['taxonomy' => 'pa_colour', 'label' => 'رنگ'],
            ])
        );
    }

    /**
     * ⛔ AND «I CANNOT TELL» IS AN ANSWER, not a silently empty list.
     *
     * This is the whole reason the method returns `?string`: the screen has to
     * be able to say «این فروشگاه فهرست برند ندارد» instead of painting an
     * empty box that looks exactly like a broken one.
     */
    public function testAShopWithNoBrandListAnswersNull(): void
    {
        self::assertNull(BrandTaxonomy::discover([]));
        self::assertNull(BrandTaxonomy::discover(['product_cat', 'product_tag']));
        self::assertNull(BrandTaxonomy::discover(['', '  ']));
    }

    /** Rubbish in the lists is ignored rather than returned. */
    public function testEmptyAndBlankEntriesAreIgnored(): void
    {
        self::assertSame('product_brand', BrandTaxonomy::discover(['', 'product_brand', '   ']));
        self::assertNull(BrandTaxonomy::discover(['pa_x'], [['taxonomy' => '', 'label' => 'برند']]));
        self::assertNull(BrandTaxonomy::discover(['pa_x'], [['taxonomy' => 'pa_x', 'label' => '']]));
    }
}
