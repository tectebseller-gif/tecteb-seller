<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

/**
 * A WooCommerce attribute name, made readable — FOR DISPLAY, and nowhere else.
 *
 * ⛔ WHY THIS EXISTS. `sanitize_title()` percent-encodes every non-ASCII
 * character, so a vendor who typed «اندازه» as a custom attribute has it
 * stored under `%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87`, and
 * `wc_attribute_label()` with no product to consult hands that string straight
 * back. The owner read it in their own panel.
 *
 * ⛔ AND WHY IT IS ONLY DISPLAY. The stored key is WooCommerce's, it is what
 * joins a variation to its parent's attribute list, and rewriting it to read
 * better would detach every child from its axis. «دادهٔ ذخیره‌شده را برای
 * زیبایی متن دستکاری نکن» is the instruction, and this class has no way to
 * write anything: it takes a string and returns a string.
 *
 * Three rules, each of which exists because the obvious version is unsafe:
 *
 *  1. ONE decode, never a loop. `%2525` decodes to `%25` and then to `%`, so a
 *     repeated decode lets stored text pretend to be an encoding of other
 *     text. One pass answers the question actually being asked — «what did
 *     `sanitize_title()` encode?» — and nothing more.
 *  2. The result must be VALID UTF-8, or the raw string is kept. A partial
 *     escape such as `%d8%a7%d9` decodes to broken bytes, and broken bytes in
 *     an HTML attribute are how an escaper is persuaded to let something
 *     through.
 *  3. It returns TEXT, never markup, and every caller escapes. Decoding is the
 *     one operation here that can turn `%3Cscript%3E` into `<script>`, so the
 *     class deliberately cannot emit HTML and `AttributeLabelTest` asserts
 *     that shape.
 */
final class AttributeLabel
{
    /**
     * The readable form of one stored name, or the name itself.
     *
     * A string with no percent escape comes back untouched — a taxonomy label
     * WooCommerce already resolved, or an ASCII name, which is the ordinary
     * case and must not be touched at all.
     */
    public static function forDisplay(string $stored): string
    {
        if ($stored === '' || !str_contains($stored, '%')) {
            return $stored;
        }
        // `rawurldecode()` and not `urldecode()`: the latter also turns `+`
        // into a space, and a `+` in an attribute name is a `+`.
        $decoded = rawurldecode($stored);
        if ($decoded === $stored) {
            return $stored;
        }
        // Valid UTF-8, and nothing a terminal or a parser would read as a
        // control. A decode that produces either is a decode that answers a
        // different question from the one asked.
        if (!mb_check_encoding($decoded, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return $stored;
        }
        return $decoded;
    }
}
