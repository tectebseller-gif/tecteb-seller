<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * An APPROVED brand: a term the manager keeps, with the id it is known by.
 *
 * The id matters more than it looks. Storing only the name would make a
 * manager renaming a brand in WooCommerce into a product whose brand no longer
 * matches anything in the list — and the marketplace would have no way to tell
 * «they renamed it» from «somebody typed free text». So the chosen brand is
 * stored as both: the id, which is identity, and the name, which is what the
 * shop displays.
 */
final class Brand
{
    public function __construct(
        public readonly int $termId,
        public readonly string $name
    ) {
    }

    /** The key this brand is compared by (`BrandName`). */
    public function key(): string
    {
        return BrandName::key($this->name);
    }
}
