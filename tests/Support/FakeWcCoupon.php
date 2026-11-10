<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

/**
 * The three questions the apply-time guard asks of a `WC_Coupon`.
 *
 * Deliberately NOT a `WC_Coupon`: the guard duck-types through
 * `method_exists`, so the contract is these three answers — and modelling
 * `is_valid_for_cart()` / `is_valid_for_product()` separately is the whole
 * point, because the first is what a `fixed_cart` code answers true to and
 * the second is what a code scoped to the host shop's own ids answers false
 * to. A double that collapsed them would make §4-الف's control case — «a
 * WooCommerce coupon scoped away from marketplace lines still works» —
 * inexpressible (`alpha.59`).
 *
 * ⛔ `$productScope === null` means «this build has no product scope to
 * report», used to reach the `touches === null` branch. It is NOT the same as
 * an empty scope, which in WooCommerce means «all items» — see
 * `WC_Coupon::is_valid_for_product()`, `class-wc-coupon.php:1024-1028`.
 */
final class FakeWcCoupon
{
    /**
     * @param string $code the code as `wc_format_coupon_code()` would leave it
     * @param bool $cartLevel true for `fixed_cart`, WooCommerce's only
     *        cart-level type (`wc_get_cart_coupon_types()`)
     * @param list<int>|null $productScope wc product ids this code applies to;
     *        an EMPTY list means every item, matching WooCommerce
     */
    public function __construct(
        private readonly string $code,
        private readonly bool $cartLevel = false,
        private readonly ?array $productScope = []
    ) {
    }

    public function get_code(): string
    {
        return $this->code;
    }

    public function is_valid_for_cart(): bool
    {
        return $this->cartLevel;
    }

    /** @param array<string,mixed> $values */
    public function is_valid_for_product(mixed $product, array $values = []): bool
    {
        unset($values);
        if ($this->cartLevel || $this->productScope === null) {
            // WooCommerce answers false for a cart-level type here too
            // (`class-wc-coupon.php:1007-1010`); the guard never asks in that
            // case because `is_valid_for_cart()` already said true.
            return false;
        }
        if ($this->productScope === []) {
            return true;                // no ids and no categories: all items
        }
        $id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $parent = is_object($product) && method_exists($product, 'get_parent_id')
            ? (int) $product->get_parent_id()
            : 0;
        return in_array($id, $this->productScope, true)
            || ($parent > 0 && in_array($parent, $this->productScope, true));
    }
}
