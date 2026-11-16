<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the WooCommerce class of a product is chosen once, when
 * the product is created, and nothing in this plugin ever changes it again.
 *
 * ⛔ THIS IS THE MEASUREMENT `ProductTypeChange` RESTS ON, so it is pinned
 * here rather than described in a docblock. §3 asks when a type change is
 * safe, and the answer turns entirely on whether the last leg of the path
 * exists: `project()` takes `$linked ?? $this->blank($product)`, and
 * `blank()` is the only place either `WC_Product_Simple` or
 * `WC_Product_Variable` is constructed. A product that is already in the shop
 * takes the `$linked` branch, so its type is never restated.
 *
 * Two assertions, in opposite directions:
 *
 *  1. The two classes are constructed in `blank()` and nowhere else.
 *  2. No writer for the type exists at all — no `set_props(['type' => …])`,
 *     no `wp_set_object_terms(…, 'product_type')`, no `->set_type(`.
 *
 * ⛔ AND THIS IS NOT A COMPLAINT ABOUT THE PROJECTOR. Writing the type of a
 * live product would mean deciding what happens to its existing children, and
 * «تنوع‌های قدیمی WooCommerce دوباره ساخته یا حذف نشوند» forbids the two
 * answers that are easy. The projector is right not to; what was wrong was
 * accepting a request it could never carry out.
 */
final class ProjectorNeverChangesAnExistingTypeTest extends TestCase
{
    private const PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php';

    public function testTheProductClassIsChosenOnlyWhereANewProductIsBuilt(): void
    {
        $body = self::tokenised();

        // The class choice itself, in the one method that makes a new product.
        self::assertSame(
            1,
            preg_match_all('/new \\\\WC_Product_Variable\\(\\)/', $body),
            'exactly one place constructs a variable product'
        );
        self::assertSame(
            1,
            preg_match_all('/new \\\\WC_Product_Simple\\(\\)/', $body),
            'and exactly one constructs a simple one'
        );

        // Both inside `blank()`, which is reached only from the `?? ` below.
        $blank = self::methodBody($body, 'blank');
        self::assertStringContainsString('new \\WC_Product_Variable()', $blank);
        self::assertStringContainsString('new \\WC_Product_Simple()', $blank);
        self::assertStringContainsString(
            '$linked ?? $this->blank($product)',
            $body,
            'an existing product keeps the object it already has'
        );
    }

    public function testNothingWritesTheProductType(): void
    {
        $body = self::tokenised();
        foreach (['->set_type(', "'product_type'", '"product_type"'] as $writer) {
            self::assertStringNotContainsString(
                $writer,
                $body,
                'the type of a live WooCommerce product is nobody here\'s to rewrite'
            );
        }
    }

    /** Comments blanked first: these files explain the rule they obey. */
    private static function tokenised(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::PROJECTOR);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** The body of one method, by brace matching rather than by a window. */
    private static function methodBody(string $body, string $name): string
    {
        $at = strpos($body, 'function ' . $name . '(');
        self::assertNotFalse($at, 'the method is still called ' . $name);
        $open = strpos($body, '{', $at);
        self::assertNotFalse($open);
        $depth = 0;
        for ($i = $open, $n = strlen($body); $i < $n; $i++) {
            if ($body[$i] === '{') {
                $depth++;
            } elseif ($body[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $open, $i - $open + 1);
                }
            }
        }
        self::fail('unbalanced braces reading ' . $name);
    }
}
