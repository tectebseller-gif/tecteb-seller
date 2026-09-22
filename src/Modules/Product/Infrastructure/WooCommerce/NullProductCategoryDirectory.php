<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;

/**
 * No WooCommerce, no categories — said out loud rather than guessed.
 *
 * The picker renders «دسته‌ای در دسترس نیست، چون ووکامرس فعال نیست» from this,
 * which is a different sentence from «هیچ دسته‌ای ساخته نشده» and sends the
 * manager somewhere different.
 */
final class NullProductCategoryDirectory implements ProductCategoryDirectoryInterface
{
    public function search(string $query, int $limit = 50): array
    {
        return [];
    }

    public function countMatches(string $query): int
    {
        return 0;
    }

    public function find(string $value): ?\Tecteb\Marketplace\Modules\Product\Domain\ProductCategory
    {
        return null;
    }

    public function total(): int
    {
        return 0;
    }
}
