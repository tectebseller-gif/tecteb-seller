<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedAttributes;

/**
 * WHAT THIS PROVES: the stub's `sanitize_title()` answers what WordPress answers.
 *
 * It is here because of what this function DECIDES. `WC_Product::set_attributes()`
 * files every attribute under `sanitize_title($attribute->get_name())`, so this
 * one function chooses the key that every ownership decision in
 * `ProjectedAttributes` is made against. A stub that answered differently —
 * and the one in this repo did, because it kept `\p{L}` and handed Persian
 * text straight back — cannot see the `alpha.42` defect at all. The test that
 * missed it was not weak; it was asking the wrong oracle.
 *
 * Every pair below was READ OUT of a real WordPress 7.1 with
 * `wp eval-file`, not reasoned about:
 *
 *     foreach (file('titles.txt') as $t) { echo $t, "\t", sanitize_title($t), "\n"; }
 *
 * The three that matter most, and why each is in the list:
 *
 *  - «برند» → `%d8%a8%d8%b1%d9%86%d8%af`. Non-ASCII is percent-encoded, so
 *    no Persian label ever produces a key a human would recognise.
 *  - «رنگ‌ها» → `%d8%b1%d9%86%da%af%d9%87%d8%a7`. The نیم‌فاصله is STRIPPED,
 *    so «رنگ‌ها» and «رنگها» are one key — two labels, one attribute.
 *  - «دستهٔ لوازم» keeps `%d9%94`. U+0654 is NOT an accent WordPress removes,
 *    and the first version of this stub dropped it along with the Latin ones,
 *    which would have made two different keys look like one.
 */
final class SanitizeTitleTest extends ContractTestCase
{
    /**
     * Measured on WordPress 7.1. Change a value here only after re-measuring.
     *
     * @return array<string,array{string,string}>
     */
    public static function measured(): array
    {
        return [
            'برند' => ['برند', '%d8%a8%d8%b1%d9%86%d8%af'],
            'جنس' => ['جنس', '%d8%ac%d9%86%d8%b3'],
            'اندازه' => ['اندازه', '%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87'],
            'parens and spaces' => ['سایز (اینچ)', '%d8%b3%d8%a7%db%8c%d8%b2-%d8%a7%db%8c%d9%86%da%86'],
            'ascii words' => ['Latex Gloves', 'latex-gloves'],
            'mixed scripts' => ['گلاوز Nitrile', '%da%af%d9%84%d8%a7%d9%88%d8%b2-nitrile'],
            'already a slug' => ['tmc-brand', 'tmc-brand'],
            'hamza above' => ['دستهٔ لوازم', '%d8%af%d8%b3%d8%aa%d9%87%d9%94-%d9%84%d9%88%d8%a7%d8%b2%d9%85'],
            'zero width non joiner' => ['رنگ‌ها', '%d8%b1%d9%86%da%af%d9%87%d8%a7'],
            'dots' => ['A.B.C', 'a-b-c'],
            'runs of space' => ['  فاصله   زیاد', '%d9%81%d8%a7%d8%b5%d9%84%d9%87-%d8%b2%db%8c%d8%a7%d8%af'],
            'persian digits' => ['۱۲۳', '%db%b1%db%b2%db%b3'],
            'latin accents' => ['Größe', 'grose'],
            'plain' => ['color', 'color'],
            'taxonomy name' => ['pa_color', 'pa_color'],
        ];
    }

    /**
     * ⛔ ONE ROW OF THIS LIST NEEDS AN EXTENSION, AND IT NOW SAYS SO.
     *
     * `tmc_stub_remove_accents()` decomposes Latin letters through
     * `\Normalizer`, and on a PHP without `intl` it returns early by its own
     * documented design — so `Größe` came back `gr%c3%b6se` instead of
     * `grose`. Measured: the host interpreter here has `intl`, the 8.1.34
     * container does not, and the suite therefore reported a FAILURE about a
     * build that is fine. That is `alpha.11` exactly: an environment
     * difference wearing the clothes of a defect.
     *
     * So the row is SKIPPED, by name, and only the rows that contain a Latin
     * accented letter — the Persian pairs, which are what every attribute key
     * on this site actually rests on, stay measured on both interpreters
     * because the stub never touches non-Latin characters. Nothing shipped is
     * involved: `tools/wp-stubs/` does not travel inside the package.
     */
    private static function needsIntl(string $title): bool
    {
        return !class_exists(\Normalizer::class)
            && preg_match('/[\x{00C0}-\x{024F}\x{1E00}-\x{1EFF}]/u', $title) === 1;
    }

    /**
     * ⛔ And the skip is no wider than the extension that is missing.
     *
     * The fix above opened a hole of its own shape: a predicate that skipped
     * MORE than it has to would stop measuring the fifteen pinned pairs and
     * the suite would still print `OK` — a warning outliving the thing it
     * describes (`alpha.102`), and «nothing was measured» wearing the clothes
     * of «nothing is wrong» (`alpha.77`).
     *
     * So the predicate is asserted directly, in both directions: no Persian
     * label and no plain ASCII title may ever be skipped, and a Latin accent
     * is skipped EXACTLY when `\Normalizer` is absent — which makes this one
     * assertion answer differently on the two interpreters, on purpose. It is
     * the interpreter's own answer, not a number typed from memory
     * (`alpha.34`).
     */
    public function testTheSkipIsNoWiderThanTheMissingExtension(): void
    {
        self::assertFalse(self::needsIntl('کالای ویژه'), 'a Persian label never needs ext-intl');
        self::assertFalse(self::needsIntl('Simple Title'), 'plain ASCII never needs ext-intl');
        self::assertFalse(self::needsIntl(''), 'an empty title never needs ext-intl');
        self::assertSame(
            !class_exists(\Normalizer::class),
            self::needsIntl('Größe'),
            'a Latin accent is skipped exactly when Normalizer is absent'
        );

        // And the pairs this class pins are mostly Persian, so the skip can
        // never take the whole measurement with it.
        $skipped = 0;
        foreach (self::measured() as $row) {
            $skipped += self::needsIntl($row[0]) ? 1 : 0;
        }
        self::assertLessThan(
            count(self::measured()),
            $skipped,
            'if every pinned pair is skipped, this class measures nothing'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('measured')]
    public function testTheStubAnswersWhatWordPressAnswers(string $title, string $expected): void
    {
        if (self::needsIntl($title)) {
            self::markTestSkipped('Latin accents need ext-intl (\Normalizer); not loaded on this interpreter.');
        }
        self::assertSame($expected, sanitize_title($title));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('measured')]
    public function testTheKeyThePluginComputesIsThatSameAnswer(string $title, string $expected): void
    {
        if (self::needsIntl($title)) {
            self::markTestSkipped('Latin accents need ext-intl (\Normalizer); not loaded on this interpreter.');
        }
        self::assertSame($expected, ProjectedAttributes::keyFor($title));
    }

    /**
     * And the one conclusion that follows from the list.
     *
     * Not an assertion about a string: an assertion about what CANNOT be
     * done. No Persian label produces a key with a `tmc-` prefix, so the
     * `alpha.42` scheme had no possible input on which it worked.
     */
    public function testNoPersianLabelCanProduceAPrefixedKey(): void
    {
        foreach (['برند', 'جنس', 'اندازه', 'وزن', 'کشور سازنده'] as $label) {
            self::assertStringStartsWith('%', ProjectedAttributes::keyFor($label), $label);
        }
    }
}
