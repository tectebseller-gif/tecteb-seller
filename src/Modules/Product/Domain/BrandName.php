<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * A brand's name, and the one key two names are compared by.
 *
 * The owner's §4 asks for duplicate detection that covers «فاصله، نیم‌فاصله و
 * ی/ک عربی و فارسی», is explicit and tested, and does NOT merge names that are
 * genuinely different. Those two halves pull against each other, so the rule is
 * written down rather than left to a `trim()` at each call site:
 *
 *  - **what is folded away** is everything a Persian keyboard produces for the
 *    same word: the Arabic ي and ك for the Persian ی and ک, the zero-width
 *    non-joiner and joiner, Arabic diacritics and the tatweel, the Arabic
 *    forms of alef with hamza above or below, the Arabic heh with hamza, the
 *    Arabic-Indic digits for the Persian ones, every kind of space, and case
 *    for Latin letters. «آمبو بگ» and «آمبوبگ» are one brand; so are
 *    «برند آزمايشي» and «برند آزمایشی».
 *  - **what is NOT folded** is anything that changes the word. No stemming, no
 *    transliteration between scripts, no dropping of «ال» or of plural
 *    endings, and no edit distance: «سینا» and «سیما» are two brands, and a
 *    rule that guessed otherwise would quietly put one shop's goods under
 *    another's name. `alpha.26` used an edit distance for CATEGORY SEARCH,
 *    where the cost of a near miss is an extra row in a list; identity is a
 *    different question and gets a different answer.
 *
 * The key is for COMPARISON only. What is shown, stored and written to the
 * shop is always the name as the manager approved it (`display()`), because a
 * brand is a proper noun and nobody's brand is spelled without spaces.
 */
final class BrandName
{
    /** The width of `tmc_products.brand`, so a name that fits the key fits the column. */
    public const MAX_LENGTH = 120;

    /**
     * Folded one-for-one. Written as pairs rather than as ranges so that
     * adding one is a decision somebody can read.
     *
     * @var array<string,string>
     */
    private const FOLD = [
        // The Arabic letters a Persian keyboard is forever producing.
        'ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ے' => 'ی',
        // Alef with hamza, and the bare alef every one of them is written as.
        'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'آ' => 'ا',
        // Heh with hamza above, which «هٔ» also decomposes to.
        'ۀ' => 'ه', 'ة' => 'ه',
        // Arabic-Indic and extended Arabic-Indic digits.
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Removed outright: they are invisible, or they are a space.
     *
     * Every space goes, not just the ones between words, because «نیم‌فاصله»
     * and «فاصله» are the same brand written by two people and the owner named
     * both.
     *
     * @var list<string>
     */
    private const DROP = [
        "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}", "\u{00AD}",
        "\u{0640}",                                 // tatweel
        "\u{00A0}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}",
        "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}",
        "\u{202F}", "\u{205F}", "\u{3000}",
        ' ', "\t", "\n", "\r",
    ];

    /** Arabic diacritics: a brand is not spelled differently for carrying one. */
    private const MARKS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}'
        . '\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}]/u';

    /**
     * The name as it is shown, stored and written to the shop.
     *
     * It does NOT truncate. An over-long name is refused by `isValid()`
     * instead, because a brand silently cut at a hundred and twenty characters
     * is a different brand — and the column would cut it anyway, which is
     * exactly the silence worth avoiding. Only whitespace is tidied: inner
     * runs collapse to one space and the ends go.
     */
    public static function display(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($name));
    }

    /**
     * The key two names are the same brand by.
     *
     * Deliberately not reversible: it is never shown and never stored as the
     * brand. An empty key means «no name», which is how an absent brand and a
     * name made only of invisible characters both answer — and both have to,
     * because a brand nobody can see is not a brand.
     */
    public static function key(string $name): string
    {
        $name = (string) preg_replace(self::MARKS, '', $name);
        $name = strtr($name, self::FOLD);
        $name = str_replace(self::DROP, '', $name);
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /** Whether two names are the same brand. */
    public static function same(string $a, string $b): bool
    {
        $keyA = self::key($a);
        return $keyA !== '' && $keyA === self::key($b);
    }

    /**
     * Whether this is a name at all.
     *
     * Length is measured on the DISPLAY form, because that is what goes into
     * the column; a key is shorter by construction and would let a name
     * through that the column then truncates.
     */
    public static function isValid(string $name): bool
    {
        $display = self::display($name);
        if ($display === '' || self::key($display) === '') {
            return false;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') : strlen($display);
        return $length <= self::MAX_LENGTH;
    }
}
