<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Domain\FieldMerge;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;

/**
 * The shop's side of a projected product, without WooCommerce.
 *
 * It models one thing and says so: after a projection, the storefront holds
 * what the marketplace wrote — EXCEPT for the fields a person has since edited
 * in WooCommerce, which the test names in `$managerEdits`. That is enough to
 * exercise the three things `ReviewProducts` asks a storefront for (the
 * optimistic lock, the round-trip copy and the baseline material) with real
 * repositories and real SQL underneath.
 *
 * What it deliberately does NOT model is the merge itself. Which side wins a
 * field is `FieldMerge`'s answer and it is asserted directly in
 * `FieldMergeTest`; the whole path with a real WooCommerce is measured on a
 * clean install by `tools/revision-baseline-check.sh`.
 */
final class FakeStorefrontFields implements StorefrontFieldsInterface
{
    /** @var array<string,string> what a person typed into WooCommerce, by field */
    public array $managerEdits = [];

    /**
     * @var array<string,string> proposals the projection HELD, by field
     *
     * Presence is the question, not the value: an empty proposal is a vendor
     * asking for the field to be cleared (`alpha.28`).
     */
    public array $pending = [];

    public function __construct(private readonly ProductRepositoryInterface $products)
    {
    }

    public function compare(Product $product): array
    {
        $shop = $this->storefrontValues($product);
        if ($shop === []) {
            return [];
        }
        $record = $this->recordValues($product);
        $rows = [];
        foreach ($shop as $field => $value) {
            $rows[] = new StorefrontField(
                $field,
                $record[$field] ?? '',
                $value,
                $this->pending[$field] ?? '',
                StorefrontField::OWNER_MARKETPLACE,
                $field !== 'description',
                false,
                array_key_exists($field, $this->pending)
            );
        }
        return $rows;
    }

    public function keepStorefront(Product $product, string $field): ?string
    {
        return $this->storefrontValues($product)[$field] ?? null;
    }

    public function acceptProposal(Product $product, string $field): ?string
    {
        unset($this->managerEdits[$field], $this->pending[$field]);
        return null;
    }

    public function fingerprints(Product $product): array
    {
        return array_map(
            static fn (string $value): string => FieldMerge::fingerprint($value),
            $this->storefrontValues($product)
        );
    }

    public function changedSince(Product $product, array $seen): array
    {
        if ($seen === []) {
            return [];
        }
        $now = $this->storefrontValues($product);
        $moved = [];
        foreach ($seen as $field => $fingerprint) {
            if (!array_key_exists($field, $now)) {
                continue;
            }
            if (!hash_equals($fingerprint, FieldMerge::fingerprint($now[$field]))) {
                $moved[] = $field;
            }
        }
        return $moved;
    }

    public function reconcile(Product $product): array
    {
        $values = $this->storefrontValues($product);
        unset($values['description']);      // built, never copied back
        return $values;
    }

    public function storefrontValues(Product $product): array
    {
        $fresh = $this->products->find($product->id) ?? $product;
        if (!$fresh->isProjected()) {
            return [];                      // no storefront row to read
        }
        return array_merge($this->recordValues($product), $this->managerEdits);
    }

    /**
     * What the marketplace record says, field by field — the values a
     * projection would write if nothing else had touched them.
     *
     * @return array<string,string>
     */
    private function recordValues(Product $product): array
    {
        $fresh = $this->products->find($product->id) ?? $product;
        return [
            'title' => $fresh->details->title,
            'short_description' => $fresh->details->shortDescription,
            // Generated, exactly as the real projector generates it: out of
            // the vendor's short description rather than typed by anyone.
            'description' => 'ساخته‌شده: ' . $fresh->details->shortDescription,
            'category' => $fresh->details->categoryKey,
            'images' => StorefrontImages::encode($fresh->mainImageId, $fresh->imageIds),
        ];
    }

    public function editorUrl(int $wcProductId): string
    {
        return '';
    }

    public function seoPluginName(): string
    {
        return '';
    }
}
