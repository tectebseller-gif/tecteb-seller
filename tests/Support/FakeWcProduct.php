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
    /**
     * `$name` is optional so that every construction site written before the
     * cart guard needed it keeps working — the `alpha.32` rule, which this
     * repository has paid for three times.
     *
     * It matters because the refusal sentence NAMES the product, and a double
     * with no name would let a test pass on the generic fallback while the
     * named branch went unmeasured.
     */
    public function __construct(
        private readonly int $id,
        private readonly int $parentId = 0,
        private readonly string $name = ''
    ) {
    }

    public function get_name(): string
    {
        return $this->name;
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
