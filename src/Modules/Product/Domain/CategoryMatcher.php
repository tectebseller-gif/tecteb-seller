<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Matching a typed category name against a Persian catalogue.
 *
 * Three things break a plain `str_contains` on this data, and all three are
 * ordinary rather than exotic:
 *
 *   * **The half-space.** «آمبو بگ» and «آمبوبگ» are the same product to
 *     everyone except a byte comparison, and a vendor typing on a phone
 *     keyboard produces whichever one the autocorrect felt like.
 *   * **Arabic letter shapes.** ی/ي, ک/ك, ه/ة, ا/آ/أ/إ. A supplier's
 *     spreadsheet pasted into the box routinely carries the Arabic ones.
 *   * **One wrong letter.** «امبوبک» for «آمبوبگ». Near enough that a person
 *     would say yes; far enough that nothing matches.
 *
 * So: fold, then rank. An EXACT match is always first and is never mixed in
 * with the rest — a guess presented beside a certainty is how the wrong
 * category gets picked. Anything found only by distance is labelled
 * «پیشنهاد نزدیک» and says so on screen.
 *
 * Pure on purpose: no WordPress, no database, so the whole ranking is
 * testable on its own.
 */
final class CategoryMatcher
{
    /** How the ranks are ordered. Lower is better; `NEAR` is the only fuzzy one. */
    public const EXACT = 0;
    public const PREFIX = 1;
    public const CONTAINS = 2;
    public const NEAR = 3;
    public const NO_MATCH = 99;

    /** Beyond this many single-letter edits it is a different word, not a typo. */
    public const MAX_EDITS = 2;

    /**
     * One comparable form: Arabic shapes folded, marks and joiners gone,
     * spaces collapsed, lower-cased.
     */
    public static function fold(string $text): string
    {
        $text = strtr($text, [
            'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ؤ' => 'و', 'ئ' => 'ی',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        // Zero-width joiners, the half-space, directionality marks, and the
        // Arabic marks a keyboard sometimes leaves behind. The range runs to
        // U+0655 on purpose: U+0654, the combining hamza, is what makes
        // «دستهٔ» and «دسته» two different words to a byte comparison, and it
        // is on half the category names on this shop.
        $text = preg_replace('/[\x{200b}-\x{200f}\x{0610}-\x{061a}\x{064b}-\x{0655}\x{0670}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim(function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
    }

    /** The same, with every space removed: «آمبو بگ» and «آمبوبگ» become one word. */
    public static function tight(string $text): string
    {
        return str_replace(' ', '', self::fold($text));
    }

    /**
     * How well does `$candidate` answer `$query`? Lower is better.
     *
     * `$extra` is searched too — the ancestry and the slug — but only for the
     * CONTAINS rank: a parent's name matching is a reason to offer a row, not
     * a reason to call it exact.
     */
    public static function rank(string $query, string $candidate, string $extra = ''): int
    {
        $q = self::fold($query);
        if ($q === '') {
            return self::NO_MATCH;
        }
        $name = self::fold($candidate);
        if ($name === $q) {
            return self::EXACT;
        }

        $qt = self::tight($query);
        $nt = self::tight($candidate);
        if ($nt === $qt) {
            return self::EXACT;                 // «آمبو بگ» is «آمبوبگ»
        }
        if ($qt !== '' && str_starts_with($nt, $qt)) {
            return self::PREFIX;
        }
        if ($qt !== '' && (str_contains($nt, $qt) || str_contains(self::tight($extra), $qt))) {
            return self::CONTAINS;
        }
        // A typo is only a typo against a word of comparable length. Without
        // this, a two-letter query is within two edits of half the catalogue.
        if (mb_strlen($qt) >= 3 && abs(mb_strlen($qt) - mb_strlen($nt)) <= self::MAX_EDITS) {
            if (self::editsWithin($qt, $nt, self::MAX_EDITS)) {
                return self::NEAR;
            }
        }
        return self::NO_MATCH;
    }

    public static function isFuzzy(int $rank): bool
    {
        return $rank === self::NEAR;
    }

    /**
     * Levenshtein distance on CHARACTERS, capped.
     *
     * PHP's own `levenshtein()` counts bytes, and every Persian letter is two
     * of them — so «امبوبک» and «آمبوبگ» come out four apart instead of one,
     * and the cap rejects them.
     */
    private static function editsWithin(string $a, string $b, int $max): bool
    {
        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($x);
        $m = count($y);
        if (abs($n - $m) > $max) {
            return false;
        }
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $row = [$i];
            $best = $i;
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $row[$j] = min($row[$j - 1] + 1, $prev[$j] + 1, $prev[$j - 1] + $cost);
                $best = min($best, $row[$j]);
            }
            if ($best > $max) {
                return false;                   // no row can recover
            }
            $prev = $row;
        }
        return $prev[$m] <= $max;
    }
}
