<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

/**
 * WHAT THIS PROVES: the WooCommerce status an intake copies is decided by ONE
 * total map, and every status this marketplace cannot hold is refused rather
 * than rounded to the nearest one it can.
 *
 * §1-د asked for an explicit mapping «نه کپی کور از published/draft». What was
 * there was `$status === 'publish' ? Published : Draft`, and the cost is not
 * only tidiness: `project()` writes our status to the shop unconditionally, so
 * a `private` product taken in as «پیش‌نویس» becomes `draft` at the first
 * projection — removed even from the roles that could see it — and a `future`
 * one loses its schedule. Measured on the installed `alpha.65` bytes:
 * `shop_status_before=private` → `shop_status_after=draft`.
 *
 * Exhaustive rather than by example, because `post_status` is extensible and
 * the interesting case is always the status nobody listed.
 */
final class IntakeStatusMapTest extends TestCase
{
    public function testEveryStatusThisMarketplaceCanHoldIsNamed(): void
    {
        self::assertSame(ProductStatus::Published, ProductStatus::forIntakeOf('publish'));
        self::assertSame(ProductStatus::Draft, ProductStatus::forIntakeOf('draft'));
        // `pending` is a draft here and NOT «ارسال‌شده»: a submission is an act
        // of the vendor with a row in `tmc_product_decisions` behind it, and an
        // intake has none — so a product marked submitted would sit in the
        // review queue with an identity of `s0.r0.b0`, a decision nobody asked
        // for and a badge nobody can clear.
        self::assertSame(ProductStatus::Draft, ProductStatus::forIntakeOf('pending'));
        self::assertSame(ProductStatus::Published, ProductStatus::forIntakeOf('  publish  '));
    }

    public function testEveryOtherStatusIsRefusedRatherThanRounded(): void
    {
        foreach ([
            'private',
            'future',
            'trash',
            'auto-draft',
            'inherit',
            '',
            'wc-processing',
            'some-plugin-status',
        ] as $status) {
            self::assertNull(
                ProductStatus::forIntakeOf($status),
                $status . ' must be refused, not mapped to the nearest status we have'
            );
        }
    }

    /**
     * WHAT THIS PROVES: «قیمتی ثبت نشده» has ONE definition, and the projector
     * and the readiness check now read the same one.
     *
     * `missingFields()` has named `price` for `<= 0` since the four-step form
     * existed — so nought cannot mean «free» in this marketplace, and a
     * product carrying it can never be approved or published. The projector
     * read it the other way and wrote the nought to the shop as a price.
     */
    public function testNoughtIsNotAPriceAndSaysSoOnce(): void
    {
        $priceless = new ProductDetails('عنوان', 'simple', 'cat', '', '', 0);
        self::assertFalse($priceless->hasPrice());
        self::assertContains('price', $priceless->missingFields());
        self::assertFalse($priceless->isComplete());

        $priced = new ProductDetails('عنوان', 'simple', 'cat', '', '', 450000);
        self::assertTrue($priced->hasPrice());
        self::assertNotContains('price', $priced->missingFields());
    }
}
