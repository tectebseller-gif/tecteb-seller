<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
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
    public function __construct(private readonly SpecTemplateRepositoryInterface $templates)
    {
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
        return OperationResult::success('ready');
    }
}
