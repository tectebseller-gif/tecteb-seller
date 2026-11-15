<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\PriceAndStockOrigin;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * "Could this product be published?" — asked in three places, answered once.
 *
 * Step 4 of the form asks it to draw the checklist, submit() asks it before
 * queueing, and the manager's approve() asks it before anything goes live.
 * That last one is why this is its own class: a manager clicking «تأیید» on a
 * product with no picture and an empty required medical field would otherwise
 * publish something the vendor's own submit button would have refused.
 */
final class ProductReadiness
{
    public function __construct(
        private readonly SpecTemplateRepositoryInterface $templates,
        private readonly VariationRepositoryInterface $variations,
        /**
         * What the shop holds for an imported variable parent — READ ONLY,
         * appended with a default so every existing caller keeps compiling.
         *
         * Without it this class can only see OUR combination tables, which is
         * the whole of `alpha.105`'s §P0-الف: an imported parent has none, so
         * a perfectly sellable product was refused.
         */
        private readonly ?StorefrontVariationReaderInterface $storefront = null
    ) {
    }

    public function check(Product $product): OperationResult
    {
        // ⛔ WHERE THE FIGURES LIVE, ASKED FIRST — because the price check
        // below used to answer about the wrong product.
        //
        // `missingFields()` names `price` for `priceMinor <= 0` on every
        // product type, and that reading is right for all but one: an
        // imported variable parent keeps its price on its children, so its
        // nought is the absence of a question rather than an unanswered one.
        //
        // This mattered far beyond step 4. `ManageProducts::plan()` consults
        // this before queueing, `ReviewProducts::approve()` before anything
        // goes live, and — the one that does real harm —
        // `StorefrontStop::refusalFor()` on `resume()`. A healthy imported
        // product taken down by a temporary stop could not be put back.
        // READ AT MOST ONCE PER CALL, and passed down rather than re-read.
        //
        // ⛔ A per-instance memo stood here for one commit and a test caught
        // it: two `check()` calls on the same product, with the shop changed
        // in between, both answered from the first read. That is the
        // `alpha.37` stale-memo trap, and on `resume()` — which calls this for
        // up to 500 products — a memo keyed by product would also have been
        // the wrong economy, since each product is asked about once anyway.
        // One read, handed to both consumers.
        $set = $this->shopSetFor($product);
        $origin = PriceAndStockOrigin::decide(
            $product->details->type,
            $this->oursHoldsCombinations($product->id),
            $product->isProjected() && (int) ($product->wcProductId ?? 0) > 0,
            $set
        );

        $missing = $product->details->missingFields();
        if (!ProductType::isValid($product->details->type)) {
            $missing[] = 'type';
        }
        if (!$origin->parentFiguresAreReal()) {
            // Only the price, and only this one entry. A missing title or
            // category is still missing, and the product is still refused
            // below if its variations cannot sell — by a name that says so.
            $missing = array_values(array_filter($missing, static fn (string $f): bool => $f !== 'price'));
        }
        if ($missing !== []) {
            return OperationResult::failure('incomplete_product', ['fields' => implode('، ', $missing)]);
        }
        if ($product->imageIds === []) {
            return OperationResult::failure('missing_image');
        }
        $template = $this->templates->findByCategory($product->details->categoryKey);
        if ($template !== null) {
            $verdict = $template->validate($product->specs);
            if ($verdict['missing'] !== []) {
                return OperationResult::failure('missing_specs', ['fields' => implode('، ', $verdict['missing'])]);
            }
            if ($verdict['invalid'] !== []) {
                return OperationResult::failure('invalid_specs', ['fields' => implode('، ', $verdict['invalid'])]);
            }
        }
        if ($product->details->type === ProductType::VARIABLE) {
            $verdict = $origin->parentFiguresAreReal()
                ? $this->variableIsSellable($product->id)
                : $this->shopVariationsAreSellable($set, $origin);
            if ($verdict !== null) {
                return $verdict;
            }
        }
        return OperationResult::success('ready');
    }

    private function oursHoldsCombinations(int $productId): bool
    {
        return $this->variations->attributes($productId) !== []
            || $this->variations->variations($productId) !== [];
    }

    /**
     * What the shop holds for this product, or `null` when the question does
     * not arise — a simple product, a variable one whose combinations are
     * ours, or a row with no storefront product. Those never pay for the read,
     * which is the whole catalogue but a handful of imported parents.
     */
    private function shopSetFor(Product $product): ?StorefrontVariationSet
    {
        $wcProductId = (int) ($product->wcProductId ?? 0);
        if ($this->storefront === null
            || $wcProductId <= 0
            || !$product->isProjected()
            || $product->details->type !== ProductType::VARIABLE
            || $this->oursHoldsCombinations($product->id)
        ) {
            return null;
        }
        return $this->storefront->setFor($wcProductId);
    }

    /**
     * Is an IMPORTED variable parent sellable — asked of the shop, which is
     * the only place its combinations exist.
     *
     * ⛔ `UNKNOWN` is refused rather than waved through, and the refusal is
     * named. «در وضعیت نامطمئن با دلیلِ مشخص fail-closed کن» is the
     * instruction, and the direction matters: declaring a product ready on a
     * read that failed is how it would be published with no price anywhere.
     *
     * ⛔ And nothing here republishes or drafts anything. This verb answers a
     * question; the statuses of the products already live are not its
     * business, which is the «محصولات Published فعلی را … خودکار
     * draft/unpublish یا republish نکن» half of the same instruction.
     */
    private function shopVariationsAreSellable(
        ?StorefrontVariationSet $set,
        PriceAndStockOrigin $origin
    ): ?OperationResult {
        if ($origin->state === PriceAndStockOrigin::UNKNOWN) {
            return OperationResult::failure('variations_unreadable', ['reason' => $origin->reason]);
        }
        if ($set === null || !$set->isRead()) {
            return OperationResult::failure('variations_unreadable', [
                'reason' => $set?->state ?? 'not_read',
            ]);
        }
        if ($set->variations === []) {
            return OperationResult::failure('variable_needs_variations');
        }
        $buyable = array_filter($set->variations, static fn ($v): bool => $v->isBuyable());
        if ($buyable === []) {
            // Read, real children, and not one of them can be bought today —
            // every one disabled, or priceless, or out of stock. A different
            // sentence from «no variations», because the next step differs.
            return OperationResult::failure('variable_needs_priced_variation');
        }
        return null;
    }

    /**
     * A variable product is not "ready to sell" merely because its type says
     * variable — the owner's instruction in as many words. It needs an axis
     * with options, and at least one enabled combination that carries a
     * price; a variation without a price has no number to charge.
     */
    private function variableIsSellable(int $productId): ?OperationResult
    {
        $attributes = $this->variations->attributes($productId);
        $usable = array_filter($attributes, static fn ($attribute): bool => $attribute->isUsable());
        if ($usable === []) {
            return OperationResult::failure('variable_needs_attributes');
        }
        $variations = $this->variations->variations($productId);
        if ($variations === []) {
            return OperationResult::failure('variable_needs_variations');
        }
        $sellable = array_filter(
            $variations,
            static fn (ProductVariation $v): bool => $v->enabled && $v->isComplete()
        );
        if ($sellable === []) {
            return OperationResult::failure('variable_needs_priced_variation');
        }
        $incomplete = array_filter(
            $variations,
            static fn (ProductVariation $v): bool => $v->enabled && !$v->isComplete()
        );
        if ($incomplete !== []) {
            return OperationResult::failure('variation_without_price', [
                'count' => count($incomplete),
            ]);
        }
        return null;
    }
}
