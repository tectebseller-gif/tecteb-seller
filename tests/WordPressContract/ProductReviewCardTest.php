<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewCardView;

/**
 * The owner's complaint about the review screen, held open by a test.
 *
 * «شمارندهٔ تصویر و شناسهٔ عددی دسته کافی نیست» — and the screen that was
 * there showed exactly those two things. A manager cannot decide whether a
 * medical product may go on sale from «تصویر: ۳» and «دسته: 30».
 */
final class ProductReviewCardTest extends TestCase
{
    private function product(int $wcProductId = 0): Product
    {
        return new Product(
            7,
            41,
            new ProductDetails(
                'آمبوبگ سیلیکونی بزرگسال',
                'simple',
                '30',
                'مدیکو',
                'کیسهٔ تنفس دستی قابل اتوکلاو',
                2450000,
                null,
                null,
                null,
                'AMB-01',
                12
            ),
            ProductStatus::Submitted,
            ['volume' => '1500', 'material' => 'سیلیکون'],
            [101, 102],
            101,
            '',
            1,
            null,
            '',
            $wcProductId > 0 ? $wcProductId : null
        );
    }

    private function template(): SpecTemplate
    {
        return new SpecTemplate(3, '30', 'آمبوبگ', 1, [
            new SpecField(1, 'volume', 'حجم کیسه', SpecFieldType::Number, true, 'میلی‌لیتر'),
            new SpecField(2, 'material', 'جنس', SpecFieldType::Text),
        ]);
    }

    /** @return list<array{url:string,id:int,main:bool}> */
    private function images(): array
    {
        return [
            ['id' => 101, 'url' => 'https://shop.test/wp-content/uploads/ambo-1.jpg', 'main' => true],
            ['id' => 102, 'url' => 'https://shop.test/wp-content/uploads/ambo-2.jpg', 'main' => false],
        ];
    }

    public function testTheCardShowsTheProductRatherThanCountersAndIds(): void
    {
        $html = ProductReviewCardView::render(
            $this->product(),
            $this->images(),
            'تجهیزات پزشکی › بیهوشی و تنفسی › آمبوبگ',
            $this->template(),
            ['name' => 'فروشگاه سلامت پارس', 'city' => 'تهران', 'status' => 'اجازهٔ فروش دارد'],
            [],
            "کیسهٔ تنفس دستی قابل اتوکلاو\n\nحجم کیسه: 1500 میلی‌لیتر",
            '',
            '',
            '<input type="hidden" name="n" value="x">',
            true
        );

        self::assertStringContainsString('ambo-1.jpg', $html, 'the picture is on the page, not counted');
        self::assertStringContainsString('ambo-2.jpg', $html);
        self::assertStringContainsString('تجهیزات پزشکی › بیهوشی و تنفسی › آمبوبگ', $html, 'the category is a path, not an id');
        self::assertStringContainsString('کیسهٔ تنفس دستی قابل اتوکلاو', $html, 'the short description is shown');
        self::assertStringContainsString('حجم کیسه', $html, 'the spec label, not its storage key');
        self::assertStringContainsString('میلی‌لیتر', $html, 'with its unit');
        self::assertStringContainsString('فروشگاه سلامت پارس', $html, 'and which shop it came from');
        // Grouped and in Persian digits. The separator is whatever
        // `number_format` produced — the point of the assertion is that the
        // manager reads ۲,۴۵۰,۰۰۰ rather than 2450000.
        self::assertStringContainsString('۲,۴۵۰,۰۰۰ تومان', $html, 'the price as a person reads it');
        self::assertStringContainsString('۱۲', $html, 'and the stock');
    }

    public function testAProductWithNoPictureIsCalledOutRatherThanLeftBlank(): void
    {
        $html = ProductReviewCardView::render(
            $this->product(),
            [],
            'تجهیزات پزشکی',
            null,
            [],
            [],
            '',
            '',
            '',
            '',
            true
        );
        self::assertStringContainsString('هیچ تصویری ندارد', $html);
    }

    public function testWithoutAStorefrontRowTheOfferIsPreparationAndNotPublication(): void
    {
        $html = ProductReviewCardView::render(
            $this->product(),
            $this->images(),
            'تجهیزات پزشکی',
            null,
            [],
            [],
            '',
            '',
            'Rank Math',
            '',
            true
        );
        self::assertStringContainsString('value="prepare"', $html);
        self::assertStringContainsString('پیش‌نویس', $html, 'and it says what «آماده‌سازی» produces');
        self::assertStringContainsString('قابل خرید نیست', $html, 'before anybody presses it');
        self::assertStringNotContainsString('value="approve"', $html, 'the card itself never publishes');
    }

    public function testWithAStorefrontRowTheManagerIsSentIntoWooCommerceRatherThanASecondEditor(): void
    {
        $html = ProductReviewCardView::render(
            $this->product(1234),
            $this->images(),
            'تجهیزات پزشکی',
            null,
            [],
            [],
            '',
            'https://shop.test/wp-admin/post.php?post=1234&action=edit',
            'Rank Math',
            '',
            true
        );
        self::assertStringContainsString('post=1234&amp;action=edit', $html, 'a link into the real editor');
        self::assertStringContainsString('Rank Math', $html, 'named, so nobody retypes the SEO somewhere else');
        self::assertStringContainsString('محصول را منتشر نمی‌کند', $html, 'and opening it cannot publish');
        // No second editor: the card carries no rich-text field of its own for
        // the description, and no SEO inputs at all.
        self::assertStringNotContainsString('name="seo_title"', $html);
        self::assertStringNotContainsString('name="fix_description"', $html);
    }

    public function testADisagreementIsShownWithBothValuesAndTwoWaysToEndIt(): void
    {
        $fields = [
            new StorefrontField(
                'title',
                'آمبوبگ سیلیکونی بزرگسال',
                'آمبوبگ سیلیکونی بزرگسال — ویرایش مدیر',
                'آمبوبگ سیلیکونی بزرگسال نسخهٔ ۲',
                StorefrontField::OWNER_MANAGER
            ),
        ];
        $html = ProductReviewCardView::render(
            $this->product(1234),
            $this->images(),
            'تجهیزات پزشکی',
            null,
            [],
            $fields,
            '',
            'https://shop.test/wp-admin/post.php?post=1234&action=edit',
            '',
            '',
            true
        );
        self::assertStringContainsString('ویرایش مدیر', $html, 'what WooCommerce has');
        self::assertStringContainsString('نسخهٔ ۲', $html, 'and what the vendor asked for');
        self::assertStringContainsString('value="keep"', $html);
        self::assertStringContainsString('value="accept"', $html);
    }

    public function testTheDerivedDescriptionIsCalledOutAsNotRoundTripping(): void
    {
        $fields = [
            new StorefrontField(
                'description',
                'متن ساخته‌شده',
                'متنی که مدیر نوشته',
                '',
                StorefrontField::OWNER_MANAGER,
                false
            ),
        ];
        $html = ProductReviewCardView::render(
            $this->product(1234),
            $this->images(),
            'تجهیزات پزشکی',
            null,
            [],
            $fields,
            '',
            '',
            '',
            '',
            true
        );
        self::assertStringContainsString('بازارگاه دیگر آن را نمی‌نویسد', $html);
    }

    public function testWithoutWooCommerceTheCardSaysSoInsteadOfOfferingLinks(): void
    {
        $html = ProductReviewCardView::render(
            $this->product(),
            $this->images(),
            '',
            null,
            [],
            [],
            '',
            '',
            '',
            '',
            false
        );
        self::assertStringContainsString('ووکامرس فعال نیست', $html);
        self::assertStringNotContainsString('value="prepare"', $html);
    }

    public function testAProductOlderThanTheStampsGetsItsOwnBlockAndABulkWayOut(): void
    {
        $fields = [
            new StorefrontField(
                'title',
                'عنوان بازارگاه',
                'عنوانی که مدیر نوشته',
                '',
                StorefrontField::OWNER_UNKNOWN
            ),
            // Same on both sides, and it STILL has to appear: the field is
            // frozen until somebody settles it, and a vendor whose next edit
            // silently becomes a proposal deserves a screen that said why.
            new StorefrontField(
                'short_description',
                'توضیح یکسان',
                'توضیح یکسان',
                '',
                StorefrontField::OWNER_UNKNOWN
            ),
        ];
        $html = ProductReviewCardView::render(
            $this->product(1234),
            $this->images(),
            'تجهیزات پزشکی',
            null,
            [],
            $fields,
            '',
            'https://shop.test/wp-admin/post.php?post=1234&action=edit',
            '',
            '',
            true
        );

        self::assertStringContainsString('فیلدهای بدون سابقه', $html, 'its own block, not the disagreement table');
        self::assertStringContainsString('سابقه‌ای', $html, 'and it explains what that means');
        self::assertStringContainsString('هر دو طرف یکی است', $html, 'the agreeing field says so rather than looking like a conflict');
        self::assertStringContainsString('value="product_fields"', $html, 'one button for the whole product');
        self::assertSame(2, substr_count($html, 'name="field" value="'), 'and one pair per field as well');
    }

    public function testASettledProductShowsNoOwnershipTableAtAll(): void
    {
        $settled = [
            new StorefrontField('title', 'یکی', 'یکی', '', StorefrontField::OWNER_MARKETPLACE),
            new StorefrontField('description', 'الف', 'ب', '', StorefrontField::OWNER_MANAGER, false, true),
        ];
        $html = ProductReviewCardView::render(
            $this->product(1234),
            $this->images(),
            'تجهیزات پزشکی',
            null,
            [],
            $settled,
            '',
            '',
            '',
            '',
            true
        );
        self::assertStringContainsString('یکی است', $html);
        self::assertStringNotContainsString('فیلدهای بدون سابقه', $html);
        self::assertStringNotContainsString('value="keep"', $html, 'a decided field is not asked about again');
    }
}
