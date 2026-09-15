<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;

/**
 * Reads Dokan's own storage, as Dokan lays it out — and never writes to it.
 *
 * The three places Dokan keeps what this migration needs, measured on Dokan
 * Lite 5.1.1:
 *
 *  - a seller is a WordPress user with the `seller` role, and their store name
 *    is in the `dokan_profile_settings` user meta;
 *  - a seller's product is a WooCommerce product whose `post_author` is that
 *    user — Dokan uses authorship, not a meta key, which is why a product's
 *    ownership survives Dokan being switched off;
 *  - a seller's order line is a row in `{prefix}dokan_orders`.
 *
 * Every query is a SELECT. If Dokan changes its layout, this reads less and
 * reports it; it never guesses a shape it did not find.
 */
final class WpDokanReader implements DokanReaderInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function isAvailable(): bool
    {
        return class_exists('WeDevs_Dokan', false)
            || function_exists('dokan')
            || $this->tableExists($this->db->prefix() . 'dokan_orders');
    }

    public function vendors(): array
    {
        if (!$this->isAvailable() || !function_exists('get_users')) {
            return [];
        }
        $vendors = [];
        foreach (get_users(['role' => 'seller', 'fields' => ['ID', 'user_email']]) as $user) {
            $settings = get_user_meta((int) $user->ID, 'dokan_profile_settings', true);
            $vendors[] = [
                'user_id' => (int) $user->ID,
                'store_name' => is_array($settings) && ($settings['store_name'] ?? '') !== ''
                    ? (string) $settings['store_name']
                    // Dokan allows a seller with no store name; the migration
                    // reports what is there rather than inventing a title.
                    : (string) ($user->display_name ?? ''),
                'email' => (string) $user->user_email,
                'enabled' => is_array($settings) ? (($settings['enable_selling'] ?? 'yes') === 'yes') : true,
            ];
        }
        return $vendors;
    }

    public function products(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sellerIds = array_map(static fn (array $v): int => $v['user_id'], $this->vendors());
        if ($sellerIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($sellerIds), '%d'));
        $rows = $this->db->getResults(
            'SELECT p.ID, p.post_author, p.post_title FROM `' . $this->posts() . '` p
             WHERE p.post_type = %s AND p.post_status IN (%s, %s) AND p.post_author IN (' . $placeholders . ')
             ORDER BY p.ID ASC',
            array_merge(['product', 'publish', 'draft'], $sellerIds)
        );
        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $products[] = [
                'wc_product_id' => $id,
                'vendor_user_id' => (int) $row['post_author'],
                'title' => (string) $row['post_title'],
                'sku' => (string) get_post_meta($id, '_sku', true),
                'price_minor' => (int) round((float) get_post_meta($id, '_price', true)),
                'stock' => (int) get_post_meta($id, '_stock', true),
            ];
        }
        return $products;
    }

    public function orders(): array
    {
        $table = $this->db->prefix() . 'dokan_orders';
        if (!$this->isAvailable() || !$this->tableExists($table)) {
            return [];
        }
        $rows = $this->db->getResults(
            'SELECT order_id, seller_id, order_status, order_total FROM `' . $table . '` ORDER BY order_id ASC LIMIT 500'
        );
        return array_map(static fn (array $row): array => [
            'wc_order_id' => (int) $row['order_id'],
            'vendor_user_id' => (int) $row['seller_id'],
            'status' => (string) $row['order_status'],
            'total_minor' => (int) round((float) $row['order_total']),
        ], $rows);
    }

    private function tableExists(string $table): bool
    {
        return (string) $this->db->getVar('SHOW TABLES LIKE %s', [$table]) === $table;
    }

    private function posts(): string
    {
        return $this->db->prefix() . 'posts';
    }
}
