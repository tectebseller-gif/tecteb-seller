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

    #[\PHPUnit\Framework\Attributes\DataProvider('measured')]
    public function testTheStubAnswersWhatWordPressAnswers(string $title, string $expected): void
    {
        self::assertSame($expected, sanitize_title($title));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('measured')]
    public function testTheKeyThePluginComputesIsThatSameAnswer(string $title, string $expected): void
    {
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
