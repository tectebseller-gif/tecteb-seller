<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: `post_author` is neither read as ownership nor rewritten
 * on a product this plugin did not create.
 *
 * §۴ names four facts and asks for them to be kept apart — «`wp_posts.post_author`،
 * دادهٔ مالکیت خودِ دکان، `tmc_products.vendor_user_id` ما و `link_ownership`
 * را از هم جدا نگه دار؛ هیچ‌وقت `post_author` را خودکار عوض نکن» — and this
 * round found both halves broken in different places:
 *
 *  • the reconciliation's other direction could have asked «which shop
 *    products are authored by this vendor», which is a question about DOKAN,
 *    not about a missing marketplace row; and
 *  • the projector rewrote `post_author` at the end of EVERY projection, so
 *    the first sync after an ownership transfer changed the one fact Dokan
 *    attributes a product by — on 184 imported products, silently.
 *
 * The behaviour of the second is pinned in both directions by
 * `ProjectedFactsTest::testTheAuthorOfAProductThatWasAlreadyThereIsNeverRewritten`.
 * What a behavioural test cannot say is «and no OTHER statement in the
 * repository grew the same habit», which is what this file is for.
 *
 * ⛔ Comments are blanked before the needles are looked for (`alpha.22`): the
 * docblocks of both methods explain `post_author` at length, by design, and a
 * test that searched the raw bytes would be measuring its own prose.
 */
final class PostAuthorIsNotOwnershipTest extends TestCase
{
    private const REPOSITORY = __DIR__ . '/../../src/Modules/Product/Infrastructure/DbProductRepository.php';
    private const PROJECTOR = __DIR__
        . '/../../src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php';

    /** The file with every comment replaced by whitespace. */
    private function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * The catalogue reconciliation asks our own mark, never the author.
     *
     * Both directions of the report live in this file, and neither may read
     * `post_author`: the one claim this plugin writes on a shop product is
     * `WooCommerceProjector::VENDOR_META`.
     */
    public function testTheProductRepositoryNeverReadsPostAuthor(): void
    {
        self::assertStringNotContainsString(
            'post_author',
            $this->code(self::REPOSITORY),
            '⛔ §۴: a product authored by a Dokan seller is evidence about Dokan, '
            . 'not about a missing marketplace row'
        );
    }

    /**
     * And the projector writes it only for a post it has just created.
     *
     * Asserted on the source as well as on behaviour, because the behavioural
     * test needs a WooCommerce double to exist and this one does not: the
     * single `wp_update_post` that names `post_author` must sit inside the
     * `if ($isNew)` block, and the block is extracted by matching braces
     * rather than by a character window (`alpha.98`).
     */
    public function testTheProjectorWritesTheAuthorOnlyForAProductItCreated(): void
    {
        $code = $this->code(self::PROJECTOR);
        $needle = "'post_author' => \$product->vendorUserId";
        self::assertSame(
            1,
            substr_count($code, $needle),
            'exactly one statement may name post_author'
        );

        // EVERY `if ($isNew)` block in the file, brace-matched — the method
        // has several and the write belongs to one of them. Asking only about
        // the first would be a test about whichever block happens to come
        // earliest in the file.
        $guarded = false;
        $from = 0;
        while (($at = strpos($code, 'if ($isNew) {', $from)) !== false) {
            $open = (int) strpos($code, '{', $at);
            $depth = 0;
            $end = null;
            for ($i = $open, $len = strlen($code); $i < $len; $i++) {
                if ($code[$i] === '{') {
                    $depth++;
                } elseif ($code[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            self::assertIsInt($end, 'every guarded block must close');
            if (str_contains(substr($code, $open, $end - $open), $needle)) {
                $guarded = true;
                break;
            }
            $from = $at + 1;
        }
        self::assertTrue(
            $guarded,
            '⛔ the author of a product this run did not create is not ours to change'
        );
    }
}
