<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
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
        private readonly VariationRepositoryInterface $variations
    ) {
    }

    public function check(Product $product): OperationResult
    {
        $missing = $product->details->missingFields();
        if (!ProductType::isValid($product->details->type)) {
            $missing[] = 'type';
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
            $verdict = $this->variableIsSellable($product->id);
            if ($verdict !== null) {
                return $verdict;
            }
        }
        return OperationResult::success('ready');
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
