<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;

/**
 * WHAT THIS PROVES: «which category is this product in?» has six answers, and
 * only one of them may become a stored key.
 *
 * The round exists because `alpha.80` had two: it took `category_ids[0]` and
 * called it the category. Measured on its own bytes, that stored one of three
 * terms and dropped two with nothing said, and the first accepted write then
 * DELETED the other two from WooCommerce
 * (`docs/category-model-limit-alpha81-fa.md`).
 *
 * The cases here are the ones the owner listed — «تک‌دسته‌ای، چنددسته‌ای، نام
 * مشابه با شناسهٔ متفاوت، دستهٔ فارسی، دستهٔ حذف‌شده، بدون دسته» — plus the
 * two the measurement added: the shop's default term worn as a category, and
 * a read that did not happen.
 */
final class CategoryLinkTest extends TestCase
{
    /** Paths as the directory would give them: full ancestry, never a bare leaf. */
    private const PATHS = [
        165 => 'تجهیزات پزشکی › تزریق',
        166 => 'تجهیزات پزشکی › چسب',
        167 => 'مواد مصرفی › چسب',
    ];

    public function testOneRealTermIsADefiniteLink(): void
    {
        $link = CategoryLink::of(11, [166], self::PATHS, 15);

        self::assertSame(CategoryLink::DEFINITE, $link->state);
        self::assertTrue($link->isDefinite());
        self::assertSame('166', $link->definiteKey());
        self::assertSame([166], $link->validIds);
        self::assertSame([], $link->invalidIds);
    }

    public function testSeveralRealTermsAreNotALinkAndOfferNoKey(): void
    {
        $link = CategoryLink::of(12, [165, 166, 167], self::PATHS, 15);

        self::assertSame(CategoryLink::MULTIPLE, $link->state);
        self::assertFalse($link->isDefinite());
        // The whole point. «The first of several» is the answer the owner
        // refused, so the method a writer calls returns nothing at all.
        self::assertSame('', $link->definiteKey());
        self::assertSame([165, 166, 167], $link->validIds);
    }

    /** Every id and its full path travel with the group, so nothing is dropped silently. */
    public function testASeveralTermLinkCarriesEveryIdAndItsPath(): void
    {
        $link = CategoryLink::of(12, [167, 165], self::PATHS, 15);

        self::assertSame(
            [
                ['id' => 167, 'path' => 'مواد مصرفی › چسب'],
                ['id' => 165, 'path' => 'تجهیزات پزشکی › تزریق'],
            ],
            $link->pathList()
        );
    }

    /**
     * Two categories whose leaf name is the same word, under different parents.
     *
     * The owner asked for this case by name. Ids 166 and 167 are both «چسب»,
     * and nothing here compares names — so they stay two categories, and a
     * product in both is `MULTIPLE` rather than «the same category twice».
     */
    public function testTheSameLeafNameUnderTwoParentsIsTwoCategories(): void
    {
        $link = CategoryLink::of(13, [166, 167], self::PATHS, 15);

        self::assertSame(CategoryLink::MULTIPLE, $link->state);
        self::assertSame([166, 167], $link->validIds);
        self::assertNotSame($link->paths[166], $link->paths[167]);
    }

    public function testTheShopsDefaultTermAloneIsNotACategory(): void
    {
        $link = CategoryLink::of(14, [15], self::PATHS, 15);

        self::assertSame(CategoryLink::DEFAULT_ONLY, $link->state);
        self::assertSame('', $link->definiteKey());
        self::assertTrue($link->defaultAttached);
        self::assertSame([], $link->validIds);
        // Still recorded, because a screen that says «بدون دسته» should be
        // able to show that WooCommerce put its placeholder there.
        self::assertSame([15], $link->termIds);
    }

    /**
     * The default term beside a real one does not make the real one a default.
     *
     * WooCommerce substitutes `default_product_cat` on a save with no
     * categories, but a manager may also have left it attached next to a real
     * choice. Excluding it from the real terms — rather than filtering the
     * attached list — keeps that product a definite link.
     */
    public function testTheDefaultTermBesideOneRealTermIsStillDefinite(): void
    {
        $link = CategoryLink::of(15, [15, 166], self::PATHS, 15);

        self::assertSame(CategoryLink::DEFINITE, $link->state);
        self::assertSame('166', $link->definiteKey());
        self::assertTrue($link->defaultAttached);
    }

    public function testTheDefaultTermBesideTwoRealTermsIsStillMultiple(): void
    {
        $link = CategoryLink::of(16, [15, 165, 166], self::PATHS, 15);

        self::assertSame(CategoryLink::MULTIPLE, $link->state);
        self::assertSame('', $link->definiteKey());
    }

    public function testNoTermAtAllIsItsOwnAnswer(): void
    {
        $link = CategoryLink::of(17, [], self::PATHS, 15);

        self::assertSame(CategoryLink::NONE, $link->state);
        self::assertSame('', $link->definiteKey());
        self::assertFalse($link->defaultAttached);
    }

    /**
     * A term the directory no longer knows is invalid, not a category.
     *
     * The owner listed «دستهٔ حذف‌شده». Writing 999 as a key would store
     * something `find()` cannot resolve, which is how a product ends up with
     * a category every screen prints as «نامعتبر».
     */
    public function testATermTheDirectoryDoesNotKnowIsInvalid(): void
    {
        $link = CategoryLink::of(18, [999], self::PATHS, 15);

        self::assertSame(CategoryLink::INVALID, $link->state);
        self::assertSame('', $link->definiteKey());
        self::assertSame([999], $link->invalidIds);
        self::assertSame([], $link->validIds);
    }

    /** One real term plus one deleted term is still a definite link — to the real one. */
    public function testOneRealTermBesideADeletedOneIsDefiniteAndNamesTheDeletedOne(): void
    {
        $link = CategoryLink::of(19, [166, 999], self::PATHS, 15);

        self::assertSame(CategoryLink::DEFINITE, $link->state);
        self::assertSame('166', $link->definiteKey());
        self::assertSame([999], $link->invalidIds);
    }

    public function testAReadThatDidNotHappenIsNeitherEmptyNorWritable(): void
    {
        $link = CategoryLink::unreadable(20);

        self::assertSame(CategoryLink::UNREADABLE, $link->state);
        self::assertFalse($link->isDefinite());
        self::assertSame('', $link->definiteKey());
        self::assertSame([], $link->termIds);
        // ⛔ And it must not look like NONE. «No categories» invites a human
        // to pick one; «I could not read it» invites a retry, and treating
        // the second as the first is how `alpha.8` keeps costing rounds.
        self::assertNotSame(CategoryLink::NONE, $link->state);
    }

    public function testADuplicatedRelationshipRowIsOneCategory(): void
    {
        $link = CategoryLink::of(21, [166, 166], self::PATHS, 15);

        self::assertSame(CategoryLink::DEFINITE, $link->state);
        self::assertSame([166], $link->validIds);
    }

    /** Grouping is by the SET, so order and the default marker cannot split a group. */
    public function testTheSignatureIsTheSetAndNotTheOrder(): void
    {
        $a = CategoryLink::of(22, [167, 165], self::PATHS, 15);
        $b = CategoryLink::of(23, [165, 167], self::PATHS, 15);

        self::assertSame($a->signature(), $b->signature());
        self::assertSame('165,167', $a->signature());
    }

    public function testDifferentSetsGetDifferentSignatures(): void
    {
        $one = CategoryLink::of(24, [166], self::PATHS, 15)->signature();
        $two = CategoryLink::of(25, [166, 167], self::PATHS, 15)->signature();
        $withDefault = CategoryLink::of(26, [166, 15], self::PATHS, 15)->signature();
        $withInvalid = CategoryLink::of(27, [166, 999], self::PATHS, 15)->signature();

        self::assertSame(['166', '166,167', '166|default', '166|invalid:999'], [$one, $two, $withDefault, $withInvalid]);
        self::assertCount(4, array_unique([$one, $two, $withDefault, $withInvalid]));
        self::assertSame(CategoryLink::UNREADABLE, CategoryLink::unreadable(28)->signature());
    }
}
