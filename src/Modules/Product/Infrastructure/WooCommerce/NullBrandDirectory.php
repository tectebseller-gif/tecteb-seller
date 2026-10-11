<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Brand;

/**
 * No WooCommerce, so no brand list — and «no list» is said rather than faked.
 *
 * `isAvailable()` answering false is what makes the brand gate stand down:
 * refusing every brand edit on a site with no storefront would stop a vendor
 * filling in a draft, and accepting them silently would create the free-text
 * duplicates §4 exists to prevent. Saying «there is no list» lets the caller
 * decide, once, in one place.
 */
final class NullBrandDirectory implements BrandDirectoryInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function search(string $query, int $limit = 30): array
    {
        return [];
    }

    public function find(int $termId): ?Brand
    {
        return null;
    }

    public function findByKey(string $key): ?Brand
    {
        return null;
    }

    public function register(string $name): ?Brand
    {
        return null;
    }

    public function count(): int
    {
        return 0;
    }
}
