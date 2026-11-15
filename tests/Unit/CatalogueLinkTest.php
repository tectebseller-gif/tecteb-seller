<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\CatalogueLink;

/**
 * WHAT THIS PROVES: one row gets ONE answer, and the order of the questions
 * is the one §۴ needs.
 *
 * The screen and `tools/production-audit.php` both ask this, so a precedence
 * that was only right in one of them would be two tools disagreeing about one
 * site (`alpha.78`).
 */
final class CatalogueLinkTest extends TestCase
{
    public function testAnUnreadableShopIsNeverAnythingElse(): void
    {
        // ⛔ Every other input here says «linked», and the answer is still
        // «unreadable»: a failed read is not a fact about the shop
        // (`alpha.8`).
        self::assertSame(
            CatalogueLink::UNREADABLE,
            CatalogueLink::decide(4300, 'product', false, 1)
        );
        // …including when the row names nothing, which would otherwise be a
        // perfectly good answer.
        self::assertSame(
            CatalogueLink::UNREADABLE,
            CatalogueLink::decide(null, null, false, 1)
        );
    }

    public function testARowThatNamesNothingIsNotMissingAndNotDuplicated(): void
    {
        self::assertSame(CatalogueLink::TMC_ONLY, CatalogueLink::decide(null, null, true, 1));
        self::assertSame(CatalogueLink::TMC_ONLY, CatalogueLink::decide(0, null, true, 1));
        // Even if the count said many — which it cannot, because `NULL`
        // matches no row — «nothing» wins.
        self::assertSame(CatalogueLink::TMC_ONLY, CatalogueLink::decide(null, null, true, 7));
    }

    public function testAnIdTheShopHasNotGotIsSaidBeforeTheDuplicate(): void
    {
        // Two rows naming a product that is not there: nothing can be
        // overwritten, so the id being absent is the thing to act on.
        self::assertSame(CatalogueLink::WOO_MISSING, CatalogueLink::decide(4999, null, true, 2));
        self::assertSame(CatalogueLink::WOO_MISSING, CatalogueLink::decide(4999, '', true, 1));
    }

    public function testAnIdThatIsNotAProductIsSaidBeforeTheDuplicate(): void
    {
        self::assertSame(CatalogueLink::WRONG_TYPE, CatalogueLink::decide(4301, 'page', true, 2));
        self::assertSame(CatalogueLink::WRONG_TYPE, CatalogueLink::decide(4302, 'product_variation', true, 1));
    }

    public function testTwoRowsNamingOneProductAreADuplicate(): void
    {
        self::assertSame(CatalogueLink::DUPLICATE, CatalogueLink::decide(4300, 'product', true, 2));
        self::assertSame(CatalogueLink::LINKED, CatalogueLink::decide(4300, 'product', true, 1));
        // A count of nought is not «many»: it is a read that found nothing,
        // and the row in hand names the product, so it is at least one.
        self::assertSame(CatalogueLink::LINKED, CatalogueLink::decide(4300, 'product', true, 0));
    }

    public function testWhatAPersonHasToLookAt(): void
    {
        self::assertFalse(CatalogueLink::needsAttention(CatalogueLink::LINKED));
        // ⛔ A row with no shop product is NOT a fault: an imported catalogue
        // is full of them before a transfer, and §۴ says «وضعیت ۴ محصول بدون
        // پیوند WC جدا بماند».
        self::assertFalse(CatalogueLink::needsAttention(CatalogueLink::TMC_ONLY));
        foreach ([
            CatalogueLink::WOO_MISSING,
            CatalogueLink::WRONG_TYPE,
            CatalogueLink::DUPLICATE,
            CatalogueLink::WOO_ONLY,
            CatalogueLink::UNREADABLE,
        ] as $answer) {
            self::assertTrue(CatalogueLink::needsAttention($answer), $answer);
        }
    }

    /** Every answer the class can give is in the list a screen iterates. */
    public function testTheListOfAnswersIsTheWholeSetOfAnswers(): void
    {
        $constants = (new \ReflectionClass(CatalogueLink::class))->getConstants();
        unset($constants['ALL']);
        $values = array_values($constants);
        sort($values);
        $all = CatalogueLink::ALL;
        sort($all);
        self::assertSame($values, $all, 'an answer missing from ALL is an answer no screen prints');
    }
}
