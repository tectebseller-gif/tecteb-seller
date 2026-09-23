<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;

/**
 * The two edits a sorted id list could not see.
 *
 * Both are ordinary things a manager does on a product page — promote the
 * better photograph, drag the gallery into the order the parts arrive in —
 * and under `alpha.26` both compared as «no change», so the vendor's next
 * save quietly undid them. Every test here is written as a comparison,
 * because comparison is the thing that was broken; asserting the encoding
 * alone would pass on a function that encoded uniquely and was never used.
 */
final class StorefrontImagesTest extends TestCase
{
    /** What the old sorted form did, kept here so the difference is visible. */
    private static function sorted(int $main, array $gallery): string
    {
        return ProjectedFieldOwnership::idsToValue(array_merge([$main], $gallery));
    }

    public function testSwappingTheMainPictureIsAChange(): void
    {
        $before = StorefrontImages::encode(10, [20, 30]);
        $after = StorefrontImages::encode(20, [10, 30]);

        self::assertNotSame($before, $after, 'the featured picture moved, and that is the change');
        // And the proof that this needed fixing: the form it replaces said
        // these two were identical.
        self::assertSame(
            self::sorted(10, [20, 30]),
            self::sorted(20, [10, 30]),
            'sanity: the sorted form really could not tell them apart'
        );
    }

    public function testReorderingTheGalleryIsAChange(): void
    {
        self::assertNotSame(
            StorefrontImages::encode(10, [20, 30]),
            StorefrontImages::encode(10, [30, 20])
        );
        self::assertSame(self::sorted(10, [20, 30]), self::sorted(10, [30, 20]), 'sanity');
    }

    public function testRemovingAPictureIsAChange(): void
    {
        self::assertNotSame(
            StorefrontImages::encode(10, [20, 30]),
            StorefrontImages::encode(10, [20])
        );
    }

    public function testNoFeaturedPictureIsAStateOfItsOwn(): void
    {
        // A gallery with nothing featured is something WooCommerce allows and
        // a manager may have arranged. It must not read as «same as before».
        self::assertNotSame(
            StorefrontImages::encode(10, [20]),
            StorefrontImages::encode(0, [10, 20])
        );
        self::assertSame(0, StorefrontImages::decode(StorefrontImages::encode(0, [10, 20]))->main);
    }

    public function testTheMainPictureNeverAppearsTwice(): void
    {
        // The two sides disagree about whether the featured id belongs in the
        // list: WooCommerce keeps them apart, the marketplace row keeps one
        // list. Both must encode to the same thing or every projection would
        // report a change.
        self::assertSame(
            StorefrontImages::encode(10, [20, 30]),          // WooCommerce's shape
            StorefrontImages::encode(10, [10, 20, 30])       // the marketplace row's shape
        );
    }

    public function testDecodingGivesBackTheSameArrangement(): void
    {
        $images = StorefrontImages::decode(StorefrontImages::encode(20, [10, 30]));
        self::assertSame(20, $images->main);
        self::assertSame([10, 30], $images->gallery);
        self::assertSame([20, 10, 30], $images->ids(), 'one list, featured first');
    }

    public function testAdoptingAnArrangementIsStableOnTheNextRun(): void
    {
        // What «نسخهٔ ووکامرس بماند» does: decode the shop's arrangement into
        // the product record, then project again. If those two disagreed the
        // product would flap between two states for ever.
        $shop = StorefrontImages::encode(20, [10, 30]);
        $record = StorefrontImages::decode($shop);

        self::assertSame(
            $shop,
            StorefrontImages::encode($record->main, $record->ids()),
            'encoding the adopted record reproduces exactly what the shop had'
        );
    }

    public function testAnEmptyGalleryIsNotTheSameAsOnePicture(): void
    {
        self::assertNotSame(StorefrontImages::encode(0, []), StorefrontImages::encode(10, []));
        self::assertTrue(StorefrontImages::decode(StorefrontImages::encode(0, []))->isEmpty());
    }

    public function testAValueWrittenByTheVersionBeforeThisOneStillReads(): void
    {
        // `alpha.26` stored a bare sorted list. It decodes as «first id is
        // the featured one», which is what that version meant — and it will
        // not fingerprint the same as the new form, so such a field waits for
        // a decision instead of being overwritten.
        $legacy = StorefrontImages::decode('10,20,30');
        self::assertSame(10, $legacy->main);
        self::assertSame([20, 30], $legacy->gallery);
        self::assertNotSame('10,20,30', StorefrontImages::encode(10, [20, 30]));
    }

    public function testCategoriesKeepTheSortedSetBecauseOrderMeansNothingThere(): void
    {
        // The sorted form was not wrong, it was wrong FOR IMAGES. A product
        // in categories 19 and 23 is in the same two categories whichever
        // order they arrive in, and this must keep reporting «no change».
        self::assertSame(
            ProjectedFieldOwnership::idsToValue([19, 23]),
            ProjectedFieldOwnership::idsToValue([23, 19])
        );
    }

    public function testRemovingTheFeaturedPictureDoesNotPromoteTheNextOne(): void
    {
        // What the projector's write path now relies on. «بدون تصویر اصلی»
        // with a gallery still in it is an arrangement somebody chose, and
        // quietly publishing the first gallery picture in the empty slot
        // would be this plugin choosing a product's main photo for them.
        $images = StorefrontImages::of(0, [20, 30]);
        self::assertSame(0, $images->main);
        self::assertSame([20, 30], $images->gallery);
        self::assertSame([20, 30], $images->ids(), 'main-first only applies when there IS a main');
    }
}
