<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;

/**
 * The waiting products are named, their two doors are gated apart, and an
 * unfinished page never claims to be the whole list.
 *
 * Three properties that no behaviour test can reach, so they are asserted in
 * the source — each on the BODY of the method that owns it, extracted by
 * matching braces rather than «up to the next `function`» (`alpha.77`).
 */
final class WaitingProductsAreNamedTest extends TestCase
{
    private const PAGE = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php';

    /**
     * ⛔ CLAUSE 6, and it cannot be measured behaviourally without 501 rows:
     * the sentence beside the table is chosen by the flag the scan sets, and
     * BOTH sentences have to exist. A test that only checked the honest one
     * would pass on a page that printed it unconditionally — which is exactly
     * the «a page that hit its cap claimed to be all of them» defect
     * (`alpha.70`).
     */
    public function testTheSentenceBesideTheRowsIsChosenByWhetherTheScanFinished(): void
    {
        $body = $this->body('renderCategoryApply');

        // ⛔ `esc_html(` is part of the needle on purpose: the method ALREADY
        // tested `$plan['finished']` higher up, for the scan notice, so the
        // bare flag survived a mutation that replaced this very conditional
        // with `true`. A needle has to be unique to the statement it is
        // supposed to break (`alpha.81`).
        self::assertStringContainsString(
            "esc_html((bool) \$plan['finished']",
            $body,
            'the rows must be introduced by the flag, not by a fixed sentence'
        );
        self::assertStringContainsString(
            'این ردیف‌ها همان‌هایی‌اند که شمارندهٔ دروازه می‌شمارد.',
            $body,
            'the finished case'
        );
        self::assertStringContainsString(
            'این ردیف‌ها فقط همین صفحه‌اند، نه همهٔ آن‌ها.',
            $body,
            'and the unfinished case, which is the one that matters'
        );
    }

    /**
     * The shop comes off the row the counter counted — not from a second read.
     *
     * «فهرست باید دقیقاً از همان داده‌هایی ساخته شود که شمارنده دروازه
     * استفاده می‌کند.» A lookup of our own here would be a second source for
     * the same fact, and the two could disagree.
     */
    public function testTheShopOnEachRowComesFromThePlanRow(): void
    {
        $body = $this->body('renderCategoryApply');

        self::assertStringContainsString(
            "\$row['vendor_user_id']",
            $body,
            'the vendor is read off the plan row'
        );
        // ⛔ And the comments are emptied first, because this method's own
        // docblock NAMES those methods to explain where the rows come from —
        // and a byte-level check cannot tell prose from code. The `alpha.22`
        // trap: the sentence «we do not do this» breaks the test for it. This
        // is how the first run of this very test failed.
        $code = self::withoutComments($body);
        foreach (['importedWithoutCategory', 'countImportedWithoutCategory', '$wpdb'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'the renderer must not ask the data again: ' . $forbidden
            );
        }
    }

    /**
     * CLAUSE 3: two doors, two capabilities, and neither implies the other.
     *
     * Asserted in both directions, because half of this rule is that the
     * marketplace review screen is NOT opened by the capability that opens
     * this page (`alpha.86`: «نتیجهٔ یکسان ⇒ مجوز یکسان؛ نتیجهٔ متفاوت ⇒ مجوز
     * متفاوت»).
     */
    public function testTheTwoLinksAreGatedIndependently(): void
    {
        self::assertNotSame(
            Capabilities::REVIEW_VENDOR,
            Capabilities::REVIEW_PRODUCTS,
            'the whole point is that these are different permissions'
        );

        $page = $this->source();
        self::assertStringContainsString(
            'current_user_can(ProductReviewPage::CAPABILITY)',
            $page,
            'the review link asks the review screen’s own capability'
        );

        $review = $this->body('marketplaceReviewCell');
        self::assertStringContainsString('ProductReviewPage::SLUG', $review);
        self::assertStringNotContainsString(
            'self::CAPABILITY',
            $review,
            'this page’s capability must not be what opens the other page'
        );

        // And the WooCommerce half asks nothing of ours: core decides, by
        // handing out no link at all.
        $editor = $this->body('storefrontEditCell');
        self::assertStringContainsString('editorUrl(', $editor);
        self::assertStringNotContainsString(
            'current_user_can',
            $editor,
            'edit_post is enforced by get_edit_post_link() returning nothing'
        );
    }

    /**
     * CLAUSE 4: in both cells every refusal comes BEFORE the link.
     *
     * Position, because a cell that built the anchor first and then returned a
     * reason would still contain both strings. The guards are what make «no
     * invalid link» true, and `strpos` is how «inside, not merely present» is
     * measured (`alpha.74`).
     */
    public function testNeitherCellCanPrintALinkBeforeItsGuards(): void
    {
        foreach (['storefrontEditCell', 'marketplaceReviewCell'] as $method) {
            $body = $this->body($method);
            $anchor = strpos($body, '<a href="');
            self::assertIsInt($anchor, $method . '() must be able to print a link');
            $lastGuard = strrpos($body, 'tmc-field__desc');
            self::assertIsInt($lastGuard, $method . '() must be able to refuse');
            self::assertLessThan(
                $anchor,
                $lastGuard,
                $method . '(): every named refusal has to be reachable before the link'
            );
        }
    }

    /** The same body with every comment blanked — prose is not code. */
    private static function withoutComments(string $php): string
    {
        $out = '';
        foreach (token_get_all('<?php ' . $php) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    private function source(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . self::PAGE);
        self::assertIsString($source);
        return $source;
    }

    /** One method's body, by matching braces. */
    private function body(string $method): string
    {
        $source = $this->source();
        $at = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($at, $method . '() not found');
        $open = strpos($source, '{', $at);
        self::assertIsInt($open);
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        self::fail($method . '() has no closing brace');
    }
}
