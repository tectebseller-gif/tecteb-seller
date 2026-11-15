<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * WHERE THIS PRODUCT'S PRICE AND STOCK LIVE — the one rule, three answers.
 *
 * ⛔ The reason this type exists is a measured defect, not tidiness. Until
 * `alpha.106` eight places each decided for themselves what a variable
 * parent's `priceMinor = 0` and `stock = 0` meant, and they disagreed:
 *
 *  - `ProductFormView::stepPrice()` rendered them as EDITABLE fields, so the
 *    vendor of an imported product was shown «۰» as their own price;
 *  - `ProductDetails::missingFields()` read the nought as «قیمتی ثبت نشده»
 *    and `ProductReadiness` refused the product — the same check
 *    `StorefrontStop::resume()` consults, so a healthy product could be
 *    stranded after a temporary stop;
 *  - `ManageProducts::saveAsRevision()` and `updateInventory()` pushed the
 *    nought OUTWARD, and `WooCommerceProjector::writeStock()` turned it into
 *    `set_manage_stock(true)` + `set_stock_quantity(0)` +
 *    `set_stock_status('outofstock')` on the parent — which on WooCommerce
 *    takes the whole product off sale, every variation with it;
 *  - `StorePageView` and the REST route published the nought to shoppers.
 *
 * Every one of those readings is defensible on its own and the product they
 * were all reading is a perfectly healthy one: an imported variable parent
 * keeps its price and its stock on its CHILDREN, which is WooCommerce's own
 * design. The nought in our row is not a price and never was — it is the
 * absence of a question we have no business asking.
 *
 * So: one rule, several readers (`alpha.78`), and `UNKNOWN` is a real answer
 * rather than a quiet fall back to `OURS` — because the direction that is
 * safe when we cannot tell is to write nothing and say «نامشخص», not to
 * write a nought and call it stock.
 */
final class PriceAndStockOrigin
{
    /**
     * The marketplace row holds the real figures.
     *
     * A simple product; a variable product whose combinations are in OUR
     * tables (the shop is then the mirror, ADR-008); or a product with no
     * storefront product at all, where our row is the only record there is.
     */
    public const OURS = 'ours';

    /**
     * The figures live on the WooCommerce children and the parent has none.
     *
     * Reached only for a variable product whose combinations this marketplace
     * does not hold and whose storefront parent was READ and really does have
     * children. Nothing here may write the parent's price or stock.
     */
    public const SHOP = 'shop';

    /**
     * We could not tell, so nothing is written and nothing is claimed.
     *
     * The read FAILED, or part of the children could not be loaded. Nothing
     * else: WooCommerce being absent, the storefront product being gone and
     * the storefront product not being variable are all SUCCESSFUL readings
     * that say the shop holds nothing here, so they answer `OURS` — see
     * `decide()` for the measurement that corrected this.
     */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $state,
        /** Why, in a word the audit row and the screen can both carry. */
        public readonly string $reason
    ) {
    }

    /**
     * @param string                  $type                 the marketplace row's product type
     * @param bool                    $oursHoldsCombinations our attribute or variation tables hold something
     * @param bool                    $linkedToShop          the row points at a storefront product
     * @param StorefrontVariationSet|null $set              what the shop said, or null when nobody asked
     */
    public static function decide(
        string $type,
        bool $oursHoldsCombinations,
        bool $linkedToShop,
        ?StorefrontVariationSet $set
    ): self {
        // A simple product's own price IS the price. This is the branch almost
        // every product in the catalogue takes, and it must keep today's
        // behaviour exactly: a guard wider than the defect is the bigger
        // defect (`alpha.60`).
        if ($type !== ProductType::VARIABLE) {
            return new self(self::OURS, 'simple_product');
        }
        // Ours, and the shop is the mirror — the same discriminator
        // `ManageVariations::shopOwnsTheCombinations()` has used since
        // `alpha.105`, so the two cannot drift apart.
        if ($oursHoldsCombinations) {
            return new self(self::OURS, 'marketplace_combinations');
        }
        if (!$linkedToShop) {
            // Nothing out there to own them, and nothing out there to write
            // to. Readiness still refuses this product by name
            // (`variable_needs_attributes`), which is the correct refusal:
            // it is incomplete, not foreign.
            return new self(self::OURS, 'no_storefront_product');
        }
        if ($set === null) {
            // Nobody asked. The caller that cannot ask is the caller that must
            // not write.
            return new self(self::UNKNOWN, 'not_read');
        }
        if (!$set->isRead()) {
            // ⛔ ONLY A FAILED READ IS «نمی‌دانم», and the first draft of this
            // method got that wrong in the direction that matters.
            //
            // Three of these four states are SUCCESSFUL readings that say the
            // shop holds no combinations here: WooCommerce is not installed,
            // the storefront product is gone, or the storefront product is not
            // a variable one. None of them can have children, so none of them
            // can hold this product's figures — and treating them as «unknown»
            // refused writes that have always been allowed.
            //
            // It was measured, not reasoned: the first version broke
            // `VariationFlowTest::testAProductWhoseCombinationsAreOursStays
            // Editable`, where a marketplace variable product linked to a
            // storefront row that is not yet variable could no longer have its
            // FIRST attribute defined. That is a guard wider than the defect,
            // which is the bigger defect (`alpha.60`) — and the control test
            // existed precisely to catch it.
            //
            // What is left in `UNKNOWN` is the hole `alpha.105` actually had:
            // a read that FAILED while we were asking about a product we
            // believe is linked. There, the shop may well hold three children
            // and we cannot see them.
            return match ($set->state) {
                StorefrontVariationSet::NO_WOOCOMMERCE => new self(self::OURS, 'no_woocommerce'),
                StorefrontVariationSet::NOT_VARIABLE => new self(self::OURS, 'shop_product_not_variable'),
                StorefrontVariationSet::NO_STOREFRONT_PRODUCT => new self(self::OURS, 'no_storefront_product'),
                default => new self(self::UNKNOWN, 'unreadable'),
            };
        }
        if (!$set->isComplete()) {
            // Read, but some children did not load. A partial read presented
            // as the whole is the `alpha.100` lie; and writing the parent on
            // the strength of children we could not see is worse.
            return new self(self::UNKNOWN, 'children_unreadable');
        }
        if ($set->variations === []) {
            // A CONFIRMED EMPTY ANSWER, which is not the same as a failed one
            // — the distinction the prompt asks for in as many words. The shop
            // holds no combinations either, so there is nothing for it to own
            // and readiness refuses by name (`variable_needs_variations`).
            return new self(self::OURS, 'shop_has_no_children');
        }
        return new self(self::SHOP, 'shop_combinations');
    }

    /** For a simple product and for the tests that build a known state. */
    public static function ours(string $reason = 'simple_product'): self
    {
        return new self(self::OURS, $reason);
    }

    public function isOurs(): bool
    {
        return $this->state === self::OURS;
    }

    /**
     * May anything write this product's own price, stock or stock status?
     *
     * FALSE for both `SHOP` and `UNKNOWN`, and that is the whole point of the
     * third state: «نمی‌دانم» and «مال فروشگاه است» have the same answer here
     * and different sentences on screen.
     */
    public function mayWriteParentFigures(): bool
    {
        return $this->state === self::OURS;
    }

    /**
     * Should the form offer an editable price and stock box?
     *
     * The same question as above, asked by the renderer. One method would
     * have done; two names are kept because a later round that loosens one of
     * them must be made to say which.
     */
    public function offersParentFigures(): bool
    {
        return $this->state === self::OURS;
    }

    /** Is the parent's nought a real figure, or the absence of one? */
    public function parentFiguresAreReal(): bool
    {
        return $this->state === self::OURS;
    }
}
