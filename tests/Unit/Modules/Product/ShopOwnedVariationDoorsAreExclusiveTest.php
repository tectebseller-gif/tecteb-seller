<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationOwnership;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Tests\Support\FakeStorefrontVariations;

/**
 * WHAT THIS PROVES: a product is behind exactly ONE of the two variation
 * doors, and an unreadable shop opens NEITHER.
 *
 * ⛔ **THIS TEST EXISTS BECAUSE I WROTE THE BUG IT CATCHES, TWICE.**
 *
 * There are two doors into a variable product's combinations.
 * `ManageVariations` writes `tmc_product_attributes` and
 * `tmc_product_variations`; `EditStorefrontVariations` writes the real
 * `WC_Product_Variation`. A product behind both would have two writers for
 * one figure, and a product behind neither cannot be edited at all.
 *
 * The first version of the shared rule answered `!isOurs()` for the shop's
 * door — and `UNKNOWN` is also not-ours, so an unreadable shop opened a write
 * straight at WooCommerce. That is the fail-open `alpha.105` had and
 * `alpha.106` closed, one object along, and `StorefrontVariationEditTest`
 * reported it.
 *
 * The obvious repair — make the shared rule positive and have both doors read
 * it — would have broken the other direction, because `ManageVariations` was
 * RIGHT to refuse on an unreadable shop and `!isOurs()` is what made it do so.
 * So each door asks its own positive question, and this table is the only
 * thing that keeps them from drifting back into one.
 */
final class ShopOwnedVariationDoorsAreExclusiveTest extends TestCase
{
    private const PRODUCT = 189;
    private const PARENT = 7700;

    /**
     * The shop confirmedly holds the children: the shop's door opens, ours
     * refuses.
     */
    public function testAConfirmedShopProductIsBehindTheShopsDoorOnly(): void
    {
        $rule = $this->rule(ours: false, set: StorefrontVariationSet::read([$this->child()], 0));

        self::assertTrue($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
        self::assertFalse($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
    }

    /** Our own tables hold the axes: our door opens, the shop's refuses. */
    public function testAMarketplaceBuiltProductIsBehindOurDoorOnly(): void
    {
        $rule = $this->rule(ours: true, set: StorefrontVariationSet::read([$this->child()], 0));

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
        self::assertTrue($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
    }

    /**
     * ⛔ THE STATE THAT MUST OPEN NEITHER DOOR: a read that FAILED.
     *
     * The shop may well be holding three children we cannot see. Neither
     * «they are the shop's» nor «they are ours» is supported by that, so both
     * verbs refuse and whichever one was asked says so by name.
     *
     * A single negation shared by the two doors cannot produce this row, which
     * is the whole reason there are two methods.
     */
    public function testAFailedReadOpensNeitherDoor(): void
    {
        $rule = $this->rule(ours: false, set: StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE));

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
        self::assertFalse($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
    }

    /**
     * And a read that came back PARTIAL: some children did not load.
     *
     * Read, but not read whole — the `alpha.100` half. Writing the parent on
     * the strength of children we could not see is worse than refusing, and
     * claiming the combinations as ours while the shop holds some is worse
     * again.
     */
    public function testAPartialReadOpensNeitherDoor(): void
    {
        $rule = $this->rule(
            ours: false,
            set: StorefrontVariationSet::read([$this->child()], 0, 2)
        );

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
        self::assertFalse($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
    }

    /**
     * ⛔ AND THE ROW I GOT WRONG WHILE WRITING THIS TEST, WHICH IS WHY IT HAS
     * ITS OWN METHOD AND ITS OWN SENTENCE.
     *
     * Three of the four «unavailable» answers are not failures at all. They
     * are successful readings that say the shop holds no combinations here:
     * WooCommerce is not installed, the storefront product is gone, or the
     * storefront product is not a variable one. None of them CAN have
     * children, so none of them can be holding this product's figures — and
     * `PriceAndStockOrigin::decide()` answers `OURS` for all three, with the
     * measurement that corrected it written above the `match`:
     * treating them as «unknown» broke
     * `VariationFlowTest::testAProductWhoseCombinationsAreOursStaysEditable`,
     * where a marketplace variable product linked to a storefront row that is
     * not yet variable could no longer have its FIRST attribute defined.
     *
     * My first draft of this file asserted all four open neither door. That
     * would have pinned a guard wider than the defect (`alpha.60`) — so the
     * row is here, positively, rather than deleted.
     */
    public function testAConfirmedAbsenceOfAShopLeavesTheCombinationsOurs(): void
    {
        foreach ([
            StorefrontVariationSet::NO_WOOCOMMERCE,
            StorefrontVariationSet::NO_STOREFRONT_PRODUCT,
            StorefrontVariationSet::NOT_VARIABLE,
        ] as $state) {
            $rule = $this->rule(ours: false, set: StorefrontVariationSet::unavailable($state));

            self::assertFalse(
                $rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT),
                $state . ': the shop cannot own what it does not hold'
            );
            self::assertTrue(
                $rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT),
                $state . ': our door must stay open — this is a reading, not a failure'
            );
        }
    }

    /**
     * A variable parent the shop confirmedly holds NO children for: that is a
     * confirmed reading, and it means the combinations are ours to build.
     *
     * This is the «Woo variable با صفر فرزندِ واقعاً تأییدشده را از unreadable
     * تفکیک کن» line `alpha.105` was asked for by name, and it is the one row
     * of this table that an `isRead()`-only check would get wrong.
     */
    public function testAConfirmedEmptyShopParentIsOursToBuild(): void
    {
        $rule = $this->rule(ours: false, set: StorefrontVariationSet::read([], 0));

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
        self::assertTrue($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
    }

    /** A simple product has no combinations, so neither door is about it. */
    public function testASimpleProductIsNotTheShopsToEdit(): void
    {
        $rule = $this->rule(ours: false, set: StorefrontVariationSet::read([$this->child()], 0));

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::SIMPLE, self::PARENT));
        self::assertTrue($rule->oursConfirmed(self::PRODUCT, ProductType::SIMPLE, self::PARENT));
    }

    /**
     * A build with no storefront reader at all: there is no shop to be
     * foreign to, so our door is the only one — the pre-`alpha.105` behaviour
     * and the `alpha.32` reason the collaborator is nullable.
     */
    public function testWithoutAStorefrontReaderOurDoorIsTheOnlyOne(): void
    {
        $rule = new StorefrontVariationOwnership($this->repository(false), null);

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
        self::assertTrue($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, self::PARENT));
    }

    /** And a row with no storefront product at all. */
    public function testARowWithNoStorefrontProductIsOursAlone(): void
    {
        $rule = $this->rule(ours: false, set: StorefrontVariationSet::read([$this->child()], 0));

        self::assertFalse($rule->shopOwns(self::PRODUCT, ProductType::VARIABLE, null));
        self::assertTrue($rule->oursConfirmed(self::PRODUCT, ProductType::VARIABLE, null));
    }

    /**
     * ⛔ AND THE INVARIANT ITSELF: never both, for any combination of the
     * inputs this rule reads.
     *
     * Asserted as a property rather than as seven rows, because the rows are
     * what somebody edits and the property is what the two services depend on.
     */
    public function testNoInputOpensBothDoors(): void
    {
        $sets = [
            'shop holds three' => StorefrontVariationSet::read([$this->child()], 0),
            'shop holds none' => StorefrontVariationSet::read([], 0),
            'failed read' => StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE),
            'partial read' => StorefrontVariationSet::read([$this->child()], 0, 2),
            'no woocommerce' => StorefrontVariationSet::unavailable(StorefrontVariationSet::NO_WOOCOMMERCE),
            'shop product gone' => StorefrontVariationSet::unavailable(StorefrontVariationSet::NO_STOREFRONT_PRODUCT),
        ];
        foreach ([true, false] as $ours) {
            foreach ($sets as $label => $set) {
                foreach ([ProductType::SIMPLE, ProductType::VARIABLE] as $type) {
                    foreach ([self::PARENT, null] as $wcId) {
                        $rule = $this->rule($ours, $set);
                        $shop = $rule->shopOwns(self::PRODUCT, $type, $wcId);
                        $mine = $rule->oursConfirmed(self::PRODUCT, $type, $wcId);
                        self::assertFalse(
                            $shop && $mine,
                            "both doors opened for [{$label}, ours=" . var_export($ours, true)
                                . ", type={$type}, wc=" . var_export($wcId, true) . ']'
                        );
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------- internals

    private function rule(bool $ours, StorefrontVariationSet $set): StorefrontVariationOwnership
    {
        $storefront = new FakeStorefrontVariations();
        $storefront->answers(self::PARENT, $set);
        return new StorefrontVariationOwnership($this->repository($ours), $storefront);
    }

    private function child(): StorefrontVariation
    {
        return new StorefrontVariation(
            7701,
            ['بسته' => '۵۰ عددی'],
            'BPI-50',
            2_600_000,
            null,
            2_600_000,
            true,
            true,
            8,
            'instock',
            'no',
            0
        );
    }

    /** A repository that either holds this product's axes or holds none. */
    private function repository(bool $holdsOurOwn): VariationRepositoryInterface
    {
        return new class ($holdsOurOwn) implements VariationRepositoryInterface {
            public function __construct(private readonly bool $holds)
            {
            }

            public function attributes(int $productId): array
            {
                return $this->holds ? [new ProductAttribute(1, 'bundle', 'بسته', ['۵۰ عددی'], 0)] : [];
            }

            public function saveAttribute(int $productId, string $key, string $label, array $options, int $sort = 0): int
            {
                return 0;
            }

            public function deleteAttribute(int $productId, int $attributeId): bool
            {
                return false;
            }

            public function variations(int $productId): array
            {
                return [];
            }

            public function findVariation(int $productId, int $variationId): ?ProductVariation
            {
                return null;
            }

            public function saveVariation(int $productId, ProductVariation $variation): int
            {
                return 0;
            }

            public function deleteVariation(int $productId, int $variationId): bool
            {
                return false;
            }

            public function updateVariationStock(int $variationId, int $stock): bool
            {
                return false;
            }

            public function linkVariation(int $variationId, ?int $wcVariationId): bool
            {
                return false;
            }

            public function forProducts(array $productIds): array
            {
                return [];
            }
        };
    }
}
