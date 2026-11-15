<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Presentation\AttributeLabel;

/**
 * ⛔ THE DECODE IS DISPLAY ONLY, AND IT IS THE DANGEROUS KIND.
 *
 * Decoding is the one operation in this class that can turn stored text into
 * markup — `%3Cscript%3E` is seven harmless characters until something
 * decodes it. So the rules are measured rather than described: one pass, valid
 * UTF-8 or nothing, no control characters, and text out rather than HTML.
 *
 * Pure: no WordPress, no database, no product.
 */
final class AttributeLabelTest extends TestCase
{
    /**
     * The measured case. `sanitize_title('اندازه')` on WordPress 7.1 is this
     * string, and `wc_attribute_label()` with no product hands it straight
     * back — which is what the owner read in their own panel.
     */
    public function testAPercentEncodedPersianNameBecomesReadable(): void
    {
        self::assertSame(
            'اندازه',
            AttributeLabel::forDisplay('%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87')
        );
    }

    public function testAnOrdinaryNameIsUntouched(): void
    {
        foreach (['pa_size', 'Size', 'اندازه', 'size-2', '', 'a+b'] as $name) {
            self::assertSame($name, AttributeLabel::forDisplay($name), $name . ' must not be rewritten');
        }
    }

    /**
     * ⛔ ONE PASS, NEVER A LOOP. `%2525` is `%25` once decoded and `%` twice,
     * so a repeating decode lets stored text claim to be an encoding of other
     * text. The question being answered is «what did `sanitize_title()`
     * encode», which is exactly one pass deep.
     */
    public function testItDecodesOnceAndStops(): void
    {
        self::assertSame('%25', AttributeLabel::forDisplay('%2525'));
        // The nested case, spelled out: a name that would become a tag only
        // after two passes stays harmless after one.
        self::assertSame('%3Cscript%3E', AttributeLabel::forDisplay('%253Cscript%253E'));
    }

    /**
     * ⛔ AND THE SINGLE-PASS RESULT IS STILL ONLY TEXT. A name that really is
     * an encoded tag decodes to the characters of that tag — this class does
     * not escape, by design, and every caller does. The assertion here is that
     * nothing is INVENTED: the output is the decoded characters and nothing
     * more, so an escaper downstream has an ordinary string to work on.
     */
    public function testAnEncodedTagDecodesToTextThatAnEscaperCanHandle(): void
    {
        $out = AttributeLabel::forDisplay('%3Cscript%3E');

        self::assertSame('<script>', $out);
        self::assertSame('&lt;script&gt;', htmlspecialchars($out, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * A partial or invalid escape keeps the RAW string: broken bytes in an
     * HTML attribute are how an escaper is talked into letting something
     * through, and «unreadable» is a better answer than «mojibake».
     */
    public function testBrokenBytesKeepTheStoredString(): void
    {
        // The first two bytes of «ا» with the third missing.
        self::assertSame('%d8', AttributeLabel::forDisplay('%d8'));
        self::assertSame('%d8%a7%d9', AttributeLabel::forDisplay('%d8%a7%d9'));
    }

    public function testControlCharactersKeepTheStoredString(): void
    {
        self::assertSame('%00', AttributeLabel::forDisplay('%00'));
        self::assertSame('size%0anext', AttributeLabel::forDisplay('size%0anext'));
        self::assertSame('%7f', AttributeLabel::forDisplay('%7f'));
    }

    /**
     * `rawurldecode()` and not `urldecode()`: a `+` in an attribute name is a
     * `+`, and the other function would make it a space.
     */
    public function testAPlusIsNotASpace(): void
    {
        self::assertSame('a+b', AttributeLabel::forDisplay('a+b'));
        self::assertSame('a+ب', AttributeLabel::forDisplay('a+%d8%a8'));
    }

    /**
     * ⛔ AND IT CANNOT WRITE. The class takes a string and returns a string;
     * the stored key stays WooCommerce's, because it is what joins a variation
     * to its parent's axis. Asserted from the bytes, because «display only» is
     * a property of the file rather than a sentence in it.
     */
    public function testTheClassCannotTouchAnythingStored(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Modules/Product/Presentation/AttributeLabel.php';
        $code = file_get_contents($path);
        self::assertIsString($code);
        // Comments stripped first: a docblock that EXPLAINS what this class
        // must not do would otherwise fail the check (`alpha.22`).
        $stripped = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $stripped .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
                continue;
            }
            $stripped .= $token;
        }
        $code = $stripped;

        foreach ([
            'update_post_meta',
            'wp_update_post',
            'wp_set_object_terms',
            'wp_insert_term',
            'update_option',
            'wpdb',
            'UPDATE ',
            'INSERT ',
            'DELETE ',
            // And no markup: this returns text, and the caller escapes.
            '<span',
            '<bdi',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, $forbidden . ' has no business in a label formatter');
        }
    }
}
