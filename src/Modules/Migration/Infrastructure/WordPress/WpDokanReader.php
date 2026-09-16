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
 *
 * **Reading is paged, and the page is keyed rather than offset.** The first
 * version of `orders()` ended in `LIMIT 500` with nothing after it: a shop with
 * 600 Dokan orders had 500 of them planned, 100 of them invisible, and a report
 * that said the run was complete. Silent truncation is worse than a refusal,
 * because the refusal would at least have been read. Now every read walks
 * `WHERE id > ? ORDER BY id ASC LIMIT ?` until a page comes back short, and the
 * resumable import asks for one page at a time and writes down which id it
 * reached. Keyset and not OFFSET for the reason `alpha.11` measured on the
 * unpaid-order guard: paging by offset over a set somebody else is writing to
 * skips rows, and here the other writer is the live Dokan shop.
 */
final class WpDokanReader implements DokanReaderInterface
{
    /**
     * Ceiling on a single unpaged read, so `products()` cannot spin for ever on
     * a shop whose ids keep moving. At `PAGE` rows each this is 200,000 rows —
     * far above any shop this migration targets, and low enough that hitting it
     * means something is wrong rather than something is big. The resumable job
     * never reaches it: it asks for one page at a time.
     */
    private const MAX_PAGES = 1000;

    /**
     * Sellers, read once per request.
     *
     * Every product page needs the seller list to scope its query, and without
     * this a 40-page walk asked WordPress for the same user list 40 times. It
     * is a request-scoped memo, not a cache: the object is rebuilt on the next
     * request, so a seller added meanwhile is seen by the next batch — which is
     * exactly the granularity a resumable job wants.
     *
     * @var list<array{user_id:int, store_name:string, email:string, enabled:bool}>|null
     */
    private ?array $vendorMemo = null;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function isAvailable(): bool
    {
        return class_exists('WeDevs_Dokan', false)
            || function_exists('dokan')
            || $this->tableExists($this->db->prefix() . 'dokan_orders');
    }

    public function vendorsAfter(int $afterUserId, int $limit = DokanReaderInterface::PAGE): array
    {
        if (!$this->isAvailable() || !function_exists('get_userdata')) {
            return [];
        }
        // Keyset in SQL, because `WP_User_Query` has no «id greater than».
        //
        // The join is how WordPress itself implements a role filter: a role is
        // a key inside the serialized `capabilities` meta, and core matches it
        // with exactly this LIKE. Doing it here rather than in `get_users`
        // buys the one thing `get_users` cannot express — `u.ID > ?` — which
        // is what makes the walk a keyset rather than an offset.
        //
        // An offset walk would be wrong for the reason `alpha.11` measured on
        // the unpaid-order guard: paging by offset over a set somebody else is
        // writing to skips rows, and here the other writer is a live Dokan
        // shop granting somebody the seller role mid-import.
        $rows = $this->db->getResults(
            'SELECT u.ID FROM `' . $this->db->prefix() . 'users` u
             INNER JOIN `' . $this->db->prefix() . 'usermeta` m
                     ON m.user_id = u.ID AND m.meta_key = %s
             WHERE m.meta_value LIKE %s AND u.ID > %d
             ORDER BY u.ID ASC LIMIT %d',
            [
                $this->db->prefix() . 'capabilities',
                '%"seller"%',
                max(0, $afterUserId),
                $this->page($limit),
            ]
        );

        $vendors = [];
        foreach ($rows as $row) {
            $user = get_userdata((int) $row['ID']);
            if ($user === false) {
                continue;                       // a meta row whose user is gone
            }
            $vendors[] = $this->vendorRow($user);
        }
        return $vendors;
    }

    public function vendors(): array
    {
        if ($this->vendorMemo !== null) {
            return $this->vendorMemo;
        }
        if (!$this->isAvailable() || !function_exists('get_userdata')) {
            return [];
        }
        // Drained through the SAME paged read the import uses, so «all of them»
        // and «one page at a time» can never disagree about who a seller is.
        /** @var list<array{user_id:int, store_name:string, email:string, enabled:bool}> $vendors */
        $vendors = $this->drain(fn (int $after): array => $this->vendorsAfter($after), 'user_id');
        $this->vendorMemo = $vendors;
        return $vendors;
    }

    public function products(): array
    {
        return $this->drain(fn (int $after): array => $this->productsAfter($after), 'wc_product_id');
    }

    public function productsAfter(int $afterId, int $limit = DokanReaderInterface::PAGE): array
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
               AND p.ID > %d
             ORDER BY p.ID ASC LIMIT %d',
            array_merge(['product', 'publish', 'draft'], $sellerIds, [max(0, $afterId), $this->page($limit)])
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
        return $this->drain(fn (int $after): array => $this->ordersAfter($after), 'wc_order_id');
    }

    public function ordersAfter(int $afterId, int $limit = DokanReaderInterface::PAGE): array
    {
        $table = $this->ordersTable();
        if (!$this->isAvailable() || !$this->tableExists($table)) {
            return [];
        }
        // `net_amount` and `is_refunded` are Dokan's own columns and they are
        // read because the history has to carry DOKAN's numbers. Selected
        // defensively: older Dokan releases do not have them, and a migration
        // that fatals on a column it assumed would be a migration nobody can
        // run.
        $hasNet = $this->columnExists($table, 'net_amount');
        $hasRefunded = $this->columnExists($table, 'is_refunded');
        $columns = 'order_id, seller_id, order_status, order_total'
            . ($hasNet ? ', net_amount' : '')
            . ($hasRefunded ? ', is_refunded' : '');

        $rows = $this->db->getResults(
            'SELECT ' . $columns . ' FROM `' . $table . '`
             WHERE order_id > %d ORDER BY order_id ASC LIMIT %d',
            [max(0, $afterId), $this->page($limit)]
        );
        return array_map(static function (array $row): array {
            $total = (int) round((float) $row['order_total']);
            // Absent net means unknown, NOT zero — and unknown is carried as
            // the total rather than as a figure this plugin worked out. A
            // computed commission here would be exactly the recalculation the
            // whole design refuses.
            $net = array_key_exists('net_amount', $row) && $row['net_amount'] !== null
                ? (int) round((float) $row['net_amount'])
                : $total;
            return [
                'wc_order_id' => (int) $row['order_id'],
                'vendor_user_id' => (int) $row['seller_id'],
                'status' => (string) $row['order_status'],
                'total_minor' => $total,
                'net_minor' => $net,
                // The difference Dokan itself recorded, not a rate applied.
                'commission_minor' => max(0, $total - $net),
                'refunded' => array_key_exists('is_refunded', $row) && (int) $row['is_refunded'] === 1,
            ];
        }, $rows);
    }

    public function counts(): array
    {
        if (!$this->isAvailable()) {
            return ['vendors' => 0, 'products' => 0, 'orders' => 0];
        }
        $sellerIds = array_map(static fn (array $v): int => $v['user_id'], $this->vendors());
        $products = 0;
        if ($sellerIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($sellerIds), '%d'));
            $products = (int) $this->db->getVar(
                'SELECT COUNT(*) FROM `' . $this->posts() . '`
                 WHERE post_type = %s AND post_status IN (%s, %s) AND post_author IN (' . $placeholders . ')',
                array_merge(['product', 'publish', 'draft'], $sellerIds)
            );
        }
        $orders = 0;
        if ($this->tableExists($this->ordersTable())) {
            $orders = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->ordersTable() . '`');
        }
        return ['vendors' => count($sellerIds), 'products' => $products, 'orders' => $orders];
    }

    /**
     * Walk every page until one comes back short.
     *
     * The cap exists so a runaway query cannot hang wp-admin, and when it is
     * reached the last row read is still the true last row read — which is what
     * makes this safe to resume from. The previous version stopped at 500 rows
     * with no page after it and no note anywhere, so a shop with 600 products
     * migrated 500 of them and reported success.
     *
     * @param callable(int):list<array<string,mixed>> $page
     * @return list<array<string,mixed>>
     */
    private function drain(callable $page, string $idKey): array
    {
        $all = [];
        $after = 0;
        for ($guard = 0; $guard < self::MAX_PAGES; $guard++) {
            $rows = $page($after);
            if ($rows === []) {
                return $all;
            }
            foreach ($rows as $row) {
                $all[] = $row;
                $after = max($after, (int) $row[$idKey]);
            }
            if (count($rows) < DokanReaderInterface::PAGE) {
                return $all;
            }
        }
        return $all;
    }

    /**
     * One seller, as this migration reads them.
     *
     * @param object $user
     * @return array{user_id:int, store_name:string, email:string, enabled:bool}
     */
    private function vendorRow(object $user): array
    {
        $settings = get_user_meta((int) $user->ID, 'dokan_profile_settings', true);
        return [
            'user_id' => (int) $user->ID,
            'store_name' => is_array($settings) && ($settings['store_name'] ?? '') !== ''
                ? (string) $settings['store_name']
                // Dokan allows a seller with no store name; the migration
                // reports what is there rather than inventing a title.
                : (string) ($user->display_name ?? ''),
            'email' => (string) ($user->user_email ?? ''),
            'enabled' => is_array($settings) ? (($settings['enable_selling'] ?? 'yes') === 'yes') : true,
        ];
    }

    private function page(int $limit): int
    {
        return max(1, min(1000, $limit));
    }

    private function ordersTable(): string
    {
        return $this->db->prefix() . 'dokan_orders';
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        ) > 0;
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
