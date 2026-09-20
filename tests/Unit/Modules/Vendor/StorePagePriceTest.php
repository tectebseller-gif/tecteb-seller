<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the price a shopper reads is the price the vendor typed.
 *
 * `ProductDetails::$priceMinor` is an integer, and until `alpha.23` two
 * surfaces disagreed about what it counts. The vendor's form labels the box
 * «قیمت (تومان)» and stores the digits as typed; its review step prints
 * `number_format($priceMinor)` unchanged; `WooCommerceProjector` writes the
 * same integer into `_price`. The public store page divided it by 100.
 *
 * So a product entered at 2,450,000 تومان appeared to its own vendor, to the
 * manager and to WooCommerce as 2,450,000 — and to a BUYER, on the only page
 * built for buyers, as ۲۴٬۵۰۰. Nothing failed; the number was simply wrong on
 * the one screen that sells.
 *
 * It is tested from the source rather than by calling, for the same reason
 * `StaffActivityLabelsTest` is: the formatter is private, it calls `__()`,
 * and what is under test is the arithmetic, not the rendering.
 */
final class StorePagePriceTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/src/' . $relative;
        $code = file_get_contents($path);
        self::assertIsString($code, $relative . ' could not be read');
        return $code;
    }

    public function testThePublicStorePageDoesNotRescaleThePrice(): void
    {
        $code = $this->source('Modules/Vendor/Presentation/StorePageView.php');
        $start = strpos($code, 'private static function toman(');
        self::assertIsInt($start, 'the store page has no price formatter any more — rename or removal, either way look');
        $body = substr($code, $start, 400);

        self::assertStringNotContainsString(
            '/ 100',
            $body,
            'the store page is dividing the price again: a buyer would see one hundredth of what the vendor typed'
        );
        self::assertStringContainsString('number_format(', $body, 'the price is still grouped for reading');
    }

    /**
     * The other half: the two surfaces have to agree, so this pins the one
     * the fix was measured against. If the vendor form ever starts scaling,
     * this fails and the pair gets looked at together rather than one of them
     * being «corrected» to match a bug.
     */
    public function testTheVendorFormShowsTheSameUnit(): void
    {
        $code = $this->source('Modules/Product/Presentation/ProductFormView.php');
        self::assertStringContainsString(
            'number_format($d->priceMinor)',
            $code,
            'the vendor review step changed how it prints the price; the store page must be changed with it'
        );
        self::assertStringNotContainsString('priceMinor / 100', $code);
    }
}
