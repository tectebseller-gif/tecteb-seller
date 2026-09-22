<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One WooCommerce `product_cat` term, as the vendor's form needs to show it.
 *
 * `path` is the whole ancestry — «تجهیزات پزشکی › بیهوشی و تنفسی › آمبوبگ» —
 * because on a site with a thousand categories the leaf name alone is not an
 * answer. Two different branches routinely end in «لوازم جانبی», and a picker
 * that shows only the leaf asks the vendor to guess which one they are on.
 *
 * `id` is the term id, and it is the value that gets stored. Not the slug,
 * not a key of our own: the whole point of this type is that the marketplace
 * and WooCommerce are naming the same row.
 */
final class ProductCategory
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $path,
        public readonly int $parentId = 0,
        public readonly int $productCount = 0,
        public readonly bool $hasTemplate = false
    ) {
    }

    /** The value a form field carries — always the term id, as a string. */
    public function value(): string
    {
        return (string) $this->id;
    }
}
