<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Finance\Application\TectebCommissionSourceInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;

/**
 * The owner's per-product commission, without WordPress.
 *
 * The real source reads two post meta rows, which the order suite has no way
 * to write: it runs against MariaDB and a stub post table, not a WooCommerce
 * install. What a capture test needs to know is which rule the line was
 * charged by and where it came from, and that is exactly what this hands over.
 *
 * `set()` after a capture is how «a later product change must not rewrite a
 * past order» is measured: the rule moves and the stored line must not.
 */
final class FakeTectebCommissionSource implements TectebCommissionSourceInterface
{
    /** @var array<int,TectebCommission> wc product id => rule */
    private array $products = [];

    /** @var array<int,TectebCommission> variation id => rule */
    private array $variations = [];

    public function set(int $wcProductId, TectebCommission $rule): void
    {
        $this->products[$wcProductId] = $rule;
    }

    public function setVariation(int $variationId, TectebCommission $rule): void
    {
        $this->variations[$variationId] = $rule;
    }

    public function forItem(int $wcProductId, int $variationId = 0): array
    {
        if ($variationId > 0 && isset($this->variations[$variationId])) {
            return ['rule' => $this->variations[$variationId], 'source' => self::FROM_VARIATION];
        }
        if (isset($this->products[$wcProductId])) {
            return ['rule' => $this->products[$wcProductId], 'source' => self::FROM_PRODUCT];
        }
        return ['rule' => TectebCommission::none(), 'source' => self::FROM_NONE];
    }
}
