<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;

/**
 * WHAT THIS PROVES: a refusal the vendor can reach is never printed as «انجام
 * شد.»
 *
 * ⛔ THE DEFECT IT WAS WRITTEN FOR IS THE WORST SHAPE THIS CAN TAKE.
 * `ProductFormView` asks three catalogues in turn — product, marketplace,
 * vendor — and `VendorMessages::notice()` ends in
 * `default => __('انجام شد.')`. A code none of the three knows therefore
 * prints as DONE. Measured on `alpha.107` bytes:
 * `parent_figures_live_in_shop` — the guard `alpha.106` shipped as its P0,
 * the one that stops a variable parent's price and stock being overwritten —
 * returned with no sentence anywhere, so the vendor's save was correctly
 * refused and the screen told them it had worked. Three other codes were in
 * the same state.
 *
 * Nothing in PHP notices that. A refusal is a `string` and a catalogue is a
 * `match`, and the two drift apart silently for as long as nobody reads them
 * side by side. This reads them side by side.
 *
 * ⛔ IN THE CONTRACT SUITE, not the architecture one: the catalogues call
 * `__()`, and the architecture bootstrap defines no WordPress at all. The
 * first version of this file lived there and errored on its own first
 * assertion.
 *
 * ⛔ AND IT IS AN OPT-IN LIST, deliberately, with the limit stated rather
 * than hidden: these are the application classes whose `OperationResult`
 * reaches the vendor's own screens. An admin-only verb is a different
 * question — `StorefrontStop`'s five codes, for instance, are answered inline
 * on `StorefrontPage` with a full sentence each and would be false positives
 * here. When a new verb starts answering the vendor area, add its file.
 */
final class EveryRefusalTheVendorSeesHasASentenceTest extends TestCase
{
    /**
     * The verbs whose refusals land on a vendor screen.
     *
     * @var list<string>
     */
    private const VENDOR_FACING = [
        'src/Modules/Product/Application/ManageProducts.php',
        'src/Modules/Product/Application/ManageVariations.php',
        'src/Modules/Product/Application/ManageBrands.php',
        'src/Modules/Product/Application/EditStorefrontVariations.php',
        'src/Modules/Product/Application/ProductReadiness.php',
        'src/Modules/Product/Application/ProductCsv.php',
        'src/Modules/Marketplace/Application/ManageWholesale.php',
    ];

    /**
     * Codes built from a constant rather than written inline, so the scan
     * below cannot see them.
     *
     * `ProductTypeChange` returns one of two reasons and `ManageProducts`
     * passes it through as a variable — which is the right shape for one rule
     * read at both ends, and invisible to a regex. Named here instead.
     *
     * @var list<string>
     */
    private const ALSO = [
        'type_change_not_allowed',
        'type_change_needs_new_product',
    ];

    public function testEveryRefusalCodeHasAPersianSentence(): void
    {
        $fallback = VendorMessages::notice('a_code_no_catalogue_can_possibly_know', []);
        self::assertNotSame('', $fallback, 'sanity: the vendor catalogue has a default, and that is the hazard');

        $unanswered = [];
        foreach ($this->codes() as $code => $where) {
            $product = ProductMessages::notice($code, []);
            $marketplace = MarketplaceMessages::notice($code, []);
            $vendor = VendorMessages::notice($code, []);
            $answered = $product !== null
                || $marketplace !== null
                || ($vendor !== null && $vendor !== $fallback);
            if (!$answered) {
                $unanswered[] = $code . ' (' . implode(', ', $where) . ')';
            }
        }
        sort($unanswered);
        self::assertSame(
            [],
            $unanswered,
            "these refusals print as «انجام شد.» on the vendor's screen:\n" . implode("\n", $unanswered)
        );
    }

    /**
     * And the tone is a refusal's tone, not a success's.
     *
     * `ProductFormView` paints the notice green unless BOTH catalogues that
     * own codes call it an error, so a code neither recognises could in
     * principle be painted as a success even once it has a sentence.
     */
    public function testEveryRefusalCodeIsTreatedAsAnError(): void
    {
        $successes = [];
        foreach (array_keys($this->codes()) as $code) {
            $isSuccess = !ProductMessages::isErrorNotice($code)
                || !MarketplaceMessages::isErrorNotice($code);
            if ($isSuccess) {
                $successes[] = $code;
            }
        }
        sort($successes);
        self::assertSame([], $successes, "painted as a success:\n" . implode("\n", $successes));
    }

    /** @return array<string,list<string>> code => the files that return it */
    private function codes(): array
    {
        $root = dirname(__DIR__, 2) . '/';
        $codes = [];
        foreach (self::VENDOR_FACING as $relative) {
            $path = $root . $relative;
            self::assertFileExists($path, 'the list names a file that is gone');
            $body = (string) file_get_contents($path);
            if (preg_match_all("/failure\\(\\s*'([a-z0-9_]+)'/", $body, $m) === false) {
                continue;
            }
            foreach ($m[1] as $code) {
                $codes[$code][basename($relative)] = true;
            }
        }
        foreach (self::ALSO as $code) {
            $codes[$code]['ProductTypeChange.php'] = true;
        }
        self::assertGreaterThan(50, count($codes), 'sanity: the scan found the codes');
        return array_map(static fn (array $files): array => array_keys($files), $codes);
    }
}
