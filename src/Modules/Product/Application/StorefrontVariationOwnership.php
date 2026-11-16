<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\PriceAndStockOrigin;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;

/**
 * Whose combinations these are: ours, or the shop's.
 *
 * ⛔ **ONE RULE, TWO DOORS, AND THAT IS WHY IT IS A CLASS.** Two services now
 * ask this question and they must never disagree about one product:
 *
 *  - `ManageVariations` refuses its five writing verbs when the answer is
 *    «the shop's», because writing a row into `tmc_product_variations` for a
 *    product whose children WooCommerce already holds is how a SECOND set of
 *    variations appears beside the first (`alpha.105` §3-۹);
 *  - `EditStorefrontVariations` refuses when the answer is «ours», because its
 *    writes go straight at a real `WC_Product_Variation` and a combination
 *    this plugin created is mirrored by `project()` — editing it from both
 *    ends is two writers for one figure.
 *
 * So the two doors are MUTUALLY EXCLUSIVE BY CONSTRUCTION rather than by two
 * sentences that happen to agree today (`alpha.78`). A product is behind
 * exactly one of them, and «موجودی یا قیمت ترکیب‌های ساخته‌شده توسط افزونه
 * تغییر نکند» is that structure and not a check somebody has to remember.
 *
 * **Fail-closed in BOTH directions**, which is the half `alpha.105` got wrong
 * and `alpha.106` fixed: a shop that cannot be read is not evidence for
 * either door, so `shopOwns()` is false and `oursToEdit()` is false too. A
 * product in that state gets a named refusal from whichever verb was asked,
 * not a write.
 */
final class StorefrontVariationOwnership
{
    public function __construct(
        private readonly VariationRepositoryInterface $variations,
        /**
         * Nullable for the same reason `ManageVariations` keeps it nullable:
         * a build with no storefront reader has no shop to be foreign to, and
         * every hand-built test service goes on working (`alpha.32`).
         */
        private readonly ?StorefrontVariationReaderInterface $storefront = null
    ) {
    }

    /**
     * True only when the shop CONFIRMEDLY holds this product's combinations
     * and the marketplace holds none of its own.
     */
    public function shopOwns(int $productId, string $type, ?int $wcProductId): bool
    {
        if ($this->storefront === null || $wcProductId === null || $wcProductId <= 0) {
            return false;
        }
        if ($type !== ProductType::VARIABLE) {
            return false;
        }
        if ($this->variations->attributes($productId) !== [] || $this->variations->variations($productId) !== []) {
            return false;       // ours, and the shop is the mirror
        }
        // ⛔ A CONFIRMED «SHOP», not `!isOurs()`.
        //
        // `UNKNOWN` is also not-ours, so `!isOurs()` would open this door on a
        // shop that could not be read — and this door writes straight at a
        // real `WC_Product_Variation`. That was the first version of this
        // method and `StorefrontVariationEditTest` reported the write going
        // through; it is the same fail-open `alpha.106` fixed in the other
        // direction. Both doors now ask a POSITIVE question of the one rule
        // every save path and readiness read (`alpha.78`), and `UNKNOWN`
        // refuses at both.
        return $this->origin($wcProductId)->isShop();
    }

    /**
     * True only when the marketplace's own tables are CONFIRMEDLY the one
     * description of this product's combinations.
     *
     * ⛔ **NOT `!shopOwns()`, AND THE DIFFERENCE IS THE WHOLE REASON BOTH
     * METHODS EXIST.** An unreadable shop is not-shop AND not-ours: it is the
     * one state in which neither door may open. So each door asks its own
     * POSITIVE question and `UNKNOWN` refuses at both —
     * `EditStorefrontVariations` needs `shopOwns()`, and
     * `ManageVariations` refuses unless this one is true.
     *
     * Fixing the first of these to a positive question without fixing the
     * second would have turned the `alpha.106` guard fail-open: `!isOurs()` is
     * true for an unreadable shop, which is what made that guard right in its
     * direction, and a single shared negation cannot be right in both.
     */
    public function oursConfirmed(int $productId, string $type, ?int $wcProductId): bool
    {
        if ($this->storefront === null || $wcProductId === null || $wcProductId <= 0) {
            return true;        // no shop to be foreign to
        }
        if ($type !== ProductType::VARIABLE) {
            return true;
        }
        if ($this->variations->attributes($productId) !== [] || $this->variations->variations($productId) !== []) {
            return true;
        }
        return $this->origin($wcProductId)->isOurs();
    }

    private function origin(int $wcProductId): PriceAndStockOrigin
    {
        return PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            false,
            true,
            $this->storefront?->setFor($wcProductId)
        );
    }
}
