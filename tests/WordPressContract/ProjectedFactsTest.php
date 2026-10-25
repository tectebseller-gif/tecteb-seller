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
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedAttributes;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Tests\Support\NoSpecTemplates;
use TmcWpStubs\State;

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

        // Read back from storage, not from the object that was handed over:
        // the keys this asserts on are the ones WooCommerce chose, and
        // `alpha.42` asserted on the ones it passed in.
        $attributes = $this->reload($result['wc_product_id']);
        $brand = ProjectedAttributes::keyFor('برند');
        self::assertArrayHasKey($brand, $attributes);
        self::assertSame('برند', $attributes[$brand]->get_name());
        self::assertSame(['آریا طب'], $attributes[$brand]->get_options());

        // And the key is NOT the one the plugin used to look for. This is the
        // §1 defect in one line: `set_attributes()` files the attribute under
        // `sanitize_title(get_name())` and discards the key it was given.
        self::assertArrayNotHasKey('tmc-brand', $attributes);
        self::assertSame([], array_filter(
            array_keys($attributes),
            static fn (string $key): bool => str_starts_with($key, 'tmc-')
        ), 'no key the plugin passed in survives, so no prefix can identify ours');

        // The specification: the manager's LABEL, and the unit appended, both
        // from the template — a vendor who typed «7» sees «۷ اینچ» and does
        // not have to type the unit.
        $material = ProjectedAttributes::keyFor('جنس');
        self::assertArrayHasKey($material, $attributes);
        self::assertSame('جنس', $attributes[$material]->get_name());
        self::assertSame(['لاتکس'], $attributes[$material]->get_options());
        self::assertSame(['7 اینچ'], $attributes[ProjectedAttributes::keyFor('اندازه')]->get_options());

        // Empty answers are skipped (UX §6.2) rather than shown as a blank row.
        self::assertArrayNotHasKey(ProjectedAttributes::keyFor('بی‌جواب'), $attributes);

        // Visible, and NOT a variation attribute: these describe the product,
        // they do not generate anything to buy.
        foreach ([$brand, $material] as $key) {
            self::assertTrue($attributes[$key]->get_visible(), $key . ' must be shown');
            self::assertFalse($attributes[$key]->get_variation(), $key . ' is not a variation axis');
        }

        // And the record that makes all of this possible on the NEXT run —
        // key => fingerprint, and each fingerprint taken from the attribute
        // STORAGE holds rather than from the one that was handed over.
        $record = get_post_meta($result['wc_product_id'], ProjectedAttributes::OWNED_META, true);
        self::assertSame(
            [$brand, $material, ProjectedAttributes::keyFor('اندازه')],
            array_keys((array) $record)
        );
        foreach ($record as $key => $fingerprint) {
            self::assertSame(
                ProjectedAttributes::fingerprint($attributes[$key]),
                $fingerprint,
                $key . ': the record must be of what the shop holds'
            );
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
        self::assertArrayHasKey(ProjectedAttributes::keyFor('برند'), $this->reload($wcId));
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
     * Ownership, through save → reload → change → save → reload.
     *
     * The shape matters as much as the cases. Everything `alpha.42` measured
     * was the array it had just built, which is a measurement of this
     * plugin's intention; the question is what survives WooCommerce's own
     * keying, and only a reload can answer it.
     *
     * (a) a changed value is written, (b) a retired specification goes away,
     * (c) a renamed label does not leave the old attribute behind, (d) a
     * manager's own attribute under another name is kept, (f) a second
     * identical projection changes nothing. The collision case (e) has a test
     * of its own, because it is the one with a policy rather than an outcome.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOwnershipSurvivesASaveAndAReload(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        // (d) the manager's own attribute, in place before anything of ours.
        $theirs = new \WC_Product_Attribute();
        $theirs->set_name('ساخت');
        $theirs->set_options(['ایران']);
        $theirs->set_visible(true);
        $wcId = $this->existingProduct(static function (\WC_Product $p) use ($theirs): void {
            $p->set_attributes([$theirs]);
        });
        $madeIn = ProjectedAttributes::keyFor('ساخت');

        $product = $this->product(description: 'x', wcProductId: $wcId, specs: [
            'material' => 'لاتکس',
            'size' => '7',
        ]);
        self::assertTrue($this->projector()->project($product)['ok']);

        $first = $this->reload($wcId);
        self::assertArrayHasKey($madeIn, $first, "the manager's own attribute must survive the projection");
        self::assertSame(['ایران'], $first[$madeIn]->get_options());
        self::assertSame(['لاتکس'], $first[ProjectedAttributes::keyFor('جنس')]->get_options());
        self::assertSame(['7 اینچ'], $first[ProjectedAttributes::keyFor('اندازه')]->get_options());
        self::assertNotContains($madeIn, get_post_meta($wcId, ProjectedAttributes::OWNED_META, true));

        // (a) the vendor changes an answer.
        $changed = $this->product(description: 'x', wcProductId: $wcId, specs: [
            'material' => 'نیتریل',
            'size' => '7',
        ]);
        self::assertTrue($this->projector()->project($changed)['ok']);
        $second = $this->reload($wcId);
        self::assertSame(['نیتریل'], $second[ProjectedAttributes::keyFor('جنس')]->get_options());
        self::assertArrayHasKey($madeIn, $second);

        // (f) and again, with nothing changed: no second copy of anything.
        self::assertTrue($this->projector()->project($changed)['ok']);
        self::assertSame(array_keys($second), array_keys($this->reload($wcId)));

        // (b) the manager retires a field. Nobody edits the product.
        $retired = new NoSpecTemplates(new SpecTemplate(1, 'gloves', 'دستکش', 4, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
            new SpecField(2, 'size', 'اندازه', SpecFieldType::Text, false, 'اینچ', [], 0, true),
        ]));
        self::assertTrue((new WooCommerceProjector($retired))->project($changed)['ok']);
        $third = $this->reload($wcId);
        self::assertArrayNotHasKey(
            ProjectedAttributes::keyFor('اندازه'),
            $third,
            'a retired specification must stop being shown'
        );
        self::assertArrayHasKey($madeIn, $third, "and retiring ours must not take the manager's with it");
        self::assertArrayHasKey(ProjectedAttributes::keyFor('جنس'), $third);

        // (c) the manager renames a label. The OLD key must not be orphaned.
        $renamed = new NoSpecTemplates(new SpecTemplate(1, 'gloves', 'دستکش', 5, [
            new SpecField(1, 'material', 'جنس سازنده', SpecFieldType::Text, true),
        ]));
        self::assertTrue((new WooCommerceProjector($renamed))->project($changed)['ok']);
        $fourth = $this->reload($wcId);
        self::assertArrayNotHasKey(
            ProjectedAttributes::keyFor('جنس'),
            $fourth,
            'the old label must not be left behind as a second attribute'
        );
        self::assertSame(['نیتریل'], $fourth[ProjectedAttributes::keyFor('جنس سازنده')]->get_options());
        self::assertArrayHasKey($madeIn, $fourth);
    }

    /**
     * (e) A manager's attribute under OUR name: theirs stays, ours is not written.
     *
     * «نام مشابه اثبات مالکیت نیست» — so the policy cannot be «the name
     * matches, take it». The record says this key was never ours, and the
     * safe direction when ownership is unknown is the one that deletes
     * nothing. The key is written to `CONFLICT_META` so it is a visible fact
     * rather than a silence.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAManagersAttributeUnderOurOwnNameIsNeverOverwritten(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $theirs = new \WC_Product_Attribute();
        $theirs->set_name('برند');
        $theirs->set_options(['چیزی که مدیر نوشته']);
        $theirs->set_visible(true);
        $wcId = $this->existingProduct(static function (\WC_Product $p) use ($theirs): void {
            $p->set_attributes([$theirs]);
        });

        $product = $this->product(description: 'x', wcProductId: $wcId);
        self::assertTrue($this->projector()->project($product)['ok']);

        $brand = ProjectedAttributes::keyFor('برند');
        $attributes = $this->reload($wcId);
        self::assertSame(
            ['چیزی که مدیر نوشته'],
            $attributes[$brand]->get_options(),
            "a name this plugin also uses is not a licence to overwrite somebody's work"
        );
        self::assertSame([$brand], get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));
        self::assertNotContains($brand, get_post_meta($wcId, ProjectedAttributes::OWNED_META, true));

        // The specification next to it is unaffected: one collision does not
        // stop the rest of the block.
        self::assertSame(['لاتکس'], $attributes[ProjectedAttributes::keyFor('جنس')]->get_options());
    }

    /**
     * The product `alpha.42` projected, with no record of what it wrote.
     *
     * An unrecorded key holding an attribute IDENTICAL to the one we are
     * about to write is adopted, because writing ours over it provably
     * changes nothing. That is the one place where «we have no record» is
     * answered with «ours» — and it is answered on evidence, not on the name.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnIdenticalUnrecordedAttributeIsAdoptedRatherThanFoughtOver(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        // Exactly what `factAttributes()` produces for this product, written
        // by a build that kept no record.
        $asAlpha42Wrote = new \WC_Product_Attribute();
        $asAlpha42Wrote->set_name('برند');
        $asAlpha42Wrote->set_options(['آریا طب']);
        $asAlpha42Wrote->set_position(100);
        $asAlpha42Wrote->set_visible(true);
        $asAlpha42Wrote->set_variation(false);
        $wcId = $this->existingProduct(static function (\WC_Product $p) use ($asAlpha42Wrote): void {
            $p->set_attributes([$asAlpha42Wrote]);
        });

        $product = $this->product(description: 'x', wcProductId: $wcId, specs: []);
        self::assertTrue($this->projector()->project($product)['ok']);

        $brand = ProjectedAttributes::keyFor('برند');
        self::assertSame([$brand], array_keys((array) get_post_meta($wcId, ProjectedAttributes::OWNED_META, true)));
        self::assertSame('', (string) get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));

        // And from the next run on it behaves like any other owned key: the
        // vendor's new brand is written.
        $rebranded = new Product(
            41,
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                brand: 'آریا طب نوین',
                shortDescription: 'یک جمله',
                priceMinor: 200000,
                sku: 'SKU-FACT',
                stock: 5,
                description: 'x'
            ),
            ProductStatus::Published,
            [],
            [],
            0,
            '',
            0,
            null,
            '',
            $wcId
        );
        self::assertTrue($this->projector()->project($rebranded)['ok']);
        $after = $this->reload($wcId);
        self::assertSame(['آریا طب نوین'], $after[ProjectedAttributes::keyFor('برند')]->get_options());
        self::assertCount(1, $after, 'and no second brand attribute next to it');
    }

    /**
     * A manager's edit to one of OUR attributes is not overwritten.
     *
     * **The whole of this round.** `alpha.43` recorded which keys it wrote
     * and read that record as a licence to rewrite them, so an ordinary
     * projection — nothing changed on the vendor's side — put the plugin's
     * value back over the manager's. MEASURED on real WooCommerce 11.0.1
     * before the fix: `brand_after_manager=برندی که مدیر نوشته`, then
     * `brand_after_projection=آریا طب`, and `conflicts=` — silent.
     *
     * The record now carries the fingerprint of what the shop HELD, so
     * «recorded» and «unchanged since» are two questions again.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAManagersEditToOneOfOursIsNotOverwritten(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
        });
        $product = $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'لاتکس']);
        self::assertTrue($this->projector()->project($product)['ok']);

        $brand = ProjectedAttributes::keyFor('برند');
        self::assertSame(['آریا طب'], $this->reload($wcId)[$brand]->get_options());
        self::assertArrayHasKey($brand, (array) get_post_meta($wcId, ProjectedAttributes::OWNED_META, true));

        // The manager changes it in the editor. Nothing else moves.
        $this->managerEdits($wcId, 'برند', 'برندی که مدیر نوشته');

        self::assertTrue($this->projector()->project($product)['ok']);
        $after = $this->reload($wcId);
        self::assertSame(
            ['برندی که مدیر نوشته'],
            $after[$brand]->get_options(),
            "a key in the record is not a licence to overwrite what is in it"
        );
        self::assertSame([$brand], get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));
        self::assertArrayNotHasKey(
            $brand,
            (array) get_post_meta($wcId, ProjectedAttributes::OWNED_META, true),
            'and the plugin stops claiming it'
        );
        // The specification beside it is untouched by the disagreement.
        self::assertSame(['لاتکس'], $after[ProjectedAttributes::keyFor('جنس')]->get_options());
    }

    /**
     * …and the vendor changing their value at the same time does not help.
     *
     * The test exists because the opposite implementation passes the one
     * above: a rule of «rewrite only when the vendor's value changed» would
     * leave the manager's edit alone in that test and destroy it here.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAVendorsOwnChangeDoesNotOverrideAManagersEdit(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
        });
        self::assertTrue($this->projector()->project(
            $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'لاتکس'])
        )['ok']);
        $this->managerEdits($wcId, 'جنس', 'جنسی که مدیر نوشته');

        // The vendor now edits that very specification.
        self::assertTrue($this->projector()->project(
            $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'نیتریل'])
        )['ok']);

        $material = ProjectedAttributes::keyFor('جنس');
        self::assertSame(['جنسی که مدیر نوشته'], $this->reload($wcId)[$material]->get_options());
        self::assertSame([$material], get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));
    }

    /**
     * A retired specification a manager had edited is NOT deleted.
     *
     * Retiring is how this plugin drops its own rows, and `alpha.43` dropped
     * the row whatever was in it: measured, `size_present=no` after a manager
     * had written their own value there. Deleting somebody's work is worse
     * than overwriting it, because there is nothing left to compare.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARetiredSpecificationAManagerEditedIsKept(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
        });
        $product = $this->product(description: 'x', wcProductId: $wcId, specs: [
            'material' => 'لاتکس',
            'size' => '7',
        ]);
        self::assertTrue($this->projector()->project($product)['ok']);
        $size = ProjectedAttributes::keyFor('اندازه');
        self::assertSame(['7 اینچ'], $this->reload($wcId)[$size]->get_options());

        $this->managerEdits($wcId, 'اندازه', 'اندازه‌ای که مدیر نوشته');

        // The manager retires the field. The attribute they EDITED stays.
        $retired = new NoSpecTemplates(new SpecTemplate(1, 'gloves', 'دستکش', 4, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
            new SpecField(2, 'size', 'اندازه', SpecFieldType::Text, false, 'اینچ', [], 0, true),
        ]));
        self::assertTrue((new WooCommerceProjector($retired))->project($product)['ok']);

        $after = $this->reload($wcId);
        self::assertArrayHasKey($size, $after, 'a retired row a manager edited is not ours to delete');
        self::assertSame(['اندازه‌ای که مدیر نوشته'], $after[$size]->get_options());
        self::assertContains($size, get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));
    }

    /**
     * And the control, which is the only reason the one above means anything:
     * a retired specification NOBODY touched is still removed.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARetiredSpecificationNobodyTouchedIsStillRemoved(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
        });
        $product = $this->product(description: 'x', wcProductId: $wcId, specs: [
            'material' => 'لاتکس',
            'size' => '7',
        ]);
        self::assertTrue($this->projector()->project($product)['ok']);

        $retired = new NoSpecTemplates(new SpecTemplate(1, 'gloves', 'دستکش', 4, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
            new SpecField(2, 'size', 'اندازه', SpecFieldType::Text, false, 'اینچ', [], 0, true),
        ]));
        self::assertTrue((new WooCommerceProjector($retired))->project($product)['ok']);

        $after = $this->reload($wcId);
        self::assertArrayNotHasKey(ProjectedAttributes::keyFor('اندازه'), $after);
        self::assertArrayHasKey(ProjectedAttributes::keyFor('جنس'), $after);
        self::assertSame('', (string) get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));
    }

    /**
     * The `alpha.43` record: a list of keys, with no record of their values.
     *
     * The policy is conservative and is stated rather than inferred. Such a
     * key is decided by whether writing ours would change ANYTHING: if the
     * stored attribute is identical in every persisted field the write is a
     * no-op, so taking the key risks nothing — and that is the argument, not
     * a claim that the match proves this plugin wrote it. Anything else is
     * held, and the way out is the manager's own: delete the attribute and
     * the next projection writes a fresh one it owns.
     *
     * Both halves here, because either alone is satisfied by a wrong rule.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnAlpha43KeyListIsNoLicenceToOverwriteButUpgradesItselfWhenNothingMoved(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
        });
        $product = $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'لاتکس']);
        self::assertTrue($this->projector()->project($product)['ok']);

        $brand = ProjectedAttributes::keyFor('برند');
        $material = ProjectedAttributes::keyFor('جنس');

        // Put the product back into exactly the state `alpha.43` left: the
        // keys, and nothing about what is in them.
        update_post_meta($wcId, ProjectedAttributes::OWNED_META, [$brand, $material]);
        $this->managerEdits($wcId, 'برند', 'برندی که مدیر نوشته');

        self::assertTrue($this->projector()->project($product)['ok']);
        $after = $this->reload($wcId);
        self::assertSame(
            ['برندی که مدیر نوشته'],
            $after[$brand]->get_options(),
            'a list of keys says nothing about what is in them'
        );
        self::assertContains($brand, get_post_meta($wcId, ProjectedAttributes::CONFLICT_META, true));

        // The other half: the untouched key passed the no-op test, so it was
        // adopted WITH a fingerprint — and the vendor's next edit lands.
        $record = (array) get_post_meta($wcId, ProjectedAttributes::OWNED_META, true);
        self::assertArrayHasKey($material, $record);
        self::assertNotSame('', $record[$material], 'the legacy key upgraded to a fingerprint');

        self::assertTrue($this->projector()->project(
            $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'نیتریل'])
        )['ok']);
        self::assertSame(['نیتریل'], $this->reload($wcId)[$material]->get_options());
    }

    /**
     * A write that did not land is not recorded as ours.
     *
     * `update_post_meta()` answers `false` for a failure AND for «the value
     * is already this», which are opposite facts, so the record is read back
     * and compared instead of trusted. And a key the shop does not hold
     * afterwards is not written down at all — recording it would license
     * overwriting whatever IS there on the next run.
     *
     * Here the projection has nothing of its own to write (no brand, no
     * answered specification), so the record must come away EMPTY rather
     * than keep what an earlier run put in it.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheRecordOnlyEverHoldsKeysTheShopActuallyHolds(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $wcId = $this->existingProduct(static function (\WC_Product $p): void {
        });
        self::assertTrue($this->projector()->project(
            $this->product(description: 'x', wcProductId: $wcId, specs: ['material' => 'لاتکس'])
        )['ok']);
        self::assertNotSame([], (array) get_post_meta($wcId, ProjectedAttributes::OWNED_META, true));

        // A product with no brand and no answered specification: there is
        // nothing of ours on it any more.
        $bare = new Product(
            41,
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                brand: '',
                shortDescription: 'یک جمله',
                priceMinor: 200000,
                sku: 'SKU-FACT',
                stock: 5,
                description: 'x'
            ),
            ProductStatus::Published,
            [],
            [],
            0,
            '',
            0,
            null,
            '',
            $wcId
        );
        self::assertTrue($this->projector()->project($bare)['ok']);
        self::assertSame(
            '',
            (string) get_post_meta($wcId, ProjectedAttributes::OWNED_META, true),
            'a record of keys nothing holds is a licence nobody should have'
        );
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

    /**
     * The product's attributes as STORAGE holds them.
     *
     * A fresh object built from the saved row, because the object handed to
     * `set_attributes()` is not evidence about anything: the keys it carries
     * are the ones this plugin chose, and the question is which keys
     * WooCommerce chose.
     *
     * @return array<string,\WC_Product_Attribute>
     */
    private function reload(int $wcId): array
    {
        return array_filter(
            (new \WC_Product_Simple($wcId))->get_attributes(),
            static fn ($a): bool => $a instanceof \WC_Product_Attribute
        );
    }

    /**
     * What a manager does in the WooCommerce editor: change one value, save.
     *
     * FRESH attribute objects, every one. Mutating the objects the product
     * already holds and handing the same array back registers NO change —
     * `set_prop()` compares against what is there, and what is there is the
     * object that was just edited. The probe's first version did exactly that
     * and reported «the manager's edit did not happen» on a product the
     * manager had edited.
     */
    private function managerEdits(int $wcId, string $label, string $value): void
    {
        $product = \wc_get_product($wcId);
        self::assertInstanceOf(\WC_Product::class, $product);
        $rewritten = [];
        foreach ($this->reload($wcId) as $attribute) {
            $copy = new \WC_Product_Attribute();
            $copy->set_id((int) $attribute->get_id());
            $copy->set_name((string) $attribute->get_name());
            $copy->set_options(
                $attribute->get_name() === $label ? [$value] : (array) $attribute->get_options()
            );
            $copy->set_position((int) $attribute->get_position());
            $copy->set_visible((bool) $attribute->get_visible());
            $copy->set_variation((bool) $attribute->get_variation());
            $rewritten[] = $copy;
        }
        $product->set_attributes($rewritten);
        $product->save();
        self::assertSame(
            [$value],
            $this->reload($wcId)[ProjectedAttributes::keyFor($label)]->get_options(),
            'the fixture must really have changed the attribute'
        );
    }

    private function declareWooCommerce(): void
    {
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        \WC_Product::reset();
    }

    private function projector(?ProductCategoryDirectoryInterface $categories = null): WooCommerceProjector
    {
        return new WooCommerceProjector($this->templates(), $categories);
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
    /**
     * §1-ب/ج: THE SHOP'S PRICE IS LEFT ALONE WHEN THIS MARKETPLACE HAS NONE.
     *
     * ⛔ `alpha.65` called `set_regular_price((string) $details->priceMinor)`
     * unconditionally, and `ProductDetails::missingFields()` has named `price`
     * for `<= 0` since the four-step form existed — so the model already said
     * nought is «قیمتی ثبت نشده» and the projector wrote it out as a price.
     * Measured on the installed bytes with the intake of an existing product:
     * a PUBLISHED WooCommerce product whose `_regular_price` row did not exist
     * came out of one projection with `_regular_price = '0'` and a price of
     * «۰ تومان» where there had been nothing.
     *
     * The field is HELD rather than written, the `writeOwned()` shape one
     * property down, and the holding is recorded under its own key so the
     * screens that have to act on it can read it on a later request.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAProductWithNoPriceDoesNotGetANoughtWrittenToTheShop(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $result = $this->projector()->project($this->product(description: null, priceMinor: 0));

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertTrue($result['price_held'], 'the projection must say it held the price');
        $saved = \WC_Product::$saved[$result['wc_product_id']];
        // NOT `'0'`: the setter was never called, so the stub holds whatever
        // it started with — which is what «WooCommerce keeps its own» means.
        self::assertNotSame('0', (string) $saved->get_regular_price());
        self::assertSame(
            '1',
            (string) get_post_meta($result['wc_product_id'], WooCommerceProjector::PRICE_HELD_META, true)
        );
    }

    /**
     * The control: a product that HAS a price still gets it, and the holding
     * record is cleared.
     *
     * A measurement that only ever looks at the broken case cannot tell a fix
     * from a feature that stopped working (`alpha.46`) — and «cleared» rather
     * than «left behind» is what makes the record mean «right now».
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPricedProductStillHasItsPriceWrittenAndNothingHeld(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $result = $this->projector()->project($this->product(description: null));

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertFalse($result['price_held']);
        $saved = \WC_Product::$saved[$result['wc_product_id']];
        self::assertSame('200000', (string) $saved->get_regular_price());
        self::assertSame(
            '',
            (string) get_post_meta($result['wc_product_id'], WooCommerceProjector::PRICE_HELD_META, true)
        );
    }

    /**
     * THE WHOLE RECORDED SET REACHES WOOCOMMERCE, NOT THE SINGLE KEY.
     *
     * ⛔ THIS TEST EXISTS BECAUSE A MUTATION SURVIVED. The falsification pass
     * for `alpha.82` made the projector read `CategorySet::of([$details->
     * categoryKey])` instead of `$details->categorySet()` — the single key
     * instead of the recorded relationship — and NOTHING in any suite went
     * red. The projector's category write had no behaviour test at all, which
     * is the worst place in this plugin to have none: `set_category_ids()`
     * REPLACES, and this is the line where `alpha.81` deleted term 245 from a
     * product filed under a parent and its child
     * (`docs/evidence/alpha82-audit/before-alpha81.txt`).
     *
     * The single key is `''` for a multi-category product by construction
     * (`CategorySet::soleKey()`), and this fixture's key is a pre-`alpha.25`
     * SLUG, so under that mutation nothing resolves and nothing is written —
     * the shop keeps the one category it already had and the vendor's second
     * one never arrives.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheWholeRecordedSetReachesTheShopAndNotTheSingleKey(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();
        $this->seedCategories();

        // One projection, so the field is OURS: `writeOwned()` stamps what it
        // wrote, and everything below depends on the shop still holding it
        // (`alpha.44` — ownership is bound to the value, not the key).
        $first = $this->projector($this->categories())->project(
            $this->product(description: null, categoryIds: [244])
        );
        self::assertTrue($first['ok'], (string) $first['code']);
        $wcId = (int) $first['wc_product_id'];
        self::assertSame([244], \WC_Product::$saved[$wcId]->get_category_ids());

        // The vendor's set grows to the parent AND its child.
        $second = $this->projector($this->categories())->project(
            $this->product(description: null, wcProductId: $wcId, categoryIds: [244, 245])
        );
        self::assertTrue($second['ok'], (string) $second['code']);

        $after = \WC_Product::$saved[$wcId]->get_category_ids();
        sort($after);
        self::assertSame([244, 245], $after, 'both ids, from the SET and not from the key');
    }

    /**
     * A RECORDED SET THAT NARROWS CANNOT REMOVE A RELATIONSHIP.
     *
     * ⛔ And this is the other survivor, and the one that matters most:
     * replacing `CategorySet::of(array_merge($current, $wanted))` with
     * `return $wanted` broke nothing. That union is the whole defence against
     * the `alpha.81` deletion — `set_category_ids()` REPLACES, so a recorded
     * value that has narrowed (which is exactly what `category_ids[0]` is)
     * would otherwise take the rest of the product's categories with it.
     *
     * Ownership is intact here: the shop holds precisely what the last
     * projection wrote, so the write DOES go ahead. That is what makes this
     * the dangerous case rather than a held one.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testANarrowedRecordedSetCannotDeleteACategoryFromTheShop(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();
        $this->seedCategories();

        $first = $this->projector($this->categories())->project(
            $this->product(description: null, categoryIds: [244, 245])
        );
        self::assertTrue($first['ok'], (string) $first['code']);
        $wcId = (int) $first['wc_product_id'];
        $before = \WC_Product::$saved[$wcId]->get_category_ids();
        sort($before);
        self::assertSame([244, 245], $before, 'the fixture must really start with both');

        // Now the recorded set carries ONE of them — the `alpha.81` shape.
        $second = $this->projector($this->categories())->project(
            $this->product(description: null, wcProductId: $wcId, categoryIds: [244])
        );
        self::assertTrue($second['ok'], (string) $second['code']);

        $after = \WC_Product::$saved[$wcId]->get_category_ids();
        sort($after);
        self::assertSame(
            [244, 245],
            $after,
            'the narrowing is not a licence to delete: «هیچ رابطه‌ای خودکار حذف یا اضافه نشود»'
        );
    }

    /**
     * A category the MANAGER attached is not overwritten — it is held.
     *
     * The control from the other side, and existing behaviour rather than
     * this round's: once the shop's value is neither what we wrote nor what
     * was agreed, the field is the manager's and our change becomes a
     * proposal (`alpha.44`). What this measures is that holding it removes
     * nothing — both the manager's term and ours stay exactly as they were.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAManagersOwnCategoryIsHeldRatherThanOverwritten(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();
        $this->seedCategories();

        $first = $this->projector($this->categories())->project(
            $this->product(description: null, categoryIds: [244])
        );
        $wcId = (int) $first['wc_product_id'];

        // What a manager does in the WooCommerce editor.
        $shop = \WC_Product::$saved[$wcId];
        $shop->set_category_ids([244, 246]);
        $shop->save();

        $second = $this->projector($this->categories())->project(
            $this->product(description: null, wcProductId: $wcId, categoryIds: [244, 245])
        );
        self::assertTrue($second['ok'], (string) $second['code']);

        $after = \WC_Product::$saved[$wcId]->get_category_ids();
        sort($after);
        self::assertSame(
            [244, 246],
            $after,
            "the manager's categories stand, and nothing of theirs is removed"
        );
    }

    /** The three terms every category test here resolves against. */
    private function seedCategories(): void
    {
        State::$terms['product_cat'] = [
            244 => ['name' => 'ترالی', 'slug' => 'trolley', 'parent' => 0, 'count' => 1],
            245 => ['name' => 'ترالی استیل', 'slug' => 'trolley-steel', 'parent' => 244, 'count' => 1],
            246 => ['name' => 'دستهٔ مدیر', 'slug' => 'managers-own', 'parent' => 0, 'count' => 1],
        ];
    }

    /**
     * The control: a product whose ids the directory does not know writes
     * NOTHING, rather than emptying the shop's categories.
     *
     * A measurement that only looks at the working case cannot tell a fix
     * from a feature that stopped working (`alpha.46`) — and this is the
     * direction that matters, because «resolved, never created» means an
     * unknown id is not a category and an empty `$wanted` must leave the
     * shop alone rather than replace it with nothing.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnUnresolvableSetLeavesTheShopsCategoriesAlone(): void
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();
        $this->seedCategories();

        $first = $this->projector($this->categories())->project(
            $this->product(description: null, categoryIds: [244])
        );
        $wcId = (int) $first['wc_product_id'];
        self::assertSame([244], \WC_Product::$saved[$wcId]->get_category_ids());

        // 9 999 is an id the directory refuses — a term somebody deleted.
        $second = $this->projector($this->categories())->project(
            $this->product(description: null, wcProductId: $wcId, categoryIds: [9999])
        );
        self::assertTrue($second['ok'], (string) $second['code']);
        self::assertSame(
            [244],
            \WC_Product::$saved[$wcId]->get_category_ids(),
            'nothing resolved, so nothing is written — and nothing is removed'
        );
    }

    /**
     * The directory the projector resolves ids against.
     *
     * Narrow on purpose: an id it does not return is an id whose term was
     * deleted, and «resolved, never created» is the rule being measured.
     */
    private function categories(): ProductCategoryDirectoryInterface
    {
        return new class implements ProductCategoryDirectoryInterface {
            public function search(string $query, int $limit = 50): array
            {
                return [];
            }

            public function countMatches(string $query): int
            {
                return 0;
            }

            public function find(string $value): ?ProductCategory
            {
                $id = (int) $value;
                $known = State::$terms['product_cat'] ?? [];
                return isset($known[$id])
                    ? new ProductCategory($id, (string) $known[$id]['name'], (string) $known[$id]['name'])
                    : null;
            }

            public function explainNoMatch(string $query): array
            {
                return ['words' => [], 'missing' => [], 'found' => []];
            }

            public function total(): int
            {
                return count(State::$terms['product_cat'] ?? []);
            }
        };
    }

    private function product(
        ?string $description,
        ?int $wcProductId = null,
        array $specs = ['material' => 'لاتکس'],
        int $priceMinor = 200000,
        array $categoryIds = []
    ): Product {
        return new Product(
            41,
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                brand: 'آریا طب',
                shortDescription: 'یک جمله',
                priceMinor: $priceMinor,
                sku: 'SKU-FACT',
                stock: 5,
                description: $description,
                categoryIds: $categoryIds
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
