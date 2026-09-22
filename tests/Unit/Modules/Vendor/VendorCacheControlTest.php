<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorCacheControl;

/**
 * Which URLs count as «the vendor panel», decided without WordPress.
 *
 * It has to be answerable from the raw URI alone: the decision is made at
 * `plugins_loaded`, where the rewrite rules have not run and there is no
 * query var yet. That is the whole reason this is a pure function with its
 * own test rather than a `get_query_var()` call.
 */
final class VendorCacheControlTest extends TestCase
{
    /** @return array<string,array{0:string,1:bool}> */
    public static function uris(): array
    {
        return [
            'the panel root' => ['/vendor/', true],
            'without the trailing slash' => ['/vendor', true],
            'a view' => ['/vendor/products/', true],
            // The one the owner used to get their data back. It must be
            // rejected too — otherwise the workaround becomes the fix.
            'a view with a cache-busting parameter' => ['/vendor/products/?tmc_check=2241', true],
            'a view with a real parameter' => ['/vendor/products/?status=draft&paged=2', true],
            'the invite page' => ['/vendor/invite/?token=abc', true],
            'plain permalinks' => ['/index.php?tmc_vendor=products', true],
            'a subdirectory install' => ['/shop/vendor/orders/', true],

            'the shop' => ['/?post_type=product', false],
            'a product' => ['/product/vendor-branded-thing/', false],
            // «vendor» inside a word is not the panel.
            'a page whose slug merely contains it' => ['/our-vendors/', false],
            'the home page' => ['/', false],
            'an empty query var' => ['/index.php?tmc_vendor=', false],
        ];
    }

    /** @dataProvider uris */
    public function testTheVendorPanelIsRecognisedFromTheRawUri(string $uri, bool $expected): void
    {
        self::assertSame($expected, VendorCacheControl::isVendorRequest($uri), $uri);
    }

    public function testTheRejectListGainsTheVendorPathsAndStaysIdempotent(): void
    {
        $first = VendorCacheControl::addRejectedUris([]);
        self::assertContains('/vendor/(.*)', $first);

        // A caching plugin rebuilds its config more than once; the same path
        // must not pile up in the file.
        self::assertSame($first, VendorCacheControl::addRejectedUris($first));
    }

    public function testAnUnexpectedShapeIsHandedBackUntouched(): void
    {
        // WP Rocket has passed a string here in the past. Returning an array
        // where the caller expects a string writes a broken config file.
        self::assertSame('not-an-array', VendorCacheControl::addRejectedUris('not-an-array'));
    }
}
