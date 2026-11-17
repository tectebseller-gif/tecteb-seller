<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorRoutes;

/**
 * WHAT THIS PROVES: the vendor area's nonce field carries no `id`, so a page
 * printing it in many forms does not print many elements with one id — and it
 * still carries everything a nonce has to carry.
 *
 * ⛔ WHY IT IS A TEST OF ITS OWN. The defect is older than the round that
 * surfaced it: `wp_nonce_field()` gives its input `id` equal to its NAME, and
 * every form in the vendor area prints the same field, so every page with two
 * forms has had two elements sharing `tmc_vendor_nonce` since this area was
 * built. §5's per-combination cards made it nine on one page, which is how it
 * was measured — but counting cards is not what proves the fix, and the
 * product form's own render test cannot prove it either: that harness hands
 * the area a stub field of its own (`name="_wpnonce"`, no id), so the real
 * `wp_nonce_field()` output never reaches that HTML.
 *
 * This is the `alpha.57` defect, caused there by the same function on the
 * admin commission panel and fixed there the same way.
 */
final class VendorNonceFieldTest extends ContractTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheNonceFieldCarriesNoIdButKeepsEverythingElse(): void
    {
        $this->bootPlugin(true);

        $method = new \ReflectionMethod(VendorRoutes::class, 'nonceField');
        $method->setAccessible(true);
        $field = (string) $method->invoke(null);

        // The id is gone — and named, so a future edit that reintroduces it
        // under a different spelling still fails.
        self::assertStringNotContainsString('id="' . VendorRoutes::NONCE_FIELD . '"', $field);
        self::assertStringNotContainsString(' id=', $field);

        // Everything a nonce has to carry is still there: the field by NAME,
        // which is how every reader in this repository and in
        // `tools/browser/` selects it, a value, and the `_wp_http_referer`
        // input the third argument adds — the referer check behaves as before.
        self::assertStringContainsString('name="' . VendorRoutes::NONCE_FIELD . '"', $field);
        self::assertStringContainsString('name="_wp_http_referer"', $field);
        self::assertMatchesRegularExpression('/name="' . VendorRoutes::NONCE_FIELD . '" value="[^"]+"/', $field);
    }

    /**
     * ⛔ AND THE CONTROL: WordPress really does put that id there, so the
     * assertions above are measuring a removal rather than describing a
     * function that never had one.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWordPressItselfWouldHavePutTheIdThere(): void
    {
        $this->bootPlugin(true);

        $raw = (string) \wp_nonce_field('tmc_vendor', VendorRoutes::NONCE_FIELD, true, false);

        self::assertStringContainsString('id="' . VendorRoutes::NONCE_FIELD . '"', $raw);
    }
}
