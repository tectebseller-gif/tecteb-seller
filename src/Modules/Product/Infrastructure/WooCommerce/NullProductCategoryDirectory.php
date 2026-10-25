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

    /**
     * No catalogue to look in, so no word can be called missing FROM it.
     *
     * The words are echoed back and `missing` is empty on purpose: saying
     * «این کلمه در فهرست نیست» when there is no list would blame the typing
     * for the plugin's own blindness.
     */
    public function explainNoMatch(string $query): array
    {
        return [
            'words' => \Tecteb\Marketplace\Modules\Product\Domain\CategoryMatcher::words($query),
            'missing' => [],
            'found' => [],
        ];
    }

    public function total(): int
    {
        return 0;
    }
}
