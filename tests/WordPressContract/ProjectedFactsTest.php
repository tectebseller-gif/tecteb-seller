<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Tests\Support\NoSpecTemplates;

/**
 * WHAT THIS PROVES: what `project()` actually writes to a product.
 *
 * **It had no test at all.** Every suite that touches the projection goes
 * through `FakeCatalogProjector`, which is a different object — so the
 * projector's own branches were only ever read. `alpha.41` deleted the block
 * that put the brand and the medical specification on the product page, from
 * this very method, and nothing failed.
 *
 * The product here is a STUB and is never reported as WooCommerce: it is a
 * bag of values that records which setters were called, which is exactly what
 * «this field was not written» needs in order to be measurable. Real
 * WooCommerce stays `Not Run`.
 *
 * Process-isolated because it has to define `class WooCommerce` for
 * `isAvailable()`, and `WpDependencyProbeTest` is about that class being
 * ABSENT.
 */
final class ProjectedFactsTest extends ContractTestCase
{
    private const VENDOR = 77;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheBrandAndTheSpecificationReachTheProductAsTheirOwnBlock(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $product = $this->product(description: '<p>متن فروشنده</p>', specs: [
            'material' => 'لاتکس',
            'size' => '7',
            'nothing' => '   ',
        ]);
        $result = $this->projector()->project($product);

        self::assertTrue($result['ok'], (string) $result['code']);
        $saved = \WC_Product::$saved[$result['wc_product_id']];

        // The vendor's own text, unchanged and not padded with anything.
        self::assertSame('<p>متن فروشنده</p>', $saved->get_description());

        $attributes = $saved->get_attributes();
        self::assertArrayHasKey('tmc-brand', $attributes);
        self::assertSame('برند', $attributes['tmc-brand']->get_name());
        self::assertSame(['آریا طب'], $attributes['tmc-brand']->get_options());

        // The specification: the manager's LABEL, and the unit appended, both
        // from the template — a vendor who typed «7» sees «۷ اینچ» and does
        // not have to type the unit.
        self::assertArrayHasKey('tmc-spec-material', $attributes);
        self::assertSame('جنس', $attributes['tmc-spec-material']->get_name());
        self::assertSame(['لاتکس'], $attributes['tmc-spec-material']->get_options());
        self::assertSame(['7 اینچ'], $attributes['tmc-spec-size']->get_options());

        // Empty answers are skipped (UX §6.2) rather than shown as a blank row.
        self::assertArrayNotHasKey('tmc-spec-nothing', $attributes);

        // Visible, and NOT a variation attribute: these describe the product,
        // they do not generate anything to buy.
        foreach (['tmc-brand', 'tmc-spec-material'] as $key) {
            self::assertTrue($attributes[$key]->get_visible(), $key . ' must be shown');
            self::assertFalse($attributes[$key]->get_variation(), $key . ' is not a variation axis');
        }
    }

    /**
     * The §1 defect, measured where it does its damage.
     *
     * `description === null` is «this row predates the field», so the
     * projection must not touch the product's description at all — not write
     * an empty string over it, not write the short text into it, not write a
     * generated one. The assertion is that the setter was NEVER CALLED, which
     * is stronger than comparing the value: a write of the same string would
     * pass a value comparison and would still be a write.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testALegacyProductsOwnDescriptionIsNotTouchedAtAll(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
            $p->set_description('<p>متنی که مدیر در ووکامرس نوشته</p>');
        });

        $product = $this->product(description: null, wcProductId: $wcId);
        $result = $this->projector()->project($product);

        self::assertTrue($result['ok'], (string) $result['code']);
        $saved = \WC_Product::$saved[$wcId];
        self::assertNotContains('description', $saved->wrote, 'the description was written, and nothing asked for it');
        self::assertSame('<p>متنی که مدیر در ووکامرس نوشته</p>', $saved->get_description());

        // And the block still arrives, so the specification is not the price
        // of leaving the manager's text alone.
        self::assertArrayHasKey('tmc-brand', $saved->get_attributes());
    }

    /**
     * A deliberate clear is a value, and over OUR OWN text it is written.
     *
     * «Our own» is the stamp: `writeOwned()` writes a field only while the
     * shop still holds what this plugin last put there. So the clear needs a
     * stamped product to act on — and the test that was written without one
     * failed, correctly, because the text it was clearing was indistinguishable
     * from a manager's.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADeliberateClearIsWrittenOverTextThisPluginWrote(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
            $p->set_description('<p>متن قبلی</p>');
        });
        update_post_meta(
            $wcId,
            ProjectedFieldOwnership::STAMP_PREFIX . 'description',
            ProjectedFieldOwnership::fingerprint('<p>متن قبلی</p>')
        );

        $product = $this->product(description: '', wcProductId: $wcId);
        $result = $this->projector()->project($product);

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame('', \WC_Product::$saved[$wcId]->get_description());
    }

    /**
     * And over the MANAGER'S text it is held, not written.
     *
     * The other half of §1's «متن مدیر و توضیحات موجود ووکامرس حفظ شوند»:
     * `null` means nobody asked, and this means somebody asked but the shop's
     * text is not ours to delete. Both end with the manager's words intact,
     * for two different reasons, and the `alpha.27` guard is the one that
     * produces this one.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAClearIsHeldWhenTheTextInTheShopIsNotOurs(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
            $p->set_description('<p>متنی که مدیر نوشته</p>');
        });

        $product = $this->product(description: '', wcProductId: $wcId);
        $result = $this->projector()->project($product);

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame(
            '<p>متنی که مدیر نوشته</p>',
            \WC_Product::$saved[$wcId]->get_description(),
            "a vendor's clear does not delete words the manager wrote"
        );
    }

    /**
     * An attribute a manager added by hand survives; one of ours that is no
     * longer current goes away.
     *
     * `set_attributes()` replaces the whole set, so writing ours over the top
     * would delete the manager's work — and keeping the old set would leave a
     * retired specification on the page for ever. Both halves in one test,
     * because either alone is satisfied by the wrong implementation.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOursIsRewrittenAndTheManagersOwnAttributeIsKept(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $theirs = new \WC_Product_Attribute();
        $theirs->set_name('ساخت');
        $theirs->set_options(['ایران']);
        $stale = new \WC_Product_Attribute();
        $stale->set_name('مشخصهٔ بازنشسته');
        $stale->set_options(['قدیمی']);

        $wcId = $this->existingProduct(static function (\WC_Product $p) use ($theirs, $stale): void {
            $p->set_attributes(['made-in' => $theirs, 'tmc-spec-retired' => $stale]);
        });

        $product = $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'لاتکس']);
        $this->projector()->project($product);

        $attributes = \WC_Product::$saved[$wcId]->get_attributes();
        self::assertArrayHasKey('made-in', $attributes, "the manager's own attribute must survive the projection");
        self::assertSame(['ایران'], $attributes['made-in']->get_options());
        self::assertArrayNotHasKey('tmc-spec-retired', $attributes, 'one of ours that we no longer write must go');
        self::assertArrayHasKey('tmc-spec-material', $attributes);
    }

    // ----------------------------------------------------------------- setup

    /**
     * A product that already exists AND is really ours.
     *
     * The ownership meta is the whole point: without it `owns()` answers
     * false, `linkedProduct()` returns null, and the projection quietly makes
     * a BRAND NEW product — so every assertion about «the existing one» would
     * be about an object the code never touched. Three tests here failed
     * exactly that way first.
     */
    private function existingProduct(callable $arrange): int
    {
        $existing = new \WC_Product_Simple();
        $arrange($existing);
        $wcId = $existing->save();
        update_post_meta($wcId, WooCommerceProjector::PRODUCT_META, 41);
        update_post_meta($wcId, WooCommerceProjector::VENDOR_META, self::VENDOR);
        $existing->wrote = [];
        return $wcId;
    }

    private function declareWooCommerce(): void
    {
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        \WC_Product::reset();
    }

    private function projector(): WooCommerceProjector
    {
        return new WooCommerceProjector($this->templates());
    }

    /** One template, with a unit on one field, so the unit is measurable. */
    private function templates(): SpecTemplateRepositoryInterface
    {
        return new NoSpecTemplates(new SpecTemplate(1, 'gloves', 'دستکش', 3, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
            new SpecField(2, 'size', 'اندازه', SpecFieldType::Text, false, 'اینچ'),
            new SpecField(3, 'nothing', 'بی‌جواب', SpecFieldType::Text),
        ]));
    }

    /** @param array<string,string> $specs */
    private function product(?string $description, ?int $wcProductId = null, array $specs = ['material' => 'لاتکس']): Product
    {
        return new Product(
            41,
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                brand: 'آریا طب',
                shortDescription: 'یک جمله',
                priceMinor: 200000,
                sku: 'SKU-FACT',
                stock: 5,
                description: $description
            ),
            ProductStatus::Published,
            $specs,
            [],
            0,
            '',
            0,
            null,
            '',
            $wcProductId
        );
    }
}
