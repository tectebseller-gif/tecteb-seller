<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

/**
 * The two methods the purchase guard asks of a WooCommerce product.
 *
 * Deliberately NOT a `WC_Product`: the guard is written to duck-type through
 * `method_exists`, precisely so it can be exercised without WooCommerce in the
 * process, and a fake that answers those two questions is the whole contract.
 */
final class FakeWcProduct
{
    public function __construct(private readonly int $id, private readonly int $parentId = 0)
    {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_parent_id(): int
    {
        return $this->parentId;
    }
}
