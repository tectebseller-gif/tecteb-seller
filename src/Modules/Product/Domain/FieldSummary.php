<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * How a long field is SHOWN when two versions of it are put side by side.
 *
 * ⛔ THIS IS NOT SANITISATION AND MUST NEVER BE USED AS SUCH.
 *
 * The security boundary for every value this class touches is `esc_html()` at
 * the point of output, and nothing here removes it or stands in for it. What
 * this class answers is a different question — «how much of this can a person
 * read in a table cell» — and its answer is thrown away the moment the page is
 * rendered. The stored value is never passed through it on the way IN.
 *
 * The defect it exists for, measured on the owner's own site: a published
 * product whose long description had been pasted from a rich-text editor
 * carried a few thousand characters of markup, `data-start` and `data-end`
 * attributes included. `ProductConflictView` put that value in a table cell
 * and escaped it, which is correct and unreadable: the vendor was shown the
 * markup itself, twice — once for the published version and once for their
 * own — and the field names the table was built to surface were pushed off
 * the screen by it.
 *
 * ⛔ AND THE DATA IS NOT TOUCHED. Nothing here writes, and nothing here is
 * called on the way into the database. The markup a product holds — its
 * links, its headings, its images, whatever a search engine reads from the
 * storefront — stays exactly as it is. «تغییر داده برای زیباسازی نمایش
 * ممنوع است» is kept by this class having no writer at all.
 *
 * Three rules, and each one is measured rather than assumed:
 *
 *  1. **Markup is detected by stripping it.** `carriesMarkup()` does not look
 *     for a `<` — it compares the value with its own tagless form. A value
 *     that mentions a less-than sign in prose is not markup, and a value
 *     whose only tag is `<br>` is.
 *  2. **Length is counted in CHARACTERS, not bytes.** A Persian letter is two
 *     bytes, so a byte cut lands inside one and prints a replacement
 *     character (`alpha.12`, and `alpha.21` paid for it again in a shell
 *     script). Every bound here is `mb_*`.
 *  3. **The number quoted to the reader is the STORED length**, not the
 *     readable one. The table is about two stored values; telling somebody
 *     their description is «۴۱۲ characters» when the row holds 2,413 would be
 *     a statement about this class rather than about their product.
 */
final class FieldSummary
{
    /** How much of a folded value the summary line shows. */
    public const PREVIEW_CHARS = 160;

    /**
     * Above this, a plain value is folded even with no markup in it.
     *
     * A paragraph is readable in a cell; a page is not, and the field names
     * beside it are what the table is for.
     */
    public const FOLD_CHARS = 220;

    /** Whether stripping tags changes the value — the measurement, not a guess. */
    public static function carriesMarkup(string $value): bool
    {
        return self::tagless($value) !== $value;
    }

    /** Whether this value belongs behind a disclosure rather than in a cell. */
    public static function needsFolding(string $value): bool
    {
        return self::carriesMarkup($value) || mb_strlen($value) > self::FOLD_CHARS;
    }

    /** The stored value's length, in characters. */
    public static function characters(string $value): int
    {
        return mb_strlen($value);
    }

    /**
     * The value as a person reads it: no tags, no entities, line breaks kept.
     *
     * Entities are decoded so that `&nbsp;` and `&amp;` read as a space and an
     * ampersand rather than as themselves; `esc_html()` re-encodes whatever
     * comes back, so decoding here cannot widen what reaches the browser.
     *
     * Script and style bodies go first, because `strip_tags()` leaves their
     * CONTENT behind — and a page of CSS is not a description.
     */
    public static function text(string $value): string
    {
        $out = preg_replace('@<(script|style)\b[^>]*>.*?</\1>@is', '', $value) ?? $value;
        $out = strip_tags($out);
        $out = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Horizontal runs collapse, line breaks survive: a reader needs the
        // paragraphs of a long description, and nothing else.
        $out = preg_replace('/[^\S\r\n]+/u', ' ', $out) ?? $out;
        $out = preg_replace('/(\r\n|\r|\n){3,}/u', "\n\n", $out) ?? $out;
        return trim($out);
    }

    /**
     * One line of it, bounded, with an ellipsis only when something was cut.
     *
     * An empty result is a real answer: a value made entirely of markup — an
     * image on its own, say — has no text to preview, and saying so is better
     * than an empty line that looks like a bug.
     */
    public static function preview(string $value): string
    {
        $flat = preg_replace('/\s+/u', ' ', self::text($value)) ?? '';
        $flat = trim($flat);
        if (mb_strlen($flat) <= self::PREVIEW_CHARS) {
            return $flat;
        }
        return mb_substr($flat, 0, self::PREVIEW_CHARS) . '…';
    }

    private static function tagless(string $value): string
    {
        return strip_tags($value);
    }
}
