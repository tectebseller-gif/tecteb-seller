<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * A sort key that puts Persian titles in Persian order.
 *
 * **Why a key at all.** Sorting «عنوان (الفبایی فارسی)» by the column itself
 * orders Arabic-block letters by codepoint, which is the ARABIC alphabet, not
 * the Persian one: پ (U+067E), چ (U+0686), ژ (U+0698) and گ (U+06AF) were all
 * added after the Arabic block was laid out, so every one of them sorts after
 * ی instead of in its own place. «پالس‌اکسیمتر» landed below «یدک» — measured,
 * not assumed. No collation this plugin can rely on fixes that AND orders
 * numbers the way a person reads them, so the order is written here, once, and
 * stored beside the title.
 *
 * **What the key guarantees**, in the order the rules apply:
 *
 *  1. **Persian first, then everything else.** The group is the first
 *     character of the key: `1` when the first LETTER in the title is from the
 *     Arabic block, `2` otherwise, `3` when there is no title at all — so
 *     untitled rows land at the end rather than at the top, where they would
 *     push real products off the first page.
 *  2. **The Persian alphabet, including پ چ ژ گ**, by mapping each letter to a
 *     two-character token in alphabet order. Byte order then IS Persian order,
 *     on any collation, including `utf8mb4_bin`.
 *  3. **ی/ي and ک/ك are the same letter.** So are the hamza carriers (أ إ ٱ →
 *     ا, ؤ → و, ئ → ی) and ة → ه. The STORED title is never touched: this is a
 *     reading of it, not a correction to it.
 *  4. **Numbers read as numbers, at any length.** A digit run becomes
 *     `0` + how many digits it has + the digits themselves, so a longer number
 *     is a bigger number and two numbers of the same length compare digit by
 *     digit. «محصول ۲» comes before «محصول ۱۰», and Persian, Arabic-Indic and
 *     Latin digits all reduce to the same block — «محصول ۲» and «محصول 2» sort
 *     together instead of in two different alphabets.
 *
 *     **This replaced a fixed twelve-digit block in `alpha.34`, and the old
 *     one was wrong.** Padding to twelve and keeping the LAST twelve digits
 *     meant `1000000000000` — thirteen digits — encoded as twelve zeros, i.e.
 *     as the number nought, and sorted below `1`. A length prefix has no such
 *     ceiling: the only bound left is the key length itself.
 *  5. **Nothing invisible changes the order.** ZWNJ, tatweel and the Arabic
 *     diacritics are dropped, and runs of whitespace become one space, so
 *     «آمبو بگ», «آمبو‌بگ» (with a half-space) and «آمبو  بگ» sort as one word
 *     pair rather than three.
 *
 * The key is NOT unique and is not meant to be: two titles that read the same
 * produce the same key, and the caller breaks the tie with the row id, which
 * never repeats. It is also bounded — a title longer than the key can hold is
 * compared by its beginning, and the id decides the rest.
 *
 * Pure PHP: no WordPress, no `intl`, no collation that has to be installed.
 */
final class PersianCollation
{
    /**
     * Which key format the stored column holds.
     *
     * Bumped whenever `sortKey()` would answer differently for some title.
     * Every stored key is then stale by definition, and `TitleSortRepair`
     * rebuilds them — the alternative being a column that silently mixes two
     * formats, where the order between an old row and a new one is whatever
     * the two happen to produce.
     *
     *  - `1` — `alpha.33`. Numbers were a fixed twelve-digit block.
     *  - `2` — `alpha.34`. Numbers carry their length, so thirteen digits and
     *    more sort above twelve rather than wrapping to zero.
     */
    public const BUILD = 2;

    /** Fits an indexable `VARCHAR` and holds ~90 Persian letters. */
    public const KEY_LENGTH = 191;

    /**
     * How many characters the digit-count prefix takes.
     *
     * Two, so a run of up to 99 digits is ordered exactly. A longer run than
     * that cannot fit in a 191-character key anyway, and is recorded here as
     * the limit it is rather than discovered as a wrong order.
     */
    private const NUMBER_LENGTH_DIGITS = 2;

    /** The longest digit run the length prefix can describe. */
    private const MAX_NUMBER_DIGITS = 99;

    private const GROUP_PERSIAN = '1';
    private const GROUP_OTHER = '2';
    private const GROUP_EMPTY = '3';

    /**
     * The Persian alphabet, in order, each letter with the token that encodes
     * its place. Tokens start at `aa` so every letter sorts after a digit
     * block (`0…`) and before a Latin letter (`c…`).
     *
     * @var array<string,string>
     */
    private const ALPHABET = [
        'آ' => 'aa', 'ا' => 'ab', 'ب' => 'ac', 'پ' => 'ad', 'ت' => 'ae', 'ث' => 'af',
        'ج' => 'ag', 'چ' => 'ah', 'ح' => 'ai', 'خ' => 'aj', 'د' => 'ak', 'ذ' => 'al',
        'ر' => 'am', 'ز' => 'an', 'ژ' => 'ao', 'س' => 'ap', 'ش' => 'aq', 'ص' => 'ar',
        'ض' => 'as', 'ط' => 'at', 'ظ' => 'au', 'ع' => 'av', 'غ' => 'aw', 'ف' => 'ax',
        'ق' => 'ay', 'ک' => 'az', 'گ' => 'ba', 'ل' => 'bb', 'م' => 'bc', 'ن' => 'bd',
        'و' => 'be', 'ه' => 'bf', 'ی' => 'bg',
        // Not one of the thirty-two, and it still has to land somewhere every
        // time: after the alphabet, before anything Latin.
        'ء' => 'bh',
    ];

    /** Letters that are the same letter for sorting. @var array<string,string> */
    private const FOLD = [
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی',
        'ك' => 'ک',
        'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'ٲ' => 'ا', 'ٳ' => 'ا',
        'ؤ' => 'و',
        'ة' => 'ه', 'ۀ' => 'ه',
        'ی' => 'ی',
    ];

    /**
     * The half-space is a SPACE here, not nothing.
     *
     * «آمبو‌بگ» and «آمبو بگ» are the same two words typed two ways, and a
     * reader expects them together; dropping the joiner instead would file the
     * first one under «آمبوبگ» and put a word between them. This is the same
     * rule the category search settled on in `alpha.26` — a half-space is not
     * a difference — applied to the order rather than to the match.
     */
    private const HALF_SPACE = "\u{200C}";

    /** Marks and joiners that must not change the order. @var list<string> */
    private const INVISIBLE = [
        "\u{200D}", "\u{200E}", "\u{200F}", "\u{0640}",
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}",
        "\u{0651}", "\u{0652}", "\u{0653}", "\u{0654}", "\u{0655}", "\u{0670}",
        "\u{FEFF}",
    ];

    /** @var array<string,string> every digit this shop's titles can carry */
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function sortKey(string $title): string
    {
        $text = self::normalize($title);
        if ($text === '') {
            return self::GROUP_EMPTY;
        }
        return substr(self::group($text) . self::encode($text), 0, self::KEY_LENGTH);
    }

    /**
     * The same reading of the title the key is built from, exposed so a test —
     * or a person reading evidence — can see what the order was decided on.
     */
    public static function normalize(string $title): string
    {
        $text = str_replace(self::HALF_SPACE, ' ', $title);
        $text = str_replace(self::INVISIBLE, '', $text);
        $text = strtr($text, self::FOLD);
        $text = strtr($text, self::DIGITS);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Persian or not, decided by the first LETTER rather than the first
     * character: «۱۲۳ ماسک» is a Persian title with a number in front of it,
     * and a title that is only digits has no letter to judge by at all.
     */
    private static function group(string $text): string
    {
        foreach (self::characters($text) as $char) {
            if (isset(self::ALPHABET[$char])) {
                return self::GROUP_PERSIAN;
            }
            if (preg_match('/^\p{L}$/u', $char) === 1) {
                return self::GROUP_OTHER;
            }
        }
        return self::GROUP_OTHER;
    }

    private static function encode(string $text): string
    {
        $key = '';
        $characters = self::characters($text);
        $count = count($characters);
        for ($i = 0; $i < $count; $i++) {
            $char = $characters[$i];
            if ($char >= '0' && $char <= '9') {
                // The whole run at once, so ten beats two by value and not by
                // its first digit.
                $number = '';
                while ($i < $count && $characters[$i] >= '0' && $characters[$i] <= '9') {
                    $number .= $characters[$i];
                    $i++;
                }
                $i--;
                $key .= self::number($number);
                continue;
            }
            if (isset(self::ALPHABET[$char])) {
                $key .= self::ALPHABET[$char];
                continue;
            }
            if ($char === ' ') {
                $key .= ' ';
                continue;
            }
            if ($char >= 'a' && $char <= 'z') {
                $key .= 'c' . $char;
                continue;
            }
            // Everything else still has to be somewhere, and the same
            // somewhere every time: punctuation and other scripts sort after
            // the letters, by code point.
            $key .= sprintf('d%03x', min(0xfff, self::codepoint($char)));
        }
        return $key;
    }

    /**
     * One digit run, encoded so that byte order is numeric order.
     *
     * `0` + the digit COUNT + the digits. Length first is what makes this work
     * without a ceiling: `002` sorts below `013`, so any two-digit number is
     * below any thirteen-digit one, and within a length the digits compare
     * directly. Leading zeros are dropped first, so `007` and `7` are one
     * number and not two.
     *
     * The old fixed-width form kept the last twelve digits of the run, which
     * turned `1000000000000` into twelve zeros — the number nought, sorted
     * below `1`. Measured, then fixed.
     */
    private static function number(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $length = strlen($digits);
        if ($length > self::MAX_NUMBER_DIGITS) {
            // A run this long cannot fit the key at all. Keeping the MOST
            // significant digits is the reading that is least wrong, and the
            // clamp on the prefix keeps every such run in the same, highest
            // bucket rather than wrapping it back under short numbers.
            $digits = substr($digits, 0, self::MAX_NUMBER_DIGITS);
            $length = self::MAX_NUMBER_DIGITS;
        }
        return '0' . str_pad((string) $length, self::NUMBER_LENGTH_DIGITS, '0', STR_PAD_LEFT) . $digits;
    }

    /** @return list<string> */
    private static function characters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $characters === false ? [] : $characters;
    }

    private static function codepoint(string $char): int
    {
        $ordinals = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8') ?: '');
        return is_array($ordinals) ? (int) ($ordinals[1] ?? 0) : 0;
    }
}
