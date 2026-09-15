<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

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
     * @return list<array{order_item_id:int, wc_product_id:int, variation_id:?int, title:string, sku:string, quantity:int, line_total_minor:int, line_tax_minor:int}>
     */
    public function lines(mixed $order): array
    {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return [];
        }
        $lines = [];
        foreach ($order->get_items() as $orderItemId => $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $lines[] = [
                'order_item_id' => (int) $orderItemId,
                'wc_product_id' => (int) $item->get_product_id(),
                'variation_id' => $variationId > 0 ? $variationId : null,
                'title' => (string) $item->get_name(),
                'sku' => is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
                'quantity' => (int) $item->get_quantity(),
                // Minor units with no decimals for IRR/تومان: the store's own
                // totals, after discount and before tax, which is exactly the
                // base FIN-01 asks for.
                'line_total_minor' => (int) round((float) $item->get_total()),
                'line_tax_minor' => (int) round((float) $item->get_total_tax()),
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
