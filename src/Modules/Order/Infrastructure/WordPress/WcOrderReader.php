<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Order\Domain\OrderCustomerView;

/**
 * Reads a WooCommerce order into plain values.
 *
 * It exists so the Application layer never holds a `WC_Order`: that boundary
 * is enforced by a test, and it is also what lets the capture logic be
 * exercised without WooCommerce at all.
 *
 * What it does NOT read is the point of `customerView()`: the billing e-mail
 * and phone are simply not fetched, so no later refactor can leak them into a
 * vendor's screen, export or notification (PRIV-01).
 */
final class WcOrderReader
{
    /**
     * The order's unit of money: the currency it was placed in, and how many
     * decimal places the storefront keeps.
     *
     * Read, not assumed. `alpha.38` never carried this across the boundary —
     * `OrderHooks` called `capture($id, $lines)` and `CaptureOrder` defaulted
     * to `'IRR', 0` — so an order in any other currency was recorded under a
     * currency nobody had asked WooCommerce about. The exponent comes from
     * `wc_get_price_decimals()`, which is the number of places the store
     * itself keeps its totals to; an absent function means no WooCommerce, and
     * then there is no order to read either.
     *
     * @return array{currency:string, exponent:int}
     */
    public function unit(mixed $order): array
    {
        $currency = is_object($order) && method_exists($order, 'get_currency')
            ? strtoupper(trim((string) $order->get_currency()))
            : '';
        $exponent = function_exists('wc_get_price_decimals') ? (int) \wc_get_price_decimals() : 0;
        if ($exponent < 0 || $exponent > DecimalAmount::MAX_EXPONENT) {
            // Out of what `Money` will hold. Reported as a line error rather
            // than clamped, because clamping is the silent rounding this
            // round exists to remove.
            $exponent = -1;
        }
        return ['currency' => $currency, 'exponent' => $exponent];
    }

    /**
     * @return list<array{order_item_id:int, wc_product_id:int, variation_id:?int, title:string, sku:string, quantity:int, line_total_minor:int, line_tax_minor:int, currency:string, exponent:int, unit_error:string}>
     */
    public function lines(mixed $order): array
    {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return [];
        }
        ['currency' => $currency, 'exponent' => $exponent] = $this->unit($order);
        $lines = [];
        foreach ($order->get_items() as $orderItemId => $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            // Converted before the row is assembled, so «could not be read
            // exactly» is a value on the row and not an exception thrown past
            // a hook WooCommerce is in the middle of.
            $total = $exponent < 0 ? null : DecimalAmount::toMinor((string) $item->get_total(), $exponent);
            $tax = $exponent < 0 ? null : DecimalAmount::toMinor((string) $item->get_total_tax(), $exponent);
            $unitError = '';
            if ($currency === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $unitError = 'currency_unreadable';
            } elseif ($exponent < 0) {
                $unitError = 'exponent_unsupported';
            } elseif ($total === null || $tax === null) {
                $unitError = 'amount_not_exact';
            }
            $lines[] = [
                'order_item_id' => (int) $orderItemId,
                'wc_product_id' => (int) $item->get_product_id(),
                'variation_id' => $variationId > 0 ? $variationId : null,
                'title' => (string) $item->get_name(),
                'sku' => is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
                'quantity' => (int) $item->get_quantity(),
                // The store's own totals — after discount and before tax,
                // which is exactly the base FIN-01 asks for — converted to
                // minor units at the order's OWN exponent, by string
                // arithmetic.
                //
                // `(int) round((float) …)` was here until `alpha.39`. It is
                // correct for a zero-decimal currency and a silent rounding
                // for every other one, and nothing downstream could tell the
                // two apart. Now a conversion that cannot be exact comes back
                // as `unit_error` and `CaptureOrder` refuses the line by name.
                'line_total_minor' => $total ?? 0,
                'line_tax_minor' => $tax ?? 0,
                'currency' => $currency,
                'exponent' => max(0, $exponent),
                'unit_error' => $unitError,
            ];
        }
        return $lines;
    }

    /** Four fields, and the two that matter are absent by construction. */
    public function customerView(mixed $order): OrderCustomerView
    {
        if (!is_object($order) || !method_exists($order, 'get_shipping_city')) {
            return new OrderCustomerView();
        }
        $name = trim(((string) $order->get_shipping_first_name()) . ' ' . ((string) $order->get_shipping_last_name()));
        if ($name === '') {
            $name = trim(((string) $order->get_billing_first_name()) . ' ' . ((string) $order->get_billing_last_name()));
        }
        $city = (string) $order->get_shipping_city();
        $address = trim(((string) $order->get_shipping_address_1()) . ' ' . ((string) $order->get_shipping_address_2()));
        $postcode = (string) $order->get_shipping_postcode();
        if ($city === '' && $address === '') {
            $city = (string) $order->get_billing_city();
            $address = trim(((string) $order->get_billing_address_1()) . ' ' . ((string) $order->get_billing_address_2()));
            $postcode = (string) $order->get_billing_postcode();
        }
        return new OrderCustomerView($name, $city, $address, $postcode);
    }

    /** @return array{id:int, number:string, status:string, created:string, currency:string} */
    public function summary(mixed $order): array
    {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return ['id' => 0, 'number' => '', 'status' => '', 'created' => '', 'currency' => ''];
        }
        $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
        return [
            'id' => (int) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => (string) $order->get_status(),
            'created' => is_object($created) && method_exists($created, 'date') ? (string) $created->date('Y-m-d H:i') : '',
            'currency' => method_exists($order, 'get_currency') ? (string) $order->get_currency() : '',
        ];
    }
}
